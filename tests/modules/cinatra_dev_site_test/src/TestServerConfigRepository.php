<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stands in for the MCP Tools server config repository.
 *
 * Mirrors drupal/mcp_tools 1.0.0-beta18 (commit 737803e7),
 * src/Mcp/ServerConfigRepository.php: getServers(), getServer(),
 * checkAccess() and allowsTransport() (lines 58-100 and 126-173).
 */
final class TestServerConfigRepository {

  /**
   * The profile defaults.
   */
  private const DEFAULTS = [
    'name' => 'Drupal MCP Tools',
    'version' => '1.0.0',
    'pagination_limit' => 50,
    'include_all_tools' => FALSE,
    'gateway_mode' => FALSE,
    'enable_resources' => TRUE,
    'enable_prompts' => TRUE,
    'component_public_only' => FALSE,
    'transports' => [],
    'enabled' => TRUE,
    'scopes' => [],
    'permission_callback' => NULL,
  ];

  /**
   * The denial message.
   */
  private const DENIED = 'Access denied by server permission callback.';

  /**
   * Constructs the repository.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private ModuleHandlerInterface $moduleHandler,
    private ContainerInterface $container,
    private LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns all server profiles, altered, without the disabled ones.
   *
   * @return array
   *   The profiles keyed by id.
   */
  public function getServers(): array {
    $servers = $this->configFactory->get('mcp_tools.servers')->get('servers') ?? [];
    if (!is_array($servers)) {
      $servers = [];
    }
    if (empty($servers)) {
      $servers['default'] = [];
    }
    $normalized = [];
    foreach ($servers as $id => $server) {
      $merged = array_merge(self::DEFAULTS, is_array($server) ? $server : []);
      $merged['id'] = (string) $id;
      $normalized[$id] = $merged;
    }
    $this->moduleHandler->alter('mcp_tools_server_configs', $normalized);
    foreach ($normalized as $id => $server) {
      if (!($server['enabled'] ?? TRUE)) {
        unset($normalized[$id]);
      }
    }
    return $normalized;
  }

  /**
   * Returns one profile, or NULL.
   *
   * @param string|null $server_id
   *   The profile id.
   *
   * @return array|null
   *   The profile.
   */
  public function getServer(?string $server_id): ?array {
    $servers = $this->getServers();
    if ($server_id === NULL || $server_id === '') {
      $server_id = isset($servers['default']) ? 'default' : (string) array_key_first($servers);
    }
    return $servers[$server_id] ?? NULL;
  }

  /**
   * Evaluates the profile's permission callback.
   *
   * @param array $profile
   *   The profile.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request.
   *
   * @return array
   *   The keys allowed and message.
   */
  public function checkAccess(array $profile, ?Request $request): array {
    $callback = $this->resolve($profile['permission_callback'] ?? NULL);
    if ($callback === NULL) {
      return ['allowed' => TRUE, 'message' => NULL];
    }
    try {
      $result = $callback($request, $profile);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('mcp_tools')->error('Server permission callback failed: @message', ['@message' => $e->getMessage()]);
      return ['allowed' => FALSE, 'message' => self::DENIED];
    }
    if (is_string($result)) {
      return ['allowed' => FALSE, 'message' => $result];
    }
    if (is_bool($result)) {
      return ['allowed' => $result, 'message' => $result ? NULL : self::DENIED];
    }
    return ['allowed' => FALSE, 'message' => self::DENIED];
  }

  /**
   * Checks whether a profile allows a transport.
   *
   * @param array $profile
   *   The profile.
   * @param string $transport
   *   The transport.
   *
   * @return bool
   *   TRUE when allowed.
   */
  public function allowsTransport(array $profile, string $transport): bool {
    $transports = $profile['transports'] ?? [];
    if (!is_array($transports) || $transports === []) {
      return TRUE;
    }
    return in_array(strtolower($transport), $transports, TRUE);
  }

  /**
   * Resolves a service:method callback from the real container.
   *
   * @param mixed $callback
   *   The configured callback.
   *
   * @return callable|null
   *   The callable, or NULL (allowed).
   */
  private function resolve(mixed $callback): ?callable {
    if (!is_string($callback) || $callback === '') {
      return NULL;
    }
    if (str_contains($callback, ':') && !str_contains($callback, '::')) {
      [$service_id, $method] = explode(':', $callback, 2);
      if ($this->container->has($service_id)) {
        $service = $this->container->get($service_id);
        if (is_callable([$service, $method])) {
          return [$service, $method];
        }
      }
      $this->loggerFactory->get('mcp_tools')->warning('Invalid permission callback service for MCP server: @callback', ['@callback' => $callback]);
      return NULL;
    }
    return NULL;
  }

}
