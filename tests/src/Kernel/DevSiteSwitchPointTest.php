<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Config\DevSiteConfigOverride;
use Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Routing\DevSiteRouteSubscriber;
use Drupal\cinatra_dev_site_probe\ProbeController;
use Drupal\cinatra_dev_site_probe\ProbeState;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests the three switch points of the development site.
 *
 * The profile alter, the server id override and the route subscriber each
 * ask the exposure guard before they change anything (MUST-FIX 3). Each guard
 * row checks its control first: with the exposure active the switch point
 * acts, so a row that then sees no change is not vacuous.
 *
 * @group cinatra
 */
final class DevSiteSwitchPointTest extends KernelTestBase {

  /**
   * The stock controller of the remote route in this fixture.
   */
  private const STOCK_CONTROLLER = '\Drupal\cinatra_dev_site_probe\ProbeController::handle';

  /**
   * The remote settings as stored.
   */
  private const REMOTE_SETTINGS = [
    'enabled' => TRUE,
    'uid' => 1,
    'allow_uid1' => FALSE,
    'server_id' => '',
    'include_all_tools' => FALSE,
  ];

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
    ProbeController::$trail = [];
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
   * The profile alter adds the cinatra profile only while active.
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
  public function testAlterAsksTheGuard(?string $marker, mixed $armed, bool $active): void {
    $this->setMarkerAndFlag('1', TRUE);
    [$servers, $binding] = $this->readServers();
    $this->assertSame(CinatraDevSite::PROFILE, $servers[CinatraDevSite::PROFILE_ID] ?? NULL);
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));

    $this->setMarkerAndFlag($marker, $armed);
    [$servers, $binding] = $this->readServers();
    if ($active) {
      $this->assertSame(CinatraDevSite::PROFILE, $servers[CinatraDevSite::PROFILE_ID] ?? NULL);
      $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
      return;
    }
    // The servers are unchanged and no read was counted, so the binding
    // cannot bind.
    $this->assertSame($this->storedServers(), $servers);
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());
  }

  /**
   * The profile is added only for the current request and its own binding.
   */
  public function testAlterNeedsTheRequestsOwnBinding(): void {
    $this->setMarkerAndFlag('1', TRUE);
    [$servers] = $this->readServers();
    $this->assertArrayHasKey(CinatraDevSite::PROFILE_ID, $servers);

    // A request without a binding.
    $servers = $this->storedServers();
    $this->alterOn(Request::create('/_mcp_tools', 'POST'), $servers);
    $this->assertSame($this->storedServers(), $servers);

    // A value under the attribute that is not a binding.
    $request = Request::create('/_mcp_tools', 'POST');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, CinatraDevSite::PROFILE);
    $servers = $this->storedServers();
    $this->alterOn($request, $servers);
    $this->assertSame($this->storedServers(), $servers);

    // The binding of another request: it counts no read.
    $other = new ProfileBinding(Request::create('/_mcp_tools', 'POST'));
    $request = Request::create('/_mcp_tools', 'POST');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $other);
    $servers = $this->storedServers();
    $this->alterOn($request, $servers);
    $this->assertSame($this->storedServers(), $servers);
    $this->assertFalse($other->bind(CinatraDevSite::PROFILE));

    // A request with a binding that is not the current request.
    $request = Request::create('/_mcp_tools', 'POST');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $servers = $this->storedServers();
    $this->alterOn(NULL, $servers);
    $this->assertSame($this->storedServers(), $servers);
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // No current request at all.
    $stack = $this->container->get('request_stack');
    $held = [];
    while ($stack->getCurrentRequest() !== NULL) {
      $held[] = $stack->pop();
    }
    $servers = $this->storedServers();
    try {
      $this->container->get('module_handler')->alter('mcp_tools_server_configs', $servers);
    }
    finally {
      foreach (array_reverse($held) as $request) {
        $stack->push($request);
      }
    }
    $this->assertSame($this->storedServers(), $servers);
  }

  /**
   * Each alter run counts a read, so a second read revokes the binding.
   */
  public function testAlterCountsEachRead(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $request = Request::create('/_mcp_tools', 'POST');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $first = $this->storedServers();
    $this->alterOn($request, $first);
    $second = $this->storedServers();
    $this->alterOn($request, $second);
    $this->assertSame(CinatraDevSite::PROFILE, $first[CinatraDevSite::PROFILE_ID]);
    $this->assertSame(CinatraDevSite::PROFILE, $second[CinatraDevSite::PROFILE_ID]);
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());
  }

  /**
   * While active, the alter disables or removes an entry under the cinatra id.
   *
   * The rows run the hook through the module handler, not through the
   * mcp_tools repository. The repository turns every stored entry into an
   * array before the alter, so the rows with another value stand for a value
   * that an earlier implementation of the hook or another caller of the
   * alter passes in.
   */
  public function testAlterDisablesForeignProfile(): void {
    $foreign = [
      'name' => 'Foreign',
      'enabled' => TRUE,
      'include_all_tools' => TRUE,
      'permission_callback' => NULL,
    ];
    // Not active: the foreign profile is left as it is.
    $this->setMarkerAndFlag(NULL, TRUE);
    [$servers] = $this->readServers([CinatraDevSite::PROFILE_ID => $foreign] + $this->storedServers());
    $this->assertSame($foreign, $servers[CinatraDevSite::PROFILE_ID]);

    // Active: it is disabled in the altered view, nothing else changes, and
    // no read is counted.
    $this->setMarkerAndFlag('1', TRUE);
    [$servers, $binding] = $this->readServers([CinatraDevSite::PROFILE_ID => $foreign] + $this->storedServers());
    $this->assertSame(array_replace($foreign, ['enabled' => FALSE]), $servers[CinatraDevSite::PROFILE_ID]);
    unset($servers[CinatraDevSite::PROFILE_ID]);
    $this->assertSame($this->storedServers(), $servers);
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // An array entry without an enabled key is disabled too.
    [$servers, $binding] = $this->readServers([CinatraDevSite::PROFILE_ID => ['name' => 'Bare']] + $this->storedServers());
    $this->assertSame(['name' => 'Bare', 'enabled' => FALSE], $servers[CinatraDevSite::PROFILE_ID]);
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // A foreign value that is not an array is removed.
    foreach (['foreign', NULL, TRUE] as $value) {
      [$servers, $binding] = $this->readServers([CinatraDevSite::PROFILE_ID => $value] + $this->storedServers());
      $this->assertSame($this->storedServers(), $servers);
      $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    }
  }

  /**
   * The effective server id is cinatra only while active.
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
  public function testOverrideAsksTheGuard(?string $marker, mixed $armed, bool $active): void {
    $this->storeRemoteSettings();
    $this->setMarkerAndFlag('1', TRUE);
    $this->assertSame(CinatraDevSite::PROFILE_ID, $this->remoteSettings()['server_id']);

    $this->setMarkerAndFlag($marker, $armed);
    $this->assertSame($active ? CinatraDevSite::PROFILE_ID : '', $this->remoteSettings()['server_id']);
    // The stored value is never written.
    $this->assertSame(self::REMOTE_SETTINGS, $this->container->get('config.storage')->read('mcp_tools_remote.settings'));
  }

  /**
   * The override sets the server id and nothing else.
   */
  public function testOverrideSetsOnlyTheServerId(): void {
    $this->storeRemoteSettings();
    $this->setMarkerAndFlag('1', TRUE);
    $this->assertSame(array_replace(self::REMOTE_SETTINGS, ['server_id' => CinatraDevSite::PROFILE_ID]), $this->remoteSettings());
    $this->assertSame('', $this->container->get('config.factory')->get('mcp_tools_remote.settings')->getOriginal('server_id', FALSE));

    $override = $this->container->get('cinatra_dev_site.config_override');
    $this->assertInstanceOf(DevSiteConfigOverride::class, $override);
    $this->assertSame([], $override->loadOverrides(['system.site']));
    $this->assertSame([], $override->loadOverrides([]));
    // Only the exact name counts; the check is strict.
    $names = [0, NULL, TRUE, 'MCP_TOOLS_REMOTE.SETTINGS', ' mcp_tools_remote.settings'];
    $this->assertSame([], $override->loadOverrides($names));
    $this->assertSame(
      ['mcp_tools_remote.settings' => ['server_id' => CinatraDevSite::PROFILE_ID]],
      $override->loadOverrides(['system.site', 'mcp_tools_remote.settings']),
    );
    $this->assertSame('cinatra_dev_site', $override->getCacheSuffix());
    $this->assertNull($override->createConfigObject('mcp_tools_remote.settings'));
    $metadata = $override->getCacheableMetadata('mcp_tools_remote.settings');
    $this->assertSame([], $metadata->getCacheTags());
    $this->assertSame([], $metadata->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $metadata->getCacheMaxAge());
  }

  /**
   * The override asks the guard only for the remote settings.
   *
   * Any other configuration read reaches no State, so the override adds no
   * read of the armed flag to the rest of the site.
   */
  public function testOverrideReadsStateOnlyForTheRemoteSettings(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $this->container->set('state', new ProbeState($this->container->get('state')));
    $override = $this->container->get('cinatra_dev_site.config_override');
    $armed_read = 'state ' . CinatraDevSite::ARMED_KEY;

    $this->assertSame([], $override->loadOverrides(['system.site', 'user.settings']));
    $this->assertNotContains($armed_read, ProbeController::$trail);

    $this->assertNotSame([], $override->loadOverrides(['mcp_tools_remote.settings']));
    $this->assertSame([$armed_read], array_values(array_intersect(ProbeController::$trail, [$armed_read])));
  }

  /**
   * A router rebuild points the remote route at the endpoint only while active.
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
  public function testRouteAsksTheGuard(?string $marker, mixed $armed, bool $active): void {
    $this->setMarkerAndFlag('1', TRUE);
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $this->rebuiltRoute()->getDefault('_controller'));

    $this->setMarkerAndFlag($marker, $armed);
    $controller = $this->rebuiltRoute()->getDefault('_controller');
    $this->assertSame($active ? CinatraDevSite::ENDPOINT_CONTROLLER : self::STOCK_CONTROLLER, $controller);
  }

  /**
   * The switched route keeps its path, methods, requirements and options.
   */
  public function testRouteKeepsEverythingElse(): void {
    $this->setMarkerAndFlag(NULL, TRUE);
    $before = $this->rebuiltRoute();
    $this->setMarkerAndFlag('1', TRUE);
    $after = $this->rebuiltRoute();
    $this->assertSame(self::STOCK_CONTROLLER, $before->getDefault('_controller'));
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $after->getDefault('_controller'));
    $this->assertSame('/_mcp_tools', $after->getPath());
    $this->assertSame($before->getPath(), $after->getPath());
    $this->assertSame($before->getMethods(), $after->getMethods());
    $this->assertSame($before->getRequirements(), $after->getRequirements());
    $this->assertSame($before->getOptions(), $after->getOptions());
    $defaults = static fn(Route $route): array => array_diff_key($route->getDefaults(), ['_controller' => TRUE]);
    $this->assertSame($defaults($before), $defaults($after));
    $this->assertNotSame([], $defaults($after));
  }

  /**
   * The subscriber switches only the stock controller of the remote route.
   */
  public function testRouteSubscriberSwitchesOnlyTheStockController(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $subscriber = $this->container->get('cinatra_dev_site.route_subscriber');
    $this->assertInstanceOf(DevSiteRouteSubscriber::class, $subscriber);
    $this->assertSame([RoutingEvents::ALTER => ['onAlterRoutes', -1024]], DevSiteRouteSubscriber::getSubscribedEvents());
    // The endpoint controller is the handle() method of the endpoint class.
    $this->assertSame('\Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint::handle', CinatraDevSite::ENDPOINT_CONTROLLER);
    $this->assertTrue(method_exists(DevSiteRemoteEndpoint::class, 'handle'));

    // The stock controller, with or without the leading backslash.
    foreach ([self::STOCK_CONTROLLER, ltrim(self::STOCK_CONTROLLER, '\\')] as $stock) {
      $collection = $this->collection($stock);
      $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
      $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));
      $this->assertSame(self::STOCK_CONTROLLER, $collection->get('probe.other')->getDefault('_controller'));
    }

    // Another controller on the remote route is left alone, also the stock
    // controller with two leading backslashes.
    $foreign_controllers = [
      '\Drupal\other\Controller\Remote::handle',
      '\\' . self::STOCK_CONTROLLER,
      self::STOCK_CONTROLLER . 'X',
      ltrim(self::STOCK_CONTROLLER, '\\') . 'Other',
      NULL,
    ];
    foreach ($foreign_controllers as $foreign) {
      $collection = $this->collection($foreign);
      $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
      $this->assertSame($foreign, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));
    }

    // A subscriber built with an empty stock controller leaves the
    // fixture's route alone.
    $collection = $this->collection(self::STOCK_CONTROLLER);
    (new DevSiteRouteSubscriber(''))->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame(self::STOCK_CONTROLLER, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));

    // A collection without the remote route.
    $collection = new RouteCollection();
    $collection->add('probe.other', new Route('/other', ['_controller' => self::STOCK_CONTROLLER]));
    $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame(['probe.other'], array_keys($collection->all()));
    $this->assertSame(self::STOCK_CONTROLLER, $collection->get('probe.other')->getDefault('_controller'));
  }

  /**
   * The subscriber leaves a controller that is not a string alone.
   *
   * An object whose string form is the stock controller is not the stock
   * controller, and an array, a Closure, an integer or a boolean is not
   * compared at all.
   */
  public function testRouteSubscriberLeavesNonStringControllers(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $subscriber = $this->container->get('cinatra_dev_site.route_subscriber');
    $stringable = new class() {

      /**
       * The stock controller of the fixture, as a string.
       */
      public function __toString(): string {
        return '\Drupal\cinatra_dev_site_probe\ProbeController::handle';
      }

    };
    $this->assertSame(self::STOCK_CONTROLLER, (string) $stringable);

    // The control: the stock controller as a string is switched.
    $collection = $this->collection(self::STOCK_CONTROLLER);
    $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));

    $controllers = [
      'an object whose string form is the stock controller' => $stringable,
      'a callable pair' => [ProbeController::class, 'handle'],
      'a Closure' => static fn() => NULL,
      'an integer' => 1,
      'a boolean' => TRUE,
    ];
    foreach ($controllers as $label => $controller) {
      $collection = $this->collection(self::STOCK_CONTROLLER);
      $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->setDefault('_controller', $controller);
      $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
      $this->assertSame($controller, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'), $label);
    }
  }

  /**
   * With the marker, a failing State read stops each switch point.
   *
   * Each asks the exposure guard first, so the failure propagates before it
   * changes anything.
   */
  public function testStateFailureStopsEverySwitchPoint(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $this->container->set('state', new ProbeState($this->container->get('state')));
    $override = $this->container->get('cinatra_dev_site.config_override');
    $subscriber = $this->container->get('cinatra_dev_site.route_subscriber');
    $request = Request::create('/_mcp_tools', 'POST');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $servers = $this->storedServers();
    $collection = $this->collection(self::STOCK_CONTROLLER);
    $calls = [
      'alter' => function () use ($request, &$servers): void {
        $this->alterOn($request, $servers);
      },
      'override' => fn() => $override->loadOverrides(['mcp_tools_remote.settings']),
      'route' => fn() => $subscriber->onAlterRoutes(new RouteBuildEvent($collection)),
    ];
    ProbeState::$fail = TRUE;
    foreach ($calls as $label => $call) {
      $thrown = NULL;
      try {
        $call();
      }
      catch (\Throwable $e) {
        $thrown = $e;
      }
      $this->assertInstanceOf(\RuntimeException::class, $thrown, $label);
      $this->assertSame('probe state failure', $thrown->getMessage(), $label);
    }
    ProbeState::$fail = FALSE;
    $this->assertSame($this->storedServers(), $servers);
    $this->assertSame(self::STOCK_CONTROLLER, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));

    // The control: without the failure each switch point acts, and the
    // binding binds, so the failed alter counted no read.
    $calls['alter']();
    $this->assertSame(CinatraDevSite::PROFILE, $servers[CinatraDevSite::PROFILE_ID]);
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
    $this->assertSame(['mcp_tools_remote.settings' => ['server_id' => CinatraDevSite::PROFILE_ID]], $calls['override']());
    $calls['route']();
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $collection->get(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller'));
  }

  /**
   * The cases of the exposure gate table.
   *
   * @return array<string, array{string|null, mixed, bool}>
   *   Marker, flag and whether the exposure is active.
   */
  public static function guardCases(): array {
    return DevSiteExposureGateTest::guardCases();
  }

  /**
   * A short stand-in for the servers that the repository passes in.
   *
   * The mcp_tools repository fills every default key of a profile; this
   * profile carries four. The alter reads no key of another profile, so the
   * rows that compare the servers before and after it hold for either form.
   *
   * @return array
   *   One stored profile.
   */
  private function storedServers(): array {
    return [
      'default' => [
        'name' => 'Default',
        'enabled' => TRUE,
        'include_all_tools' => FALSE,
        'id' => 'default',
      ],
    ];
  }

  /**
   * Runs the profile alter for a new request that carries its own binding.
   *
   * @param array|null $servers
   *   The servers before the alter, or NULL for the stored servers.
   *
   * @return array{0: array, 1: \Drupal\cinatra_dev_site\Mcp\ProfileBinding}
   *   The servers after the alter, and the request's binding.
   */
  private function readServers(?array $servers = NULL): array {
    $request = Request::create('/_mcp_tools', 'POST');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $servers ??= $this->storedServers();
    $this->alterOn($request, $servers);
    return [$servers, $binding];
  }

  /**
   * Runs the profile alter with a request on top of the stack.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL to keep the stack as it is.
   * @param array $servers
   *   The servers to alter.
   */
  private function alterOn(?Request $request, array &$servers): void {
    $stack = $this->container->get('request_stack');
    if ($request !== NULL) {
      $stack->push($request);
    }
    try {
      $this->container->get('module_handler')->alter('mcp_tools_server_configs', $servers);
    }
    finally {
      if ($request !== NULL) {
        $stack->pop();
      }
    }
  }

  /**
   * Stores the remote settings, as the remote module would.
   */
  private function storeRemoteSettings(): void {
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', self::REMOTE_SETTINGS);
    $this->container->get('config.factory')->reset('mcp_tools_remote.settings');
  }

  /**
   * The effective remote settings, freshly loaded.
   *
   * @return array
   *   The settings with every override applied.
   */
  private function remoteSettings(): array {
    $factory = $this->container->get('config.factory');
    $factory->reset('mcp_tools_remote.settings');
    return $factory->get('mcp_tools_remote.settings')->get();
  }

  /**
   * Rebuilds the router and loads the remote route.
   *
   * @return \Symfony\Component\Routing\Route
   *   The remote route as the router stores it.
   */
  private function rebuiltRoute(): Route {
    $this->container->get('router.builder')->rebuild();
    return $this->container->get('router.route_provider')->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
  }

  /**
   * A collection with the remote route and one other route.
   *
   * @param string|null $controller
   *   The remote route's controller, or NULL for none.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   The collection.
   */
  private function collection(?string $controller): RouteCollection {
    $collection = new RouteCollection();
    $defaults = $controller === NULL ? [] : ['_controller' => $controller];
    $collection->add(CinatraDevSite::ENDPOINT_ROUTE, new Route('/_mcp_tools', $defaults));
    $collection->add('probe.other', new Route('/other', ['_controller' => self::STOCK_CONTROLLER]));
    return $collection;
  }

  /**
   * Sets the development marker and the armed State flag.
   *
   * @param string|null $marker
   *   The marker value, or NULL to unset it.
   * @param mixed $armed
   *   The State value, or NULL to delete it.
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
