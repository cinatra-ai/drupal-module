<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A read-only container view for one remote request.
 *
 * It answers the tool manager and the account switcher with the request's
 * scoped manager and gate, and delegates everything else unchanged.
 */
final class ScopedToolContainer implements ContainerInterface {

  /**
   * The tool manager id.
   */
  private const TOOL_MANAGER = 'plugin.manager.tool';

  /**
   * The account switcher id.
   */
  private const ACCOUNT_SWITCHER = 'account_switcher';

  /**
   * Constructs the view.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $toolManager
   *   The request's scoped tool manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The request's execution gate.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly PluginManagerInterface $toolManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $id, ?object $service): void {
    throw new \LogicException('The scoped container view is read-only.');
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object {
    if ($id === self::TOOL_MANAGER) {
      return $this->toolManager;
    }
    if ($id === self::ACCOUNT_SWITCHER) {
      return $this->accountSwitcher;
    }
    return $this->container->get($id, $invalidBehavior);
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    if ($id === self::TOOL_MANAGER || $id === self::ACCOUNT_SWITCHER) {
      return TRUE;
    }
    return $this->container->has($id);
  }

  /**
   * {@inheritdoc}
   */
  public function initialized(string $id): bool {
    return $this->container->initialized($id);
  }

  /**
   * {@inheritdoc}
   */
  public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
    return $this->container->getParameter($name);
  }

  /**
   * {@inheritdoc}
   */
  public function hasParameter(string $name): bool {
    return $this->container->hasParameter($name);
  }

  /**
   * {@inheritdoc}
   */
  public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
    throw new \LogicException('The scoped container view is read-only.');
  }

}
