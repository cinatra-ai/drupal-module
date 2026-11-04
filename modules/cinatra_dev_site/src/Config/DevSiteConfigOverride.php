<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Config;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Points the remote server id at the cinatra profile while active.
 *
 * Only the effective value changes; the stored value is never written. The
 * exposure guard is asked only when the remote settings are requested, so no
 * other configuration read reaches State.
 */
final class DevSiteConfigOverride implements ConfigFactoryOverrideInterface {

  /**
   * The remote settings whose server id is overridden.
   */
  private const SETTINGS = 'mcp_tools_remote.settings';

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array(self::SETTINGS, $names, TRUE) || !CinatraDevSite::exposureActive()) {
      return [];
    }
    return [self::SETTINGS => ['server_id' => CinatraDevSite::PROFILE_ID]];
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
