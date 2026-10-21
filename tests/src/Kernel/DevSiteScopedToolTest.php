<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ScopedToolContainer;
use Drupal\cinatra_dev_site\Mcp\ScopedToolManager;
use Drupal\Component\Plugin\Definition\PluginDefinition;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Tool\ToolDefinition;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves the per-request binding, tool manager, gate and container view.
 *
 * @group cinatra
 */
final class DevSiteScopedToolTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'cinatra', 'cinatra_dev_site'];

  /**
   * The read tool id.
   */
  private const READ = 'cinatra_read_protected_revision';

  /**
   * The write tool id.
   */
  private const WRITE = 'cinatra_write_protected_draft';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists(ToolDefinition::class)) {
      require_once dirname(__DIR__, 2) . '/fixtures/ToolDefinitionDouble.php';
    }
  }

  /**
   * Builds a tool definition.
   *
   * @param string $id
   *   The id.
   * @param string $provider
   *   The provider.
   *
   * @return \Drupal\tool\Tool\ToolDefinition
   *   The definition.
   */
  private function tool(string $id, string $provider): ToolDefinition {
    return new ToolDefinition([
      'id' => $id,
      'class' => PluginDefinition::class,
      'provider' => $provider,
    ]);
  }

  /**
   * Builds a binding that counted one read and bound the given profile.
   *
   * @param array $profile
   *   The profile.
   *
   * @return \Drupal\cinatra_dev_site\Mcp\ProfileBinding
   *   The validated binding.
   */
  private function validated(array $profile): ProfileBinding {
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $binding->noteRead();
    $this->assertTrue($binding->bind($profile));
    $this->assertTrue($binding->isValidated());
    return $binding;
  }

  /**
   * Builds a recording inner manager over the given definitions.
   *
   * @param array $definitions
   *   The definitions keyed by id.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   The recording double; its public $calls lists every call.
   */
  private function inner(array $definitions): PluginManagerInterface {
    return new class($definitions) implements PluginManagerInterface {

      /**
       * The recorded calls, each a list of method name and arguments.
       *
       * @var array
       */
      public array $calls = [];

      /**
       * Constructs the double.
       *
       * @param array $definitions
       *   The definitions.
       */
      public function __construct(
        private array $definitions,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
        $this->calls[] = ['getDefinition', func_get_args()];
        return $this->definitions[$plugin_id] ?? NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function getDefinitions() {
        $this->calls[] = ['getDefinitions', []];
        return $this->definitions;
      }

      /**
       * {@inheritdoc}
       */
      public function hasDefinition($plugin_id) {
        $this->calls[] = ['hasDefinition', func_get_args()];
        return isset($this->definitions[$plugin_id]);
      }

      /**
       * {@inheritdoc}
       */
      public function createInstance($plugin_id, array $configuration = []) {
        $this->calls[] = ['createInstance', func_get_args()];
        return new \stdClass();
      }

      /**
       * {@inheritdoc}
       */
      public function getInstance(array $options) {
        $this->calls[] = ['getInstance', func_get_args()];
        return FALSE;
      }

    };
  }

  /**
   * Builds a recording account switcher.
   *
   * @return \Drupal\Core\Session\AccountSwitcherInterface
   *   The recording double; its public $calls lists every call.
   */
  private function switcher(): AccountSwitcherInterface {
    return new class() implements AccountSwitcherInterface {

      /**
       * The recorded calls.
       *
       * @var array
       */
      public array $calls = [];

      /**
       * {@inheritdoc}
       */
      public function switchTo(AccountInterface $account) {
        $this->calls[] = ['switchTo', $account];
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function switchBack() {
        $this->calls[] = ['switchBack'];
        return $this;
      }

    };
  }

  /**
   * The binding counts reads and binds one array for the request.
   */
  public function testProfileBinding(): void {
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $this->assertSame($request, $binding->request());
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());
    $binding->noteRead();
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE), 'A revoked binding stays revoked.');

    $binding = new ProfileBinding($request);
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());
    $binding->noteRead();
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
    $this->assertTrue($binding->isValidated());
    $this->assertSame(CinatraDevSite::EXPOSED_TOOLS, $binding->tools());
    $reordered = array_reverse(CinatraDevSite::PROFILE, TRUE);
    $this->assertTrue($binding->bind($reordered));
    $this->assertTrue($binding->isValidated());
    $other = CinatraDevSite::PROFILE;
    $other['scopes'] = ['read'];
    $this->assertFalse($binding->bind($other));
    $this->assertFalse($binding->isValidated());
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());

    $binding = new ProfileBinding($request);
    $binding->noteRead();
    $binding->noteRead();
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());

    $binding = new ProfileBinding($request);
    $binding->noteRead();
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
    $binding->revoke();
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());
  }

  /**
   * An unvalidated binding never reaches the inner manager.
   */
  public function testScopedManagerBeforeValidation(): void {
    $inner = $this->inner([self::READ => $this->tool(self::READ, 'cinatra')]);
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $scoped = new ScopedToolManager($inner, $binding);
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    try {
      $scoped->getDefinition(self::READ);
      $this->fail('getDefinition() did not throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
    $this->assertNull($scoped->getDefinition(self::READ, FALSE));
    try {
      $scoped->createInstance(self::READ);
      $this->fail('createInstance() did not throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame([], $inner->calls);
  }

  /**
   * A validated binding serves only the bound cinatra tools, as clones.
   */
  public function testScopedManagerAfterValidation(): void {
    $read = $this->tool(self::READ, 'cinatra');
    $write = $this->tool(self::WRITE, 'cinatra');
    $inner = $this->inner([
      self::READ => $read,
      'cinatra_other_tool' => $this->tool('cinatra_other_tool', 'cinatra'),
      self::WRITE => $write,
    ]);
    $scoped = new ScopedToolManager($inner, $this->validated(CinatraDevSite::PROFILE));
    $definitions = $scoped->getDefinitions();
    $this->assertSame([self::READ, self::WRITE], array_keys($definitions));
    foreach ([self::READ => $read, self::WRITE => $write] as $id => $original) {
      $this->assertInstanceOf(ToolDefinition::class, $definitions[$id]);
      $this->assertSame('mcp_tools_remote', $definitions[$id]->getProvider());
      $this->assertNotSame($original, $definitions[$id]);
      $this->assertSame('cinatra', $original->getProvider());
      $this->assertTrue($scoped->hasDefinition($id));
      $this->assertSame($definitions[$id], $scoped->getDefinition($id));
    }
    $this->assertFalse($scoped->hasDefinition('cinatra_other_tool'));
    try {
      $scoped->getDefinition('cinatra_other_tool');
      $this->fail('getDefinition() of another id did not throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
    $before = count($inner->calls);
    try {
      $scoped->createInstance('cinatra_other_tool');
      $this->fail('createInstance() of another id did not throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
    $this->assertCount($before, $inner->calls);
    $scoped->createInstance(self::READ, ['a' => 1], 'invoker');
    $this->assertSame(['createInstance', [self::READ, ['a' => 1], 'invoker']], end($inner->calls));
    $this->assertFalse($scoped->getInstance(['id' => self::READ]));
  }

  /**
   * Other providers, bare definitions and arrays are not served.
   */
  public function testScopedManagerDropsOtherDefinitions(): void {
    $inner = $this->inner([
      self::READ => $this->tool(self::READ, 'mcp_tools'),
      self::WRITE => new PluginDefinition(),
    ]);
    $scoped = new ScopedToolManager($inner, $this->validated(CinatraDevSite::PROFILE));
    $this->assertSame([], $scoped->getDefinitions());

    $inner = $this->inner([
      self::READ => ['id' => self::READ, 'provider' => 'cinatra'],
      self::WRITE => $this->tool(self::WRITE, 'cinatra'),
    ]);
    $scoped = new ScopedToolManager($inner, $this->validated(CinatraDevSite::PROFILE));
    $this->assertSame([self::WRITE], array_keys($scoped->getDefinitions()));
  }

  /**
   * The bound tools narrow the list; a revoked binding serves nothing.
   */
  public function testScopedManagerFollowsTheBinding(): void {
    $inner = $this->inner([
      self::READ => $this->tool(self::READ, 'cinatra'),
      self::WRITE => $this->tool(self::WRITE, 'cinatra'),
    ]);
    $profile = CinatraDevSite::PROFILE;
    $profile['tools'] = [self::WRITE];
    $scoped = new ScopedToolManager($inner, $this->validated($profile));
    $this->assertSame([self::WRITE], array_keys($scoped->getDefinitions()));

    $binding = $this->validated(CinatraDevSite::PROFILE);
    $scoped = new ScopedToolManager($inner, $binding);
    $this->assertCount(2, $scoped->getDefinitions());
    $binding->revoke();
    $before = count($inner->calls);
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    try {
      $scoped->createInstance(self::READ);
      $this->fail('createInstance() after revoke() did not throw.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
    $this->assertCount($before, $inner->calls);
  }

  /**
   * The gate switches only for a validated binding on an armed site.
   */
  public function testExecutionGate(): void {
    $account = new AnonymousUserSession();
    $state = $this->container->get('state');

    $state->delete(CinatraDevSite::ARMED_KEY);
    $switcher = $this->switcher();
    $gate = new ExecutionGate($switcher, $this->validated(CinatraDevSite::PROFILE));
    $this->assertDenied($gate, $account);
    $this->assertSame([], $switcher->calls);

    $state->set(CinatraDevSite::ARMED_KEY, TRUE);
    $switcher = $this->switcher();
    $gate = new ExecutionGate($switcher, new ProfileBinding(Request::create('/_mcp_tools')));
    $this->assertDenied($gate, $account);
    $this->assertSame([], $switcher->calls);

    $binding = $this->validated(CinatraDevSite::PROFILE);
    $binding->revoke();
    $gate = new ExecutionGate($switcher, $binding);
    $this->assertDenied($gate, $account);
    $this->assertSame([], $switcher->calls);

    $gate = new ExecutionGate($switcher, $this->validated(CinatraDevSite::PROFILE));
    $this->assertSame($gate, $gate->switchTo($account));
    $this->assertSame([['switchTo', $account]], $switcher->calls);
    $gate->switchBack();
    $this->assertSame(['switchBack'], end($switcher->calls));

    $state->delete(CinatraDevSite::ARMED_KEY);
    $denied = new ExecutionGate($switcher, new ProfileBinding(Request::create('/_mcp_tools')));
    $denied->switchBack();
    $this->assertSame(['switchBack'], end($switcher->calls));
  }

  /**
   * Asserts that the gate denies without calling the switcher.
   *
   * @param \Drupal\cinatra_dev_site\Mcp\ExecutionGate $gate
   *   The gate.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   */
  private function assertDenied(ExecutionGate $gate, AccountInterface $account): void {
    try {
      $gate->switchTo($account);
      $this->fail('The gate switched.');
    }
    catch (ExposureDeniedException $e) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * The container view overrides two ids and is read-only.
   */
  public function testScopedToolContainer(): void {
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $scoped = new ScopedToolManager($this->inner([]), $binding);
    $gate = new ExecutionGate($this->switcher(), $binding);
    $view = new ScopedToolContainer($this->container, $scoped, $gate);
    $this->assertSame($scoped, $view->get('plugin.manager.tool'));
    $this->assertSame($gate, $view->get('account_switcher'));
    $this->assertTrue($view->has('plugin.manager.tool'));
    $this->assertTrue($view->has('account_switcher'));
    $this->assertSame($this->container->get('state'), $view->get('state'));
    $this->assertSame($this->container->has('state'), $view->has('state'));
    $this->assertSame($this->container->hasParameter('cinatra_dev_site.remote_controller'), $view->hasParameter('cinatra_dev_site.remote_controller'));
    try {
      $view->set('state', NULL);
      $this->fail('set() did not throw.');
    }
    catch (\LogicException $e) {
      $this->addToAssertionCount(1);
    }
    try {
      $view->setParameter('x', 'y');
      $this->fail('setParameter() did not throw.');
    }
    catch (\LogicException $e) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * The profile check is an exact match.
   */
  public function testValidProfile(): void {
    $profile = CinatraDevSite::PROFILE;
    $this->assertTrue(CinatraDevSite::validProfile($profile));
    $this->assertTrue(CinatraDevSite::validProfile(array_reverse($profile, TRUE)));

    $invalid = [];
    $copy = $profile;
    unset($copy['id']);
    $invalid['no id'] = $copy;
    $invalid['other id'] = ['id' => 'other'] + $profile;
    $copy = $profile;
    unset($copy['permission_callback']);
    $invalid['no callback'] = $copy;
    $invalid['other callback'] = ['permission_callback' => 'other.service:check'] + $profile;
    $copy = $profile;
    unset($copy['tools']);
    $invalid['no tools'] = $copy;
    $invalid['tools string'] = ['tools' => self::READ] + $profile;
    $invalid['one tool'] = ['tools' => [self::READ]] + $profile;
    $invalid['three tools'] = ['tools' => [self::READ, self::WRITE, 'cinatra_dev_site_test_third']] + $profile;
    $invalid['duplicate'] = ['tools' => [self::READ, self::READ]] + $profile;
    $invalid['unknown'] = ['tools' => ['cinatra_unknown']] + $profile;
    $invalid['empty'] = ['tools' => []] + $profile;
    $invalid['reverse'] = ['tools' => [self::WRITE, self::READ]] + $profile;
    $invalid['include all'] = ['include_all_tools' => TRUE] + $profile;
    $invalid['gateway'] = ['gateway_mode' => TRUE] + $profile;
    $invalid['disabled'] = ['enabled' => FALSE] + $profile;
    $invalid['transports'] = ['transports' => ['http', 'stdio']] + $profile;
    $invalid['scopes'] = ['scopes' => ['read']] + $profile;
    $invalid['resources'] = ['enable_resources' => FALSE] + $profile;
    $invalid['added key'] = $profile + ['x' => TRUE];
    foreach ($invalid as $label => $candidate) {
      $this->assertFalse(CinatraDevSite::validProfile($candidate), $label);
    }
    $this->assertFalse(CinatraDevSite::validProfile('cinatra'));
    $this->assertFalse(CinatraDevSite::validProfile(NULL));
  }

  /**
   * The exposed tools are exactly the two protected revision tools.
   */
  public function testExposedTools(): void {
    $this->assertSame([self::READ, self::WRITE], CinatraDevSite::EXPOSED_TOOLS);
    $this->assertSame(CinatraDevSite::EXPOSED_TOOLS, CinatraDevSite::PROFILE['tools']);
  }

}
