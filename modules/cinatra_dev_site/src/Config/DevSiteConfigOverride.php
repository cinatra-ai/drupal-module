<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Config;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;

/**
 * Points the remote server id at the cinatra profile while armed.
 *
 * The stored value is never written.
 */
final class DevSiteConfigOverride implements ConfigFactoryOverrideInterface {

  /**
   * Constructs the override.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (in_array('mcp_tools_remote.settings', $names, TRUE) && $this->state->get(CinatraDevSite::ARMED_KEY) === TRUE) {
      return ['mcp_tools_remote.settings' => ['server_id' => CinatraDevSite::PROFILE_ID]];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'cinatra_dev_site';
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
