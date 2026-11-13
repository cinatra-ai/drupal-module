<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\DevSiteEnsure;
use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\cinatra_dev_site_ensure_test\EnsureFault;
use Drupal\cinatra_dev_site_ensure_test\FaultLock;
use Drupal\cinatra_dev_site_ensure_test\FaultState;
use Drupal\cinatra_dev_site_ensure_test\PrivateServerRepository;
use Drupal\cinatra_dev_site_page_test\PageMutation;
use Drupal\cinatra_dev_site_probe\ProbeController;
use Drupal\cinatra_dev_site_verify_test\VerifyToolManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\workflows\Entity\Workflow;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests ensure() and release(): one lock, and the armed flag set last.
 *
 * MUST-FIX 4: release() takes the lock that ensure() holds, so while that
 * lock holds, release() does not unbind the pages of a running ensure(); a
 * call nested in the same service is busy, also inside the lock backend; a
 * run whose lock lapsed does not arm; a release whose lock lapsed and which
 * unbinds during a run leaves the flag unset. MUST-FIX 5: the flag is
 * removed first and set last; a failure or a throw at the steps between
 * leaves the flag unset and the lock free, and the route at the stock
 * controller unless a router rebuild threw.
 *
 * @group cinatra
 */
final class DevSiteEnsureTest extends KernelTestBase {

  /**
   * The read tool id.
   */
  private const READ = 'cinatra_read_protected_revision';

  /**
   * The write tool id.
   */
  private const WRITE = 'cinatra_write_protected_draft';

  /**
   * The State key of the verify test alter's mode.
   */
  private const VERIFY_MODE = 'cinatra_dev_site_verify_test.mode';

  /**
   * The tag that starts the connector's one result line.
   */
  private const RESULT_TAG = 'CINATRA_DEV_SITE_ENSURE_RESULT=';

  /**
   * The name of the profile record in drupal/mcp_tools beta1 to beta14.
   *
   * Release 1.0.0-beta15 renamed it to mcp_tools.servers (update 10001).
   */
  private const LEGACY_SERVERS = 'mcp_tools_servers.settings';

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
    'language',
    'workflows',
    'content_moderation',
    'cinatra',
    'cinatra_dev_site',
    'cinatra_dev_site_probe',
    'cinatra_dev_site_verify_test',
    'cinatra_dev_site_page_test',
    'cinatra_dev_site_ensure_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // KernelTestBase registers a lock backend that grants every lock; the
    // window of MUST-FIX 4 needs the site's database locks. Their test class
    // throws at the release of the ensure lock on request.
    $container->register('lock', FaultLock::class)->addArgument(new Reference('database'));
    // Core's State, whose delete of the armed flag throws on request.
    $container->getDefinition('state')->setClass(FaultState::class);
    // release() waits one second for the lock instead of the connector's 300.
    $container->setParameter('cinatra_dev_site.lock_wait', 1);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists(ToolDefinition::class)) {
      require_once dirname(__DIR__, 2) . '/fixtures/ToolDefinitionDouble.php';
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language', 'content_moderation', 'cinatra']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    VerifyToolManager::$definitions = [
      self::READ => new ToolDefinition(['id' => self::READ, 'provider' => 'cinatra']),
      self::WRITE => new ToolDefinition(['id' => self::WRITE, 'provider' => 'cinatra']),
    ];
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', ['enabled' => TRUE, 'server_id' => '']);
    $this->container->get('config.storage')->write('mcp_tools.servers', [
      'servers' => ['default' => ['name' => 'Default', 'include_all_tools' => FALSE, 'transports' => ['http']]],
    ]);
    $this->container->get('config.factory')->reset();
    putenv('CINATRA_DEV_SITE=1');
    EnsureFault::reset();
    PageMutation::reset();
    $this->container->get('state')->delete(CinatraDevSite::ARMED_KEY);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    VerifyToolManager::$definitions = [];
    EnsureFault::reset();
    PageMutation::reset();
    parent::tearDown();
  }

  /**
   * A fresh site is bound, migrated, verified and armed; ok reaches the line.
   */
  public function testEnsureArmsVerifiedSite(): void {
    $published = $this->page(TRUE);
    $draft = $this->page(FALSE);
    Node::create(['type' => 'article', 'title' => 'Not a page'])->save();

    // The connector's step 4: one tagged line with the JSON of the result.
    $line = self::RESULT_TAG . json_encode($this->ensure()->ensure());
    $result = json_decode(substr($line, strlen(self::RESULT_TAG)), TRUE);

    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']));
    $this->assertSame(['workflow:created', 'workflow:bound', 'migrated:' . $published, 'migrated:' . $draft, 'verified'], $result['steps']);
    $this->assertSame([], $result['refused']);
    $this->assertNotNull($result['census']['replay']['profile'] ?? NULL);
    $this->assertArmed(TRUE);
    $this->assertSame(['published'], $this->rows($published));
    $this->assertSame(['draft'], $this->rows($draft));
    $this->assertSame(['6 ensure() verified the exposure; the armed flag is set after this line unless a later step throws; steps: ' . implode(', ', $result['steps']) . '.'], EnsureFault::$lines);
  }

  /**
   * A stored include_all_tools of FALSE passes the preflight and arms.
   *
   * FALSE is the only stored value that passes when the key exists; an
   * absent key is the setUp of every other passing row.
   */
  public function testStoredFalseIncludeAllToolsArms(): void {
    $this->page(TRUE);
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', [
      'enabled' => TRUE,
      'server_id' => '',
      'include_all_tools' => FALSE,
    ]);
    $this->container->get('config.factory')->reset();

    $result = $this->ensure()->ensure();

    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']));
    $this->assertSame([], $result['refused']);
    $this->assertArmed(TRUE);
  }

  /**
   * The record that drupal/mcp_tools beta1 to beta14 installs arms.
   *
   * Those releases store the profiles in mcp_tools_servers.settings, and
   * their config/install ships the four stock profiles; such a site has no
   * mcp_tools.servers record.
   */
  public function testLegacyMcpToolsProfilesArm(): void {
    $this->page(TRUE);
    $profile = [
      'name' => 'Drupal MCP Tools',
      'version' => '1.0.0',
      'pagination_limit' => 50,
      'include_all_tools' => FALSE,
      'gateway_mode' => FALSE,
      'enable_resources' => TRUE,
      'enable_prompts' => TRUE,
      'component_public_only' => FALSE,
      'transports' => ['http', 'stdio'],
    ];
    $this->legacyOnly([
      'default_server' => 'default',
      'servers' => [
        'default' => $profile,
        'development' => ['scopes' => ['read', 'write', 'admin']] + $profile,
        'staging' => ['gateway_mode' => TRUE, 'scopes' => ['read', 'write']] + $profile,
        'production' => ['gateway_mode' => TRUE, 'scopes' => ['read']] + $profile,
      ],
    ]);
    $this->container->get('config.factory')->reset();

    $result = $this->ensure()->ensure();

    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']));
    $this->assertSame([], $result['refused']);
    $this->assertSame(['default', 'development', 'staging', 'production'], array_keys($result['census']['profiles']));
    $this->assertArmed(TRUE);
  }

  /**
   * An empty profile list is a readable list: it passes and arms.
   *
   * The module drupal/mcp_tools serves its default profile for an empty
   * list; only a missing or non-array list refuses.
   *
   * @param string $name
   *   The record name.
   *
   * @dataProvider recordNames
   */
  public function testEmptyServerListArms(string $name): void {
    $this->page(TRUE);
    $storage = $this->container->get('config.storage');
    $storage->delete('mcp_tools.servers');
    $storage->write($name, ['servers' => []]);
    $this->container->get('config.factory')->reset();

    $result = $this->ensure()->ensure();

    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']));
    $this->assertSame([], $result['refused']);
    $this->assertArmed(TRUE);
  }

  /**
   * The two record names of the mcp_tools server profiles.
   *
   * @return array<string, array{string}>
   *   The name.
   */
  public static function recordNames(): array {
    return [
      'beta15 and later' => ['mcp_tools.servers'],
      'beta1 to beta14' => [self::LEGACY_SERVERS],
    ];
  }

  /**
   * A second run verifies again and changes no stored value.
   *
   * The snapshot compares State, configuration and the revision tables. The
   * run still removes and sets the flag again, takes and releases the lock,
   * rebuilds the router with the same values and logs its line.
   */
  public function testSecondRunChangesNoStoredValue(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    $snapshot = $this->snapshot();

    $result = $this->ensure()->ensure();

    $this->assertTrue($result['ok']);
    $this->assertSame(['verified'], $result['steps']);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertArmed(TRUE);
  }

  /**
   * Each refusal of the preflight is named; no stored value changes.
   *
   * The run writes its one log line and removes the absent flag.
   *
   * @param string $case
   *   The site's defect.
   * @param string[] $expected
   *   The codes.
   *
   * @dataProvider refusals
   */
  public function testRefusalWritesNothing(string $case, array $expected): void {
    $this->page(TRUE);
    $storage = $this->container->get('config.storage');
    match ($case) {
      'no marker' => putenv('CINATRA_DEV_SITE'),
      'include all tools' => $storage->write('mcp_tools_remote.settings', [
        'enabled' => TRUE,
        'server_id' => '',
        'include_all_tools' => TRUE,
      ]),
      'include all tools 1' => $storage->write('mcp_tools_remote.settings', [
        'enabled' => TRUE,
        'server_id' => '',
        'include_all_tools' => 1,
      ]),
      'include all tools null' => $storage->write('mcp_tools_remote.settings', [
        'enabled' => TRUE,
        'server_id' => '',
        'include_all_tools' => NULL,
      ]),
      'stored profile' => $storage->write('mcp_tools.servers', ['servers' => [CinatraDevSite::PROFILE_ID => ['name' => 'Foreign']]]),
      'servers not a list' => $storage->write('mcp_tools.servers', ['servers' => CinatraDevSite::PROFILE_ID]),
      'servers null' => $storage->write('mcp_tools.servers', ['servers' => NULL]),
      'no servers config' => $storage->delete('mcp_tools.servers'),
      'legacy stored profile' => $this->legacyOnly(['servers' => [CinatraDevSite::PROFILE_ID => ['name' => 'Foreign']]]),
      'legacy servers not a list' => $this->legacyOnly(['servers' => CinatraDevSite::PROFILE_ID]),
      'both servers configs' => $storage->write(self::LEGACY_SERVERS, ['servers' => ['default' => ['name' => 'Default']]]),
      'legacy servers missing' => $this->legacyOnly(['default_server' => 'default']),
      'legacy empty record' => $this->legacyOnly([]),
      'legacy servers null' => $this->legacyOnly(['servers' => NULL]),
      'servers missing' => $storage->write('mcp_tools.servers', ['default_server' => 'default']),
      'tool missing' => VerifyToolManager::$definitions = [self::READ => VerifyToolManager::$definitions[self::READ]],
      'tool provider' => VerifyToolManager::$definitions[self::READ] = new ToolDefinition([
        'id' => self::READ,
        'provider' => 'other',
      ]),
      'other workflow' => Workflow::create(['id' => 'other', 'label' => 'Other', 'type' => 'content_moderation'])->save(),
      'foreign route' => EnsureFault::$step = 'foreign route',
      'no route' => EnsureFault::$step = 'no route',
      'stringable route' => EnsureFault::$step = 'stringable route',
      'object route' => EnsureFault::$step = 'object route',
    };
    $this->container->get('router.builder')->rebuild();
    $this->container->get('config.factory')->reset();
    $snapshot = $this->snapshot();

    // A call that throws is compared as its class and message.
    $result = $this->attempt(fn(): array => $this->ensure()->ensure());

    $keys = ['ok' => 0, 'steps' => 0, 'refused' => 0, 'thrown' => 0];
    $this->assertSame(['ok' => FALSE, 'steps' => [], 'refused' => $expected], array_intersect_key($result, $keys));
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $this->assertFalse(CinatraDevSite::exposureActive());
    $this->assertNull(Workflow::load(EditorialBinder::ID));
    $this->assertSame(['4 ensure() did not arm; steps: none; refused: ' . implode(', ', $expected) . '.'], EnsureFault::$lines);
  }

  /**
   * The refusal cases.
   *
   * @return array<string, array{string, string[]}>
   *   The case and its codes.
   */
  public static function refusals(): array {
    return [
      'no marker' => ['no marker', ['no_marker']],
      'include all tools' => ['include all tools', ['include_all_tools_true']],
      'include all tools 1' => ['include all tools 1', ['include_all_tools_true']],
      'include all tools null' => ['include all tools null', ['include_all_tools_true']],
      'stored profile' => ['stored profile', ['profile_collision']],
      'servers not a list' => ['servers not a list', ['mcp_servers_unreadable']],
      'servers null' => ['servers null', ['mcp_servers_unreadable']],
      'no servers config' => ['no servers config', ['mcp_config_absent']],
      'legacy stored profile' => ['legacy stored profile', ['profile_collision']],
      'legacy servers not a list' => ['legacy servers not a list', ['mcp_servers_unreadable']],
      'both servers configs' => ['both servers configs', ['mcp_config_ambiguous']],
      'legacy servers missing' => ['legacy servers missing', ['mcp_servers_unreadable']],
      // Core's config storage reads an empty stored record as absent.
      'legacy empty record' => ['legacy empty record', ['mcp_config_absent']],
      'legacy servers null' => ['legacy servers null', ['mcp_servers_unreadable']],
      'servers missing' => ['servers missing', ['mcp_servers_unreadable']],
      'tool missing' => ['tool missing', ['tool_definition_missing:' . self::WRITE]],
      'tool provider' => ['tool provider', ['tool_provider_unexpected:' . self::READ]],
      'other workflow' => ['other workflow', ['other_workflow']],
      'foreign route' => ['foreign route', ['endpoint_route_unexpected']],
      'no route' => ['no route', ['endpoint_route_unexpected']],
      'stringable route' => ['stringable route', ['endpoint_route_unexpected']],
      'object route' => ['object route', ['endpoint_route_unexpected']],
    ];
  }

  /**
   * Without the tool manager or a callable server repository the run refuses.
   *
   * @param string $absent
   *   The service the run is built without.
   * @param string $code
   *   The code.
   *
   * @dataProvider absentServices
   */
  public function testAbsentServiceRefuses(string $absent, string $code): void {
    $this->page(TRUE);
    $c = $this->container;
    $ensure = new DevSiteEnsure(
      $c->get('lock'),
      $c->get('config.factory'),
      $c->get('router.builder'),
      $c->get('router.route_provider'),
      $c->get('cinatra_dev_site.editorial_binder'),
      $c->get('cinatra_dev_site.page_migrator'),
      $c->get('cinatra_dev_site.exposure_verifier'),
      $c->get('cinatra_dev_site.census'),
      $c->get('logger.factory'),
      $absent === 'tool manager' ? NULL : $c->get('plugin.manager.tool'),
      match ($absent) {
        'repository' => NULL,
        'private repository' => new PrivateServerRepository(),
        default => $c->get('mcp_tools.server_config_repository'),
      },
      $c->getParameter('cinatra_dev_site.remote_controller'),
      1,
    );
    $snapshot = $this->snapshot();

    $result = $ensure->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame([], $result['steps']);
    $this->assertSame([$code], $result['refused']);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertNull(Workflow::load(EditorialBinder::ID));
    $this->assertArmed(FALSE);
  }

  /**
   * The services a run can be built without.
   *
   * @return array<string, array{string, string}>
   *   The absent service and its code.
   */
  public static function absentServices(): array {
    return [
      'tool manager' => ['tool manager', 'tool_manager_absent'],
      'repository' => ['repository', 'server_config_repository_absent'],
      'private repository' => ['private repository', 'server_config_repository_absent'],
    ];
  }

  /**
   * A page the plan refuses refuses the whole run; no stored value changes.
   */
  public function testPageRefusalWritesNothing(): void {
    $this->page(TRUE);
    $pending = Node::load($this->page(TRUE));
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setTitle('Pending draft');
    $pending->save();
    $snapshot = $this->snapshot();

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame([], $result['steps']);
    // The page's draft, and the same draft as its translation's latest.
    $this->assertSame(['pending_revision:' . $pending->id(), 'pending_revision:' . $pending->id() . ':en'], $result['refused']);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertNull(Workflow::load(EditorialBinder::ID));
    $this->assertArmed(FALSE);
  }

  /**
   * A refusal disarms a site armed earlier and restores the stock route.
   */
  public function testRefusalDisarmsAnArmedSite(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    $this->assertArmed(TRUE);
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', [
      'enabled' => TRUE,
      'server_id' => '',
      'include_all_tools' => TRUE,
    ]);
    $this->container->get('config.factory')->reset();

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame(['include_all_tools_true'], $result['refused']);
    $this->assertArmed(FALSE);
  }

  /**
   * While another process holds the lock, ensure() and release() write nothing.
   *
   * The window of MUST-FIX 4: release() and the uninstall hook must not
   * unbind the pages of a site whose ensure() runs. After the lock is free,
   * release() disarms and unbinds the binding it owns.
   */
  public function testHeldLockRefusesEnsureAndRelease(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(DevSiteEnsure::LOCK, 60.0));
    $snapshot = $this->snapshot();

    // A call that throws is compared as its class and message.
    $this->assertSame(['ok' => FALSE, 'steps' => [], 'refused' => ['busy'], 'census' => []], $this->attempt(fn(): array => $this->ensure()->ensure()));
    $this->assertSame(['ok' => FALSE, 'steps' => [], 'refused' => ['busy']], $this->attempt(fn(): array => $this->ensure()->release(FALSE)));
    $thrown = NULL;
    $this->container->get('module_handler')->loadInclude('cinatra_dev_site', 'install');
    try {
      cinatra_dev_site_uninstall(FALSE);
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    $this->assertSame(DevSiteEnsure::BUSY_TEXT, $thrown);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertArmed(TRUE);

    $other->release(DevSiteEnsure::LOCK);
    $this->assertSame(['ok' => TRUE, 'steps' => ['disarmed', 'binding:unbound'], 'refused' => []], $this->ensure()->release(FALSE));
    $this->assertFalse(CinatraDevSite::exposureActive());
    $this->assertSame(FALSE, $this->bound());
  }

  /**
   * A throw at each step leaves the flag unset and the lock free.
   *
   * The route is the stock one again, unless a router rebuild threw.
   *
   * @param string $step
   *   The step that throws.
   * @param bool $armed
   *   Whether a run before armed the site.
   *
   * @dataProvider throwingSteps
   */
  public function testThrowBeforeTheArmLeavesTheFlagUnset(string $step, bool $armed): void {
    $this->page(TRUE);
    if ($armed) {
      $this->assertTrue($this->ensure()->ensure()['ok']);
      EnsureFault::reset();
    }
    if ($step === 'verify') {
      $this->container->get('state')->set(self::VERIFY_MODE, 'throw');
    }
    else {
      EnsureFault::$step = $step;
    }
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    $this->assertSame($step === 'verify' ? 'verify test failure' : 'ensure test failure: ' . $step, $thrown);
    if ($step !== 'verify') {
      // What a kill at that step would have left stored: no flag, also on a
      // site armed earlier, because the run removed it first.
      $this->assertNull(EnsureFault::$flagAtFault);
    }
    // A rebuild that threw dumped nothing: an armed site keeps the endpoint
    // route, which the cleared flag sends to the not-active path.
    $this->assertArmed(FALSE, $step === 'router' && $armed);
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(DevSiteEnsure::LOCK, 60.0), 'The lock was not released.');
    $other->release(DevSiteEnsure::LOCK);
  }

  /**
   * The throwing steps, each on a fresh and on an armed site.
   *
   * @return array<string, array{string, bool}>
   *   The step and whether the site was armed before.
   */
  public static function throwingSteps(): array {
    $cases = [];
    foreach (['plan', 'router', 'verify', 'census', 'log'] as $step) {
      $cases[$step . ', fresh'] = [$step, FALSE];
      $cases[$step . ', armed'] = [$step, TRUE];
    }
    return $cases;
  }

  /**
   * A release during an import disarms and leaves the binding to the import.
   */
  public function testReleaseWhileSyncingKeepsTheBinding(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);

    $this->assertSame(['ok' => TRUE, 'steps' => ['disarmed', 'binding:syncing'], 'refused' => []], $this->ensure()->release(TRUE));

    $this->assertFalse(CinatraDevSite::exposureActive());
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $this->assertSame(TRUE, $this->bound());
    // U4's contract: a syncing release keeps the ownership record.
    $this->assertNotNull($this->container->get('state')->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * MUST-FIX 1 of review 1: a run whose lock lapsed and was taken elsewhere.
   *
   * The lock expires at the log line and another backend takes it; the run
   * then neither arms nor releases the other backend's lock.
   */
  public function testLapsedLockDoesNotArm(): void {
    $this->page(TRUE);
    EnsureFault::$step = 'lapse';
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    $this->assertTrue(EnsureFault::$otherHeld);
    $this->assertSame(DevSiteEnsure::LAPSED_TEXT, $thrown);
    $this->assertArmed(FALSE);
    $third = new DatabaseLockBackend($this->container->get('database'));
    $this->assertFalse($third->acquire(DevSiteEnsure::LOCK, 60.0), 'The run released the lock of another backend.');
    EnsureFault::$other->release(DevSiteEnsure::LOCK);
  }

  /**
   * MUST-FIX 2 of review 1: a call nested in a run of the same service.
   *
   * Core's database lock grants a lock again to the backend that holds it;
   * the service itself answers busy then, and the outer run arms.
   */
  public function testNestedCallIsBusy(): void {
    $this->page(TRUE);
    EnsureFault::$step = 'nested';

    // A call that throws is compared as its class and message.
    $result = $this->attempt(fn(): array => $this->ensure()->ensure());

    // The outer result first: on a module without the service its failure
    // text names the missing service.
    $this->assertSame([TRUE, []], [$result['ok'] ?? $result, $result['refused'] ?? NULL]);
    $this->assertSame([
      'ensure' => ['ok' => FALSE, 'steps' => [], 'refused' => ['busy'], 'census' => []],
      'release' => ['ok' => FALSE, 'steps' => [], 'refused' => ['busy']],
    ], EnsureFault::$nested);
    $this->assertArmed(TRUE);
    $this->assertSame(TRUE, $this->bound());
  }

  /**
   * MUST-FIX 2 of review 2: a call the lock backend makes at the lock.
   *
   * Right after the backend took the lock of ensure(), or right after it
   * released it, it calls ensure() and release() of the same service. Both
   * are busy, and the outer run arms.
   *
   * @param string $step
   *   The step: 'nested at acquire' or 'nested at release'.
   *
   * @dataProvider lockBoundaries
   */
  public function testNestedCallAtTheLockIsBusy(string $step): void {
    $this->page(TRUE);
    EnsureFault::$step = $step;

    // A call that throws is compared as its class and message.
    $result = $this->attempt(fn(): array => $this->ensure()->ensure());

    // The outer result first: on a module without the service its failure
    // text names the missing service.
    $this->assertSame([TRUE, []], [$result['ok'] ?? $result, $result['refused'] ?? NULL]);
    $this->assertSame([
      'ensure' => ['ok' => FALSE, 'steps' => [], 'refused' => ['busy'], 'census' => []],
      'release' => ['ok' => FALSE, 'steps' => [], 'refused' => ['busy']],
    ], EnsureFault::$nested);
    $this->assertArmed(TRUE);
    $this->assertSame(TRUE, $this->bound());
  }

  /**
   * The two lock boundaries of a run.
   *
   * @return array<string, array{string}>
   *   The step.
   */
  public static function lockBoundaries(): array {
    return [
      'acquire' => ['nested at acquire'],
      'release' => ['nested at release'],
    ];
  }

  /**
   * MUST-FIX 1 of review 2: a release elsewhere unbinds before the arm.
   *
   * At the log line, after the verify step, the binding is released as a
   * release whose lock lapsed would. The run still holds its own lock, so
   * the renewal passes; the read after the arm finds the page unbound, and
   * the run removes the flag and throws.
   */
  public function testStaleReleaseLeavesTheSiteUnarmed(): void {
    $this->page(TRUE);
    EnsureFault::$step = 'stale release';
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    $this->assertSame('unbound', EnsureFault::$staleRelease);
    $this->assertSame(DevSiteEnsure::UNBOUND_TEXT, $thrown);
    $this->assertArmed(FALSE);
    $this->assertSame(FALSE, $this->bound());
  }

  /**
   * MUST-FIX 1 of review 2: a run elsewhere arms while release() unbinds.
   *
   * The workflow save of the unbind arms the site, as a run of ensure()
   * that took the lapsed lock would; release() removes the flag again.
   */
  public function testReleaseRemovesTheFlagAfterItsUnbind(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    EnsureFault::reset();
    EnsureFault::$step = 'arm at unbind';

    $result = $this->ensure()->release(FALSE);

    $this->assertNull(EnsureFault::$step, 'The unbind did not save the workflow.');
    $this->assertSame(['ok' => TRUE, 'steps' => ['disarmed', 'binding:unbound'], 'refused' => []], $result);
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $this->assertFalse(CinatraDevSite::exposureActive());
    $this->assertSame(FALSE, $this->bound());
  }

  /**
   * An unbind that throws: release() removed the flag before it.
   *
   * The workflow save of the unbind throws; the flag was already gone then,
   * the throw passes up, and the lock is free.
   */
  public function testThrowingUnbindLeavesTheFlagUnset(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    EnsureFault::reset();
    EnsureFault::$step = 'unbind';

    $result = $this->attempt(fn(): array => $this->ensure()->release(FALSE));

    $this->assertSame(['thrown' => \RuntimeException::class . ': ensure test failure: unbind'], $result);
    $this->assertNull(EnsureFault::$flagAtFault);
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(DevSiteEnsure::LOCK, 60.0), 'The lock was not released.');
    $other->release(DevSiteEnsure::LOCK);
  }

  /**
   * MUST-FIX 6 of review 2: a refusal whose census cannot be encoded.
   *
   * Without the marker the run refuses; the raw server_id that is not UTF-8
   * reaches the census. The run throws instead of returning a result that
   * the connector's json_encode() cannot encode, writes no log line, and
   * changes no stored value.
   */
  public function testUnencodableRefusalThrows(): void {
    $this->page(TRUE);
    $settings = ['enabled' => TRUE, 'server_id' => "\xB1"];
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', $settings);
    $this->container->get('config.factory')->reset();
    putenv('CINATRA_DEV_SITE');
    $snapshot = $this->snapshot();
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\JsonException $e) {
      $thrown = get_class($e);
    }
    $this->assertSame(\JsonException::class, $thrown);
    $this->assertSame([], EnsureFault::$lines);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * MUST-FIX 3 of review 1: the lock release throws after the arm.
   */
  public function testThrowingLockReleaseDisarms(): void {
    $this->page(TRUE);
    EnsureFault::$step = 'lock release';
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    $this->assertSame('ensure test failure: lock release', $thrown);
    // The run had armed the site when the release threw.
    $this->assertTrue(EnsureFault::$flagAtFault);
    EnsureFault::$step = NULL;
    $this->assertArmed(FALSE);
  }

  /**
   * MUST-FIX 4 of review 1: a result the connector cannot encode.
   *
   * A raw server_id that is not UTF-8 reaches the census; the override
   * still gives the verify step its value.
   */
  public function testUnencodableResultDoesNotArm(): void {
    $this->page(TRUE);
    $settings = ['enabled' => TRUE, 'server_id' => "\xB1"];
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', $settings);
    $this->container->get('config.factory')->reset();
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\JsonException $e) {
      $thrown = get_class($e);
    }
    $this->assertSame(\JsonException::class, $thrown);
    $this->assertArmed(FALSE);
  }

  /**
   * MUST-FIX 5 of review 1: the delete of the flag throws.
   *
   * The delete is the first write of a run, so nothing else runs: no log
   * line, no stored value changes, and the flag stays as the run found it.
   */
  public function testThrowingDisarmStopsTheRun(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    EnsureFault::reset();
    $snapshot = $this->snapshot();
    EnsureFault::$step = 'disarm';
    $thrown = NULL;
    try {
      $this->ensure()->ensure();
    }
    catch (\RuntimeException $e) {
      $thrown = $e->getMessage();
    }
    EnsureFault::$step = NULL;
    $this->assertSame('ensure test failure: disarm', $thrown);
    $this->assertSame([], EnsureFault::$lines);
    $this->assertSame($snapshot, $this->snapshot());
    $this->assertArmed(TRUE);
  }

  /**
   * MUST-FIX 6 of review 1: an armed site whose route is absent at the read.
   *
   * The refusal disarms and rebuilds the router: the stock route is back.
   */
  public function testArmedSiteWithoutRouteGetsTheStockRoute(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    EnsureFault::reset();
    EnsureFault::$step = 'no route once';
    $this->container->get('router.builder')->rebuild();
    $this->assertNull($this->controller());

    $result = $this->ensure()->ensure();

    $this->assertSame(['endpoint_route_unexpected'], $result['refused']);
    $this->assertNull($this->container->get('state')->get(CinatraDevSite::ARMED_KEY));
    $this->assertSame('\\' . ProbeController::class . '::handle', $this->controller());
  }

  /**
   * A failed verify step disarms a site armed earlier.
   */
  public function testFailedVerifyDisarms(): void {
    $this->page(TRUE);
    $this->assertTrue($this->ensure()->ensure()['ok']);
    $this->container->get('state')->set(self::VERIFY_MODE, 'change_profile');

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame([], $result['steps']);
    $this->assertSame(['exposure_unverified', 'replay_profile'], $result['refused']);
    $this->assertArmed(FALSE);
  }

  /**
   * A migration failure before any page was saved undoes the binding.
   */
  public function testFailedFirstPageUndoesTheBinding(): void {
    $nid = $this->page(TRUE);
    PageMutation::$field = 'promote';

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame(['workflow:created', 'workflow:bound'], $result['steps']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertNull(Workflow::load(EditorialBinder::ID));
    $this->assertNull($this->container->get('state')->get(EditorialBinder::OWNED_KEY));
    $this->assertArmed(FALSE);
  }

  /**
   * The log line of a failed migration names only a fixed reason.
   *
   * The result keeps only the code migration_failed:<nid>. The log line adds
   * the migrator's reason: a refusal's fixed text, or unexpected_error for
   * any other throwable, whose message may carry private data. The test
   * hook's change fails the comparison with a RuntimeException.
   */
  public function testFailedMigrationLogsFixedReason(): void {
    $nid = $this->page(TRUE);
    PageMutation::$field = 'promote';

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertArrayNotHasKey('error', $result);
    $this->assertArrayNotHasKey('reason', $result);
    $this->assertSame(['4 ensure() did not arm; steps: workflow:created, workflow:bound; refused: migration_failed:' . $nid . '; error: unexpected_error'], EnsureFault::$lines);
  }

  /**
   * A migration failure after a saved page keeps the binding; it resumes.
   */
  public function testFailedLaterPageKeepsTheBindingAndResumes(): void {
    $first = $this->page(TRUE);
    $second = $this->page(TRUE);
    PageMutation::$field = 'promote';
    PageMutation::$nid = $second;

    $result = $this->ensure()->ensure();

    $this->assertFalse($result['ok']);
    $this->assertSame(['workflow:created', 'workflow:bound', 'migrated:' . $first], $result['steps']);
    $this->assertSame(['migration_failed:' . $second], $result['refused']);
    $this->assertSame(TRUE, $this->bound());
    $this->assertArmed(FALSE);

    $result = $this->ensure()->ensure();
    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']));
    $this->assertSame(['migrated:' . $second, 'verified'], $result['steps']);
    $this->assertArmed(TRUE);
  }

  /**
   * Asserts the stored flag, the guard and the endpoint route.
   *
   * @param bool $armed
   *   Whether the site must be armed.
   * @param bool $staleRoute
   *   Whether the route keeps the endpoint while the site is not armed.
   */
  private function assertArmed(bool $armed, bool $staleRoute = FALSE): void {
    $state = $this->container->get('state');
    $state->resetCache();
    $this->assertSame($armed ? TRUE : NULL, $state->get(CinatraDevSite::ARMED_KEY));
    $this->assertSame($armed, CinatraDevSite::exposureActive());
    $route = $this->container->get('router.route_provider')->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
    $stock = '\\' . ProbeController::class . '::handle';
    $this->assertSame($armed || $staleRoute ? CinatraDevSite::ENDPOINT_CONTROLLER : $stock, $route->getDefault('_controller'));
  }

  /**
   * Whether editorial binds node:page.
   *
   * @return bool|null
   *   NULL when editorial is absent, so a test that expects a binding fails
   *   by its assertion.
   */
  private function bound(): ?bool {
    return Workflow::load(EditorialBinder::ID)?->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page');
  }

  /**
   * Runs a call; a throwable becomes a value that no result equals.
   *
   * @param \Closure $call
   *   The call.
   *
   * @return array
   *   What the call returned, or ['thrown' => class and message].
   */
  private function attempt(\Closure $call): array {
    try {
      return $call();
    }
    catch (\Throwable $e) {
      return ['thrown' => get_class($e) . ': ' . $e->getMessage()];
    }
  }

  /**
   * Reads the endpoint route's controller.
   *
   * @return string|null
   *   The controller, or NULL when the route is absent.
   */
  private function controller(): ?string {
    try {
      return $this->container->get('router.route_provider')->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller');
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * Creates a page that no workflow moderates yet.
   *
   * @param bool $published
   *   Whether the page is published.
   *
   * @return int
   *   The node id.
   */
  private function page(bool $published): int {
    $page = Node::create(['type' => 'page', 'title' => $published ? 'Published' : 'Draft', 'status' => $published]);
    $page->save();
    return (int) $page->id();
  }

  /**
   * Stores the profile record under the beta1 to beta14 name only.
   *
   * @param array $record
   *   The record.
   *
   * @return bool
   *   TRUE once written.
   */
  private function legacyOnly(array $record): bool {
    $storage = $this->container->get('config.storage');
    $storage->delete('mcp_tools.servers');
    return $storage->write(self::LEGACY_SERVERS, $record);
  }

  /**
   * Reads the moderation states stored for a page.
   *
   * @param int $nid
   *   The node id.
   *
   * @return string[]
   *   The states of the page's current state rows.
   */
  private function rows(int $nid): array {
    return $this->container->get('database')->select('content_moderation_state_field_data', 'm')
      ->fields('m', ['moderation_state'])
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->execute()->fetchCol();
  }

  /**
   * Reads State, configuration and every node and state row revision.
   *
   * @return array
   *   The snapshot.
   */
  private function snapshot(): array {
    $storage = $this->container->get('config.storage');
    $database = $this->container->get('database');
    $revisions = [];
    foreach (['node_field_revision' => 'vid', 'content_moderation_state_field_revision' => 'revision_id'] as $table => $key) {
      $revisions[$table] = $database->select($table, 'r')->fields('r')->orderBy($key)->orderBy('langcode')
        ->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    // Sorted: a value removed and set again moves to the end of the table.
    $state = $this->container->get('keyvalue')->get('state')->getAll();
    ksort($state);
    return [
      'state' => $state,
      'config' => $storage->readMultiple($storage->listAll()),
      'revisions' => $revisions,
    ];
  }

  /**
   * The ensure service.
   *
   * @return \Drupal\cinatra_dev_site\DevSiteEnsure
   *   The service.
   */
  private function ensure(): DevSiteEnsure {
    return $this->container->get('cinatra_dev_site.ensure');
  }

}
