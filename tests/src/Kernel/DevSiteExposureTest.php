<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site_test\FakeRemoteController;
use Drupal\cinatra_dev_site_test\TestLogger;
use Drupal\cinatra_dev_site_test\TestToolManager;
use Drupal\Core\Session\AccountSwitcher;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\User;
use Drupal\workflows\Entity\Workflow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves the profile, the override, the export and the enforcement road.
 *
 * @group cinatra
 */
final class DevSiteExposureTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'workflows',
    'content_moderation', 'cinatra', 'cinatra_dev_site', 'cinatra_dev_site_test',
  ];

  /**
   * The mcp configuration objects have no schema in this fixture.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The read tool id.
   */
  private const READ = 'cinatra_read_protected_revision';

  /**
   * The write tool id.
   */
  private const WRITE = 'cinatra_write_protected_draft';

  /**
   * The third tool id.
   */
  private const THIRD = 'cinatra_dev_site_test_third';

  /**
   * The fake controller.
   */
  private const FAKE = '\Drupal\cinatra_dev_site_test\FakeRemoteController::handle';

  /**
   * The guard's refusal text, as the repository answers it.
   */
  private const GUARD_DENIED = 'Access denied by server permission callback.';

  /**
   * The seeded mcp_tools.servers data.
   */
  private const SERVERS = ['servers' => []];

  /**
   * The executing user.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $executor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestToolManager::loadToolDefinition();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // drupal/mcp_tools 1.0.0-beta18 (commit 737803e7) install values.
    $this->config('mcp_tools.servers')->setData(self::SERVERS)->save();
    $this->config('mcp_tools_remote.settings')->setData([
      'enabled' => FALSE,
      'uid' => 0,
      'allow_uid1' => FALSE,
      'allowed_ips' => [],
      'allowed_origins' => [],
      'server_name' => 'Drupal MCP Tools',
      'server_version' => '1.0.0',
      'server_id' => '',
      'pagination_limit' => 50,
      'include_all_tools' => FALSE,
      'gateway_mode' => FALSE,
    ])->save();
    $this->config('mcp_tools.settings')->setData(['access' => ['allowed_scopes' => ['read', 'write']]])->save();
    User::create(['name' => 'site_admin'])->save();
    $this->executor = User::create(['name' => 'executor', 'status' => 1]);
    $this->executor->save();
    putenv(CinatraDevSite::MARKER_ENV . '=1');
    TestToolManager::$definitionsCalls = 0;
    TestToolManager::$instanceCalls = 0;
    FakeRemoteController::$built = 0;
    FakeRemoteController::$served = 0;
    TestLogger::$lines = [];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(CinatraDevSite::MARKER_ENV);
    parent::tearDown();
  }

  /**
   * Arms the site: the flag, a config reset and a router rebuild.
   */
  private function arm(): void {
    \Drupal::state()->set(CinatraDevSite::ARMED_KEY, TRUE);
    \Drupal::configFactory()->reset('mcp_tools_remote.settings');
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Disarms the site.
   */
  private function disarm(): void {
    \Drupal::state()->delete(CinatraDevSite::ARMED_KEY);
    \Drupal::configFactory()->reset('mcp_tools_remote.settings');
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Makes the remote endpoint serve the executor with the key k1.
   */
  private function serve(): void {
    $this->config('mcp_tools_remote.settings')
      ->set('enabled', TRUE)
      ->set('uid', (int) $this->executor->id())
      ->save();
    \Drupal::state()->set('cinatra_dev_site_test.key', 'k1');
  }

  /**
   * Sets a raw remote setting.
   *
   * @param string $key
   *   The key.
   * @param mixed $value
   *   The value.
   */
  private function setting(string $key, mixed $value): void {
    $this->config('mcp_tools_remote.settings')->set($key, $value)->save();
  }

  /**
   * Builds a request to the remote endpoint.
   *
   * @param bool $authenticated
   *   Whether it carries the key k1.
   * @param string $query
   *   The query string, without '?'.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function request(bool $authenticated = TRUE, string $query = ''): Request {
    $request = Request::create('/_mcp_tools' . ($query !== '' ? '?' . $query : ''), 'POST');
    $request->headers->set('Accept', 'application/json, text/event-stream');
    if ($authenticated) {
      $request->headers->set('Authorization', 'Bearer k1');
    }
    return $request;
  }

  /**
   * Returns the route's controller.
   *
   * @return string
   *   The _controller default.
   */
  private function controller(): string {
    $route = \Drupal::service('router.route_provider')->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
    return (string) $route->getDefault('_controller');
  }

  /**
   * Dispatches a request the way core resolves the route's controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function send(Request $request): Response {
    \Drupal::state()->set('cinatra_dev_site_test.reads', []);
    $callable = \Drupal::service('controller_resolver')->getControllerFromDefinition($this->controller());
    $stack = \Drupal::requestStack();
    $stack->push($request);
    try {
      return $callable($request);
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Decodes a JSON answer.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @return array
   *   The body.
   */
  private function body(Response $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return (array) json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Returns the read numbers recorded for the last request.
   *
   * @return array
   *   The read numbers.
   */
  private function reads(): array {
    return (array) \Drupal::state()->get('cinatra_dev_site_test.reads', []);
  }

  /**
   * Returns the comparable parts of an answer.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @return array
   *   Status, body and the two headers.
   */
  private function answer(Response $response): array {
    return [
      $response->getStatusCode(),
      (string) $response->getContent(),
      $response->headers->get('WWW-Authenticate'),
      $response->headers->get('Content-Type'),
    ];
  }

  /**
   * Returns the binding the endpoint put on a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\cinatra_dev_site\Mcp\ProfileBinding
   *   The binding.
   */
  private function binding(Request $request): ProfileBinding {
    $binding = $request->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE);
    $this->assertInstanceOf(ProfileBinding::class, $binding);
    return $binding;
  }

  /**
   * Reads the servers on a request that carries a binding of itself.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request to push, or NULL for the current one.
   *
   * @return array
   *   The servers.
   */
  private function serversOn(?Request $request): array {
    $repository = \Drupal::service('mcp_tools.server_config_repository');
    if ($request === NULL) {
      return $repository->getServers();
    }
    $stack = \Drupal::requestStack();
    $stack->push($request);
    try {
      return $repository->getServers();
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Asserts the stored server profiles are the seeded ones.
   */
  private function assertStoredServersUnchanged(): void {
    $this->assertSame(self::SERVERS, \Drupal::service('config.storage')->read('mcp_tools.servers'));
  }

  /**
   * Asserts that ensure() wrote nothing.
   *
   * @param array $workflows
   *   The workflow data before the run, keyed by id.
   * @param int $revisions
   *   The node revision count before the run.
   */
  private function assertNothingWritten(array $workflows, int $revisions): void {
    $this->assertSame($workflows, $this->workflowData());
    $this->assertSame($revisions, $this->nodeRevisions());
    $this->assertSame(0, (int) \Drupal::entityTypeManager()->getStorage('content_moderation_state')->getQuery()->accessCheck(FALSE)->allRevisions()->count()->execute());
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
  }

  /**
   * Returns the stored workflow config, keyed by id.
   *
   * @return array
   *   The data.
   */
  private function workflowData(): array {
    $storage = \Drupal::service('config.storage');
    $data = [];
    foreach ($storage->listAll('workflows.workflow.') as $name) {
      $data[$name] = $storage->read($name);
    }
    return $data;
  }

  /**
   * Counts the node revisions.
   *
   * @return int
   *   The count.
   */
  private function nodeRevisions(): int {
    return (int) \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)->allRevisions()->count()->execute();
  }

  /**
   * Runs ensure() and asserts its refusal wrote nothing.
   *
   * @param string[] $expected
   *   The refusal codes that must be reported.
   */
  private function assertRefusedAlone(array $expected): void {
    $workflows = $this->workflowData();
    $revisions = $this->nodeRevisions();
    $result = \Drupal::service('cinatra_dev_site.ensure')->ensure();
    $this->assertFalse($result['ok']);
    foreach ($expected as $code) {
      $this->assertContains($code, $result['refused'], implode(', ', $result['refused']));
    }
    $this->assertNothingWritten($workflows, $revisions);
  }

  /**
   * Runs a successful ensure().
   *
   * @return array
   *   The result.
   */
  private function ensureOk(): array {
    $result = \Drupal::service('cinatra_dev_site.ensure')->ensure();
    $this->assertTrue($result['ok'], implode(', ', $result['refused']) . ' ' . ($result['error'] ?? ''));
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    return $result;
  }

  /**
   * Case 1: the alter adds the profile only on a request with its binding.
   */
  public function testProfileAlter(): void {
    $this->arm();
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $servers = $this->serversOn($request);
    $this->assertArrayHasKey('cinatra', $servers);
    $profile = $servers['cinatra'];
    $this->assertSame(CinatraDevSite::PROFILE, $profile);
    $this->assertSame('cinatra', $profile['id']);
    $keys = [
      'name',
      'version',
      'pagination_limit',
      'include_all_tools',
      'gateway_mode',
      'enable_resources',
      'enable_prompts',
      'component_public_only',
      'transports',
      'enabled',
      'scopes',
      'permission_callback',
    ];
    foreach ($keys as $key) {
      $this->assertArrayHasKey($key, $profile);
    }
    $this->assertSame(CinatraDevSite::EXPOSED_TOOLS, $profile['tools']);
    $this->assertSame(CinatraDevSite::GUARD_CALLBACK, $profile['permission_callback']);
    // Exactly one read was counted, so the binding binds.
    $this->assertTrue($binding->bind($profile));

    // The current kernel request carries no binding.
    $this->assertArrayNotHasKey('cinatra', $this->serversOn(NULL));
    // No request at all.
    $stack = \Drupal::requestStack();
    $popped = [];
    while ($stack->getCurrentRequest() !== NULL) {
      $popped[] = $stack->pop();
    }
    $this->assertArrayNotHasKey('cinatra', \Drupal::service('mcp_tools.server_config_repository')->getServers());
    foreach (array_reverse($popped) as $kept) {
      $stack->push($kept);
    }
    // A binding of another request.
    $other = Request::create('/_mcp_tools');
    $other->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding(Request::create('/_mcp_tools')));
    $this->assertArrayNotHasKey('cinatra', $this->serversOn($other));

    // A foreign cinatra is disabled in the altered view only.
    $servers = ['cinatra' => ['id' => 'cinatra', 'enabled' => TRUE, 'name' => 'Foreign']];
    \Drupal::moduleHandler()->alter('mcp_tools_server_configs', $servers);
    $this->assertFalse($servers['cinatra']['enabled']);
    $this->config('mcp_tools.servers')->set('servers', ['cinatra' => ['name' => 'Foreign']])->save();
    $request = Request::create('/_mcp_tools');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding($request));
    $this->assertArrayNotHasKey('cinatra', $this->serversOn($request));
    $this->assertSame(['servers' => ['cinatra' => ['name' => 'Foreign']]], \Drupal::service('config.storage')->read('mcp_tools.servers'));
    $this->config('mcp_tools.servers')->setData(self::SERVERS)->save();

    // Not armed: nothing.
    $this->disarm();
    $request = Request::create('/_mcp_tools');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding($request));
    $this->assertArrayNotHasKey('cinatra', $this->serversOn($request));
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 2: the server_id override is effective only while armed.
   */
  public function testOverride(): void {
    $this->arm();
    $this->assertSame('cinatra', \Drupal::config('mcp_tools_remote.settings')->get('server_id'));
    $this->assertSame('', \Drupal::configFactory()->getEditable('mcp_tools_remote.settings')->get('server_id'));
    $this->assertSame('', \Drupal::service('config.storage')->read('mcp_tools_remote.settings')['server_id']);
    $this->disarm();
    $this->assertSame('', \Drupal::config('mcp_tools_remote.settings')->get('server_id'));
  }

  /**
   * Binds the editorial workflow to the page type.
   */
  private function bindEditorial(): void {
    $workflow = Workflow::load('editorial') ?? $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'page');
    $workflow->save();
  }

  /**
   * Case 3: the export drops the submodule and an owned binding.
   */
  public function testExportDropsOwnedBinding(): void {
    $this->bindEditorial();
    $this->arm();
    \Drupal::state()->set(CinatraDevSite::BOUND_KEY, TRUE);
    $export = \Drupal::service('config.storage.export');
    $extension = $export->read('core.extension');
    $this->assertArrayNotHasKey('cinatra_dev_site', $extension['module']);
    $this->assertArrayHasKey('content_moderation', $extension['module']);
    $editorial = $export->read('workflows.workflow.editorial');
    $this->assertIsArray($editorial);
    $this->assertNotContains('page', $editorial['type_settings']['entity_types']['node'] ?? []);
    $this->assertNotContains('node.type.page', $editorial['dependencies']['config'] ?? []);
    $remote = $export->read('mcp_tools_remote.settings');
    $this->assertSame('', $remote['server_id']);
    $this->assertArrayNotHasKey('cinatra', $export->read('mcp_tools.servers')['servers'] ?? []);

    $active = \Drupal::service('config.storage');
    $this->assertArrayHasKey('cinatra_dev_site', $active->read('core.extension')['module']);
    $stored = $active->read('workflows.workflow.editorial');
    $this->assertContains('page', $stored['type_settings']['entity_types']['node']);
    $this->assertContains('node.type.page', $stored['dependencies']['config']);
  }

  /**
   * Case 3: a binding the submodule did not add stays in the export.
   */
  public function testExportKeepsForeignBinding(): void {
    $this->bindEditorial();
    $this->arm();
    $export = \Drupal::service('config.storage.export');
    $this->assertArrayNotHasKey('cinatra_dev_site', $export->read('core.extension')['module']);
    $editorial = $export->read('workflows.workflow.editorial');
    $this->assertContains('page', $editorial['type_settings']['entity_types']['node']);
    $this->assertContains('node.type.page', $editorial['dependencies']['config']);
  }

  /**
   * Case 4: no marker.
   */
  public function testCheckAllNoMarker(): void {
    putenv(CinatraDevSite::MARKER_ENV);
    $this->assertRefusedAlone(['no_marker']);
  }

  /**
   * Case 4: no tool manager without the test module.
   */
  public function testCheckAllToolManagerAbsent(): void {
    $this->disableModules(['cinatra_dev_site_test']);
    $this->assertRefusedAlone(['tool_manager_absent', 'server_config_repository_absent']);
  }

  /**
   * Case 4: a missing tool definition.
   */
  public function testCheckAllToolDefinitionMissing(): void {
    \Drupal::state()->set('cinatra_dev_site_test.omit', [self::READ]);
    \Drupal::service('plugin.manager.tool')->clearCachedDefinitions();
    $this->assertRefusedAlone(['tool_definition_missing:' . self::READ]);
  }

  /**
   * Case 4: include_all_tools TRUE.
   */
  public function testCheckAllIncludeAllTools(): void {
    $this->setting('include_all_tools', TRUE);
    $this->assertRefusedAlone(['include_all_tools_true']);
  }

  /**
   * Case 4: a stored cinatra profile.
   */
  public function testCheckAllProfileCollision(): void {
    $this->config('mcp_tools.servers')->set('servers', ['cinatra' => ['name' => 'Foreign']])->save();
    $this->assertRefusedAlone(['profile_collision']);
  }

  /**
   * Case 4: the route has another controller.
   */
  public function testCheckAllEndpointRouteUnexpected(): void {
    \Drupal::state()->set('cinatra_dev_site_test.foreign_controller', '\Drupal\cinatra_dev_site_test\AllowAllCallback::check');
    \Drupal::service('router.builder')->rebuild();
    $this->assertRefusedAlone(['endpoint_route_unexpected']);
  }

  /**
   * Case 4: an editorial with other states.
   */
  public function testCheckAllEditorialMismatch(): void {
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addState('review', 'Review');
    $workflow->save();
    $this->assertRefusedAlone(['editorial_mismatch']);
  }

  /**
   * Case 4: another workflow, and two refusals reported together.
   */
  public function testCheckAllOtherWorkflow(): void {
    $other = Workflow::create(['id' => 'other', 'label' => 'Other', 'type' => 'content_moderation']);
    $other->save();
    $this->assertRefusedAlone(['other_workflow']);
    $other->getTypePlugin()->addEntityTypeAndBundle('node', 'page');
    $other->save();
    $this->assertRefusedAlone(['other_workflow', 'page_workflow_conflict:other']);
  }

  /**
   * Case 4: another workflow applies to the page type.
   */
  public function testCheckAllPageWorkflowConflict(): void {
    $this->createEditorialWorkflow();
    $other = Workflow::create(['id' => 'other', 'label' => 'Other', 'type' => 'content_moderation']);
    $other->getTypePlugin()->addEntityTypeAndBundle('node', 'page');
    $other->save();
    $this->assertRefusedAlone(['page_workflow_conflict:other']);
  }

  /**
   * Case 4: a pending page.
   */
  public function testCheckAllPendingPage(): void {
    $page = Node::create(['type' => 'page', 'title' => 'Page', 'status' => 1, 'uid' => $this->executor->id()]);
    $page->save();
    $pending = Node::load($page->id());
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setTitle('Pending');
    $pending->save();
    $this->assertRefusedAlone(['pending_revision:' . $page->id()]);
  }

  /**
   * Case 5: an armed site given include_all_tools TRUE is disarmed.
   */
  public function testRefusalDisarms(): void {
    $this->ensureOk();
    $this->assertSame(ltrim(CinatraDevSite::ENDPOINT_CONTROLLER, '\\'), ltrim($this->controller(), '\\'));
    $this->setting('include_all_tools', TRUE);
    $result = \Drupal::service('cinatra_dev_site.ensure')->ensure();
    $this->assertFalse($result['ok']);
    $this->assertContains('include_all_tools_true', $result['refused']);
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertSame(self::FAKE, $this->controller());
    $this->assertSame('', \Drupal::config('mcp_tools_remote.settings')->get('server_id'));
    $request = Request::create('/_mcp_tools');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding($request));
    $this->assertArrayNotHasKey('cinatra', $this->serversOn($request));
  }

  /**
   * Case 6: a failed workflow write leaves nothing behind.
   */
  public function testWorkflowFailed(): void {
    \Drupal::state()->set('cinatra_dev_site_test.fail_workflow', TRUE);
    $result = \Drupal::service('cinatra_dev_site.ensure')->ensure();
    $this->assertFalse($result['ok']);
    $this->assertSame(['workflow_failed'], $result['refused']);
    $this->assertNull(Workflow::load('editorial'));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
  }

  /**
   * Case 7: a tampered profile during the replay leaves the site disarmed.
   */
  public function testExposureUnverified(): void {
    \Drupal::state()->set('cinatra_dev_site_test.tamper', ['*' => ['strip_callback']]);
    $result = \Drupal::service('cinatra_dev_site.ensure')->ensure();
    $this->assertFalse($result['ok']);
    $this->assertSame(['exposure_unverified'], $result['refused']);
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertSame(self::FAKE, $this->controller());
  }

  /**
   * Case 8: release() removes only an owned binding and always disarms.
   */
  public function testRelease(): void {
    $this->ensureOk();
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
    \Drupal::service('cinatra_dev_site.ensure')->release(FALSE);
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
    $this->assertFalse(Workflow::load('editorial')->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));

    // A foreign binding: ensure() did not add it.
    $this->bindEditorial();
    $this->ensureOk();
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
    \Drupal::service('cinatra_dev_site.ensure')->release(FALSE);
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertTrue(Workflow::load('editorial')->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));

    // Syncing: no config write.
    \Drupal::state()->set(CinatraDevSite::BOUND_KEY, TRUE);
    $this->ensureOk();
    $before = $this->workflowData();
    \Drupal::service('cinatra_dev_site.ensure')->release(TRUE);
    $this->assertSame($before, $this->workflowData());
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
  }

  /**
   * Case 9 a0: the control, not armed.
   */
  public function testEnforcementControl(): void {
    $this->serve();
    $this->assertSame(self::FAKE, $this->controller());
    $body = $this->body($this->send($this->request()));
    $this->assertSame([self::THIRD], $body['listed']);
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 a: list and call, armed.
   */
  public function testEnforcementListAndCall(): void {
    $this->serve();
    $this->arm();
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $this->controller());
    $body = $this->body($this->send($this->request()));
    $this->assertSame([self::READ, self::WRITE], $body['listed']);
    $this->assertSame([1], $this->reads());
    $body = $this->body($this->send($this->request(TRUE, 'call=' . self::THIRD)));
    $this->assertSame('Unknown tool', $body['call']);
    $this->assertSame([1], $this->reads());
    foreach ([self::READ, self::WRITE] as $id) {
      $body = $this->body($this->send($this->request(TRUE, 'call=' . $id)));
      $this->assertSame('called', $body['call']);
      $this->assertSame([1], $this->reads());
    }
    $this->assertStoredServersUnchanged();
  }

  /**
   * Sends one tampered, authenticated request and returns its answer.
   *
   * @param array $tamper
   *   The tamper map.
   * @param string|null $extra
   *   The extra_read flag.
   * @param string $query
   *   The query string.
   *
   * @return array
   *   The response and the request.
   */
  private function tampered(array $tamper, ?string $extra = NULL, string $query = ''): array {
    \Drupal::state()->set('cinatra_dev_site_test.tamper', $tamper);
    \Drupal::state()->set('cinatra_dev_site_test.extra_read', $extra);
    $request = $this->request(TRUE, $query);
    $response = $this->send($request);
    \Drupal::state()->delete('cinatra_dev_site_test.tamper');
    \Drupal::state()->delete('cinatra_dev_site_test.extra_read');
    return [$response, $request];
  }

  /**
   * Asserts the gate denied a request without switching or discovery.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   * @param int $built
   *   The build count before the request.
   * @param int $served
   *   The served count before the request.
   * @param int $user
   *   The current user id before the request.
   */
  private function assertGateDenied(Response $response, int $built, int $served, int $user): void {
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
    $this->assertSame($built + 1, FakeRemoteController::$built);
    $this->assertSame($served, FakeRemoteController::$served);
    $this->assertSame(0, TestToolManager::$definitionsCalls);
    $this->assertSame(0, TestToolManager::$instanceCalls);
    $this->assertSame($user, (int) \Drupal::currentUser()->id());
    try {
      \Drupal::service('account_switcher')->switchBack();
      $this->fail('The gate switched the account.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('No more accounts to revert to.', $e->getMessage());
    }
  }

  /**
   * Case 9 b: tamper on the one read.
   */
  public function testEnforcementTamper(): void {
    $this->serve();
    $this->arm();
    foreach (['strip_callback', 'replace_callback', 'bad_callback'] as $value) {
      foreach ([[$value], [$value, 'gateway']] as $values) {
        TestToolManager::$definitionsCalls = 0;
        TestToolManager::$instanceCalls = 0;
        $built = FakeRemoteController::$built;
        $served = FakeRemoteController::$served;
        $user = (int) \Drupal::currentUser()->id();
        [$response] = $this->tampered(['*' => $values]);
        $this->assertGateDenied($response, $built, $served, $user);
      }
    }
    $values = [
      'strip_tools',
      'tools_one',
      'tools_three',
      'tools_unknown',
      'strip_id',
      'include_all',
      'gateway',
      'extra_key',
    ];
    foreach ($values as $value) {
      $served = FakeRemoteController::$served;
      [$response, $request] = $this->tampered(['*' => [$value]]);
      $this->assertSame(403, $response->getStatusCode(), $value);
      $this->assertSame(self::GUARD_DENIED, $response->getContent(), $value);
      $this->assertSame($served, FakeRemoteController::$served);
      $this->assertFalse($this->binding($request)->isValidated());
    }
    [$response] = $this->tampered(['*' => ['disable']]);
    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame('Configured MCP server profile not found: cinatra', $response->getContent());
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 c: a second read in the same request revokes the binding.
   */
  public function testEnforcementTwoReads(): void {
    $this->serve();
    $this->arm();
    TestToolManager::$instanceCalls = 0;
    $served = FakeRemoteController::$served;
    [$response] = $this->tampered([2 => ['strip_callback', 'gateway']], 'before');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
    $this->assertSame($served, FakeRemoteController::$served);

    [$response] = $this->tampered([1 => ['strip_callback', 'gateway']], 'before');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(self::GUARD_DENIED, $response->getContent());
    $this->assertSame($served, FakeRemoteController::$served);

    [$response] = $this->tampered([], 'after');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
    $this->assertSame($served, FakeRemoteController::$served);

    [$response] = $this->tampered([], 'late');
    $this->assertSame([], $this->body($response)['listed']);
    foreach ([self::READ, self::WRITE] as $id) {
      [$response] = $this->tampered([], 'late', 'call=' . $id);
      $this->assertSame('Unknown tool', $this->body($response)['call']);
    }
    $this->assertSame(0, TestToolManager::$instanceCalls);
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 d: callers rejected before the read get the same answer.
   */
  public function testEnforcementBeforeAuthentication(): void {
    $this->serve();
    $this->arm();
    $this->assertSame(CinatraDevSite::ENDPOINT_CONTROLLER, $this->controller());
    $classes = [
      'keyless' => [
        function (): Request {
          return $this->request(FALSE);
        },
        401,
        NULL,
      ],
      'wrong key' => [
        function (): Request {
          $request = $this->request(FALSE);
          $request->headers->set('Authorization', 'Bearer wrong');
          return $request;
        },
        401,
        NULL,
      ],
      'ip' => [
        function (): Request {
          return $this->request();
        },
        404,
        ['allowed_ips', ['none']],
      ],
      'origin' => [
        function (): Request {
          $request = $this->request();
          $request->headers->set('Origin', 'http://elsewhere.example');
          return $request;
        },
        404,
        NULL,
      ],
      'accept' => [
        function (): Request {
          $request = $this->request();
          $request->headers->set('Accept', 'application/json');
          return $request;
        },
        406,
        NULL,
      ],
      'disabled' => [
        function (): Request {
          return $this->request();
        },
        404,
        ['enabled', FALSE],
      ],
    ];
    foreach ($classes as $label => [$build, $status, $setting]) {
      if ($setting !== NULL) {
        $original = \Drupal::config('mcp_tools_remote.settings')->get($setting[0]);
        $this->setting($setting[0], $setting[1]);
      }
      $answers = [];
      foreach ([[], ['*' => ['strip_tools', 'gateway']]] as $tamper) {
        TestToolManager::$definitionsCalls = 0;
        TestToolManager::$instanceCalls = 0;
        TestLogger::$lines = [];
        $served = FakeRemoteController::$served;
        \Drupal::state()->set('cinatra_dev_site_test.tamper', $tamper);
        $request = $build();
        $response = $this->send($request);
        $this->assertSame($status, $response->getStatusCode(), $label);
        $binding = $this->binding($request);
        $this->assertSame([], $this->reads(), $label);
        $this->assertFalse($binding->isValidated(), $label);
        $this->assertSame(0, TestToolManager::$definitionsCalls, $label);
        $this->assertSame(0, TestToolManager::$instanceCalls, $label);
        $this->assertSame($served, FakeRemoteController::$served, $label);
        foreach (TestLogger::$lines as [$channel]) {
          $this->assertNotSame('cinatra_dev_site', $channel, $label);
        }
        $answers[] = $this->answer($response);
      }
      $this->assertSame($answers[0], $answers[1], $label);
      if ($status === 401) {
        $this->assertSame('{"error":"Authentication required"}', $answers[0][1]);
        $this->assertSame('Bearer realm="mcp_tools_remote"', $answers[0][2]);
      }
      if ($status === 404) {
        $this->assertSame('Not found.', $answers[0][1]);
      }
      if ($status === 406) {
        $this->assertSame('Not acceptable.', $answers[0][1]);
      }
      if ($setting !== NULL) {
        $this->setting($setting[0], $original);
      }
    }
    \Drupal::state()->delete('cinatra_dev_site_test.tamper');
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 e: the raw controller with the real container, armed.
   */
  public function testEnforcementRawPath(): void {
    $this->serve();
    $this->arm();
    $raw = function (Request $request): Response {
      $stack = \Drupal::requestStack();
      $stack->push($request);
      try {
        return FakeRemoteController::create(\Drupal::getContainer())->handle($request);
      }
      finally {
        $stack->pop();
      }
    };
    $this->assertSame(401, $raw($this->request(FALSE))->getStatusCode());
    $this->setting('allowed_ips', ['none']);
    $this->assertSame(404, $raw($this->request())->getStatusCode());
    $this->setting('allowed_ips', []);
    foreach ([[], ['*' => ['strip_callback']]] as $tamper) {
      \Drupal::state()->set('cinatra_dev_site_test.tamper', $tamper);
      $response = $raw($this->request());
      $this->assertSame(500, $response->getStatusCode());
      $this->assertSame('Configured MCP server profile not found: cinatra', $response->getContent());
    }
    \Drupal::state()->delete('cinatra_dev_site_test.tamper');
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 f: the guard.
   */
  public function testGuard(): void {
    $this->arm();
    $guard = \Drupal::service('cinatra_dev_site.profile_guard');
    $this->assertFalse($guard->check(NULL, CinatraDevSite::PROFILE));
    $stack = \Drupal::requestStack();

    $plain = Request::create('/_mcp_tools');
    $stack->push($plain);
    $this->assertFalse($guard->check($plain, CinatraDevSite::PROFILE));
    $stack->pop();

    $foreign = Request::create('/_mcp_tools');
    $foreign_binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $foreign_binding->noteRead();
    $foreign->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $foreign_binding);
    $stack->push($foreign);
    $this->assertFalse($guard->check($foreign, CinatraDevSite::PROFILE));
    $stack->pop();

    // Not armed: FALSE.
    \Drupal::state()->delete(CinatraDevSite::ARMED_KEY);
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $binding->noteRead();
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $stack->push($request);
    $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));
    $stack->pop();

    // Armed: TRUE, then a different array revokes.
    \Drupal::state()->set(CinatraDevSite::ARMED_KEY, TRUE);
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $binding->noteRead();
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $stack->push($request);
    $this->assertTrue($guard->check($request, CinatraDevSite::PROFILE));
    $this->assertTrue($binding->isValidated());
    $different = CinatraDevSite::PROFILE;
    $different['name'] = 'Other';
    $this->assertFalse($guard->check($request, $different));
    $this->assertFalse($binding->isValidated());
    $stack->pop();
  }

  /**
   * Case 9 g: server_id '' reads nothing; 'default' reads once, unbound.
   *
   * Spec rev7 case 9g says zero reads for both. With server_id 'default' the
   * repository's read runs the alter, which counts one read on the request's
   * binding; the binding is never validated, so the gate denies.
   */
  public function testEnforcementServerId(): void {
    $this->serve();
    $this->arm();
    foreach (['default' => [1], '' => []] as $server_id => $reads) {
      \Drupal::state()->set('cinatra_dev_site_test.server_id', (string) $server_id);
      \Drupal::configFactory()->reset('mcp_tools_remote.settings');
      $this->assertSame((string) $server_id, \Drupal::config('mcp_tools_remote.settings')->get('server_id'));
      $served = FakeRemoteController::$served;
      $request = $this->request();
      $response = $this->send($request);
      $this->assertSame(403, $response->getStatusCode(), (string) $server_id);
      $this->assertSame(CinatraDevSite::DENY_TEXT, $response->getContent());
      $this->assertSame($served, FakeRemoteController::$served);
      $this->assertSame($reads, $this->reads(), (string) $server_id);
      $this->assertFalse($this->binding($request)->isValidated());
    }
    \Drupal::state()->delete('cinatra_dev_site_test.server_id');
    $this->assertStoredServersUnchanged();
  }

  /**
   * Case 9 h: no service is replaced and the definitions keep cinatra.
   */
  public function testNoServiceReplaced(): void {
    foreach ([FALSE, TRUE] as $armed) {
      if ($armed) {
        $this->arm();
      }
      $this->assertSame(TestToolManager::class, get_class(\Drupal::service('plugin.manager.tool')));
      $this->assertSame(AccountSwitcher::class, get_class(\Drupal::service('account_switcher')));
      $definitions = \Drupal::service('plugin.manager.tool')->getDefinitions();
      foreach ([self::READ, self::WRITE] as $id) {
        $this->assertSame('cinatra', $definitions[$id]->getProvider());
      }
    }
  }

  /**
   * Case 9 i: not armed, the endpoint delegates to the inner controller.
   */
  public function testNotArmedDelegates(): void {
    $this->serve();
    $this->assertSame(self::FAKE, $this->controller());
    $control = $this->answer($this->send($this->request()));
    $built = FakeRemoteController::$built;
    $request = $this->request();
    $stack = \Drupal::requestStack();
    $stack->push($request);
    try {
      $response = DevSiteRemoteEndpoint::create(\Drupal::getContainer())->handle($request);
    }
    finally {
      $stack->pop();
    }
    $this->assertSame($built + 1, FakeRemoteController::$built);
    $this->assertSame($control, $this->answer($response));
    $request = Request::create('/_mcp_tools');
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding($request));
    $this->assertArrayNotHasKey('cinatra', $this->serversOn($request));
    $this->assertStoredServersUnchanged();
  }

}
