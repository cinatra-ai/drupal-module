<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\tool\Tool\ToolDefinition;

/**
 * Serves only the bound tools of one validated request.
 *
 * Built per request over the site-wide tool manager. It is never a service.
 * While the binding is not validated the inner manager is never called. The
 * served definitions are clones; the inner definitions keep their provider.
 */
final class ScopedToolManager implements PluginManagerInterface {

  /**
   * The provider the clones carry, which passes the remote prefix filter.
   */
  private const PROVIDER = 'mcp_tools_remote';

  /**
   * The memoised clones, keyed by id, or NULL before the first read.
   *
   * @var \Drupal\tool\Tool\ToolDefinition[]|null
   */
  private ?array $definitions = NULL;

  /**
   * Constructs the scoped manager.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $inner
   *   The site-wide tool manager.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileBinding $binding
   *   The request's binding.
   */
  public function __construct(
    private readonly PluginManagerInterface $inner,
    private readonly ProfileBinding $binding,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    if (!$this->binding->isValidated()) {
      return [];
    }
    if ($this->definitions === NULL) {
      $bound = $this->binding->tools();
      $definitions = [];
      foreach ($this->inner->getDefinitions() as $id => $definition) {
        if (!in_array($id, $bound, TRUE) || !in_array($id, CinatraDevSite::EXPOSED_TOOLS, TRUE)) {
          continue;
        }
        if (!$definition instanceof ToolDefinition || $definition->getProvider() !== 'cinatra') {
          continue;
        }
        $copy = clone $definition;
        $copy->set('provider', self::PROVIDER);
        $definitions[$id] = $copy;
      }
      $this->definitions = $definitions;
    }
    return $this->binding->isValidated() ? $this->definitions : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    $definitions = $this->getDefinitions();
    if (isset($definitions[$plugin_id])) {
      return $definitions[$plugin_id];
    }
    if ($exception_on_invalid) {
      throw new PluginNotFoundException((string) $plugin_id);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return isset($this->getDefinitions()[$plugin_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    if (!isset($this->getDefinitions()[$plugin_id])) {
      throw new PluginNotFoundException((string) $plugin_id);
    }
    return $this->inner->createInstance(...func_get_args());
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return FALSE;
  }

}
