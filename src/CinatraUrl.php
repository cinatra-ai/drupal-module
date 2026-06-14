<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * Shared validation + normalization for the configured Cinatra origin.
 *
 * The Cinatra URL is the origin the module talks to server-to-server (the
 * TokenController broker, the Connect handshake) and that the browser fetches
 * the local widget's runtime from. An attacker who can set it to an arbitrary
 * scheme/host turns the module into an SSRF / credential-exfiltration vector
 * (the long-lived API key is sent as a Bearer header to {URL}/api/...). So the
 * value is constrained to an HTTPS origin in production (HTTP is allowed only
 * for loopback hosts to keep local development working), with no userinfo,
 * query, or fragment. This mirrors the cinatra-core validateWidgetOrigin()
 * contract (cinatra#221) so the two sides agree on what a safe origin is.
 */
final class CinatraUrl {

  /**
   * Hosts for which plain HTTP is tolerated (local development only).
   */
  private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

  /**
   * Validates a Cinatra origin, returning the normalized value or NULL.
   *
   * @param string $value
   *   The raw URL as entered by the admin.
   *
   * @return string|null
   *   The normalized origin (scheme://host[:port], trailing slash stripped) if
   *   the value is a safe Cinatra origin, or NULL when it must be rejected.
   */
  public static function normalize(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    // Reject control characters / CR-LF (header & redirect smuggling).
    if (preg_match('/[\x00-\x1f\x7f-\x9f]/', $value) === 1) {
      return NULL;
    }
    $parts = parse_url($value);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return NULL;
    }
    $scheme = strtolower($parts['scheme']);
    $host = $parts['host'];
    // No credentials in the URL (https://user:pass@host hides the real origin).
    if (isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    // Origin only: reject anything beyond scheme://host[:port]. A path, query,
    // or fragment means the admin supplied more than an origin; reject it
    // outright rather than silently stripping it (mirrors cinatra-core
    // validateWidgetOrigin, and keeps the persisted value unambiguous). A bare
    // "/" path is the only path tolerated (a trailing slash on the origin).
    if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
      return NULL;
    }
    if (isset($parts['query']) || isset($parts['fragment'])) {
      return NULL;
    }
    // HTTPS always; HTTP only for loopback hosts (local dev).
    if ($scheme === 'https') {
      // Allowed.
    }
    elseif ($scheme === 'http' && in_array(strtolower($host), self::LOOPBACK_HOSTS, TRUE)) {
      // Allowed for local development only.
    }
    else {
      return NULL;
    }

    $origin = $scheme . '://' . $host;
    if (isset($parts['port'])) {
      $port = (int) $parts['port'];
      $is_default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
      if (!$is_default) {
        $origin .= ':' . $port;
      }
    }
    return $origin;
  }

  /**
   * Whether a raw URL is a valid Cinatra origin.
   */
  public static function isValid(string $value): bool {
    return self::normalize($value) !== NULL;
  }

}
