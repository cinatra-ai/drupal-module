<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint;
use Drupal\cinatra_dev_site\Mcp\AllowListContainer;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ScopedToolManager;
use Drupal\cinatra_dev_site_probe\ProbeController;
use Drupal\cinatra_dev_site_probe\ProbeRegistry;
use Drupal\cinatra_dev_site_probe\ProbeState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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
    ProbeController::$trail = [];
    ProbeController::$switchAs = NULL;
    ProbeState::$fail = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    ProbeState::$fail = FALSE;
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
    $this->observeState();
    [$response, $request] = $this->send(['state'], $marker, $armed);
    // The inner controller is built once, after the guard. The guard reads
    // the armed flag only when the marker is "1".
    $armed_read = 'state ' . CinatraDevSite::ARMED_KEY;
    $this->assertSame($marker === '1' ? [$armed_read, 'create'] : ['create'], $this->gateTrail());
    if ($active) {
      $this->assertInstanceOf(AllowListContainer::class, ProbeController::$seen['container']);
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
   * Without the marker the guard does not read State, not even once.
   *
   * State fails every read here, so a guard that read State first would throw
   * instead of answering FALSE.
   */
  public function testGuardReadsTheMarkerBeforeState(): void {
    $this->observeState();
    foreach ([NULL, '', '0', 'true', '01', ' 1', '1 '] as $marker) {
      $this->setMarkerAndFlag($marker, TRUE);
      ProbeController::$trail = [];
      ProbeState::$fail = TRUE;
      $thrown = $this->caught(static fn() => CinatraDevSite::exposureActive());
      ProbeState::$fail = FALSE;
      $this->assertNull($thrown, var_export($marker, TRUE));
      $this->assertFalse(CinatraDevSite::exposureActive(), var_export($marker, TRUE));
      $this->assertSame([], ProbeController::$trail, var_export($marker, TRUE));
    }
  }

  /**
   * With the marker, a failing State read propagates and nothing is served.
   */
  public function testStateFailureWithTheMarkerServesNothing(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $this->observeState();
    ProbeState::$fail = TRUE;
    $thrown = $this->caught(static fn() => CinatraDevSite::exposureActive());
    $this->assertInstanceOf(\RuntimeException::class, $thrown);
    $this->assertSame('probe state failure', $thrown->getMessage());
    // Through the endpoint: the failure comes before the inner controller is
    // built, so it neither gets a container nor handles the request.
    ProbeController::$trail = [];
    $endpoint = $this->endpoint(ProbeController::class, [
      'mcp_tools.resource_registry' => ProbeRegistry::class,
      'mcp_tools.prompt_registry' => ProbeRegistry::class,
    ]);
    $thrown = $this->caught(static fn() => $endpoint->handle(Request::create('/_mcp_tools', 'POST')));
    $this->assertInstanceOf(\RuntimeException::class, $thrown);
    $this->assertSame('probe state failure', $thrown->getMessage());
    $this->assertSame(['state ' . CinatraDevSite::ARMED_KEY], ProbeController::$trail);
    $this->assertSame([], ProbeController::$seen);
    $this->assertFalse(ProbeController::$handled);
  }

  /**
   * With the marker, a failing State read stops the gate and the guard.
   *
   * Each asks the exposure guard first, so the failure propagates before the
   * site's switcher is called or a binding is bound. The failure revokes the
   * request's binding, so a check after the State recovers cannot bind it.
   */
  public function testStateFailureStopsTheGateAndTheProfileGuard(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $guard = $this->container->get('cinatra_dev_site.profile_guard');
    $user = $this->container->get('current_user');
    $before = $user->id();
    $this->observeState();

    [$request, $binding] = $this->currentWithBinding();
    ProbeState::$fail = TRUE;
    $this->assertStateFailure(fn() => $guard->check($request, CinatraDevSite::PROFILE));
    ProbeState::$fail = FALSE;
    $this->assertFalse($binding->isValidated());
    $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // The control: without the failure, a request's own binding binds and
    // the gate over it switches.
    [$request, $binding] = $this->currentWithBinding();
    $this->assertTrue($guard->check($request, CinatraDevSite::PROFILE));
    $gate = new ExecutionGate($this->container->get('account_switcher'), $binding);
    ProbeState::$fail = TRUE;
    $this->assertStateFailure(fn() => $gate->switchTo(new UserSession(['uid' => 5])));
    ProbeState::$fail = FALSE;
    $this->assertSame($before, $user->id());
    $gate->switchTo(new UserSession(['uid' => 5]));
    $this->assertSame(5, (int) $user->id());
    $gate->switchBack();
    $this->assertSame($before, $user->id());
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
    // The premise: the site's own container has this service.
    $this->assertTrue($this->container->has($id), $id);
    $this->assertIsObject($this->container->get($id), $id);
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
    $site = [
      'mcp_tools.resource_registry' => ['private resource'],
      'mcp_tools.prompt_registry' => ['private prompt'],
      'mcp_tools.client_bridge' => ['client bridge'],
    ];
    // The premise: the site's own registries and bridge hold their items.
    $this->assertSame($site, $this->siteItems(array_keys($site)));
    [$response] = $this->send(array_keys($site), '1', TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], ProbeController::$seen['mcp_tools.resource_registry']);
    $this->assertSame([], ProbeController::$seen['mcp_tools.prompt_registry']);
    $this->assertNull(ProbeController::$seen['mcp_tools.client_bridge']);
    // The site's own registries and bridge still hold their items.
    $this->assertSame($site, $this->siteItems(array_keys($site)));
  }

  /**
   * While active, the tool manager and the account switcher are scoped.
   *
   * The inner controller never gets the site's own tool manager or account
   * switcher. It gets the request's scoped manager and execution gate, both
   * tied to the binding that the endpoint put on this request. Nothing in
   * this unit counts a profile read, so the binding stays unvalidated: the
   * manager lists no tool and the gate refuses every switch.
   */
  public function testToolManagerAndSwitcherAreScoped(): void {
    $ids = ['plugin.manager.tool', 'account_switcher'];
    // The premise: the site's own container has both services.
    foreach ($ids as $id) {
      $this->assertIsObject($this->container->get($id), $id);
    }
    [$response, $request] = $this->send($ids, '1', TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue(ProbeController::$handled);
    $binding = $request->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE);
    $this->assertInstanceOf(ProfileBinding::class, $binding);
    $this->assertSame($request, $binding->request);
    $this->assertFalse($binding->isValidated());
    $tools = ProbeController::$seen['plugin.manager.tool'];
    $this->assertInstanceOf(ScopedToolManager::class, $tools);
    $this->assertNotSame($this->container->get('plugin.manager.tool'), $tools);
    $this->assertSame([], $tools->getDefinitions());
    $gate = ProbeController::$seen['account_switcher'];
    $this->assertInstanceOf(ExecutionGate::class, $gate);
    $user = $this->container->get('current_user');
    $before = $user->id();
    try {
      $gate->switchTo(new UserSession(['uid' => 5]));
      $this->fail('The gate switched for an unvalidated binding.');
    }
    catch (ExposureDeniedException $e) {
      $this->assertSame($before, $user->id());
    }
    // The profile guard validates this request's binding; then the gate
    // switches the site's own account switcher, and reverts it.
    $binding->noteRead();
    $this->assertTrue($this->container->get('cinatra_dev_site.profile_guard')->check($request, CinatraDevSite::PROFILE));
    $gate->switchTo(new UserSession(['uid' => 5]));
    $this->assertSame(5, (int) $user->id());
    $gate->switchBack();
    $this->assertSame($before, $user->id());
  }

  /**
   * A switch inside the inner controller's handle() is refused.
   *
   * The upstream controller switches accounts in handle(), after create().
   * Nothing in this unit validates the binding, so the gate refuses the
   * switch, the endpoint answers the refusal, and the user stays the same.
   */
  public function testSwitchInHandleIsRefused(): void {
    $user = $this->container->get('current_user');
    $before = $user->id();
    ProbeController::$switchAs = new UserSession(['uid' => 5]);
    [$response] = $this->send(['account_switcher'], '1', TRUE);
    $this->assertInstanceOf(ExecutionGate::class, ProbeController::$seen['account_switcher']);
    $this->assertTrue(ProbeController::$handled);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
    $this->assertSame($before, $user->id());
  }

  /**
   * While active, the upstream create() gets every one of its ids.
   *
   * The ids in the order of McpToolsRemoteController::create() of
   * drupal/mcp_tools 1.0.0-beta18. The kernel fixture has no MCP Tools
   * module, so a stand-in goes under each of its five service ids first.
   */
  public function testUpstreamCreateGetsEveryId(): void {
    $upstream = [
      'config.factory',
      'mcp_tools_remote.api_key_manager',
      'mcp_tools.access_manager',
      'plugin.manager.tool',
      'mcp_tools.resource_registry',
      'mcp_tools.prompt_registry',
      'mcp_tools.server_config_repository',
      'mcp_tools.tool_error_handler',
      'entity_type.manager',
      'account_switcher',
      'event_dispatcher',
      'logger.channel.mcp_tools_remote',
      'mcp_tools.client_bridge',
    ];
    $served = [
      'plugin.manager.tool',
      'account_switcher',
      'mcp_tools.resource_registry',
      'mcp_tools.prompt_registry',
      'mcp_tools.client_bridge',
    ];
    // Every upstream id is either passed through or served, and no other.
    $known = array_merge(AllowListContainer::PASS_THROUGH, $served);
    sort($known);
    $sorted = $upstream;
    sort($sorted);
    $this->assertSame($sorted, $known);
    $absent = [
      'mcp_tools_remote.api_key_manager',
      'mcp_tools.access_manager',
      'mcp_tools.server_config_repository',
      'mcp_tools.tool_error_handler',
      'logger.channel.mcp_tools_remote',
    ];
    foreach ($absent as $id) {
      $this->assertFalse($this->container->has($id), $id);
      $this->container->set($id, new \stdClass());
    }
    [$response] = $this->send($upstream, '1', TRUE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('probe served', $response->getContent());
    $this->assertTrue(ProbeController::$handled);
    $this->assertSame(array_merge(['container'], $upstream), array_keys(ProbeController::$seen));
    foreach (AllowListContainer::PASS_THROUGH as $id) {
      $this->assertSame($this->container->get($id), ProbeController::$seen[$id], $id);
    }
    $this->assertSame([], ProbeController::$seen['mcp_tools.resource_registry']);
    $this->assertSame([], ProbeController::$seen['mcp_tools.prompt_registry']);
    $this->assertNull(ProbeController::$seen['mcp_tools.client_bridge']);
    $this->assertInstanceOf(ScopedToolManager::class, ProbeController::$seen['plugin.manager.tool']);
    $this->assertInstanceOf(ExecutionGate::class, ProbeController::$seen['account_switcher']);
  }

  /**
   * A tool manager or account switcher of the wrong kind refuses the request.
   *
   * The inner controller is not built and the request gets no binding.
   */
  public function testWrongToolServicesRefuse(): void {
    foreach (['plugin.manager.tool', 'account_switcher'] as $id) {
      $site = $this->container->get($id);
      $this->container->set($id, new \stdClass());
      ProbeController::$trail = [];
      [$response, $request] = $this->send(['config.factory'], '1', TRUE);
      $this->assertSame(404, $response->getStatusCode(), $id);
      $this->assertSame('Not found.', $response->getContent(), $id);
      $this->assertNotContains('create', ProbeController::$trail, $id);
      $this->assertFalse($request->attributes->has(CinatraDevSite::BINDING_ATTRIBUTE), $id);
      $this->container->set($id, $site);
    }
  }

  /**
   * An absent tool manager or account switcher refuses the request.
   *
   * The endpoint's container answers NULL for the absent id, the way a
   * container without that service answers NULL_ON_INVALID_REFERENCE. The
   * inner controller is not built and the request gets no binding.
   */
  public function testAbsentToolServicesRefuse(): void {
    $registries = [
      'mcp_tools.resource_registry' => ProbeRegistry::class,
      'mcp_tools.prompt_registry' => ProbeRegistry::class,
    ];
    $ids = ['plugin.manager.tool', 'account_switcher'];
    $site = [];
    foreach ($ids as $id) {
      $site[$id] = $this->container->get($id);
    }
    ProbeController::$pull = ['config.factory'];
    foreach ($ids as $absent) {
      $view = new AllowListContainer($this->container, [$absent => NULL] + $site);
      $this->assertNull($view->get($absent, ContainerInterface::NULL_ON_INVALID_REFERENCE), $absent);
      $this->setMarkerAndFlag('1', TRUE);
      ProbeController::$trail = [];
      ProbeController::$seen = [];
      $request = Request::create('/_mcp_tools', 'POST');
      $response = (new DevSiteRemoteEndpoint($view, new NullLogger(), ProbeController::class, $registries))->handle($request);
      $this->assertSame(404, $response->getStatusCode(), $absent);
      $this->assertSame('Not found.', $response->getContent(), $absent);
      $this->assertNotContains('create', ProbeController::$trail, $absent);
      $this->assertSame([], ProbeController::$seen, $absent);
      $this->assertFalse(ProbeController::$handled, $absent);
      $this->assertFalse($request->attributes->has(CinatraDevSite::BINDING_ATTRIBUTE), $absent);
    }
    // The control: the same endpoint over both services builds the inner
    // controller and puts a binding on the request.
    $view = new AllowListContainer($this->container, $site);
    $request = Request::create('/_mcp_tools', 'POST');
    $response = (new DevSiteRemoteEndpoint($view, new NullLogger(), ProbeController::class, $registries))->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue(ProbeController::$handled);
    $this->assertInstanceOf(ProfileBinding::class, $request->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE));
  }

  /**
   * Lists the items of the site's own registry stand-ins.
   *
   * @param string[] $ids
   *   The service ids.
   *
   * @return array
   *   The items of each id.
   */
  private function siteItems(array $ids): array {
    $items = [];
    foreach ($ids as $id) {
      $service = $this->container->get($id);
      $this->assertInstanceOf(ProbeRegistry::class, $service, $id);
      $items[$id] = $service->items();
    }
    return $items;
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
   * The allow-list forwards the caller's behaviour and answers initialized().
   */
  public function testAllowListForwardsAndInitialized(): void {
    $served = new ProbeRegistry([]);
    $view = new AllowListContainer($this->container, ['probe.served' => $served, 'mcp_tools.client_bridge' => NULL]);
    // An allowed id the site does not have: NULL for NULL_ON_INVALID, so the
    // behaviour reached the site's container, which throws by default.
    $this->assertFalse($this->container->has('mcp_tools.access_manager'));
    $this->assertNull($view->get('mcp_tools.access_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE));
    $this->container->get('config.factory');
    $this->assertTrue($view->initialized('probe.served'));
    $this->assertFalse($view->initialized('mcp_tools.client_bridge'));
    $this->assertTrue($view->initialized('config.factory'));
    $this->assertFalse($view->initialized('mcp_tools.access_manager'));
  }

  /**
   * The disarm step removes the flag, so the exposure is no longer active.
   */
  public function testDisarmRemovesTheFlag(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $this->assertTrue(CinatraDevSite::exposureActive());
    CinatraDevSite::disarm();
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $this->assertFalse(CinatraDevSite::exposureActive());
  }

  /**
   * A missing inner controller refuses also while not active.
   */
  public function testMissingInnerRefusesWhileNotActive(): void {
    $this->setMarkerAndFlag(NULL, TRUE);
    $response = $this->endpoint('Drupal\cinatra_dev_site_probe\Missing', [])->handle(Request::create('/_mcp_tools', 'POST'));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Not found.', $response->getContent());
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
      'missing class' => [
        ProbeController::class,
        ['mcp_tools.prompt_registry' => 'Drupal\cinatra_dev_site_probe\Missing'] + $good,
      ],
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
   * Pushes a new current request that carries its own binding, read once.
   *
   * @return array{\Symfony\Component\HttpFoundation\Request, \Drupal\cinatra_dev_site\Mcp\ProfileBinding}
   *   The request and its binding.
   */
  private function currentWithBinding(): array {
    $request = Request::create('/_mcp_tools', 'POST');
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request->setSession($session);
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $binding->noteRead();
    $this->container->get('request_stack')->push($request);
    return [$request, $binding];
  }

  /**
   * Asserts that a call throws the probe State failure.
   *
   * @param callable $call
   *   The call.
   */
  private function assertStateFailure(callable $call): void {
    $thrown = $this->caught($call);
    $this->assertInstanceOf(\RuntimeException::class, $thrown);
    $this->assertSame('probe state failure', $thrown->getMessage());
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
    // The request on top of the stack needs a session: on Drupal 11 the
    // parent tearDown() reads the session of the current request. Give it an
    // ephemeral session the way DrupalKernel::initializeEphemeralSession()
    // does.
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request->setSession($session);
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
   * Puts the recording State stand-in in place of the site's State.
   */
  private function observeState(): void {
    $this->container->set('state', new ProbeState($this->container->get('state')));
  }

  /**
   * The create() calls and the armed flag reads of the trail, in order.
   *
   * @return string[]
   *   'create' or 'state cinatra_dev_site.armed' for each event.
   */
  private function gateTrail(): array {
    $events = ['create', 'state ' . CinatraDevSite::ARMED_KEY];
    return array_values(array_filter(ProbeController::$trail, static fn(string $event): bool => in_array($event, $events, TRUE)));
  }

  /**
   * Runs a callable and returns what it threw.
   *
   * @param callable $call
   *   The call.
   *
   * @return \Throwable|null
   *   The throwable, or NULL when the call returned.
   */
  private function caught(callable $call): ?\Throwable {
    try {
      $call();
    }
    catch (\Throwable $e) {
      return $e;
    }
    return NULL;
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
