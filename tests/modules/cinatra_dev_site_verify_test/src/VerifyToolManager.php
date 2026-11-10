<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_verify_test;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * A tool manager that serves the definitions the test sets.
 */
final class VerifyToolManager implements PluginManagerInterface {

  /**
   * The definitions, keyed by id.
   *
   * @var array
   */
  public static array $definitions = [];

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return self::$definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    if (!isset(self::$definitions[$plugin_id]) && $exception_on_invalid) {
      throw new PluginNotFoundException((string) $plugin_id);
    }
    return self::$definitions[$plugin_id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return isset(self::$definitions[$plugin_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    throw new PluginNotFoundException((string) $plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return FALSE;
  }

}
