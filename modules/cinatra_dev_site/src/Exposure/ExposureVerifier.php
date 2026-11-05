<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Exposure;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\ExecutionGate;
use Drupal\cinatra_dev_site\Mcp\ExposureDeniedException;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\cinatra_dev_site\Mcp\ProfileToolGuard;
use Drupal\cinatra_dev_site\Mcp\ScopedToolManager;
use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\tool\Tool\ToolDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;

/**
 * Verifies the exposure and the moderation of the pages on the live stack.
 *
 * It writes no configuration, no State value and no entity, and it does not
 * arm. The replay pushes one request onto the request stack and pops it.
 */
final class ExposureVerifier {

  /**
   * Constructs the verifier.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $toolManager
   *   The site's tool manager, or NULL when it is absent.
   * @param object|null $repository
   *   The server configuration repository, or NULL when it is absent.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileToolGuard $guard
   *   The profile guard.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The site's account switcher.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\cinatra_dev_site\Workflow\EditorialBinder $binder
   *   The editorial binder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   The moderation information service.
   */
  public function __construct(
    private readonly ?PluginManagerInterface $toolManager,
    private readonly ?object $repository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestStack $requestStack,
    private readonly ProfileToolGuard $guard,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AccountProxyInterface $currentUser,
    private readonly EditorialBinder $binder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
  ) {
  }

  /**
   * Verifies the exposure, the page binding and each page's moderation.
   *
   * @param \Symfony\Component\Routing\Route|null $before
   *   The endpoint route as read before the router rebuild, or NULL.
   *
   * @return string[]
   *   The failure codes, in this order; [] when everything is verified:
   *   services_absent (then none of the next three checks runs), or
   *   tool_definition:<id> per exposed tool whose site-wide definition is
   *   absent, not a tool definition or not of provider cinatra, then the
   *   first of replay_profile, replay_guard, replay_listing, replay_gate and
   *   replay_user that fails, then profile_without_binding; server_id;
   *   route; the binder's preflight codes; page_not_bound (then no page is
   *   read); page_unreadable:<nid> per listed page that does not load;
   *   page_unmoderated:<nid>:<langcode> and
   *   page_state_conflict:<nid>:<langcode> per page translation. A failure
   *   that throws passes up; one thrown in the replay after its request is
   *   popped.
   */
  public function verify(?Route $before): array {
    $failed = $this->tools();
    if ($this->configFactory->get('mcp_tools_remote.settings')->get('server_id') !== CinatraDevSite::PROFILE_ID) {
      $failed[] = 'server_id';
    }
    if (!$this->routeSwitched($before)) {
      $failed[] = 'route';
    }
    $failed = array_merge($failed, $this->binder->preflight());
    $workflow = $this->entityTypeManager->getStorage('workflow')->loadUnchanged(EditorialBinder::ID);
    $plugin = $workflow?->getTypePlugin();
    $named = $this->moderationInformation->getWorkflowForEntityTypeAndBundle('node', 'page');
    if (!$plugin instanceof ContentModerationInterface || !$plugin->appliesToEntityTypeAndBundle('node', 'page')
      || $named?->id() !== EditorialBinder::ID) {
      $failed[] = 'page_not_bound';
      return $failed;
    }
    return array_merge($failed, $this->pages($plugin));
  }

  /**
   * Checks the site-wide tools, then replays the remote read.
   *
   * @return string[]
   *   The failure codes.
   */
  private function tools(): array {
    if ($this->toolManager === NULL || $this->repository === NULL || !method_exists($this->repository, 'getServer')) {
      return ['services_absent'];
    }
    $failed = [];
    $definitions = $this->toolManager->getDefinitions();
    foreach (CinatraDevSite::EXPOSED_TOOLS as $id) {
      $definition = $definitions[$id] ?? NULL;
      if (!$definition instanceof ToolDefinition || $definition->getProvider() !== 'cinatra') {
        $failed[] = 'tool_definition:' . $id;
      }
    }
    $request = Request::create('/_mcp_tools');
    $binding = new ProfileBinding($request);
    $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, $binding);
    $this->requestStack->push($request);
    try {
      $replay = $this->replay($request, $binding);
    }
    finally {
      $this->requestStack->pop();
    }
    if ($replay !== NULL) {
      $failed[] = $replay;
    }
    if ($this->repository->getServer(CinatraDevSite::PROFILE_ID) !== NULL) {
      $failed[] = 'profile_without_binding';
    }
    return $failed;
  }

  /**
   * Replays the one remote read for a request that is on the stack.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileBinding $binding
   *   The request's binding.
   *
   * @return string|null
   *   The first failure code, or NULL.
   */
  private function replay(Request $request, ProfileBinding $binding): ?string {
    $profile = $this->repository->getServer(CinatraDevSite::PROFILE_ID);
    if (!CinatraDevSite::validProfile($profile)) {
      return 'replay_profile';
    }
    if (!$this->guard->check($request, $profile) || !$binding->isValidated()) {
      return 'replay_guard';
    }
    $listed = (new ScopedToolManager($this->toolManager, $binding))->getDefinitions();
    $ids = array_keys($listed);
    sort($ids);
    $exposed = CinatraDevSite::EXPOSED_TOOLS;
    sort($exposed);
    $remote = array_filter($listed, static fn($d): bool => $d instanceof ToolDefinition && $d->getProvider() === 'mcp_tools_remote');
    if ($ids !== $exposed || count($remote) !== count($exposed)) {
      return 'replay_listing';
    }
    $user = $this->currentUser->id();
    $gate = new ExecutionGate($this->accountSwitcher, new ProfileBinding($request));
    try {
      $gate->switchTo(new AnonymousUserSession());
      $gate->switchBack();
      return 'replay_gate';
    }
    catch (ExposureDeniedException) {
      // The gate refuses a binding that is not validated.
    }
    return $this->currentUser->id() === $user ? NULL : 'replay_user';
  }

  /**
   * Whether the endpoint route has the endpoint and is otherwise unchanged.
   *
   * @param \Symfony\Component\Routing\Route|null $before
   *   The route as read before the router rebuild, or NULL.
   *
   * @return bool
   *   TRUE when the controller is the endpoint and the path, methods and
   *   requirements equal those of $before.
   */
  private function routeSwitched(?Route $before): bool {
    try {
      $route = $this->routeProvider->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE);
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
    return $before !== NULL && $route->getDefault('_controller') === CinatraDevSite::ENDPOINT_CONTROLLER
      && $route->getPath() === $before->getPath() && $route->getMethods() === $before->getMethods()
      && $route->getRequirements() === $before->getRequirements();
  }

  /**
   * Checks each page translation's moderation state row.
   *
   * For each node:page in id order, each translation of its default revision
   * needs a content_moderation_state row of editorial bound to that revision
   * and language, whose state is published exactly when the translation is.
   *
   * @param \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface $plugin
   *   The editorial workflow type.
   *
   * @return string[]
   *   The failure codes.
   */
  private function pages(ContentModerationInterface $plugin): array {
    $failed = [];
    $nodes = $this->entityTypeManager->getStorage('node');
    $states = $this->entityTypeManager->getStorage('content_moderation_state');
    $ids = $nodes->getQuery()->accessCheck(FALSE)->condition('type', 'page')->sort('nid')->execute();
    foreach ($ids as $nid) {
      $node = $nodes->load($nid);
      if ($node === NULL) {
        $failed[] = 'page_unreadable:' . $nid;
        continue;
      }
      foreach ($node->getTranslationLanguages() as $langcode => $unused) {
        $rows = $states->getQuery()->accessCheck(FALSE)->allRevisions()
          ->condition('content_entity_type_id', 'node')
          ->condition('content_entity_id', (int) $nid)
          ->condition('content_entity_revision_id', (int) $node->getRevisionId())
          ->condition('workflow', EditorialBinder::ID)
          ->condition('langcode', $langcode)
          ->sort('revision_id', 'DESC')->range(0, 1)->execute();
        $row = $rows === [] ? NULL : $states->loadRevision((int) key($rows));
        $state = $row !== NULL && $row->hasTranslation($langcode) ? $row->getTranslation($langcode)->get('moderation_state')->value : NULL;
        if (!is_string($state) || !$plugin->hasState($state)) {
          $failed[] = 'page_unmoderated:' . $nid . ':' . $langcode;
        }
        elseif ($plugin->getState($state)->isPublishedState() !== $node->getTranslation($langcode)->isPublished()) {
          $failed[] = 'page_state_conflict:' . $nid . ':' . $langcode;
        }
      }
    }
    return $failed;
  }

}
