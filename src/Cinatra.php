<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * Module-wide constants for the Cinatra integration.
 */
final class Cinatra {

  /**
   * Plugin-to-core wire-contract version.
   *
   * Cinatra rejects unknown versions with an admin-visible error. See the
   * cinatra repo: contracts/wp-drupal-assistant/. v2 drops the browser apiKey:
   * the widget exchanges a short-lived token via the same-origin cinatra.token
   * broker route instead. The bundled widget JS negotiates capabilities at boot
   * and falls back to v1 against older instances.
   */
  public const CONTRACT_VERSION = 'v2';

}
