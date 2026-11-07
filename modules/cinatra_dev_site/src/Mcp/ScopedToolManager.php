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
 * The endpoint builds one per request over the site's tool manager; it is
 * never a service. While the binding is not validated the site's manager is
 * never called. It serves clones; the site's definitions keep their provider.
 */
final class ScopedToolManager implements PluginManagerInterface {

  /**
   * The provider the clones carry, which passes the remote provider filter.
   */
  private const PROVIDER = 'mcp_tools_remote';

  /**
   * The clones, keyed by id, or NULL before the first validated read.
   */
  private ?array $definitions = NULL;

  /**
   * Constructs the scoped manager.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $inner
   *   The site's tool manager.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileBinding $binding
   *   The request's binding.
   */
  public function __construct(private readonly PluginManagerInterface $inner, private readonly ProfileBinding $binding) {
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
      $this->definitions = [];
      foreach ($this->inner->getDefinitions() as $id => $definition) {
        if (!in_array($id, $bound, TRUE) || !in_array($id, CinatraDevSite::EXPOSED_TOOLS, TRUE)
          || !$definition instanceof ToolDefinition || $definition->getProvider() !== 'cinatra') {
          continue;
        }
        $copy = clone $definition;
        $copy->set('provider', self::PROVIDER);
        $this->definitions[$id] = $copy;
      }
    }
    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    $definition = $this->getDefinitions()[$plugin_id] ?? NULL;
    if ($definition === NULL && $exception_on_invalid) {
      throw new PluginNotFoundException((string) $plugin_id);
    }
    return $definition;
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
