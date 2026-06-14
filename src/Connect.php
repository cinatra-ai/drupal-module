<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * Pure helpers for the "Connect with Cinatra" provisioning handshake.
 *
 * Implements the drupal-module half of the cinatra#221 contract (the cinatra
 * core authorize/consent + code-exchange endpoints, and the WordPress plugin
 * side, already shipped). These helpers are side-effect-free so they are unit
 * testable; all I/O (HTTP, state storage, config writes) lives in
 * \Drupal\cinatra\Controller\ConnectController and the settings form.
 *
 * Security-relevant constants/shapes are pinned to the cinatra-core contract:
 *  - the Drupal callback PATH is fixed (cinatra-core validateRedirectUri
 *    pins it
 *    and rejects ANY query/fragment/userinfo on it),
 *  - PKCE is S256 (code_challenge = base64url(sha256(code_verifier))),
 *  - scope is the provisioning scope, client is "drupal".
 */
final class Connect {

  /**
   * The connect client identifier sent to cinatra-core (pinned).
   */
  public const CLIENT = 'drupal';

  /**
   * The provisioning scope sent to cinatra-core (pinned).
   */
  public const SCOPE = 'connector:provision';

  /**
   * PKCE challenge method (cinatra-core only accepts S256).
   */
  public const CODE_CHALLENGE_METHOD = 'S256';

  /**
   * The EXACT, contract-pinned callback path for the drupal client.
   *
   * Cinatra-core validateRedirectUri() requires this exact path and rejects any
   * query string, fragment, or userinfo on the drupal callback. We build and
   * re-assert this literal value rather than trusting URL generation so a base
   * path / language prefix can never silently change it.
   */
  public const CALLBACK_PATH = '/admin/config/services/cinatra/connect/callback';

  /**
   * Lifetime of a pending connect handshake (state + PKCE verifier), seconds.
   */
  public const STATE_TTL = 600;

  /**
   * Upper bound on an accepted connection string (defensive input bound).
   */
  private const MAX_CONNECTION_STRING = 4096;

  /**
   * Hosts for which a plain-HTTP callback is tolerated (local dev only).
   */
  private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

  /**
   * URL-safe base64 with no padding (RFC 7636 / base64url).
   */
  public static function base64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  /**
   * Generates a PKCE verifier (unreserved base64url) and its S256 challenge.
   *
   * @return array{verifier: string, challenge: string}
   *   The verifier (kept server-side) and the challenge (sent to cinatra-core).
   */
  public static function pkce(): array {
    $verifier = self::base64url(random_bytes(48));
    $challenge = self::base64url(hash('sha256', $verifier, TRUE));
    return ['verifier' => $verifier, 'challenge' => $challenge];
  }

  /**
   * Generates an opaque single-use state value.
   */
  public static function newState(): string {
    return self::base64url(random_bytes(32));
  }

  /**
   * The server-side state-store key for a given state (never store state raw).
   */
  public static function stateKey(string $state): string {
    return hash('sha256', $state);
  }

  /**
   * Builds the cinatra-core authorize URL for the redirect handshake.
   *
   * @param string $instanceBase
   *   A CinatraUrl::normalize()-validated instance origin (no trailing slash).
   * @param string $redirectUri
   *   The absolute callback URI (must satisfy isValidCallbackUri()).
   * @param string $widgetOrigin
   *   This site's origin (scheme://host[:port]).
   * @param string $state
   *   The opaque single-use state.
   * @param string $challenge
   *   The PKCE S256 challenge.
   *
   * @return string
   *   The fully-built authorize URL to redirect the browser to.
   */
  public static function authorizeUrl(
    string $instanceBase,
    string $redirectUri,
    string $widgetOrigin,
    string $state,
    string $challenge,
  ): string {
    $query = http_build_query([
      'client' => self::CLIENT,
      'redirect_uri' => $redirectUri,
      'state' => $state,
      'scope' => self::SCOPE,
      'code_challenge' => $challenge,
      'code_challenge_method' => self::CODE_CHALLENGE_METHOD,
      'widget_origin' => $widgetOrigin,
    ], '', '&', PHP_QUERY_RFC3986);
    return rtrim($instanceBase, '/') . '/connect/authorize?' . $query;
  }

  /**
   * Asserts a callback URI matches the contract-pinned shape exactly.
   *
   * Cinatra-core rejects the redirect_uri unless its path is exactly
   * CALLBACK_PATH with no query, fragment, or userinfo. We re-assert it here so
   * a base-path/language-prefix-mangled generated URL is caught before we ever
   * hand it to the instance (defensive; mirrors the core contract).
   */
  public static function isValidCallbackUri(string $uri): bool {
    if ($uri === '' || preg_match('/[\x00-\x1f\x7f-\x9f]/', $uri) === 1) {
      return FALSE;
    }
    $parts = parse_url($uri);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return FALSE;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower((string) $parts['host']);
    // HTTPS always; plain HTTP only for loopback hosts (local dev). A public
    // non-TLS callback would expose the returned code/state over the wire, so
    // this mirrors the CinatraUrl origin gate (cinatra#221 redirect contract).
    if ($scheme === 'https') {
      // Allowed.
    }
    elseif ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, TRUE)) {
      // Allowed for local development only.
    }
    else {
      return FALSE;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    if (isset($parts['query']) || isset($parts['fragment'])) {
      return FALSE;
    }
    return ($parts['path'] ?? '') === self::CALLBACK_PATH;
  }

  /**
   * Parses a connection string for the install-code fallback path.
   *
   * Accepts either `cinatra-connect:<base64url(json)>` or a bare JSON object
   * `{"url":"…","install_code":"…"}`. The decoded URL is validated/normalized
   * via CinatraUrl::normalize() so the fallback path applies the same SSRF gate
   * as the redirect path. Returns NULL on any malformed/oversized/unsafe input.
   *
   * @return array{instance_url: string, install_code: string}|null
   *   The normalized instance origin and the install code, or NULL.
   */
  public static function parseConnectionString(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > self::MAX_CONNECTION_STRING) {
      return NULL;
    }
    $json = NULL;
    $prefix = 'cinatra-connect:';
    if (stripos($raw, $prefix) === 0) {
      $payload = substr($raw, strlen($prefix));
      $decoded = base64_decode(strtr($payload, '-_', '+/'), TRUE);
      if ($decoded !== FALSE) {
        $json = json_decode($decoded, TRUE);
      }
    }
    else {
      $json = json_decode($raw, TRUE);
    }
    if (!is_array($json)) {
      return NULL;
    }
    $rawUrl = (string) ($json['url'] ?? $json['instance_url'] ?? '');
    $rawCode = (string) ($json['install_code'] ?? $json['code'] ?? '');
    $url = CinatraUrl::normalize($rawUrl);
    // The install code is an opaque bearer ("cci_…"); accept only printable,
    // bounded, whitespace-free tokens and never reflect a rejected value.
    $code = trim($rawCode);
    if ($url === NULL || $code === '' || strlen($code) > 512 || preg_match('/\s/', $code) === 1) {
      return NULL;
    }
    return ['instance_url' => $url, 'install_code' => $code];
  }

}
