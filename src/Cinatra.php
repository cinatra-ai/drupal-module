<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * Module-wide constants for the Cinatra integration.
 */
final class Cinatra {

  /**
   * Plugin-to-core token-exchange wire-contract version.
   *
   * Cinatra rejects unknown versions with an admin-visible error. See the
   * cinatra repo: contracts/wp-drupal-assistant/. v2 drops the browser apiKey:
   * the widget exchanges a short-lived cit_ token via the same-origin
   * cinatra.token broker route instead. This constant is the AUTHORITATIVE
   * token-exchange contract version the broker sends to
   * /api/agents/{slug}/token — the bundled widget JS no longer negotiates it.
   * Since the S5 unified-broker cutover (cinatra#2029) the AG-UI
   * capability/contract handshake runs CLIENT-SIDE inside the /embed/assistant
   * iframe against the unified broker (GET /api/assistants/chat/capabilities);
   * the legacy shell pre-flight against /api/agents/{slug}/capabilities was
   * retired (cinatra#1991).
   */
  public const CONTRACT_VERSION = 'v2';

}
