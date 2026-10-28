<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;

/**
 * Sets the remote server_id from a State flag, above the submodule's override.
 */
final class TestServerIdOverride implements ConfigFactoryOverrideInterface {

  /**
   * Constructs the override.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(
    private StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array('mcp_tools_remote.settings', $names, TRUE)) {
      return [];
    }
    $value = $this->state->get('cinatra_dev_site_test.server_id');
    if (!is_string($value)) {
      return [];
    }
    return ['mcp_tools_remote.settings' => ['server_id' => $value]];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'cinatra_dev_site_test';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

}
