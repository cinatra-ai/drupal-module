<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Exposure\Census;
use Drupal\cinatra_dev_site\Exposure\ExposureVerifier;
use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\cinatra_dev_site_verify_test\VerifyToolManager;
use Drupal\Component\Plugin\Definition\PluginDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\workflows\Entity\Workflow;
use Symfony\Component\Routing\Route;

/**
 * Tests the exposure verifier and the census.
 *
 * The verifier checks the live stack the way the remote endpoint reads it,
 * and the page binding and each page's moderation (MUST-FIX 4: a release
 * between the migration and the arm must not pass the verify step). Each
 * failure row asserts the exact list of codes, so an extra failure fails the
 * row. Neither service writes State or configuration.
 *
 * @group cinatra
 */
final class DevSiteExposureVerifierTest extends KernelTestBase {

  /**
   * The read tool id.
   */
  private const READ = 'cinatra_read_protected_revision';

  /**
   * The write tool id.
   */
  private const WRITE = 'cinatra_write_protected_draft';

  /**
   * The State key of the test alter's mode.
   */
  private const MODE = 'cinatra_dev_site_verify_test.mode';

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
  ];

  /**
   * The endpoint route as read before the exposure was active.
   */
  private Route $before;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists(ToolDefinition::class)) {
      require_once dirname(__DIR__, 2) . '/fixtures/ToolDefinitionDouble.php';
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'content_moderation']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    VerifyToolManager::$definitions = [
      self::READ => new ToolDefinition(['id' => self::READ, 'provider' => 'cinatra']),
      self::WRITE => new ToolDefinition(['id' => self::WRITE, 'provider' => 'cinatra']),
      'other_tool' => new ToolDefinition(['id' => 'other_tool', 'provider' => 'other']),
    ];
    $this->container->get('config.storage')->write('mcp_tools_remote.settings', ['enabled' => TRUE, 'server_id' => '']);
    $this->container->get('config.storage')->write('mcp_tools.servers', [
      'servers' => ['default' => ['name' => 'Default', 'include_all_tools' => FALSE, 'transports' => ['http']]],
    ]);
    putenv('CINATRA_DEV_SITE=1');
    $this->container->get('state')->delete(CinatraDevSite::ARMED_KEY);
    $this->container->get('router.builder')->rebuild();
    $this->before = $this->container->get('router.route_provider')->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
    $this->container->get('state')->set(CinatraDevSite::ARMED_KEY, TRUE);
    $this->container->get('router.builder')->rebuild();
    $this->container->get('config.factory')->reset('mcp_tools_remote.settings');
    $this->container->get('cinatra_dev_site.editorial_binder')->bind();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    VerifyToolManager::$definitions = [];
    parent::tearDown();
  }

  /**
   * The active stack with moderated pages verifies and nothing is written.
   */
  public function testVerifyPassesAndWritesNothing(): void {
    $published = $this->page('published', TRUE);
    $this->page('draft', FALSE);
    $published->addTranslation('fr', ['title' => 'fr', 'status' => TRUE, 'moderation_state' => 'published'])->save();
    Node::create(['type' => 'article', 'title' => 'Not a page'])->save();
    $stack = $this->container->get('request_stack');
    $top = $stack->getCurrentRequest();
    $user = $this->container->get('current_user')->id();
    $snapshot = $this->snapshot();

    $this->assertSame([], $this->verifier()->verify($this->before));
    $this->assertSame([], $this->verifier()->verify($this->before));

    $this->assertSame($top, $stack->getCurrentRequest());
    $this->assertSame($user, $this->container->get('current_user')->id());
    $this->assertSame($snapshot, $this->snapshot());
  }

  /**
   * Without the marker, or without the flag, the switch points are off.
   *
   * The router is not rebuilt, so the route still points at the endpoint.
   */
  public function testVerifyFailsWhileTheExposureIsNotActive(): void {
    foreach (['marker' => fn() => putenv('CINATRA_DEV_SITE'), 'flag' => fn() => $this->container->get('state')->delete(CinatraDevSite::ARMED_KEY)] as $label => $off) {
      $off();
      $this->container->get('config.factory')->reset('mcp_tools_remote.settings');
      $this->assertSame(['replay_profile', 'server_id'], $this->verifier()->verify($this->before), $label);
      putenv('CINATRA_DEV_SITE=1');
      $this->container->get('state')->set(CinatraDevSite::ARMED_KEY, TRUE);
      $this->container->get('config.factory')->reset('mcp_tools_remote.settings');
      $this->assertSame([], $this->verifier()->verify($this->before), $label . ' control');
    }
  }

  /**
   * A changed site-wide definition of an exposed tool is named.
   *
   * @param string $change
   *   How the read tool's definition changes.
   *
   * @dataProvider changedDefinitions
   */
  public function testVerifyNamesAChangedToolDefinition(string $change): void {
    $definitions = [
      'other provider' => new ToolDefinition(['id' => self::READ, 'provider' => 'other']),
      'remote provider' => new ToolDefinition(['id' => self::READ, 'provider' => 'mcp_tools_remote']),
      'not a tool definition' => new PluginDefinition(),
    ];
    unset(VerifyToolManager::$definitions[self::READ]);
    if ($change !== 'absent') {
      VerifyToolManager::$definitions[self::READ] = $definitions[$change];
    }
    $this->assertSame(['tool_definition:' . self::READ, 'replay_listing'], $this->verifier()->verify($this->before));
  }

  /**
   * The changes of the read tool's definition.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function changedDefinitions(): array {
    return [
      'absent' => ['absent'],
      'other provider' => ['other provider'],
      'remote provider' => ['remote provider'],
      'not a tool definition' => ['not a tool definition'],
    ];
  }

  /**
   * The route must point at the endpoint and keep path, methods, requirements.
   */
  public function testVerifyNeedsTheSwitchedRoute(): void {
    $changes = [
      'path' => fn(Route $r) => $r->setPath('/other'),
      'methods' => fn(Route $r) => $r->setMethods(['GET']),
      'requirements' => fn(Route $r) => $r->setRequirements(['_access' => 'TRUE']),
    ];
    foreach ($changes as $label => $change) {
      $before = clone $this->before;
      $change($before);
      $this->assertSame(['route'], $this->verifier()->verify($before), $label);
    }
    $this->assertSame(['route'], $this->verifier()->verify(NULL), 'no route read before');

    // A router built while the exposure was off keeps the stock controller.
    $this->container->get('state')->delete(CinatraDevSite::ARMED_KEY);
    $this->container->get('router.builder')->rebuild();
    $this->container->get('state')->set(CinatraDevSite::ARMED_KEY, TRUE);
    $this->assertSame(['route'], $this->verifier()->verify($this->before), 'stock controller');
  }

  /**
   * The replay fails at its first failing step, and the stack is restored.
   *
   * @param string $mode
   *   The test alter's mode.
   * @param string[] $expected
   *   The codes.
   *
   * @dataProvider replayCases
   */
  public function testReplayFailures(string $mode, array $expected): void {
    $top = $this->container->get('request_stack')->getCurrentRequest();
    $this->container->get('state')->set(self::MODE, $mode);
    $this->assertSame($expected, $this->verifier()->verify($this->before));
    $this->assertSame($top, $this->container->get('request_stack')->getCurrentRequest());
  }

  /**
   * The replay cases.
   *
   * @return array<string, array{string, string[]}>
   *   Mode and codes.
   */
  public static function replayCases(): array {
    return [
      'profile on every request' => ['add_profile', ['profile_without_binding']],
      'second read' => ['second_read', ['replay_guard']],
      'changed profile' => ['change_profile', ['replay_profile']],
    ];
  }

  /**
   * A stored profile under the cinatra id is foreign; the replay finds none.
   */
  public function testStoredCinatraProfileFailsTheReplay(): void {
    $this->container->get('config.storage')->write('mcp_tools.servers', ['servers' => [CinatraDevSite::PROFILE_ID => CinatraDevSite::PROFILE]]);
    $this->container->get('config.factory')->reset('mcp_tools.servers');
    $this->assertSame(['replay_profile'], $this->verifier()->verify($this->before));
  }

  /**
   * A failure that throws in the replay passes up after the pop.
   */
  public function testReplayThrowPopsTheRequest(): void {
    $top = $this->container->get('request_stack')->getCurrentRequest();
    $this->container->get('state')->set(self::MODE, 'throw');
    $thrown = NULL;
    try {
      $this->verifier()->verify($this->before);
    }
    catch (\RuntimeException $e) {
      $thrown = $e;
    }
    $this->assertSame('verify test failure', $thrown?->getMessage());
    $this->assertSame($top, $this->container->get('request_stack')->getCurrentRequest());
  }

  /**
   * Without the tool manager or a repository that answers, nothing replays.
   */
  public function testAbsentServicesAreNamed(): void {
    $c = $this->container;
    $make = fn($tools, $repository) => new ExposureVerifier(
      $tools,
      $repository,
      $c->get('config.factory'),
      $c->get('router.route_provider'),
      $c->get('request_stack'),
      $c->get('cinatra_dev_site.profile_guard'),
      $c->get('account_switcher'),
      $c->get('current_user'),
      $c->get('cinatra_dev_site.editorial_binder'),
      $c->get('entity_type.manager'),
      $c->get('content_moderation.moderation_information'),
    );
    $repository = $c->get('mcp_tools.server_config_repository');
    $tools = $c->get('plugin.manager.tool');
    $this->assertSame([], $make($tools, $repository)->verify($this->before), 'control');
    $this->assertSame(['services_absent'], $make(NULL, $repository)->verify($this->before));
    $this->assertSame(['services_absent'], $make($tools, NULL)->verify($this->before));
    $this->assertSame(['services_absent'], $make($tools, new \stdClass())->verify($this->before));
  }

  /**
   * A release of the binding fails the verify step (MUST-FIX 4).
   */
  public function testReleasedBindingFails(): void {
    $this->page('published', TRUE);
    $this->assertSame([], $this->verifier()->verify($this->before));
    $this->assertSame('unbound', $this->container->get('cinatra_dev_site.editorial_binder')->release(FALSE));
    $this->assertSame(['page_not_bound'], $this->verifier()->verify($this->before));
  }

  /**
   * Each term of page_not_bound fails the verify step on its own.
   *
   * The bundle info is read (and cached) before the stored record changes
   * under it, so the stored workflow and the bundle info disagree.
   *
   * @param string $case
   *   Which term fails.
   *
   * @dataProvider unboundCases
   */
  public function testPageNotBoundTerms(string $case): void {
    $storage = $this->container->get('config.storage');
    $info = $this->container->get('content_moderation.moderation_information');
    $name = EditorialBinder::CONFIG_NAME;
    if ($case === 'editorial absent') {
      Workflow::load(EditorialBinder::ID)->delete();
    }
    elseif ($case === 'stored record unbound') {
      $this->assertSame(EditorialBinder::ID, $info->getWorkflowForEntityTypeAndBundle('node', 'page')?->id());
      $record = $storage->read($name);
      $record['type_settings']['entity_types'] = [];
      $storage->write($name, $record);
    }
    else {
      $record = $storage->read($name);
      $this->container->get('cinatra_dev_site.editorial_binder')->release(FALSE);
      $this->assertNull($info->getWorkflowForEntityTypeAndBundle('node', 'page'));
      $storage->write($name, $record);
    }
    $this->container->get('config.factory')->reset($name);
    $this->assertSame(['page_not_bound'], $this->verifier()->verify($this->before));
  }

  /**
   * The terms of page_not_bound.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function unboundCases(): array {
    return [
      'editorial absent' => ['editorial absent'],
      'stored record unbound, bundle info names editorial' => ['stored record unbound'],
      'stored record bound, bundle info names none' => ['bundle info names none'],
    ];
  }

  /**
   * An editorial that differs from the standard one fails the preflight.
   */
  public function testChangedEditorialFails(): void {
    $workflow = Workflow::load(EditorialBinder::ID);
    $workflow->set('label', 'Changed')->save();
    $this->assertSame(['editorial_mismatch'], $this->verifier()->verify($this->before));
  }

  /**
   * Each page translation needs its moderation row with a matching state.
   */
  public function testEachPageNeedsItsModeration(): void {
    $this->container->get('cinatra_dev_site.editorial_binder')->release(FALSE);
    $unbound = Node::create(['type' => 'page', 'title' => 'Saved unbound', 'status' => TRUE]);
    $unbound->save();
    $unbound->addTranslation('fr', ['title' => 'fr', 'status' => TRUE])->save();
    $this->container->get('cinatra_dev_site.editorial_binder')->bind();
    $moderated = $this->page('published', TRUE);
    $nid = (int) $unbound->id();
    $this->assertSame(['page_unmoderated:' . $nid . ':en', 'page_unmoderated:' . $nid . ':fr'], $this->verifier()->verify($this->before));

    // A row whose state disagrees with the status.
    $unbound->delete();
    $mid = (int) $moderated->id();
    $database = $this->container->get('database');
    foreach (['content_moderation_state_field_data', 'content_moderation_state_field_revision'] as $table) {
      $database->update($table)->fields(['moderation_state' => 'draft'])->condition('content_entity_id', $mid)->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('content_moderation_state')->resetCache();
    $this->assertSame(['page_state_conflict:' . $mid . ':en'], $this->verifier()->verify($this->before));

    // A row with a state the workflow does not have.
    foreach (['content_moderation_state_field_data', 'content_moderation_state_field_revision'] as $table) {
      $database->update($table)->fields(['moderation_state' => 'gone'])->condition('content_entity_id', $mid)->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('content_moderation_state')->resetCache();
    $this->assertSame(['page_unmoderated:' . $mid . ':en'], $this->verifier()->verify($this->before));
  }

  /**
   * The census lists profiles, the replay, server ids, route and modules.
   */
  public function testCensusWhileActive(): void {
    $this->container->get('config.storage')->write('mcp_tools.servers', [
      'servers' => ['default' => ['include_all_tools' => FALSE, 'transports' => ['http']], 'broken' => 'not an array'],
    ]);
    $this->container->get('config.factory')->reset('mcp_tools.servers');
    $snapshot = $this->snapshot();
    $top = $this->container->get('request_stack')->getCurrentRequest();
    $census = $this->container->get('cinatra_dev_site.census')->read();
    $this->assertSame($top, $this->container->get('request_stack')->getCurrentRequest());
    $this->assertSame([
      'profiles' => [
        'default' => ['include_all_tools' => FALSE, 'gateway_mode' => NULL, 'transports' => ['http'], 'enabled' => NULL],
        'broken' => NULL,
        'cinatra (replay)' => ['include_all_tools' => FALSE, 'gateway_mode' => FALSE, 'transports' => ['http'], 'enabled' => TRUE],
      ],
      'server_id' => ['effective' => CinatraDevSite::PROFILE_ID, 'raw' => ''],
      'controller' => CinatraDevSite::ENDPOINT_CONTROLLER,
      'modules' => array_fill_keys(Census::MODULES, FALSE),
      'server_configs_alter' => ['cinatra_dev_site', 'cinatra_dev_site_verify_test'],
    ], $census);
    $this->assertSame($snapshot, $this->snapshot());
  }

  /**
   * Stored servers that are not a list, and a replay that finds no profile.
   */
  public function testCensusOddValues(): void {
    $storage = $this->container->get('config.storage');
    $census = $this->container->get('cinatra_dev_site.census');
    $storage->write('mcp_tools.servers', ['servers' => 'not a list']);
    $this->container->get('config.factory')->reset('mcp_tools.servers');
    $this->assertSame(['cinatra (replay)'], array_keys($census->read()['profiles']));

    // A stored cinatra profile is foreign: the alter disables it, so the
    // replay reads no profile.
    $storage->write('mcp_tools.servers', ['servers' => [CinatraDevSite::PROFILE_ID => CinatraDevSite::PROFILE]]);
    $this->container->get('config.factory')->reset('mcp_tools.servers');
    $profiles = $census->read()['profiles'];
    $this->assertSame([CinatraDevSite::PROFILE_ID, 'cinatra (replay)'], array_keys($profiles));
    $this->assertNull($profiles['cinatra (replay)']);
  }

  /**
   * A failure that throws in the census replay passes up after the pop.
   */
  public function testCensusReplayThrowPopsTheRequest(): void {
    $top = $this->container->get('request_stack')->getCurrentRequest();
    $this->container->get('state')->set(self::MODE, 'throw');
    $thrown = NULL;
    try {
      $this->container->get('cinatra_dev_site.census')->read();
    }
    catch (\RuntimeException $e) {
      $thrown = $e;
    }
    $this->assertSame('verify test failure', $thrown?->getMessage());
    $this->assertSame($top, $this->container->get('request_stack')->getCurrentRequest());
  }

  /**
   * Without the exposure the census has no replay and the raw server id.
   */
  public function testCensusWhileNotActive(): void {
    putenv('CINATRA_DEV_SITE');
    $this->container->get('config.factory')->reset('mcp_tools_remote.settings');
    $census = $this->container->get('cinatra_dev_site.census')->read();
    $this->assertSame(['default'], array_keys($census['profiles']));
    $this->assertSame(['effective' => '', 'raw' => ''], $census['server_id']);
  }

  /**
   * Creates a page with a moderation state.
   *
   * @param string $state
   *   The moderation state.
   * @param bool $status
   *   The status.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved page.
   */
  private function page(string $state, bool $status): NodeInterface {
    $page = Node::create(['type' => 'page', 'title' => $state, 'status' => $status, 'moderation_state' => $state]);
    $page->save();
    return $page;
  }

  /**
   * The verifier service.
   *
   * @return \Drupal\cinatra_dev_site\Exposure\ExposureVerifier
   *   The verifier.
   */
  private function verifier(): ExposureVerifier {
    return $this->container->get('cinatra_dev_site.exposure_verifier');
  }

  /**
   * The stored State and configuration.
   *
   * @return array
   *   Every State value and every configuration record.
   */
  private function snapshot(): array {
    $storage = $this->container->get('config.storage');
    return [
      'state' => $this->container->get('keyvalue')->get('state')->getAll(),
      'config' => $storage->readMultiple($storage->listAll()),
    ];
  }

}
