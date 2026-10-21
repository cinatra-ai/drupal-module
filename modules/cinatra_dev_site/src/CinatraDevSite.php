<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site;

use Drupal\cinatra\ProtectedDraft\ProtectedDraftStorageInterface;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ProfileToolGuard;
use Drupal\cinatra_dev_site\Mcp\ScopedToolManager;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\workflows\WorkflowInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;

/**
 * Binds the development site's pages to editorial and arms the exposure.
 *
 * Nothing here acts on a site without the development marker. The exposure
 * writes no mcp configuration: the profile is added by the alter hook, the
 * server id by a configuration override, and the route controller by the
 * route subscriber, all only while the site is armed.
 */
final class CinatraDevSite {

  /**
   * The environment variable that marks a development site.
   */
  public const MARKER_ENV = 'CINATRA_DEV_SITE';

  /**
   * The State key that arms the exposure.
   */
  public const ARMED_KEY = 'cinatra_dev_site.armed';

  /**
   * The State key set when ensure() itself bound node:page to editorial.
   */
  public const BOUND_KEY = 'cinatra_dev_site.bound_page';

  /**
   * The two tools the cinatra profile lists and calls.
   */
  public const EXPOSED_TOOLS = [
    'cinatra_read_protected_revision',
    'cinatra_write_protected_draft',
  ];

  /**
   * The profile id.
   */
  public const PROFILE_ID = 'cinatra';

  /**
   * The profile's permission callback.
   */
  public const GUARD_CALLBACK = 'cinatra_dev_site.profile_guard:check';

  /**
   * The remote endpoint's route.
   */
  public const ENDPOINT_ROUTE = 'mcp_tools_remote.handle';

  /**
   * The controller the armed route points at.
   */
  public const ENDPOINT_CONTROLLER = '\Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint::handle';

  /**
   * The request attribute that carries the request's profile binding.
   */
  public const BINDING_ATTRIBUTE = '_cinatra_dev_site_binding';

  /**
   * The answer of the gate when the profile was not validated.
   */
  public const DENY_TEXT = 'cinatra_dev_site: the cinatra MCP profile was not validated for this request.';

  /**
   * The refusal of a status-only write on a moderated page.
   */
  public const STATUS_REFUSAL_TEXT = 'cinatra_dev_site: the status of a moderated page changes only through its moderation state.';

  /**
   * The refusal of an imported node:page binding without the marker.
   */
  public const IMPORT_GUARD_TEXT = 'cinatra_dev_site: a configuration import may not bind node:page to a workflow on a site without the development marker.';

  /**
   * The cinatra profile: the twelve default keys, the id and the tools.
   */
  public const PROFILE = [
    'name' => 'Cinatra development site',
    'version' => '1.0.0',
    'pagination_limit' => 50,
    'include_all_tools' => FALSE,
    'gateway_mode' => FALSE,
    'enable_resources' => TRUE,
    'enable_prompts' => TRUE,
    'component_public_only' => FALSE,
    'transports' => ['http'],
    'enabled' => TRUE,
    'scopes' => ['read', 'write'],
    'permission_callback' => self::GUARD_CALLBACK,
    'id' => self::PROFILE_ID,
    'tools' => self::EXPOSED_TOOLS,
  ];

  /**
   * Core's standard editorial type settings, with no entity type bound.
   */
  public const EDITORIAL = [
    'states' => [
      'archived' => [
        'label' => 'Archived',
        'weight' => 5,
        'published' => FALSE,
        'default_revision' => TRUE,
      ],
      'draft' => [
        'label' => 'Draft',
        'weight' => -5,
        'published' => FALSE,
        'default_revision' => FALSE,
      ],
      'published' => [
        'label' => 'Published',
        'weight' => 0,
        'published' => TRUE,
        'default_revision' => TRUE,
      ],
    ],
    'transitions' => [
      'archive' => [
        'label' => 'Archive',
        'from' => ['published'],
        'to' => 'archived',
        'weight' => 2,
      ],
      'archived_draft' => [
        'label' => 'Restore to Draft',
        'from' => ['archived'],
        'to' => 'draft',
        'weight' => 3,
      ],
      'archived_published' => [
        'label' => 'Restore',
        'from' => ['archived'],
        'to' => 'published',
        'weight' => 4,
      ],
      'create_new_draft' => [
        'label' => 'Create New Draft',
        'from' => ['draft', 'published'],
        'to' => 'draft',
        'weight' => 0,
      ],
      'publish' => [
        'label' => 'Publish',
        'from' => ['draft', 'published'],
        'to' => 'published',
        'weight' => 1,
      ],
    ],
    'entity_types' => [],
    'default_moderation_state' => 'draft',
  ];

  /**
   * The lock name of ensure().
   */
  private const LOCK = 'cinatra_dev_site.ensure';

  /**
   * The modules the census reports as enabled or not.
   */
  private const CENSUS_MODULES = [
    'mcp_tools_stdio',
    'mcp_tools_mcp_server',
    'mcp_tools_ai',
    'tool_ai_connector',
    'tool_explorer',
  ];

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $toolManager
   *   The site-wide tool manager, when the Tool module is present.
   * @param \Drupal\cinatra\ProtectedDraft\ProtectedDraftStorageInterface $protectedDraftStorage
   *   The protected draft storage, whose locked root transaction each page
   *   migration runs in.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $routerBuilder
   *   The router builder.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param object|null $serverConfigRepository
   *   The MCP server configuration repository, when present.
   * @param string $remoteController
   *   The inner remote controller class.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileToolGuard $guard
   *   The profile guard.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?PluginManagerInterface $toolManager,
    private readonly ProtectedDraftStorageInterface $protectedDraftStorage,
    private readonly RouteBuilderInterface $routerBuilder,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestStack $requestStack,
    private readonly ?object $serverConfigRepository,
    private readonly string $remoteController,
    private readonly ProfileToolGuard $guard,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
  }

  /**
   * Whether the exposure is armed.
   *
   * @return bool
   *   TRUE only when the State flag is exactly TRUE.
   */
  public static function isArmed(): bool {
    return \Drupal::state()->get(self::ARMED_KEY) === TRUE;
  }

  /**
   * Whether the site carries the development marker.
   *
   * @return bool
   *   TRUE when the marker environment variable is '1'.
   */
  public static function markerPresent(): bool {
    return getenv(self::MARKER_ENV) === '1';
  }

  /**
   * Whether a profile array is exactly the cinatra profile.
   *
   * @param mixed $profile
   *   The profile read from the repository.
   *
   * @return bool
   *   TRUE for an exact match of PROFILE, key order aside.
   */
  public static function validProfile(mixed $profile): bool {
    if (!is_array($profile)) {
      return FALSE;
    }
    $expected = self::PROFILE;
    ksort($expected);
    ksort($profile);
    return $profile === $expected;
  }

  /**
   * Checks everything, then binds, migrates, arms and verifies.
   *
   * @return array
   *   The result: ok, refused, census, and for Phase B also steps and error.
   */
  public function ensure(): array {
    if (!$this->lock->acquire(self::LOCK, 600.0)) {
      return ['ok' => FALSE, 'refused' => ['busy'], 'census' => []];
    }
    try {
      return $this->run();
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Disarms the site and removes the binding that ensure() added.
   *
   * @param bool $is_syncing
   *   TRUE when the uninstall is part of a configuration import.
   *
   * @return array
   *   The steps taken.
   */
  public function release(bool $is_syncing): array {
    $steps = [];
    $this->state->delete(self::ARMED_KEY);
    $this->configFactory->reset('mcp_tools_remote.settings');
    $steps[] = 'disarmed';
    if (!$is_syncing && $this->state->get(self::BOUND_KEY) === TRUE) {
      $workflow = $this->loadWorkflow('editorial');
      $plugin = $workflow?->getTypePlugin();
      if ($workflow !== NULL && $plugin instanceof ContentModerationInterface
        && $plugin->appliesToEntityTypeAndBundle('node', 'page')) {
        $plugin->removeEntityTypeAndBundle('node', 'page');
        $workflow->save();
        $steps[] = 'unbound';
      }
    }
    $this->state->delete(self::BOUND_KEY);
    return ['ok' => TRUE, 'steps' => $steps];
  }

  /**
   * Runs Phase A and Phase B under the lock.
   *
   * @return array
   *   The result of ensure().
   */
  private function run(): array {
    // PHASE A: read only, every refusal collected.
    $refused = $this->preflight();
    $route = $this->endpointRoute();
    $plans = [];
    foreach ($this->pageIds() as $nid) {
      [$plan, $page_refusals] = $this->pagePlan($nid);
      array_push($refused, ...$page_refusals);
      if ($plan !== NULL) {
        $plans[$nid] = $plan;
      }
    }
    if ($refused !== []) {
      if ($this->state->get(self::ARMED_KEY) !== NULL) {
        $this->disarm();
      }
      $result = ['ok' => FALSE, 'refused' => array_values(array_unique($refused)), 'census' => $this->census()];
      $this->log($result);
      return $result;
    }

    // PHASE B, step 1: the workflow.
    $steps = [];
    $bound_before = $this->state->get(self::BOUND_KEY);
    $created = FALSE;
    $bound = FALSE;
    try {
      if ($this->workflowRefusals() !== []) {
        return $this->failure($steps, 'workflow_failed', 'The workflow checks refused on the recheck.');
      }
      $workflow = $this->loadWorkflow('editorial');
      if ($workflow === NULL) {
        $workflow = $this->entityTypeManager->getStorage('workflow')->create([
          'id' => 'editorial',
          'label' => 'Editorial',
          'type' => 'content_moderation',
          'type_settings' => self::EDITORIAL,
        ]);
        assert($workflow instanceof WorkflowInterface);
        $plugin = $workflow->getTypePlugin();
        assert($plugin instanceof ContentModerationInterface);
        $plugin->addEntityTypeAndBundle('node', 'page');
        $workflow->save();
        $created = TRUE;
        $bound = TRUE;
        $this->state->set(self::BOUND_KEY, TRUE);
        $steps[] = 'workflow:created';
        $steps[] = 'workflow:bound';
      }
      else {
        $plugin = $workflow->getTypePlugin();
        assert($plugin instanceof ContentModerationInterface);
        if (!$plugin->appliesToEntityTypeAndBundle('node', 'page')) {
          $plugin->addEntityTypeAndBundle('node', 'page');
          $workflow->save();
          $bound = TRUE;
          $this->state->set(self::BOUND_KEY, TRUE);
          $steps[] = 'workflow:bound';
        }
      }
    }
    catch (\Throwable $e) {
      $this->undoWorkflow($created, $bound, $bound_before);
      return $this->failure($steps, 'workflow_failed', $e->getMessage());
    }

    // Step 2: the migration, page by page, each in its own locked root
    // transaction. The id list is read again after the binding.
    $saved = 0;
    foreach ($this->pageIds() as $nid) {
      $planned = $plans[$nid] ?? NULL;
      if ($planned !== NULL && $planned['missing'] === []) {
        continue;
      }
      try {
        $outcome = $this->protectedDraftStorage->withLockedNode($nid, function () use ($nid, $planned): array {
          return $this->migratePage($nid, $planned);
        });
      }
      catch (\Throwable $e) {
        $this->resetPageCaches($nid);
        if ($saved === 0) {
          $this->undoWorkflow($created, $bound, $bound_before);
        }
        return $this->failure($steps, 'migration_failed:' . $nid, $e->getMessage());
      }
      $this->resetPageCaches($nid);
      if (!empty($outcome['saved'])) {
        $saved++;
        $steps[] = 'migrated:' . $nid;
      }
    }

    // Step 3: arm.
    if ($this->state->get(self::ARMED_KEY) !== TRUE) {
      $this->state->set(self::ARMED_KEY, TRUE);
      $steps[] = 'armed';
    }
    $this->configFactory->reset('mcp_tools_remote.settings');
    $this->routerBuilder->rebuild();

    // Step 4: verify on the live stack.
    $problem = $this->verify($route);
    if ($problem !== '') {
      return $this->failure($steps, 'exposure_unverified', $problem);
    }
    $steps[] = 'verified';

    // Step 5: the census.
    $result = ['ok' => TRUE, 'steps' => $steps, 'refused' => [], 'census' => $this->census()];
    $this->log($result);
    return $result;
  }

  /**
   * Collects the refusals that do not depend on the pages.
   *
   * @return string[]
   *   The refusal codes.
   */
  private function preflight(): array {
    $refused = [];
    if (!self::markerPresent()) {
      $refused[] = 'no_marker';
    }
    if ($this->toolManager === NULL) {
      $refused[] = 'tool_manager_absent';
    }
    else {
      $definitions = $this->toolManager->getDefinitions();
      foreach (self::EXPOSED_TOOLS as $id) {
        if (!isset($definitions[$id])) {
          $refused[] = 'tool_definition_missing:' . $id;
        }
        elseif (!$definitions[$id] instanceof ToolDefinition || $definitions[$id]->getProvider() !== 'cinatra') {
          $refused[] = 'tool_provider_unexpected:' . $id;
        }
      }
    }
    if ($this->serverConfigRepository === NULL) {
      $refused[] = 'server_config_repository_absent';
    }
    $route = $this->endpointRoute();
    if ($route === NULL || !in_array($this->routeController($route), [
      ltrim($this->remoteController, '\\') . '::handle',
      ltrim(self::ENDPOINT_CONTROLLER, '\\'),
    ], TRUE)) {
      $refused[] = 'endpoint_route_unexpected';
    }
    $servers = $this->configFactory->get('mcp_tools.servers');
    $remote = $this->configFactory->get('mcp_tools_remote.settings');
    if ($servers->isNew() || $remote->isNew()) {
      $refused[] = 'mcp_config_absent';
    }
    $raw_remote = $remote->getRawData();
    if (!empty($raw_remote['include_all_tools'])) {
      $refused[] = 'include_all_tools_true';
    }
    $raw_servers = $servers->getRawData()['servers'] ?? [];
    if (is_array($raw_servers) && array_key_exists(self::PROFILE_ID, $raw_servers)) {
      $refused[] = 'profile_collision';
    }
    return array_merge($refused, $this->workflowRefusals());
  }

  /**
   * Collects the workflow refusals, read only.
   *
   * @return string[]
   *   The refusal codes.
   */
  private function workflowRefusals(): array {
    $refused = [];
    $storage = $this->entityTypeManager->getStorage('workflow');
    $storage->resetCache();
    $workflows = $storage->loadMultiple();
    $editorial = $workflows['editorial'] ?? NULL;
    if ($editorial instanceof WorkflowInterface) {
      if (!$editorial->getTypePlugin() instanceof ContentModerationInterface || !$this->editorialMatches($editorial)) {
        $refused[] = 'editorial_mismatch';
      }
    }
    elseif ($workflows !== []) {
      $refused[] = 'other_workflow';
    }
    $conflicts = [];
    foreach ($storage->loadByProperties(['type' => 'content_moderation']) as $id => $workflow) {
      if ($id === 'editorial' || !$workflow instanceof WorkflowInterface) {
        continue;
      }
      $plugin = $workflow->getTypePlugin();
      if ($plugin instanceof ContentModerationInterface && $plugin->appliesToEntityTypeAndBundle('node', 'page')) {
        $conflicts[] = (string) $id;
      }
    }
    $bundle_workflow = self::pageWorkflowId();
    if ($bundle_workflow !== NULL && $bundle_workflow !== 'editorial') {
      $conflicts[] = $bundle_workflow;
    }
    $conflicts = array_values(array_unique($conflicts));
    sort($conflicts);
    foreach ($conflicts as $id) {
      $refused[] = 'page_workflow_conflict:' . $id;
    }
    return $refused;
  }

  /**
   * Reads the workflow that the bundle info gives node:page.
   *
   * @return string|null
   *   The workflow id, or NULL when none applies.
   */
  private static function pageWorkflowId(): ?string {
    $workflow = \Drupal::service('content_moderation.moderation_information')
      ->getWorkflowForEntityTypeAndBundle('node', 'page');
    return $workflow instanceof WorkflowInterface ? (string) $workflow->id() : NULL;
  }

  /**
   * Returns the current user's id.
   *
   * @return int
   *   The id.
   */
  private static function currentUserId(): int {
    return (int) \Drupal::currentUser()->id();
  }

  /**
   * Whether editorial's states and transitions match EDITORIAL.
   *
   * Labels and weights may differ.
   *
   * @param \Drupal\workflows\WorkflowInterface $workflow
   *   The editorial workflow.
   *
   * @return bool
   *   TRUE when they match.
   */
  private function editorialMatches(WorkflowInterface $workflow): bool {
    $settings = $workflow->getTypePlugin()->getConfiguration();
    return self::shape($settings) === self::shape(self::EDITORIAL);
  }

  /**
   * Reduces type settings to the facts editorial_mismatch compares.
   *
   * @param array $settings
   *   The type settings.
   *
   * @return array
   *   States with their flags and transitions with their ends, sorted.
   */
  private static function shape(array $settings): array {
    $states = [];
    foreach ((array) ($settings['states'] ?? []) as $id => $state) {
      $states[$id] = [
        (bool) ($state['published'] ?? FALSE),
        (bool) ($state['default_revision'] ?? FALSE),
      ];
    }
    ksort($states);
    $transitions = [];
    foreach ((array) ($settings['transitions'] ?? []) as $id => $transition) {
      $from = array_values((array) ($transition['from'] ?? []));
      sort($from);
      $transitions[$id] = [$from, (string) ($transition['to'] ?? '')];
    }
    ksort($transitions);
    return [$states, $transitions];
  }

  /**
   * Loads a workflow afresh.
   *
   * @param string $id
   *   The workflow id.
   *
   * @return \Drupal\workflows\WorkflowInterface|null
   *   The workflow, or NULL.
   */
  private function loadWorkflow(string $id): ?WorkflowInterface {
    $workflow = $this->entityTypeManager->getStorage('workflow')->loadUnchanged($id);
    return $workflow instanceof WorkflowInterface ? $workflow : NULL;
  }

  /**
   * Undoes the workflow writes of this run.
   *
   * @param bool $created
   *   Whether this run created editorial.
   * @param bool $bound
   *   Whether this run bound node:page.
   * @param mixed $bound_before
   *   The BOUND_KEY value before this run.
   */
  private function undoWorkflow(bool $created, bool $bound, mixed $bound_before): void {
    try {
      $workflow = $this->loadWorkflow('editorial');
      if ($created && $workflow !== NULL) {
        $workflow->delete();
      }
      elseif ($bound && $workflow !== NULL) {
        $plugin = $workflow->getTypePlugin();
        if ($plugin instanceof ContentModerationInterface && $plugin->appliesToEntityTypeAndBundle('node', 'page')) {
          $plugin->removeEntityTypeAndBundle('node', 'page');
          $workflow->save();
        }
      }
    }
    finally {
      if ($bound_before === NULL) {
        $this->state->delete(self::BOUND_KEY);
      }
      else {
        $this->state->set(self::BOUND_KEY, $bound_before);
      }
    }
  }

  /**
   * Lists the page ids in id order.
   *
   * @return int[]
   *   The node ids.
   */
  private function pageIds(): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->sort('nid')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Returns the node storage.
   *
   * @return \Drupal\Core\Entity\ContentEntityStorageInterface
   *   The storage, fetched afresh.
   */
  private function nodeStorage(): ContentEntityStorageInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    assert($storage instanceof ContentEntityStorageInterface);
    return $storage;
  }

  /**
   * Returns the moderation state storage.
   *
   * @return \Drupal\Core\Entity\ContentEntityStorageInterface
   *   The storage, fetched afresh.
   */
  private function stateStorage(): ContentEntityStorageInterface {
    $storage = $this->entityTypeManager->getStorage('content_moderation_state');
    assert($storage instanceof ContentEntityStorageInterface);
    return $storage;
  }

  /**
   * Builds the plan of one page.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The plan (or NULL when the page is gone) and the page's refusals.
   */
  private function pagePlan(int $nid): array {
    $storage = $this->nodeStorage();
    $node = $storage->load($nid);
    if (!$node instanceof NodeInterface) {
      return [NULL, []];
    }
    $refused = [];
    $default = (int) $node->getRevisionId();
    $default_langcode = $node->getUntranslated()->language()->getId();
    $languages = array_keys($node->getTranslationLanguages());
    sort($languages);
    $missing = [];
    $existing = [];
    foreach ($languages as $langcode) {
      $published = $node->getTranslation($langcode)->isPublished();
      $state = $this->storedState($nid, $default, $langcode);
      if ($state === NULL) {
        $missing[$langcode] = $published ? 'published' : 'draft';
        continue;
      }
      $existing[$langcode] = $state;
      if ((self::EDITORIAL['states'][$state]['published'] ?? NULL) !== $published) {
        $refused[] = 'state_conflict:' . $nid . ':' . $langcode;
      }
    }
    $resave = [];
    if ($missing !== []) {
      // The pending guard, core's test, only for a page with a missing row.
      $latest = (int) $storage->getLatestRevisionId($nid);
      if ($latest !== $default) {
        $revision = $storage->loadRevision($latest);
        if ($revision instanceof ContentEntityInterface && !$revision->wasDefaultRevision()) {
          $refused[] = 'pending_revision:' . $nid;
        }
        else {
          $refused[] = 'default_not_latest:' . $nid;
        }
      }
      foreach ($languages as $langcode) {
        $affected = $storage->getLatestTranslationAffectedRevisionId($nid, $langcode);
        if ($affected === NULL || (int) $affected === $default) {
          continue;
        }
        $revision = $storage->loadRevision((int) $affected);
        if ($revision instanceof ContentEntityInterface && !$revision->wasDefaultRevision()) {
          $refused[] = 'pending_revision:' . $nid . ':' . $langcode;
        }
      }
      // The orientation: core's default-language save rewrites the row of the
      // state entity's own default language, which is then saved again.
      if (isset($missing[$default_langcode])) {
        $own = $this->stateEntityLangcode($nid, $default);
        if ($own !== NULL && $own !== $default_langcode && isset($existing[$own])) {
          $resave = [$own => $existing[$own]];
        }
      }
    }
    // The default language is saved first.
    if (isset($missing[$default_langcode])) {
      $missing = [$default_langcode => $missing[$default_langcode]] + $missing;
    }
    $plan = [
      'default' => $default,
      'default_langcode' => $default_langcode,
      'missing' => $missing,
      'existing' => $existing,
      'resave' => $resave,
    ];
    return [$plan, $refused];
  }

  /**
   * Reads the editorial state stored for a node revision and language.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string $langcode
   *   The language.
   *
   * @return string|null
   *   The stored moderation state, or NULL when no row is bound.
   */
  private function storedState(int $nid, int $revision_id, string $langcode): ?string {
    $storage = $this->stateStorage();
    $revisions = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('content_entity_revision_id', $revision_id)
      ->condition('workflow', 'editorial')
      ->condition('langcode', $langcode)
      ->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute();
    $revision = key($revisions);
    if (!$revision) {
      return NULL;
    }
    $stored = $storage->loadRevision((int) $revision);
    if (!$stored instanceof ContentEntityInterface || !$stored->hasTranslation($langcode)) {
      return NULL;
    }
    $state = $stored->getTranslation($langcode)->get('moderation_state')->value;
    return is_string($state) ? $state : NULL;
  }

  /**
   * Reads the own default language of the state entity bound to a revision.
   *
   * The lookup has no language condition, as core's own lookup.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   *
   * @return string|null
   *   The language, or NULL when no state entity is bound.
   */
  private function stateEntityLangcode(int $nid, int $revision_id): ?string {
    $storage = $this->stateStorage();
    $revisions = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('content_entity_revision_id', $revision_id)
      ->condition('workflow', 'editorial')
      ->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute();
    $revision = key($revisions);
    if (!$revision) {
      return NULL;
    }
    $stored = $storage->loadRevision((int) $revision);
    return $stored instanceof ContentEntityInterface ? $stored->getUntranslated()->language()->getId() : NULL;
  }

  /**
   * Migrates one page; runs inside the protected draft storage's lock.
   *
   * @param int $nid
   *   The node id.
   * @param array|null $planned
   *   The plan read before the lock, or NULL for a page that was not in it.
   *
   * @return array
   *   Whether the page was saved.
   */
  private function migratePage(int $nid, ?array $planned): array {
    // M rebuilt the handlers after taking its locks: fetch them again.
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    [$plan, $refused] = $this->pagePlan($nid);
    if ($plan === NULL) {
      return ['saved' => FALSE];
    }
    if ($refused !== []) {
      throw new \RuntimeException('The page plan refused inside the lock: ' . implode(', ', $refused));
    }
    if ($planned !== NULL && $plan !== $planned) {
      throw new \RuntimeException('The page changed between the preflight and its lock.');
    }
    if ($plan['missing'] === []) {
      return ['saved' => FALSE];
    }
    $before = $this->pageValues($nid);

    $sequence = [];
    foreach ($plan['missing'] as $langcode => $state) {
      $sequence[] = [$langcode, $state];
      if ($langcode === $plan['default_langcode']) {
        foreach ($plan['resave'] as $own => $own_state) {
          $sequence[] = [$own, $own_state];
        }
      }
    }
    foreach ($sequence as [$langcode, $state]) {
      $node = $storage->loadUnchanged($nid);
      if (!$node instanceof NodeInterface || !$node->hasTranslation($langcode)) {
        throw new \RuntimeException('The page lost its translation ' . $langcode . ' inside the lock.');
      }
      $translation = $node->getTranslation($langcode);
      $translation->setSyncing(TRUE);
      $translation->set('moderation_state', $state);
      $translation->save();
    }

    $storage->resetCache([$nid]);
    $this->stateStorage()->resetCache();
    if ($this->pageValues($nid) !== $before) {
      throw new \RuntimeException('The migration changed the page; it is rolled back.');
    }
    $expected = $plan['existing'] + $plan['missing'];
    foreach ($expected as $langcode => $state) {
      if ($this->storedState($nid, $plan['default'], (string) $langcode) !== $state) {
        throw new \RuntimeException('The state row of ' . $langcode . ' is not bound to the default revision.');
      }
    }
    return ['saved' => TRUE];
  }

  /**
   * Reads the values the migration must keep.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The default revision id, revision count and per-language values.
   */
  private function pageValues(int $nid): array {
    $storage = $this->nodeStorage();
    $node = $storage->loadUnchanged($nid);
    if (!$node instanceof NodeInterface) {
      throw new \RuntimeException('The page is gone.');
    }
    $languages = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      $languages[$langcode] = [
        'status' => $translation->isPublished(),
        'changed' => (int) $translation->getChangedTime(),
        'title' => (string) $translation->label(),
        'body' => $translation->hasField('body') ? $translation->get('body')->getValue() : [],
      ];
    }
    ksort($languages);
    $count = (int) $storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('nid', $nid)->count()->execute();
    return [
      'default' => (int) $node->getRevisionId(),
      'revisions' => $count,
      'languages' => $languages,
    ];
  }

  /**
   * Resets the page's node and moderation state caches.
   *
   * @param int $nid
   *   The node id.
   */
  private function resetPageCaches(int $nid): void {
    $this->nodeStorage()->resetCache([$nid]);
    $this->stateStorage()->resetCache();
  }

  /**
   * Reads the endpoint route.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The route, or NULL when it is absent.
   */
  private function endpointRoute(): ?Route {
    try {
      return $this->routeProvider->getRouteByName(self::ENDPOINT_ROUTE);
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * Returns a route's controller without its leading backslash.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return string
   *   The controller.
   */
  private function routeController(Route $route): string {
    return ltrim((string) $route->getDefault('_controller'), '\\');
  }

  /**
   * Verifies the exposure on the live stack.
   *
   * @param \Symfony\Component\Routing\Route|null $before
   *   The route as Phase A read it.
   *
   * @return string
   *   An empty string when verified, otherwise what failed.
   */
  private function verify(?Route $before): string {
    if ($this->toolManager === NULL || $this->serverConfigRepository === NULL
      || !method_exists($this->serverConfigRepository, 'getServer')) {
      return 'The tool manager or the server repository is absent.';
    }
    $definitions = $this->toolManager->getDefinitions();
    foreach (self::EXPOSED_TOOLS as $id) {
      $definition = $definitions[$id] ?? NULL;
      if (!$definition instanceof ToolDefinition || $definition->getProvider() !== 'cinatra') {
        return 'The site-wide definition of ' . $id . ' changed.';
      }
    }
    if ($this->configFactory->get('mcp_tools_remote.settings')->get('server_id') !== self::PROFILE_ID) {
      return 'The effective server_id is not cinatra.';
    }
    $route = $this->endpointRoute();
    if ($route === NULL || $before === NULL
      || (string) $route->getDefault('_controller') !== self::ENDPOINT_CONTROLLER
      || $route->getPath() !== $before->getPath()
      || $route->getMethods() !== $before->getMethods()
      || $route->getRequirements() !== $before->getRequirements()) {
      return 'The endpoint route does not point at the development endpoint.';
    }

    // U's single read, replayed the way U makes it.
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $request->attributes->set(self::BINDING_ATTRIBUTE, $binding);
    $this->requestStack->push($request);
    try {
      $profile = $this->serverConfigRepository->getServer(self::PROFILE_ID);
      if (!self::validProfile($profile)) {
        return 'The replayed read did not return the cinatra profile.';
      }
      if (!$this->guard->check($request, $profile) || !$binding->isValidated()) {
        return 'The guard did not validate the replayed read.';
      }
      $scoped = new ScopedToolManager($this->toolManager, $binding);
      $listed = $scoped->getDefinitions();
      $ids = array_keys($listed);
      sort($ids);
      $exposed = self::EXPOSED_TOOLS;
      sort($exposed);
      if ($ids !== $exposed) {
        return 'The scoped manager does not list exactly the two tools.';
      }
      foreach ($listed as $definition) {
        if (!$definition instanceof ToolDefinition || $definition->getProvider() !== 'mcp_tools_remote') {
          return 'The scoped manager does not serve the remote provider.';
        }
      }
      $user = self::currentUserId();
      $gate = new ExecutionGate($this->accountSwitcher, new ProfileBinding($request));
      try {
        $gate->switchTo(new AnonymousUserSession());
        $gate->switchBack();
        return 'The gate let an unvalidated binding switch accounts.';
      }
      catch (ExposureDeniedException) {
        // Expected.
      }
      if (self::currentUserId() !== $user) {
        return 'The gate changed the current user.';
      }
    }
    finally {
      $this->requestStack->pop();
    }
    if ($this->serverConfigRepository->getServer(self::PROFILE_ID) !== NULL) {
      return 'The cinatra profile exists on a request without its binding.';
    }
    return '';
  }

  /**
   * Disarms the site: the flag, the config factory and the router.
   */
  private function disarm(): void {
    $this->state->delete(self::ARMED_KEY);
    $this->configFactory->reset('mcp_tools_remote.settings');
    $this->routerBuilder->rebuild();
  }

  /**
   * Builds a Phase B failure.
   *
   * @param string[] $steps
   *   The steps taken.
   * @param string $code
   *   The refusal code.
   * @param string $error
   *   The caught message, or what failed.
   *
   * @return array
   *   The result.
   */
  private function failure(array $steps, string $code, string $error): array {
    if ($this->state->get(self::ARMED_KEY) !== NULL) {
      $this->disarm();
    }
    $result = [
      'ok' => FALSE,
      'steps' => $steps,
      'refused' => [$code],
      'error' => $error,
      'census' => $this->census(),
    ];
    $this->log($result);
    return $result;
  }

  /**
   * Reads the census of the exposure.
   *
   * @return array
   *   Profiles, server ids, the route controller, modules and alter hooks.
   */
  private function census(): array {
    $profiles = [];
    $stored = $this->configFactory->get('mcp_tools.servers')->getRawData()['servers'] ?? [];
    foreach (is_array($stored) ? $stored : [] as $id => $profile) {
      $profiles[(string) $id] = self::censusProfile(is_array($profile) ? $profile : []);
    }
    if ($this->state->get(self::ARMED_KEY) === TRUE && $this->serverConfigRepository !== NULL
      && method_exists($this->serverConfigRepository, 'getServer')) {
      $request = Request::create('/_mcp_tools');
      $request->attributes->set(self::BINDING_ATTRIBUTE, new ProfileBinding($request));
      $profile = NULL;
      $this->requestStack->push($request);
      try {
        $profile = $this->serverConfigRepository->getServer(self::PROFILE_ID);
      }
      finally {
        $this->requestStack->pop();
      }
      $profiles[self::PROFILE_ID . ' (replay)'] = is_array($profile) ? self::censusProfile($profile) : NULL;
    }
    $route = $this->endpointRoute();
    $modules = [];
    foreach (self::CENSUS_MODULES as $module) {
      $modules[$module] = $this->moduleHandler->moduleExists($module);
    }
    $alters = [];
    $this->moduleHandler->invokeAllWith('mcp_tools_server_configs_alter', function (callable $hook, string $module) use (&$alters): void {
      $alters[] = $module;
    });
    return [
      'profiles' => $profiles,
      'server_id' => [
        'effective' => $this->configFactory->get('mcp_tools_remote.settings')->get('server_id'),
        'raw' => $this->configFactory->get('mcp_tools_remote.settings')->getRawData()['server_id'] ?? NULL,
      ],
      'controller' => $route !== NULL ? (string) $route->getDefault('_controller') : NULL,
      'modules' => $modules,
      'server_configs_alter' => array_values(array_unique($alters)),
    ];
  }

  /**
   * Reduces a profile to the census keys.
   *
   * @param array $profile
   *   The profile.
   *
   * @return array
   *   The four keys.
   */
  private static function censusProfile(array $profile): array {
    return [
      'include_all_tools' => $profile['include_all_tools'] ?? NULL,
      'gateway_mode' => $profile['gateway_mode'] ?? NULL,
      'transports' => $profile['transports'] ?? NULL,
      'enabled' => $profile['enabled'] ?? NULL,
    ];
  }

  /**
   * Writes the one log line of a run.
   *
   * @param array $result
   *   The result.
   */
  private function log(array $result): void {
    $this->loggerFactory->get('cinatra_dev_site')->info('ensure: ok=@ok refused=@refused steps=@steps', [
      '@ok' => $result['ok'] ? 'yes' : 'no',
      '@refused' => implode(',', $result['refused']),
      '@steps' => implode(',', $result['steps'] ?? []),
    ]);
  }

}
