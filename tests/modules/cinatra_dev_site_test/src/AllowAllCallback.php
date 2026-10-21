<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Symfony\Component\HttpFoundation\Request;

/**
 * A permission callback that allows every request (a foreign replacement).
 */
final class AllowAllCallback {

  /**
   * Allows the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request.
   * @param array $profile
   *   The server profile.
   *
   * @return bool
   *   Always TRUE.
   */
  public function check(?Request $request, array $profile): bool {
    return TRUE;
  }

}
