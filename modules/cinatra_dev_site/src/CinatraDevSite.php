<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site;

/**
 * The development marker and the one exposure guard.
 *
 * Every path that serves the exposure asks exposureActive() before any work.
 * It is the only reader of the armed State flag, and it also requires the
 * development marker of the running process: a database restored on a site
 * without the marker serves nothing.
 */
final class CinatraDevSite {

  /**
   * The environment variable that marks a development site; only "1" counts.
   */
  public const MARKER_ENV = 'CINATRA_DEV_SITE';

  /**
   * The State key that arms the exposure; only TRUE counts.
   */
  public const ARMED_KEY = 'cinatra_dev_site.armed';

  /**
   * The fixed answer to a remote request that the exposure refuses.
   */
  public const DENY_TEXT = 'cinatra_dev_site: the remote request was refused.';

  /**
   * Whether this process may serve the exposure.
   *
   * @return bool
   *   TRUE only when the marker is "1" and the State flag is exactly TRUE.
   *   The marker is read first, so without it State is not read.
   */
  public static function exposureActive(): bool {
    return self::markerPresent() && \Drupal::state()->get(self::ARMED_KEY) === TRUE;
  }

  /**
   * Whether the running process carries the development marker.
   *
   * @return bool
   *   TRUE only when CINATRA_DEV_SITE is exactly "1".
   */
  public static function markerPresent(): bool {
    return getenv(self::MARKER_ENV) === '1';
  }

  /**
   * Removes the armed State flag.
   */
  public static function disarm(): void {
    \Drupal::state()->delete(self::ARMED_KEY);
  }

}
