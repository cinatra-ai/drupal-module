<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ProfileToolGuard;
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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the profile binding, the scoped tools, the gate and the profile.
 *
 * The cinatra profile serves the two protected tools only: no resources, no
 * prompts, public components only. The gate and the profile guard ask the
 * one exposure guard, so a flag without the marker serves nothing.
 *
 * @group cinatra
 */
final class DevSiteScopedToolTest extends KernelTestBase {

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
  protected static $modules = ['system', 'user', 'cinatra', 'cinatra_dev_site'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('CINATRA_DEV_SITE');
    if (!class_exists(ToolDefinition::class)) {
      require_once dirname(__DIR__, 2) . '/fixtures/ToolDefinitionDouble.php';
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    parent::tearDown();
  }

  /**
   * The profile serves tools only, and only the two protected ones.
   */
  public function testProfileServesTheTwoToolsOnly(): void {
    $profile = CinatraDevSite::PROFILE;
    $this->assertFalse($profile['enable_resources']);
    $this->assertFalse($profile['enable_prompts']);
    $this->assertTrue($profile['component_public_only']);
    $this->assertFalse($profile['include_all_tools']);
    $this->assertFalse($profile['gateway_mode']);
    $this->assertSame([self::READ, self::WRITE], CinatraDevSite::EXPOSED_TOOLS);
    $this->assertSame(CinatraDevSite::EXPOSED_TOOLS, $profile['tools']);
    $this->assertSame('cinatra', CinatraDevSite::PROFILE_ID);
    $this->assertSame(CinatraDevSite::PROFILE_ID, $profile['id']);
    $this->assertSame('cinatra_dev_site.profile_guard:check', CinatraDevSite::GUARD_CALLBACK);
    $this->assertSame(CinatraDevSite::GUARD_CALLBACK, $profile['permission_callback']);
    // The upstream profile keys, the id and the tools; nothing else.
    $keys = array_keys($profile);
    sort($keys);
    $this->assertSame([
      'component_public_only',
      'enable_prompts',
      'enable_resources',
      'enabled',
      'gateway_mode',
      'id',
      'include_all_tools',
      'name',
      'pagination_limit',
      'permission_callback',
      'scopes',
      'tools',
      'transports',
      'version',
    ], $keys);
    // The guard service the callback names exists.
    $this->assertInstanceOf(ProfileToolGuard::class, $this->container->get('cinatra_dev_site.profile_guard'));
  }

  /**
   * The profile check is an exact match; a changed flag is refused.
   */
  public function testValidProfile(): void {
    $profile = CinatraDevSite::PROFILE;
    $this->assertTrue(CinatraDevSite::validProfile($profile));
    $this->assertTrue(CinatraDevSite::validProfile(array_reverse($profile, TRUE)));
    $invalid = [
      'resources on' => ['enable_resources' => TRUE] + $profile,
      'prompts on' => ['enable_prompts' => TRUE] + $profile,
      'all components' => ['component_public_only' => FALSE] + $profile,
      'include all' => ['include_all_tools' => TRUE] + $profile,
      'gateway' => ['gateway_mode' => TRUE] + $profile,
      'disabled' => ['enabled' => FALSE] + $profile,
      'other id' => ['id' => 'other'] + $profile,
      'other callback' => ['permission_callback' => 'other.service:check'] + $profile,
      'no callback' => ['permission_callback' => NULL] + $profile,
      'transports' => ['transports' => ['http', 'stdio']] + $profile,
      'scopes' => ['scopes' => ['read']] + $profile,
      'tools string' => ['tools' => self::READ] + $profile,
      'one tool' => ['tools' => [self::READ]] + $profile,
      'three tools' => ['tools' => [self::READ, self::WRITE, 'cinatra_third']] + $profile,
      'reversed tools' => ['tools' => [self::WRITE, self::READ]] + $profile,
      'no tools' => ['tools' => []] + $profile,
      'resources string' => ['enable_resources' => '0'] + $profile,
      'added key' => $profile + ['x' => TRUE],
    ];
    foreach (['id', 'tools', 'enable_resources', 'enable_prompts', 'component_public_only'] as $key) {
      $copy = $profile;
      unset($copy[$key]);
      $invalid['no ' . $key] = $copy;
    }
    foreach ($invalid as $label => $candidate) {
      $this->assertFalse(CinatraDevSite::validProfile($candidate), $label);
    }
    $this->assertFalse(CinatraDevSite::validProfile('cinatra'));
    $this->assertFalse(CinatraDevSite::validProfile(NULL));
  }

  /**
   * The binding takes one read and one array; anything else revokes it.
   */
  public function testProfileBinding(): void {
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $this->assertSame($request, $binding->request);
    $this->assertSame($request, $binding->request());
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());

    // A bind before any read revokes, for good.
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $binding->noteRead();
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());

    // One read, then the same array binds; key order aside. The bound
    // profile comes back with its top-level keys sorted.
    $sorted = CinatraDevSite::PROFILE;
    ksort($sorted);
    $binding = $this->validated();
    $this->assertSame($sorted, $binding->profile());
    $this->assertSame(CinatraDevSite::EXPOSED_TOOLS, $binding->tools());
    $this->assertTrue($binding->bind(array_reverse(CinatraDevSite::PROFILE, TRUE)));
    $this->assertTrue($binding->isValidated());
    $this->assertSame($sorted, $binding->profile());

    // A changed array revokes, and the original no longer binds.
    $changed = ['scopes' => ['read']] + CinatraDevSite::PROFILE;
    $this->assertFalse($binding->bind($changed));
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // Two reads before the bind revoke.
    $binding = new ProfileBinding($request);
    $binding->noteRead();
    $binding->noteRead();
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));
    $this->assertFalse($binding->isValidated());

    // A second read after the bind revokes.
    $binding = $this->validated();
    $binding->noteRead();
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // An explicit revoke.
    $binding = $this->validated();
    $binding->revoke();
    $this->assertFalse($binding->isValidated());
    $this->assertNull($binding->profile());
    $this->assertSame([], $binding->tools());

    // A bound array whose tool list is not a list, or that has no tools key
    // at all, binds no tool.
    foreach ([['tools' => self::READ], ['name' => 'cinatra']] as $profile) {
      $binding = new ProfileBinding($request);
      $binding->noteRead();
      $this->assertTrue($binding->bind($profile));
      $this->assertSame($profile, $binding->profile());
      $this->assertSame([], $binding->tools());
    }
  }

  /**
   * An unvalidated binding never reaches the inner manager.
   */
  public function testScopedManagerBeforeValidation(): void {
    $inner = $this->inner([self::READ => $this->tool(self::READ, 'cinatra')]);
    $scoped = new ScopedToolManager($inner, new ProfileBinding(Request::create('/_mcp_tools')));
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    $this->assertNull($scoped->getDefinition(self::READ, FALSE));
    $this->assertNotFound(fn() => $scoped->getDefinition(self::READ));
    $this->assertNotFound(fn() => $scoped->createInstance(self::READ));
    $this->assertFalse($scoped->getInstance(['id' => self::READ]));
    $this->assertSame([], $inner->calls);
  }

  /**
   * A list asked for before the validation does not stick after it.
   *
   * The same manager first lists nothing; once its own binding is validated
   * it lists the bound tools and reads the site's list once.
   */
  public function testScopedManagerListsOnceTheBindingIsValidated(): void {
    $inner = $this->inner([
      self::READ => $this->tool(self::READ, 'cinatra'),
      self::WRITE => $this->tool(self::WRITE, 'cinatra'),
    ]);
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $scoped = new ScopedToolManager($inner, $binding);
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    $binding->noteRead();
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
    $this->assertSame([self::READ, self::WRITE], array_keys($scoped->getDefinitions()));
    $this->assertTrue($scoped->hasDefinition(self::READ));
    $this->assertSame([['getDefinitions', []]], $inner->calls);
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
      'mcp_tools_site_tool' => $this->tool('mcp_tools_site_tool', 'mcp_tools'),
      // Ids that differ from a protected id only in case or padding.
      strtoupper(self::READ) => $this->tool(strtoupper(self::READ), 'cinatra'),
      ' ' . self::WRITE => $this->tool(' ' . self::WRITE, 'cinatra'),
      self::WRITE => $write,
    ]);
    $scoped = new ScopedToolManager($inner, $this->validated());
    $definitions = $scoped->getDefinitions();
    $this->assertSame([self::READ, self::WRITE], array_keys($definitions));
    foreach ([self::READ => $read, self::WRITE => $write] as $id => $original) {
      $this->assertInstanceOf(ToolDefinition::class, $definitions[$id]);
      $this->assertNotSame($original, $definitions[$id]);
      $this->assertSame('mcp_tools_remote', $definitions[$id]->getProvider());
      // The site-wide definition keeps its provider.
      $this->assertSame('cinatra', $original->getProvider());
      $this->assertTrue($scoped->hasDefinition($id));
      $this->assertSame($definitions[$id], $scoped->getDefinition($id));
    }
    foreach (['cinatra_other_tool', 'mcp_tools_site_tool', strtoupper(self::READ), ' ' . self::WRITE] as $id) {
      $this->assertFalse($scoped->hasDefinition($id));
      $this->assertNotFound(fn() => $scoped->getDefinition($id));
      $before = count($inner->calls);
      $this->assertNotFound(fn() => $scoped->createInstance($id));
      $this->assertCount($before, $inner->calls);
    }
    $scoped->createInstance(self::READ, ['a' => 1]);
    $this->assertSame(['createInstance', [self::READ, ['a' => 1]]], end($inner->calls));
    // The one-argument form that the upstream controller uses.
    $scoped->createInstance(self::WRITE);
    $this->assertSame(['createInstance', [self::WRITE]], end($inner->calls));
    // The inner list is read once per request.
    $reads = array_filter($inner->calls, static fn(array $call): bool => $call[0] === 'getDefinitions');
    $this->assertCount(1, $reads);
  }

  /**
   * Other providers, bare definitions and arrays are not served.
   */
  public function testScopedManagerDropsOtherDefinitions(): void {
    $inner = $this->inner([
      self::READ => $this->tool(self::READ, 'mcp_tools'),
      self::WRITE => new PluginDefinition(),
    ]);
    $this->assertSame([], (new ScopedToolManager($inner, $this->validated()))->getDefinitions());

    // A provider that differs from cinatra only in case or padding.
    foreach (['Cinatra', ' cinatra', 'cinatra '] as $provider) {
      $inner = $this->inner([
        self::READ => $this->tool(self::READ, $provider),
        self::WRITE => $this->tool(self::WRITE, 'cinatra'),
      ]);
      $scoped = new ScopedToolManager($inner, $this->validated());
      $this->assertSame([self::WRITE], array_keys($scoped->getDefinitions()), $provider);
    }

    $inner = $this->inner([
      self::READ => ['id' => self::READ, 'provider' => 'cinatra'],
      self::WRITE => $this->tool(self::WRITE, 'cinatra'),
    ]);
    $scoped = new ScopedToolManager($inner, $this->validated());
    $this->assertSame([self::WRITE], array_keys($scoped->getDefinitions()));
  }

  /**
   * A failure of the site's list propagates; nothing is served after it.
   */
  public function testScopedManagerInnerFailureServesNothing(): void {
    $inner = $this->inner([self::READ => $this->tool(self::READ, 'cinatra')], TRUE);
    $scoped = new ScopedToolManager($inner, $this->validated());
    $this->assertThrown(\RuntimeException::class, 'inner failure', fn() => $scoped->getDefinitions());
    // The list was emptied before the read: nothing is listed or created,
    // and the site's list is not read again.
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    $this->assertNotFound(fn() => $scoped->createInstance(self::READ));
    $this->assertSame([['getDefinitions', []]], $inner->calls);
  }

  /**
   * The bound tools narrow the list; a revoked binding serves nothing.
   */
  public function testScopedManagerFollowsTheBinding(): void {
    $inner = $this->inner([
      self::READ => $this->tool(self::READ, 'cinatra'),
      self::WRITE => $this->tool(self::WRITE, 'cinatra'),
      'cinatra_third' => $this->tool('cinatra_third', 'cinatra'),
    ]);
    // A bound list names only exposed tools: a third tool is never served.
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $binding->noteRead();
    $this->assertTrue($binding->bind(['tools' => [self::WRITE, 'cinatra_third']] + CinatraDevSite::PROFILE));
    $this->assertSame([self::WRITE], array_keys((new ScopedToolManager($inner, $binding))->getDefinitions()));

    $binding = $this->validated();
    $scoped = new ScopedToolManager($inner, $binding);
    $this->assertCount(2, $scoped->getDefinitions());
    $binding->revoke();
    $before = count($inner->calls);
    $this->assertSame([], $scoped->getDefinitions());
    $this->assertFalse($scoped->hasDefinition(self::READ));
    $this->assertNotFound(fn() => $scoped->createInstance(self::READ));
    $this->assertCount($before, $inner->calls);
  }

  /**
   * The gate switches only for the guard and a validated binding.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   * @param bool $active
   *   Whether the exposure is active.
   *
   * @dataProvider guardCases
   */
  public function testGateAsksTheGuard(?string $marker, mixed $armed, bool $active): void {
    $this->setMarkerAndFlag($marker, $armed);
    $account = new AnonymousUserSession();
    $switcher = $this->switcher();
    $gate = new ExecutionGate($switcher, $this->validated());
    if (!$active) {
      // A flag without the marker, or any flag but TRUE: refused before the
      // real switcher is called.
      $this->assertDenied($gate, $account);
      $this->assertSame([], $switcher->calls);
      return;
    }
    $this->assertSame($gate, $gate->switchTo($account));
    $this->assertSame([['switchTo', $account]], $switcher->calls);
    $this->assertSame($gate, $gate->switchBack());
    $this->assertSame(['switchBack'], end($switcher->calls));
  }

  /**
   * The gate refuses an unvalidated binding, and reverts only its switches.
   */
  public function testGateNeedsValidatedBinding(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $account = new AnonymousUserSession();
    $switcher = $this->switcher();
    $this->assertDenied(new ExecutionGate($switcher, new ProfileBinding(Request::create('/_mcp_tools'))), $account);
    $binding = $this->validated();
    $binding->revoke();
    $this->assertDenied(new ExecutionGate($switcher, $binding), $account);
    $this->assertSame([], $switcher->calls);

    // A switch back without a switch of this gate never reaches the real
    // switcher, whose stack may hold another caller's switch.
    $gate = new ExecutionGate($switcher, $this->validated());
    try {
      $gate->switchBack();
      $this->fail('The gate reverted a switch it did not make.');
    }
    catch (\LogicException $e) {
      $this->assertSame([], $switcher->calls);
    }
    $gate->switchTo($account);
    $gate->switchBack();
    try {
      $gate->switchBack();
      $this->fail('The gate reverted one switch twice.');
    }
    catch (\LogicException $e) {
      $this->assertSame([['switchTo', $account], ['switchBack']], $switcher->calls);
    }
  }

  /**
   * The gate counts a switch only after the site's switcher made it.
   */
  public function testGateCountsOnlyCompletedSwitches(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $account = new AnonymousUserSession();
    $switcher = $this->switcher();
    $gate = new ExecutionGate($switcher, $this->validated());
    // A failed switch is not counted, so there is nothing to revert.
    $switcher->fail = 'switchTo';
    $this->assertThrown(\RuntimeException::class, 'switcher failure', fn() => $gate->switchTo($account));
    $switcher->fail = NULL;
    $this->assertThrown(\LogicException::class, NULL, fn() => $gate->switchBack());
    $this->assertSame([['switchTo', $account]], $switcher->calls);

    // A failed switch back is counted as reverted: a second one is refused
    // and never reaches the site's switcher.
    $gate->switchTo($account);
    $switcher->fail = 'switchBack';
    $this->assertThrown(\RuntimeException::class, 'switcher failure', fn() => $gate->switchBack());
    $switcher->fail = NULL;
    $this->assertThrown(\LogicException::class, NULL, fn() => $gate->switchBack());
    $this->assertSame([['switchTo', $account], ['switchTo', $account], ['switchBack']], $switcher->calls);
  }

  /**
   * The gate reverts each of its nested switches once, also after a disarm.
   *
   * A switch back only reverts a switch that this gate made, so it does not
   * ask the exposure guard: a site disarmed between the switch and the switch
   * back still gets its account back.
   */
  public function testGateRevertsNestedSwitchesAfterDisarm(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $account = new AnonymousUserSession();
    $switcher = $this->switcher();
    $gate = new ExecutionGate($switcher, $this->validated());
    $gate->switchTo($account);
    $gate->switchTo($account);
    $this->setMarkerAndFlag('1', FALSE);
    $this->assertDenied($gate, $account);
    $gate->switchBack();
    $gate->switchBack();
    $this->assertThrown(\LogicException::class, NULL, fn() => $gate->switchBack());
    $this->assertSame([
      ['switchTo', $account],
      ['switchTo', $account],
      ['switchBack'],
      ['switchBack'],
    ], $switcher->calls);
  }

  /**
   * The profile guard asks the exposure guard; a refusal revokes the binding.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   * @param bool $active
   *   Whether the exposure is active.
   *
   * @dataProvider guardCases
   */
  public function testProfileGuardAsksTheGuard(?string $marker, mixed $armed, bool $active): void {
    $this->setMarkerAndFlag($marker, $armed);
    [$request, $binding] = $this->current();
    $binding->noteRead();
    $guard = $this->container->get('cinatra_dev_site.profile_guard');
    $this->assertSame($active, $guard->check($request, CinatraDevSite::PROFILE));
    $this->assertSame($active, $binding->isValidated());
    if (!$active) {
      // Revoked for the rest of the request: a later check cannot bind.
      $this->setMarkerAndFlag('1', TRUE);
      $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));
      $this->assertFalse($binding->isValidated());
    }
  }

  /**
   * The profile guard refuses every other request, binding or profile.
   */
  public function testProfileGuardRefusals(): void {
    $this->setMarkerAndFlag('1', TRUE);
    $guard = $this->container->get('cinatra_dev_site.profile_guard');
    $this->assertFalse($guard->check(NULL, CinatraDevSite::PROFILE));

    // The current request without a binding.
    [$request] = $this->current();
    $request->attributes->remove(CinatraDevSite::BINDING_ATTRIBUTE);
    $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));

    // The attribute holds something other than a binding.
    foreach (['binding', 1, new \stdClass()] as $value) {
      [$request, $binding] = $this->current();
      $binding->noteRead();
      $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $value);
      $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));
      $this->assertSame($value, $request->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE));
      $this->assertFalse($binding->isValidated());
    }

    // A binding made for another request is not this request's binding.
    [$request] = $this->current();
    $other = new ProfileBinding(Request::create('/_mcp_tools'));
    $other->noteRead();
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $other);
    $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE));
    $this->assertFalse($other->isValidated());

    // A request that is not the current one; its binding is revoked.
    $stale = Request::create('/_mcp_tools', 'POST');
    $binding = new ProfileBinding($stale);
    $stale->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $binding->noteRead();
    $this->current();
    $this->assertFalse($guard->check($stale, CinatraDevSite::PROFILE));
    $this->assertFalse($binding->bind(CinatraDevSite::PROFILE));

    // A changed profile, no read, or two reads: refused and revoked.
    $cases = [
      'resources on' => [1, ['enable_resources' => TRUE] + CinatraDevSite::PROFILE],
      'prompts on' => [1, ['enable_prompts' => TRUE] + CinatraDevSite::PROFILE],
      'all components' => [1, ['component_public_only' => FALSE] + CinatraDevSite::PROFILE],
      'no read' => [0, CinatraDevSite::PROFILE],
      'two reads' => [2, CinatraDevSite::PROFILE],
    ];
    foreach ($cases as $label => [$reads, $profile]) {
      [$request, $binding] = $this->current();
      for ($i = 0; $i < $reads; $i++) {
        $binding->noteRead();
      }
      $this->assertFalse($guard->check($request, $profile), $label);
      $this->assertFalse($binding->isValidated(), $label);
      $this->assertFalse($guard->check($request, CinatraDevSite::PROFILE), $label);
    }

    // The control: the current request, its binding, one read, the profile.
    [$request, $binding] = $this->current();
    $binding->noteRead();
    $this->assertTrue($guard->check($request, CinatraDevSite::PROFILE));
    $this->assertTrue($binding->isValidated());
  }

  /**
   * Marker and flag values, and whether the exposure is active.
   *
   * @return array<string, array{string|null, mixed, bool}>
   *   The cases of the exposure gate test.
   */
  public static function guardCases(): array {
    return DevSiteExposureGateTest::guardCases();
  }

  /**
   * Pushes a new current request that carries its own binding.
   *
   * @return array{\Symfony\Component\HttpFoundation\Request, \Drupal\cinatra_dev_site\Mcp\ProfileBinding}
   *   The request and its binding.
   */
  private function current(): array {
    $request = Request::create('/_mcp_tools', 'POST');
    // On Drupal 11 the parent tearDown() reads the session of the current
    // request.
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request->setSession($session);
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $this->container->get('request_stack')->push($request);
    return [$request, $binding];
  }

  /**
   * Builds a binding that counted one read and bound the profile.
   *
   * @return \Drupal\cinatra_dev_site\Mcp\ProfileBinding
   *   The validated binding.
   */
  private function validated(): ProfileBinding {
    $binding = new ProfileBinding(Request::create('/_mcp_tools'));
    $binding->noteRead();
    $this->assertTrue($binding->bind(CinatraDevSite::PROFILE));
    $this->assertTrue($binding->isValidated());
    return $binding;
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
   * Builds a recording inner manager over the given definitions.
   *
   * @param array $definitions
   *   The definitions keyed by id.
   * @param bool $fails
   *   Whether getDefinitions() throws after it recorded the call.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   The recording double; its public $calls lists every call.
   */
  private function inner(array $definitions, bool $fails = FALSE): PluginManagerInterface {
    return new class($definitions, $fails) implements PluginManagerInterface {

      /**
       * The recorded calls, each a method name and its arguments.
       *
       * @var array
       */
      public array $calls = [];

      /**
       * Constructs the double.
       *
       * @param array $definitions
       *   The definitions.
       * @param bool $fails
       *   Whether getDefinitions() throws.
       */
      public function __construct(private array $definitions, private bool $fails) {
      }

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
        if ($this->fails) {
          throw new \RuntimeException('inner failure');
        }
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
       * The method that throws after it recorded the call, or NULL.
       *
       * @var string|null
       */
      public ?string $fail = NULL;

      /**
       * {@inheritdoc}
       */
      public function switchTo(AccountInterface $account) {
        $this->calls[] = ['switchTo', $account];
        if ($this->fail === 'switchTo') {
          throw new \RuntimeException('switcher failure');
        }
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function switchBack() {
        $this->calls[] = ['switchBack'];
        if ($this->fail === 'switchBack') {
          throw new \RuntimeException('switcher failure');
        }
        return $this;
      }

    };
  }

  /**
   * Asserts that the gate refuses without calling the real switcher.
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
      $this->assertSame(CinatraDevSite::DENY_TEXT, $e->getMessage());
    }
  }

  /**
   * Asserts that a call throws an exception of a class, with a message.
   *
   * @param string $class
   *   The exception class.
   * @param string|null $message
   *   The message, or NULL for any message.
   * @param callable $call
   *   The call.
   */
  private function assertThrown(string $class, ?string $message, callable $call): void {
    $thrown = NULL;
    try {
      $call();
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf($class, $thrown);
    if ($message !== NULL) {
      $this->assertSame($message, $thrown->getMessage());
    }
  }

  /**
   * Asserts that a call throws PluginNotFoundException.
   *
   * @param callable $call
   *   The call.
   */
  private function assertNotFound(callable $call): void {
    try {
      $call();
      $this->fail('No PluginNotFoundException was thrown.');
    }
    catch (PluginNotFoundException $e) {
      $this->addToAssertionCount(1);
    }
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
