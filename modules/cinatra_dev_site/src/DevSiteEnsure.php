<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site;

use Drupal\cinatra_dev_site\Exposure\Census;
use Drupal\cinatra_dev_site\Exposure\ExposureVerifier;
use Drupal\cinatra_dev_site\Page\PageMigrator;
use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\tool\Tool\ToolDefinition;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;

/**
 * Checks, binds, migrates, verifies and then arms the development site.
 *
 * Both ensure() and release() take the same named lock, and refuse as busy
 * a call made while this service runs either of them, from before the lock
 * is taken until after it is released. ensure() removes the armed flag as
 * its first write and sets it as its last: after the verify step, the
 * census and the log line passed inside a scope of this process only, after
 * its result encoded as JSON, and only while it still holds the lock. After
 * the arm it reads the stored editorial record again and removes the flag
 * when the record no longer binds node:page; release() removes the flag
 * again after its unbind. So when a release that outlived its lock unbinds
 * while another run arms, and both return, the flag is unset; the site is
 * armed while unbound only from the later of that arm and that unbind until
 * the read after the arm or the release's second removal. Every failed run
 * that reaches its cleanup removes the flag there, unless that removal
 * throws. The flag's state after a throwing State write is not listed here:
 * core's State writes its key/value storage before its cache, so a set() or
 * a delete() that throws may have persisted.
 */
final class DevSiteEnsure {

  /**
   * The lock that ensure() and release() share.
   */
  public const LOCK = 'cinatra_dev_site.ensure';

  /**
   * The lock's lifetime in seconds: the connector's TERM time of ensure().
   *
   * The connector runs ensure() under `timeout -k 10 300`, so a process that
   * ignores TERM can live about 310 seconds. A run that lasts longer than
   * the lifetime arms only when it still holds the lock: it renews the lock
   * right before the arm.
   */
  public const LOCK_TIMEOUT = 300.0;

  /**
   * The fixed text of an uninstall that release() refused as busy.
   */
  public const BUSY_TEXT = 'cinatra_dev_site: ensure() holds the lock; the module was not uninstalled.';

  /**
   * The fixed text of a run whose lock lapsed before the arm.
   */
  public const LAPSED_TEXT = 'cinatra_dev_site: the lock of ensure() lapsed before the arm; the site was not armed.';

  /**
   * The fixed text of a run whose page binding was gone after the arm.
   */
  public const UNBOUND_TEXT = 'cinatra_dev_site: the stored editorial record did not bind node:page after the arm; the flag was removed.';

  /**
   * The remote settings, whose server_id the override sets while active.
   */
  private const SETTINGS = 'mcp_tools_remote.settings';

  /**
   * The answer of ensure() to a call while the lock is held or this runs.
   */
  private const BUSY_ENSURE = ['ok' => FALSE, 'steps' => [], 'refused' => ['busy'], 'census' => []];

  /**
   * The answer of release() to a call while the lock is held or this runs.
   */
  private const BUSY_RELEASE = ['ok' => FALSE, 'steps' => [], 'refused' => ['busy']];

  /**
   * Whether this service runs ensure() or release() now.
   *
   * It is TRUE from before the lock is taken until after it is released, so
   * a call that the lock backend makes inside either is busy too.
   *
   * @var bool
   */
  private bool $running = FALSE;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $routerBuilder
   *   The router builder.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   * @param \Drupal\cinatra_dev_site\Workflow\EditorialBinder $binder
   *   The editorial binder.
   * @param \Drupal\cinatra_dev_site\Page\PageMigrator $migrator
   *   The page migrator.
   * @param \Drupal\cinatra_dev_site\Exposure\ExposureVerifier $verifier
   *   The exposure verifier.
   * @param \Drupal\cinatra_dev_site\Exposure\Census $census
   *   The census.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $toolManager
   *   The site's tool manager, or NULL when it is absent.
   * @param object|null $repository
   *   The server configuration repository, or NULL when it is absent.
   * @param string $remoteController
   *   The upstream remote controller class.
   * @param int $lockWait
   *   How many seconds release() waits for the lock.
   */
  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteBuilderInterface $routerBuilder,
    private readonly RouteProviderInterface $routeProvider,
    private readonly EditorialBinder $binder,
    private readonly PageMigrator $migrator,
    private readonly ExposureVerifier $verifier,
    private readonly Census $census,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ?PluginManagerInterface $toolManager,
    private readonly ?object $repository,
    private readonly string $remoteController,
    private readonly int $lockWait,
  ) {
  }

  /**
   * Checks everything, then binds, migrates, verifies and arms last.
   *
   * @return array
   *   'ok' (TRUE only when the site is armed and verified), 'steps',
   *   'refused' (the codes; ['busy'] when the lock is held elsewhere or this
   *   service runs already) and 'census'. A throwable passes up after the
   *   cleanup.
   */
  public function ensure(): array {
    if ($this->running) {
      return self::BUSY_ENSURE;
    }
    $this->running = TRUE;
    try {
      if (!$this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)) {
        return self::BUSY_ENSURE;
      }
      try {
        return $this->run();
      }
      finally {
        try {
          $this->lock->release(self::LOCK);
        }
        catch (\Throwable $e) {
          // A release that throws fails the call, also after the arm.
          $this->cleanup(NULL, TRUE);
          throw $e;
        }
      }
    }
    finally {
      $this->running = FALSE;
    }
  }

  /**
   * Disarms the site and removes the binding it owns, under the lock.
   *
   * @param bool $is_syncing
   *   TRUE when a configuration import uninstalls the module.
   *
   * @return array
   *   'ok' and 'steps'; 'ok' FALSE with 'refused' ['busy'] when the lock
   *   stays held for the wait or this service runs already, and then
   *   nothing is written. A failure of the binder passes up after the flag
   *   was removed. The flag is removed again after the binder returns.
   */
  public function release(bool $is_syncing): array {
    if ($this->running) {
      return self::BUSY_RELEASE;
    }
    $this->running = TRUE;
    try {
      $held = $this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)
        || (!$this->lock->wait(self::LOCK, $this->lockWait) && $this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT));
      if (!$held) {
        return self::BUSY_RELEASE;
      }
      try {
        CinatraDevSite::disarm();
        $this->configFactory->reset(self::SETTINGS);
        $binding = $this->binder->release($is_syncing);
        // This lock may have lapsed in a slow save; a run of ensure() that
        // took it since may have armed: the flag goes again after the unbind.
        CinatraDevSite::disarm();
        return ['ok' => TRUE, 'steps' => ['disarmed', 'binding:' . $binding], 'refused' => []];
      }
      finally {
        $this->lock->release(self::LOCK);
      }
    }
    finally {
      $this->running = FALSE;
    }
  }

  /**
   * Runs the preflight and the apply steps; the caller holds the lock.
   *
   * @return array
   *   The result of ensure().
   */
  private function run(): array {
    // The first write. If it throws, nothing else of the run happens.
    CinatraDevSite::disarm();
    $ok = FALSE;
    $cleaned = FALSE;
    $applying = FALSE;
    $before = NULL;
    try {
      $before = $this->endpointRoute();
      $refused = $this->preflight($before);
      $refused = array_merge($refused, $this->binder->preflight());
      $plan = $this->migrator->plan();
      $refused = array_values(array_unique(array_merge($refused, $plan['refused'])));
      if ($refused !== []) {
        // Give a site armed earlier the stock route before the census.
        $this->cleanup($before, FALSE);
        $cleaned = TRUE;
        return $this->finish(FALSE, [], $refused, $this->census->read());
      }
      $applying = TRUE;
      $steps = [];
      try {
        $applied = $this->binder->bind();
      }
      catch (\Throwable) {
        // bind() undid the writes of this call before it threw, or it kept
        // the intent of an earlier run for the next bind() to complete.
        return $this->finish(FALSE, $steps, ['workflow_failed'], $this->census->read());
      }
      $steps = array_merge($steps, $applied['created'] ? ['workflow:created'] : [], $applied['bound'] ? ['workflow:bound'] : []);
      $migration = $this->migrator->migrate($plan['plans']);
      foreach ($migration['migrated'] as $nid) {
        $steps[] = 'migrated:' . $nid;
      }
      if (!$migration['ok']) {
        if ($migration['migrated'] === []) {
          $this->binder->undo($applied);
        }
        return $this->finish(FALSE, $steps, $migration['refused'], $this->census->read());
      }
      $result = CinatraDevSite::verifying(function () use ($before, $steps): array {
        $this->configFactory->reset(self::SETTINGS);
        $this->routerBuilder->rebuild();
        $failed = $this->verifier->verify($before);
        $refused = $failed === [] ? [] : array_merge(['exposure_unverified'], $failed);
        $steps = $failed === [] ? array_merge($steps, ['verified']) : $steps;
        return $this->finish($failed === [], $steps, $refused, $this->census->read());
      });
      if ($result['ok']) {
        // A lock that lapsed in a slow step may be held elsewhere now.
        if (!$this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)) {
          throw new \RuntimeException(self::LAPSED_TEXT);
        }
        // The last write of the run.
        CinatraDevSite::arm();
        // A release whose lock lapsed may have unbound the pages since the
        // verify step; the cleanup then removes the flag.
        if (!self::bindsPage($this->editorialRecord())) {
          throw new \RuntimeException(self::UNBOUND_TEXT);
        }
        $ok = TRUE;
      }
      return $result;
    }
    finally {
      if (!$ok && !$cleaned) {
        $this->cleanup($before, $applying);
      }
    }
  }

  /**
   * Removes the flag and gives the endpoint route back to the stock one.
   *
   * The flag is removed first; a throw there passes up.
   *
   * @param \Symfony\Component\Routing\Route|null $before
   *   The endpoint route as the run read it, or NULL when it was absent,
   *   the read threw or the read did not happen.
   * @param bool $applied
   *   Whether the run reached the apply steps, whose verify scope rebuilt
   *   the router.
   */
  private function cleanup(?Route $before, bool $applied): void {
    CinatraDevSite::disarm();
    $this->configFactory->reset(self::SETTINGS);
    $controller = $before?->getDefault('_controller');
    $endpoint = is_string($controller) && ltrim($controller, '\\') === ltrim(CinatraDevSite::ENDPOINT_CONTROLLER, '\\');
    if (!$applied && $before !== NULL && !$endpoint) {
      // The run read a stored route that is not the endpoint and changed no
      // route: nothing to rebuild.
      return;
    }
    try {
      $this->routerBuilder->rebuild();
    }
    catch (\Throwable) {
      // The stored route stays; it reaches only the endpoint's not-active
      // path. A throwable that ended the run passes up unchanged.
    }
  }

  /**
   * Collects the refusals that do not depend on the workflow or the pages.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The endpoint route as read now, or NULL when it is absent.
   *
   * @return string[]
   *   The codes, in the order of the checks.
   */
  private function preflight(?Route $route): array {
    $refused = CinatraDevSite::markerPresent() ? [] : ['no_marker'];
    if ($this->toolManager === NULL) {
      $refused[] = 'tool_manager_absent';
    }
    // A method that this class cannot call, such as a private one, counts as
    // absent.
    if ($this->repository === NULL || !is_callable([$this->repository, 'getServer'])) {
      $refused[] = 'server_config_repository_absent';
    }
    $definitions = $this->toolManager?->getDefinitions() ?? [];
    foreach ($this->toolManager === NULL ? [] : CinatraDevSite::EXPOSED_TOOLS as $id) {
      if (!isset($definitions[$id])) {
        $refused[] = 'tool_definition_missing:' . $id;
      }
      elseif (!$definitions[$id] instanceof ToolDefinition || $definitions[$id]->getProvider() !== 'cinatra') {
        $refused[] = 'tool_provider_unexpected:' . $id;
      }
    }
    $allowed = [
      ltrim($this->remoteController, '\\') . '::handle',
      ltrim(CinatraDevSite::ENDPOINT_CONTROLLER, '\\'),
    ];
    // Only a string names a controller; any other value refuses, also an
    // object whose string is an allowed name.
    $controller = $route?->getDefault('_controller');
    if (!is_string($controller) || !in_array(ltrim($controller, '\\'), $allowed, TRUE)) {
      $refused[] = 'endpoint_route_unexpected';
    }
    // drupal/mcp_tools stores the profiles under one of two names, by
    // release. With both stored, which one the installed release reads is
    // not known here, so the run refuses.
    $stored = CinatraDevSite::storedServersConfigs($this->configFactory);
    $servers = $this->configFactory->get($stored[0] ?? CinatraDevSite::SERVERS_CONFIG);
    $settings = $this->configFactory->get(self::SETTINGS);
    if ($stored === [] || $settings->isNew()) {
      $refused[] = 'mcp_config_absent';
    }
    if (count($stored) > 1) {
      $refused[] = 'mcp_config_ambiguous';
    }
    // The stored records without overrides. A key is read as absent only
    // when the record lacks it: a stored NULL is a value, not an absent key.
    $stored_settings = $settings->getOriginal('', FALSE);
    $stored_servers = $servers->getOriginal('', FALSE);
    // Only FALSE or an absent key passes; 1, '1' or NULL refuses.
    if (array_key_exists('include_all_tools', $stored_settings) && $stored_settings['include_all_tools'] !== FALSE) {
      $refused[] = 'include_all_tools_true';
    }
    if ($stored !== [] && !is_array($stored_servers['servers'] ?? NULL)) {
      // A stored record whose profile list is missing or not an array, NULL
      // too, cannot be shown free of a collision. An empty list is a list.
      $refused[] = 'mcp_servers_unreadable';
    }
    elseif (array_key_exists(CinatraDevSite::PROFILE_ID, $stored_servers['servers'] ?? [])) {
      $refused[] = 'profile_collision';
    }
    return $refused;
  }

  /**
   * Reads the endpoint route.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The route, or NULL when it is absent.
   */
  private function endpointRoute(): ?Route {
    try {
      return $this->routeProvider->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * Reads the stored editorial record, past the config factory's cache.
   *
   * @return array
   *   The record without overrides, or [] when it is absent.
   */
  private function editorialRecord(): array {
    $this->configFactory->reset(EditorialBinder::CONFIG_NAME);
    return $this->configFactory->get(EditorialBinder::CONFIG_NAME)->getRawData();
  }

  /**
   * Whether a record is a content moderation workflow on node:page.
   *
   * @param array $record
   *   The record.
   *
   * @return bool
   *   TRUE when its node bundles list page, as the verify step requires.
   */
  private static function bindsPage(array $record): bool {
    return ($record['type'] ?? NULL) === 'content_moderation'
      && in_array('page', (array) ($record['type_settings']['entity_types']['node'] ?? []), TRUE);
  }

  /**
   * Builds the result, checks that it encodes, and writes its one log line.
   *
   * @param bool $ok
   *   Whether the run passed.
   * @param string[] $steps
   *   The steps taken.
   * @param string[] $refused
   *   The codes.
   * @param array $census
   *   The census.
   *
   * @return array
   *   The result of ensure().
   *
   * @throws \JsonException
   *   When json_encode() cannot encode the result, as the connector encodes
   *   it; no log line is written then, and a passing run does not arm.
   */
  private function finish(bool $ok, array $steps, array $refused, array $census): array {
    $result = ['ok' => $ok, 'steps' => $steps, 'refused' => $refused, 'census' => $census];
    json_encode($result, JSON_THROW_ON_ERROR);
    $logger = $this->loggerFactory->get('cinatra_dev_site');
    $context = ['@steps' => implode(', ', $steps) ?: 'none', '@refused' => implode(', ', $refused)];
    if ($ok) {
      $logger->info('ensure() verified the exposure; the armed flag is set after this line unless a later step throws; steps: @steps.', $context);
    }
    else {
      $logger->warning('ensure() did not arm; steps: @steps; refused: @refused.', $context);
    }
    return $result;
  }

}
