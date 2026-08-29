<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * HTTP-layer SSRF guard for the module's server-to-server calls.
 *
 * CinatraUrl::normalize constrains the CONFIGURED origin to a well-formed
 * http(s) scheme://host[:port], but it does NOT stop that host from being a
 * private, loopback, link-local, or otherwise non-public address — e.g. the
 * cloud metadata endpoint 169.254.169.254, an internal 10.0.0.0/8 service, or a
 * hostname that RESOLVES to one. This class is the defense-in-depth layer the
 * WordPress companion gets for free from wp_safe_remote_post: every
 * server-to-server request target is checked here immediately before the call,
 * and Guzzle redirect-following is disabled at the call sites so a 3xx pointed
 * at an internal address cannot bypass this check.
 *
 * Policy: the documented local-development hosts are allowed as-is; an IP
 * literal, or a hostname that RESOLVES, must be a public (globally-routable)
 * address — loopback/private/link-local targets (incl. the metadata IP) are
 * refused. A host that cannot be resolved at all is allowed (see isAllowedUrl:
 * the request would just fail to connect; blocking would only add fragility).
 * These guards only ever run against the admin-configured Cinatra origin (or
 * the operator-set CINATRA_BASE_URL container override) — never a value chosen
 * by a browser caller.
 */
final class Ssrf {

  /**
   * Development hosts that are always permitted (loopback / docker host).
   *
   * Exactly the hosts the module already treats as dev-safe: CinatraUrl's
   * loopback set plus the ServerBase container-host override allowlist.
   * They resolve to loopback / host-gateway addresses the private-range filter
   * would otherwise block, so the supported local/container topology (a
   * cinatra_url of http://localhost:3000, http://host.docker.internal:3000,
   * http://[::1]:3000) keeps working.
   */
  private const DEV_ALLOWED_HOSTS = [
    'localhost',
    '127.0.0.1',
    '::1',
    'host.docker.internal',
  ];

  /**
   * Whether a URL is a safe server-to-server request target.
   *
   * @param string $url
   *   The absolute request URL (already origin-validated by CinatraUrl).
   *
   * @return bool
   *   TRUE when the request may proceed; FALSE when it must be blocked.
   */
  public static function isAllowedUrl(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return FALSE;
    }
    // Defense-in-depth even though CinatraUrl ran first: http(s) only.
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
      return FALSE;
    }
    // No embedded userinfo: a URL carrying a username/password before the
    // host hides the real origin, so reject it outright.
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    // Normalize the host: strip IPv6 brackets, a trailing FQDN dot, an IPv6
    // zone id, and case, so an equivalent obfuscated spelling cannot slip a
    // dev-host match or an IP-literal check.
    $host = strtolower(trim((string) $parts['host']));
    $host = trim($host, '[]');
    $host = rtrim($host, '.');
    $zone = strpos($host, '%');
    if ($zone !== FALSE) {
      $host = substr($host, 0, $zone);
    }
    if ($host === '') {
      return FALSE;
    }
    // Documented dev hosts are always allowed.
    if (in_array($host, self::DEV_ALLOWED_HOSTS, TRUE)) {
      return TRUE;
    }
    // Resolve to candidate IPs. An IP literal resolves to itself; a hostname is
    // resolved so a name pointing at a private address is also blocked (this
    // also blocks decimal/octal/hex IPv4 spellings such as http://2130706433,
    // which are not IP literals here but resolve to 127.0.0.1).
    $ips = self::resolveIps($host);
    if ($ips === []) {
      // Unresolvable host: the SAME system resolver backs the actual Guzzle
      // request, so a host we cannot resolve here cannot be reached by the
      // request either (it simply fails to connect) — there is no internal
      // resource to protect, so allow it rather than fail-closed. Blocking it
      // would only add fragility (a transient DNS hiccup would take the widget
      // down) with no security gain. IP-literal loopback/private/link-local
      // targets are already blocked above; a name that DOES resolve to such an
      // address is blocked below. The residual (a name that resolves
      // differently between this check and the request — DNS rebinding) is the
      // documented TOCTOU residual and is out of scope for an admin-configured
      // origin.
      return TRUE;
    }
    foreach ($ips as $ip) {
      if (!self::isPublicIp($ip)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether an IP literal is a public (globally-routable) address.
   *
   * Rejects private (10/8, 172.16/12, 192.168/16, IPv6 ULA fc00::/7) AND
   * reserved (loopback 127/8 + ::1, link-local 169.254/16 incl. the cloud
   * metadata IP + fe80::/10, 0.0.0.0/8, multicast, IPv4-mapped-IPv6 forms of
   * all the above, …) ranges. Anything else is treated as public.
   *
   * The IPv4-embedding IPv6 forms are classified HERE rather than left to
   * FILTER_FLAG_NO_RES_RANGE, because that flag's range table is
   * runtime-dependent: PHP 8.1 and 8.2 do not recognize them at all, so
   * ::ffff:169.254.169.254 — the cloud metadata address written as an
   * IPv4-mapped literal — passes as public there, while PHP 8.3 and later
   * reject it. The module supports PHP 8.1 upwards, so a guard that inherited
   * that table would admit the metadata endpoint on the oldest supported
   * runtime. See isIpv4EmbeddingIpv6 for the prefixes and the ranges each one
   * covers; the check only ever ADDS a rejection to the filter below.
   */
  public static function isPublicIp(string $ip): bool {
    if (self::isIpv4EmbeddingIpv6($ip)) {
      return FALSE;
    }
    return filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) !== FALSE;
  }

  /**
   * Whether an address is an IPv6 literal that embeds an IPv4 address.
   *
   * These are never a valid public destination on their own: each one is a
   * different spelling of, or a translation gateway onto, an IPv4 address, so
   * an internal IPv4 target can be smuggled past an IPv6-unaware range check
   * by writing it in one of these forms. All of them are refused.
   *
   * Matched on the PACKED 16-byte address (inet_pton), never on the text: the
   * same address has many textual spellings, and a substring match on "::ffff:"
   * would both miss a compressed form and hit an unrelated address.
   *
   * @param string $ip
   *   The candidate address.
   *
   * @return bool
   *   TRUE when the address falls in one of the IPv4-embedding IPv6 prefixes.
   */
  private static function isIpv4EmbeddingIpv6(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
      return FALSE;
    }
    $packed = @inet_pton($ip);
    if (!is_string($packed) || strlen($packed) !== 16) {
      return FALSE;
    }
    $prefixes = [
      // ::/96 — IPv4-compatible IPv6 (RFC 4291, deprecated). Also covers the
      // unspecified address and ::1, which the range filter already refuses.
      str_repeat("\x00", 12),
      // ::ffff:0:0/96 — IPv4-mapped IPv6 (RFC 4291): ::ffff:169.254.169.254.
      str_repeat("\x00", 10) . "\xff\xff",
      // 64:ff9b::/96 — the well-known NAT64 prefix (RFC 6052).
      "\x00\x64\xff\x9b" . str_repeat("\x00", 8),
      // 64:ff9b:1::/48 — the local-use NAT64 prefix (RFC 8215).
      "\x00\x64\xff\x9b\x00\x01",
    ];
    foreach ($prefixes as $prefix) {
      if (strncmp($packed, $prefix, strlen($prefix)) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolve a host to its candidate IP addresses (IPv4 + IPv6).
   *
   * @param string $host
   *   The normalized host (brackets/zone/trailing-dot already stripped).
   *
   * @return string[]
   *   The resolved IPs — a single-element list when the host is an IP literal,
   *   an empty list when the host cannot be resolved.
   */
  private static function resolveIps(string $host): array {
    // IP literal — resolves to itself, no DNS lookup.
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [$host];
    }
    $ips = [];
    // IPv4 (A) records.
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
      foreach ($v4 as $ip) {
        $ips[] = $ip;
      }
    }
    // IPv6 (AAAA) records — combined by append, never with `+` (which would
    // drop numerically-keyed right-hand entries).
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
      foreach ($aaaa as $rec) {
        if (isset($rec['ipv6']) && is_string($rec['ipv6']) && $rec['ipv6'] !== '') {
          $ips[] = $rec['ipv6'];
        }
      }
    }
    return array_values(array_unique($ips));
  }

}
