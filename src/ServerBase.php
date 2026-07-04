<?php

declare(strict_types=1);

namespace Drupal\cinatra;

use Psr\Log\LoggerInterface;

/**
 * Resolves the server-to-server base origin for PHP->cinatra calls.
 *
 * CINATRA_BASE_URL is a dev/container-topology override: it exists ONLY so a
 * containerized Drupal (which cannot reach the host's Cinatra via the
 * browser-facing origin) can be pointed at the container-reachable base
 * WITHOUT changing what the browser is told. It is applied ONLY to
 * server-to-server calls (the token broker exchange and the node-publish
 * webhook emitter); never to the site-origin binding nor to any URL handed to
 * client JavaScript.
 *
 * SECURITY: the resolved base becomes the destination of requests that carry
 * credentials (the long-lived API key on the token exchange; the webhook
 * signature on the publish emitter). An unvalidated env value would let an
 * operator-set or environment-injected CINATRA_BASE_URL redirect those
 * requests to an arbitrary host/scheme/path. So the env value is validated
 * and canonicalized to a bare scheme://host[:port] origin, and its host is
 * required to be one of the fixed container hosts, before it is trusted;
 * anything that fails validation is rejected (no use, no leak of its value)
 * and the configured `cinatra_url` is used instead.
 *
 * Extracted verbatim from the token broker controller (cinatra#974) so the
 * publish emitter shares the SAME validated resolution instead of duplicating
 * or skipping it. Behavior is unchanged.
 */
final class ServerBase {

  /**
   * Host allowlist for the OPTIONAL CINATRA_BASE_URL server-to-server override.
   *
   * After the anchored grammar validates + canonicalizes the origin, the host
   * is required to be EXACTLY one of these (compared case-insensitively; for a
   * bracketed IPv6 host the unbracketed address is compared). Anything else is
   * discarded and the configured `cinatra_url` is used instead.
   *
   * This matches the companion WordPress plugin's container-host restriction
   * (localhost / 127.0.0.1 / host.docker.internal); the IPv6 loopback "::1" is
   * additionally accepted here because this module's grammar fully handles the
   * bracketed-host form (http://[::1]:port) and the kernel accept-tests pin it.
   */
  private const SERVER_BASE_ALLOWED_HOSTS = [
    'localhost',
    '127.0.0.1',
    'host.docker.internal',
    '::1',
  ];

  /**
   * Resolves the base URL a server-side call reaches the instance at.
   *
   * Precedence: the CINATRA_BASE_URL environment variable when it is set to a
   * non-empty value AND passes validation, otherwise the configured
   * (browser-facing) Cinatra URL.
   *
   * Production parity: when the env is unset/blank the configured URL is
   * returned exactly as the caller passed it (already trailing-slash-trimmed),
   * with no additional normalization — so a configured trailing-slash URL
   * yields the identical pre-existing endpoint.
   *
   * @param string $configUrl
   *   The configured Cinatra URL (already trailing-slash-trimmed).
   * @param \Psr\Log\LoggerInterface $logger
   *   Channel that receives the (value-free) rejected-override warning.
   *
   * @return string
   *   The server-to-server base origin, trailing slash trimmed.
   */
  public static function resolve(string $configUrl, LoggerInterface $logger): string {
    $env = getenv('CINATRA_BASE_URL');
    if (!is_string($env) || trim($env) === '') {
      // Unset or blank: production path — use the configured URL verbatim.
      return $configUrl;
    }

    $canonical = self::canonicalize($env);
    if ($canonical === NULL) {
      // The override failed validation. Never use it and never echo its value
      // (it may be hostile and is adjacent to a credential-bearing request);
      // log only that the override was rejected, then fall back to the
      // configured URL.
      $logger->warning('Ignoring invalid CINATRA_BASE_URL override; using the configured Cinatra URL for the server-to-server call.');
      return $configUrl;
    }

    return $canonical;
  }

  /**
   * Validates and canonicalizes a CINATRA_BASE_URL override to a base origin.
   *
   * The override is accepted ONLY if the ENTIRE raw string matches an exact,
   * anchored bare-origin grammar — parse_url() is NOT trusted to police the
   * value, because it is too permissive (it accepts and partially
   * canonicalizes malformed ports such as ":80x", ":+80", ":1.2" to a real
   * port, and it lets backslash hosts, unbracketed IPv6, and extra-colon forms
   * through to downstream parsers instead of a clean rejection). Since this
   * base becomes the destination of credential-bearing requests, the value
   * must be proven safe by construction before it is trusted.
   *
   * After the grammar validates + canonicalizes the origin, the host is ALSO
   * required to be one of self::SERVER_BASE_ALLOWED_HOSTS (the container-host
   * allowlist). A clean but non-allowlisted host (e.g. "http://evil.example")
   * is therefore rejected here too, so the override can never redirect a
   * credential-bearing request to an arbitrary host. For a bracketed IPv6
   * literal the unbracketed address is compared against the allowlist.
   *
   * Accepted grammar (anchored ^...\z, scheme case-insensitive; no whitespace,
   * control chars, userinfo, query, or fragment anywhere):
   * - scheme: exactly "http://" or "https://";
   * - host: ONE of —
   *     (i)   a DNS hostname of dot-separated labels, each matching
   *           [A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])? (so "localhost",
   *           "host.docker.internal", "safe.example" pass; a trailing dot,
   *           a backslash, or an empty label fails);
   *     (ii)  a dotted IPv4 d{1,3}(.d{1,3}){3} with each octet 0–255;
   *     (iii) a BRACKETED IPv6 literal \[[0-9A-Fa-f:]+\] (unbracketed IPv6
   *           is rejected);
   * - optional port: a single ":" followed by purely digits, value 1–65535
   *   (so ":0", ":80x", ":+80", ":1.2", ":65536", and an empty ":" all fail);
   * - optional path: nothing, or exactly a single "/".
   *
   * On a match the canonical bare origin "scheme://host[:port]" is returned
   * (any trailing "/" is dropped, since the request path is appended by the
   * caller). Returns NULL when the value is not a safe bare origin so the
   * caller rejects it and falls back to the configured URL.
   *
   * The regex is deliberately linear (anchored, no nested quantifiers / no
   * "(X+)+" backtracking traps) to avoid any ReDoS exposure on env input.
   *
   * @param string $value
   *   The raw environment value.
   *
   * @return string|null
   *   The canonical scheme://host[:port] origin, or NULL when invalid.
   */
  public static function canonicalize(string $value): ?string {
    // Reject any control characters or whitespace anywhere in the value before
    // anything else (a bare origin contains none; their presence signals
    // injection/corruption). The grammar below also forbids them, but this is
    // an explicit, cheap first gate.
    if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
      return NULL;
    }

    // Anchored, linear bare-origin grammar. Each alternative is a simple
    // character class with a bounded or single quantifier (no nesting), so
    // matching is O(n) in the input length. Captures: 1=scheme, 2=host,
    // 3=port digits when present.
    //
    //   scheme   : https? (case-insensitive via the /i flag)
    //   host     : IPv4-shaped | bracketed IPv6 | dot-separated DNS labels
    //   port     : : followed by 1–5 digits (range checked numerically below)
    //   path     : optional single /
    //
    // Note: the IPv4 octet range (0–255) and the port range (1–65535) are not
    // expressible cleanly without nesting, so the shape is matched here and the
    // numeric ranges are verified explicitly afterwards.
    $pattern = '#^(https?)://'
      // host: one of the three forms (each linear, no nested quantifiers).
      . '('
      // (ii) IPv4 shape: four 1–3 digit octets (range checked below).
      . '[0-9]{1,3}(?:\.[0-9]{1,3}){3}'
      . '|'
      // (iii) bracketed IPv6 literal: [ hexdigits-and-colons ].
      . '\[[0-9A-Fa-f:]+\]'
      . '|'
      // (i) DNS hostname: dot-separated labels, each starting and ending with
      // an alphanumeric, hyphens only in the interior. The repeated label group
      // is a single (?:...)* over a linear label — no (X+)+ backtracking.
      . '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*'
      . ')'
      // Optional port: a single colon then 1–5 digits (range checked below).
      . '(?::([0-9]{1,5}))?'
      // Optional path: nothing or exactly one slash. End anchored with \z
      // (not $): \z matches ONLY the absolute end of the string, so a trailing
      // newline cannot be tolerated the way "$" can (which matches before a
      // final \n). The control/whitespace pre-gate above already blocks \n;
      // \z makes the anchoring self-contained as defense-in-depth.
      . '/?\z#i';

    if (preg_match($pattern, $value, $m) !== 1) {
      return NULL;
    }

    $scheme = strtolower($m[1]);
    $host = $m[2];

    // Container-host allowlist. The grammar above proves the value is a clean
    // bare origin, but a clean origin can still name an ARBITRARY host (e.g.
    // "http://evil.example:3000"). CINATRA_BASE_URL is only ever a container
    // override, so the host must be one of the fixed container hosts; anything
    // else is discarded (NULL) and the caller falls back to the configured
    // cinatra_url — a credential-bearing request can never reach an
    // off-allowlist host. Compare case-insensitively; for a bracketed IPv6
    // literal compare the unbracketed address (so "[::1]" is matched against
    // "::1").
    $hostForAllowlist = strtolower($host);
    if (str_starts_with($hostForAllowlist, '[') && str_ends_with($hostForAllowlist, ']')) {
      $hostForAllowlist = substr($hostForAllowlist, 1, -1);
    }
    if (!in_array($hostForAllowlist, self::SERVER_BASE_ALLOWED_HOSTS, TRUE)) {
      return NULL;
    }

    // IPv4 octet range: if the host is the dotted-quad shape, every octet must
    // be 0–255 (the grammar only bounds it to 1–3 digits). A bracketed IPv6 or
    // a DNS label that merely looks numeric is left untouched.
    if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,3}){3}$/', $host) === 1) {
      foreach (explode('.', $host) as $octet) {
        if ((int) $octet > 255) {
          return NULL;
        }
      }
    }

    $origin = $scheme . '://' . $host;

    // Port range: present only when the optional group matched. Reject 0 and
    // anything above 65535 (the grammar bounds it to 1–5 digits).
    if (isset($m[3]) && $m[3] !== '') {
      $port = (int) $m[3];
      if ($port < 1 || $port > 65535) {
        return NULL;
      }
      $origin .= ':' . $port;
    }

    return $origin;
  }

}
