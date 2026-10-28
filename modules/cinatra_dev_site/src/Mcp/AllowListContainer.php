<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The deny-by-default container that the remote controller's create() gets.
 *
 * It answers only two kinds of id: the ids in $served, with the objects the
 * endpoint built for this request, and the ids in PASS_THROUGH, from the real
 * container. Every other id, every parameter and every write is refused.
 */
final class AllowListContainer implements ContainerInterface {

  /**
   * The ids of McpToolsRemoteController::create() served unchanged.
   *
   * The tool manager, the account switcher, the resource and prompt
   * registries and the client bridge are deliberately absent: the endpoint
   * serves those itself, or they are refused.
   */
  public const PASS_THROUGH = [
    'config.factory',
    'mcp_tools_remote.api_key_manager',
    'mcp_tools.access_manager',
    'mcp_tools.server_config_repository',
    'mcp_tools.tool_error_handler',
    'entity_type.manager',
    'event_dispatcher',
    'logger.channel.mcp_tools_remote',
  ];

  /**
   * Constructs the view.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container.
   * @param array<string, object|null> $served
   *   The objects the endpoint built for this request, keyed by service id.
   *   NULL serves the id as absent.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly array $served,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object {
    if (array_key_exists($id, $this->served)) {
      return $this->served[$id];
    }
    if (in_array($id, self::PASS_THROUGH, TRUE)) {
      return $this->container->get($id, $invalidBehavior);
    }
    throw new ExposureDeniedException('A service outside the allow-list was requested.');
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    if (array_key_exists($id, $this->served)) {
      return $this->served[$id] !== NULL;
    }
    return in_array($id, self::PASS_THROUGH, TRUE) && $this->container->has($id);
  }

  /**
   * {@inheritdoc}
   */
  public function initialized(string $id): bool {
    if (array_key_exists($id, $this->served)) {
      return $this->served[$id] !== NULL;
    }
    return in_array($id, self::PASS_THROUGH, TRUE) && $this->container->initialized($id);
  }

  /**
   * {@inheritdoc}
   */
  public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
    throw new ExposureDeniedException('A container parameter was requested.');
  }

  /**
   * {@inheritdoc}
   */
  public function hasParameter(string $name): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $id, ?object $service): void {
    throw new \LogicException('The allow-list container is read-only.');
  }

  /**
   * {@inheritdoc}
   */
  public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
    throw new \LogicException('The allow-list container is read-only.');
  }

}
