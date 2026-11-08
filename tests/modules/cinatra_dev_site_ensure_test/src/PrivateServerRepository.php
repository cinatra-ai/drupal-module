<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

/**
 * A server repository whose getServer() DevSiteEnsure cannot call.
 */
final class PrivateServerRepository {

  /**
   * Reads a profile; protected, so DevSiteEnsure cannot call it.
   *
   * @param string $id
   *   The profile id.
   *
   * @return array|null
   *   Never a profile.
   */
  protected function getServer(string $id): ?array {
    return $id === '' ? [] : NULL;
  }

}
