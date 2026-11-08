<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_verify_test;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Stands in for the MCP Tools server config repository.
 *
 * The methods getServers() and getServer() follow drupal/mcp_tools
 * 1.0.0-beta18, src/Mcp/ServerConfigRepository.php: stored profiles merged
 * over the defaults, the profile alter, then the disabled ones dropped.
 */
final class VerifyRepository {

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
   * Constructs the repository.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * Returns the enabled profiles, altered.
   *
   * @return array
   *   The profiles keyed by id.
   */
  public function getServers(): array {
    $servers = $this->configFactory->get('mcp_tools.servers')->get('servers') ?? [];
    $servers = is_array($servers) && $servers !== [] ? $servers : ['default' => []];
    $normalized = [];
    foreach ($servers as $id => $server) {
      $normalized[$id] = array_merge(self::DEFAULTS, is_array($server) ? $server : [], ['id' => (string) $id]);
    }
    $this->moduleHandler->alter('mcp_tools_server_configs', $normalized);
    return array_filter($normalized, static fn($server): bool => is_array($server) && ($server['enabled'] ?? TRUE));
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
    return $this->getServers()[(string) $server_id] ?? NULL;
  }

}
