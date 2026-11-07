<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Exposure;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Mcp\ProfileBinding;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Reads the census of the exposure: what serves MCP on this site.
 *
 * It writes nothing. Stored profiles are listed, not judged.
 */
final class Census {

  /**
   * The modules whose presence the census reports.
   */
  public const MODULES = ['mcp_tools_stdio', 'mcp_tools_mcp_server', 'mcp_tools_ai', 'tool_ai_connector', 'tool_explorer'];

  /**
   * The profile keys the census reports.
   */
  private const PROFILE_KEYS = ['include_all_tools', 'gateway_mode', 'transports', 'enabled'];

  /**
   * Constructs the census.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param object|null $repository
   *   The server configuration repository, or NULL when it is absent.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestStack $requestStack,
    private readonly ?object $repository,
  ) {
  }

  /**
   * Reads the census.
   *
   * @return array
   *   'profiles': each stored profile id with the four keys (NULL for a value
   *   that is not an array); 'replay': NULL, or while the exposure is active
   *   and the repository answers, ['profile' => the four keys of the cinatra
   *   profile a request with its own binding reads, or NULL], apart from the
   *   stored ids; 'server_id': the effective and the raw value; 'controller':
   *   the endpoint route's controller, or NULL without the route; 'modules':
   *   each of MODULES with whether it is enabled; 'server_configs_alter': the
   *   modules that implement the profile alter.
   */
  public function read(): array {
    $profiles = [];
    $stored = $this->configFactory->get('mcp_tools.servers')->getRawData()['servers'] ?? [];
    foreach (is_array($stored) ? $stored : [] as $id => $profile) {
      $profiles[(string) $id] = is_array($profile) ? self::keys($profile) : NULL;
    }
    $replay = NULL;
    if (CinatraDevSite::exposureActive() && $this->repository !== NULL && method_exists($this->repository, 'getServer')) {
      $request = Request::create('/_mcp_tools');
      $request->attributes->set(CinatraDevSite::BINDING_ATTRIBUTE, new ProfileBinding($request));
      $this->requestStack->push($request);
      try {
        $profile = $this->repository->getServer(CinatraDevSite::PROFILE_ID);
      }
      finally {
        $this->requestStack->pop();
      }
      $replay = ['profile' => is_array($profile) ? self::keys($profile) : NULL];
    }
    try {
      $controller = $this->routeProvider->getRouteByName(CinatraDevSite::ENDPOINT_ROUTE)->getDefault('_controller');
    }
    catch (RouteNotFoundException) {
      $controller = NULL;
    }
    $modules = [];
    foreach (self::MODULES as $module) {
      $modules[$module] = $this->moduleHandler->moduleExists($module);
    }
    $alters = [];
    $this->moduleHandler->invokeAllWith('mcp_tools_server_configs_alter', function (callable $hook, string $module) use (&$alters): void {
      $alters[] = $module;
    });
    $settings = $this->configFactory->get('mcp_tools_remote.settings');
    return [
      'profiles' => $profiles,
      'replay' => $replay,
      'server_id' => ['effective' => $settings->get('server_id'), 'raw' => $settings->getRawData()['server_id'] ?? NULL],
      'controller' => $controller,
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
   *   The four keys, each NULL when absent.
   */
  private static function keys(array $profile): array {
    return array_merge(array_fill_keys(self::PROFILE_KEYS, NULL), array_intersect_key($profile, array_flip(self::PROFILE_KEYS)));
  }

}
