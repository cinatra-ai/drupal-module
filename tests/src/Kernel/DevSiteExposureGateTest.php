<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint;
use Drupal\cinatra_dev_site\Mcp\AllowListContainer;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site_probe\ProbeController;
use Drupal\cinatra_dev_site_probe\ProbeRegistry;
use Drupal\KernelTests\KernelTestBase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the exposure gate of the development site.
 *
 * One guard, tied to the development marker, decides before any work
 * (MUST-FIX 3). While the exposure is active the inner controller gets a
 * deny-by-default container: no resource, prompt or other service beyond the
 * allow-list (MUST-FIX 2).
 *
 * @group cinatra
 */
final class DevSiteExposureGateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'workflows',
    'content_moderation',
    'cinatra',
    'cinatra_dev_site',
    'cinatra_dev_site_probe',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('CINATRA_DEV_SITE');
    ProbeController::$pull = [];
    ProbeController::$seen = [];
    ProbeController::$handled = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    parent::tearDown();
  }

  /**
   * The endpoint serves the exposure only for the marker and a TRUE flag.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   * @param bool $active
   *   Whether the exposure must be active.
   *
   * @dataProvider guardCases
   */
  public function testEndpointAsksTheGuardFirst(?string $marker, mixed $armed, bool $active): void {
    [$response, $request] = $this->send(['state'], $marker, $armed);
    if ($active) {
      $this->assertSame(403, $response->getStatusCode());
      $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
      $this->assertSame('refused', ProbeController::$seen['state']);
      $this->assertFalse(ProbeController::$handled);
      return;
    }
    // Not active: the inner controller gets the real container and the
    // request untouched, exactly as without this module.
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('probe served', $response->getContent());
    $this->assertSame($this->container, ProbeController::$seen['container']);
    $this->assertSame($this->container->get('state'), ProbeController::$seen['state']);
    $this->assertSame([], $request->attributes->all());
  }

  /**
   * The guard's own answer for the same cases.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   * @param bool $active
   *   Whether the exposure must be active.
   *
   * @dataProvider guardCases
   */
  public function testGuardTable(?string $marker, mixed $armed, bool $active): void {
    $this->setMarkerAndFlag($marker, $armed);
    $this->assertSame($active, CinatraDevSite::exposureActive());
    $this->assertSame($marker === '1', CinatraDevSite::markerPresent());
  }

  /**
   * Marker and flag values, and whether the exposure is active.
   *
   * @return array<string, array{string|null, mixed, bool}>
   *   The cases.
   */
  public static function guardCases(): array {
    return [
      'marker 1, armed TRUE' => ['1', TRUE, TRUE],
      'no marker, armed TRUE' => [NULL, TRUE, FALSE],
      'empty marker, armed TRUE' => ['', TRUE, FALSE],
      'marker 0, armed TRUE' => ['0', TRUE, FALSE],
      'marker true, armed TRUE' => ['true', TRUE, FALSE],
      'marker TRUE, armed TRUE' => ['TRUE', TRUE, FALSE],
      'marker yes, armed TRUE' => ['yes', TRUE, FALSE],
      'marker 01, armed TRUE' => ['01', TRUE, FALSE],
      'marker space 1, armed TRUE' => [' 1', TRUE, FALSE],
      'marker 1 space, armed TRUE' => ['1 ', TRUE, FALSE],
      'marker 1, no flag' => ['1', NULL, FALSE],
      'marker 1, flag FALSE' => ['1', FALSE, FALSE],
      'marker 1, flag 1' => ['1', 1, FALSE],
      'marker 1, flag string 1' => ['1', '1', FALSE],
      'marker 1, flag string TRUE' => ['1', 'TRUE', FALSE],
      'marker 1, flag list' => ['1', [TRUE], FALSE],
      'no marker, no flag' => [NULL, NULL, FALSE],
    ];
  }

  /**
   * While active, every id outside the allow-list is refused.
   *
   * @param string $id
   *   The service id the inner controller asks for.
   *
   * @dataProvider refusedIds
   */
  public function testIdOutsideTheAllowListIsRefused(string $id): void {
    [$response] = $this->send([$id], '1', TRUE);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
    $this->assertSame('refused', ProbeController::$seen[$id]);
    $this->assertFalse(ProbeController::$handled);
  }

  /**
   * Service ids that exist on the site but are not on the allow-list.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function refusedIds(): array {
    $ids = [
      'state',
      'database',
      'service_container',
      'current_user',
      'request_stack',
      'keyvalue',
      'settings',
      'module_handler',
      'class_resolver',
      'logger.factory',
      'cinatra.protected_draft_storage',
    ];
    return array_combine($ids, array_map(static fn(string $id): array => [$id], $ids));
  }

  /**
   * Case-varied and padded allow-list ids are refused (no such service).
   */
  public function testVariedIdsAreRefused(): void {
    foreach (['CONFIG.FACTORY', 'Config.factory', ' config.factory', 'config.factory ', 'config_factory', ''] as $id) {
      $view = new AllowListContainer($this->container, []);
      try {
        $view->get($id);
        $this->fail('Served: ' . $id);
      }
      catch (ExposureDeniedException $e) {
        $this->assertFalse($view->has($id));
      }
    }
  }

  /**
   * While active, the registries are empty and the client bridge absent.
   */
  public function testResourcesPromptsAndBridgeAreNotServed(): void {
    [$response] = $this->send(['mcp_tools.resource_registry', 'mcp_tools.prompt_registry', 'mcp_tools.client_bridge'], '1', TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], ProbeController::$seen['mcp_tools.resource_registry']);
    $this->assertSame([], ProbeController::$seen['mcp_tools.prompt_registry']);
    $this->assertNull(ProbeController::$seen['mcp_tools.client_bridge']);
    // The site's own registries still hold their items.
    $this->assertSame(['private resource'], $this->container->get('mcp_tools.resource_registry')->items());
  }

  /**
   * While active, the allow-listed ids pass through unchanged.
   */
  public function testAllowListedIdsPassThrough(): void {
    $ids = ['config.factory', 'entity_type.manager', 'event_dispatcher'];
    [$response] = $this->send($ids, '1', TRUE);
    $this->assertSame(200, $response->getStatusCode());
    foreach ($ids as $id) {
      $this->assertSame($this->container->get($id), ProbeController::$seen[$id], $id);
    }
  }

  /**
   * The allow-list is exactly the upstream create() ids it may pass on.
   */
  public function testAllowListIsExact(): void {
    $this->assertSame([
      'config.factory',
      'mcp_tools_remote.api_key_manager',
      'mcp_tools.access_manager',
      'mcp_tools.server_config_repository',
      'mcp_tools.tool_error_handler',
      'entity_type.manager',
      'event_dispatcher',
      'logger.channel.mcp_tools_remote',
    ], AllowListContainer::PASS_THROUGH);
  }

  /**
   * The container refuses every behaviour, parameter and write.
   */
  public function testContainerSurface(): void {
    $served = new ProbeRegistry([]);
    $view = new AllowListContainer($this->container, ['mcp_tools.client_bridge' => NULL, 'probe.served' => $served]);
    $behaviours = [
      ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE,
      ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE,
      ContainerInterface::NULL_ON_INVALID_REFERENCE,
      ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
      ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE,
    ];
    foreach ($behaviours as $behaviour) {
      try {
        $view->get('state', $behaviour);
        $this->fail('Served state with behaviour ' . $behaviour);
      }
      catch (ExposureDeniedException $e) {
        // Refused whatever the caller asked for.
      }
    }
    $this->assertSame($served, $view->get('probe.served'));
    $this->assertNull($view->get('mcp_tools.client_bridge'));
    $this->assertTrue($view->has('probe.served'));
    $this->assertFalse($view->has('mcp_tools.client_bridge'));
    $this->assertTrue($view->has('config.factory'));
    $this->assertFalse($view->has('state'));
    $this->assertFalse($view->has('mcp_tools.access_manager'));
    $this->assertTrue($this->container->initialized('state'));
    $this->assertFalse($view->initialized('state'));
    $this->assertFalse($view->hasParameter('cinatra_dev_site.remote_controller'));
    try {
      $view->getParameter('cinatra_dev_site.remote_controller');
      $this->fail('Served a parameter.');
    }
    catch (ExposureDeniedException $e) {
      // Parameters are refused.
    }
    foreach ([fn() => $view->set('state', NULL), fn() => $view->setParameter('x', 'y')] as $write) {
      try {
        $write();
        $this->fail('A write was accepted.');
      }
      catch (\LogicException $e) {
        // Read-only.
      }
    }
  }

  /**
   * A broken setting refuses; the guard still comes first.
   */
  public function testBrokenSettingsRefuse(): void {
    $good = [
      'mcp_tools.resource_registry' => ProbeRegistry::class,
      'mcp_tools.prompt_registry' => ProbeRegistry::class,
    ];
    $broken = [
      'missing key' => [ProbeController::class, ['mcp_tools.resource_registry' => ProbeRegistry::class]],
      'missing class' => [ProbeController::class, ['mcp_tools.prompt_registry' => 'Drupal\cinatra_dev_site_probe\Missing'] + $good],
      'not a string' => [ProbeController::class, ['mcp_tools.prompt_registry' => 7] + $good],
      'missing inner' => ['Drupal\cinatra_dev_site_probe\Missing', $good],
      'inner without create' => [ProbeRegistry::class, $good],
    ];
    foreach ($broken as $case => [$inner, $registries]) {
      $this->setMarkerAndFlag('1', TRUE);
      ProbeController::$seen = [];
      $response = $this->endpoint($inner, $registries)->handle(Request::create('/_mcp_tools', 'POST'));
      $this->assertSame(404, $response->getStatusCode(), $case);
      $this->assertSame('Not found.', $response->getContent(), $case);
      $this->assertSame([], ProbeController::$seen, $case);
    }
    // Not active: the broken registries are never read.
    $this->setMarkerAndFlag(NULL, TRUE);
    $response = $this->endpoint(ProbeController::class, [])->handle(Request::create('/_mcp_tools', 'POST'));
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Any other failure of the inner controller propagates unchanged.
   */
  public function testOtherFailuresPropagate(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('probe failure');
    $this->send(['!throw'], '1', TRUE);
  }

  /**
   * Sends one request to the endpoint built by the class resolver.
   *
   * @param string[] $pull
   *   The ids the probe's create() pulls.
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   *
   * @return array
   *   The response and the request.
   */
  private function send(array $pull, ?string $marker, mixed $armed): array {
    $this->setMarkerAndFlag($marker, $armed);
    ProbeController::$pull = $pull;
    $request = Request::create('/_mcp_tools', 'POST');
    $this->container->get('request_stack')->push($request);
    $endpoint = $this->container->get('class_resolver')->getInstanceFromDefinition(DevSiteRemoteEndpoint::class);
    return [$endpoint->handle($request), $request];
  }

  /**
   * Builds an endpoint with the given inner class and registry classes.
   *
   * @param string $inner
   *   The inner controller class.
   * @param array $registries
   *   The registry classes.
   *
   * @return \Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint
   *   The endpoint.
   */
  private function endpoint(string $inner, array $registries): DevSiteRemoteEndpoint {
    return new DevSiteRemoteEndpoint($this->container, new NullLogger(), $inner, $registries);
  }

  /**
   * Sets the marker and the armed flag.
   *
   * @param string|null $marker
   *   The marker value, or NULL to remove it.
   * @param mixed $armed
   *   The State value, or NULL to remove it.
   */
  private function setMarkerAndFlag(?string $marker, mixed $armed): void {
    putenv($marker === NULL ? 'CINATRA_DEV_SITE' : 'CINATRA_DEV_SITE=' . $marker);
    if ($armed === NULL) {
      $this->container->get('state')->delete(CinatraDevSite::ARMED_KEY);
    }
    else {
      $this->container->get('state')->set(CinatraDevSite::ARMED_KEY, $armed);
    }
  }

}
