<?php

declare(strict_types=1);

namespace Drupal\cinatra;

/**
 * PURE verification of a HOST-signed authenticated-preview request.
 *
 * The render half of the artifact-lifecycle review contract (cinatra#2046,
 * epic #2037) needs the Cinatra host to fetch a fully rendered page for a node
 * — INCLUDING an unpublished/draft one — so it can pin an inert capture of it
 * at review-gate creation. That fetch is a server-to-server GET, so it has to
 * be authenticated with a credential BOTH ends already hold.
 *
 * THE CREDENTIAL IS THE ONE THIS MODULE ALREADY VERIFIES-BY-CONSTRUCTION: the
 * connect-provisioned Standard-Webhooks shared secret (`webhook_secret` in
 * `cinatra.settings`, written together with `webhook_binding_id` by the connect
 * exchange). {@see \Drupal\cinatra\PublishWebhook::sign()} already computes the
 * exact Standard-Webhooks signature the host's library produces; this class
 * recomputes it for an INBOUND request and constant-time compares. No new
 * credential, no new key material, no new storage.
 *
 * The browser-facing short-lived token the widget uses is the WRONG primitive
 * here: it is host-ISSUED and cannot be verified module-side. The webhook
 * secret is the only credential both ends share that this module can verify
 * locally.
 *
 * SEMANTICS MIRRORED FROM THE WORDPRESS ADAPTER (wordpress-plugin#94), so the
 * one host capture pipeline speaks to both CMSes identically:
 *
 * - ID-BOUND canonical string. The signed content is `preview.<nid>` — never
 *   the request body (there is none). A signature minted for one node can
 *   therefore never be replayed against another.
 * - FRESHNESS. The `webhook-timestamp` must be inside a ±300s window, the
 *   Standard-Webhooks recommended tolerance the host signs with.
 * - CONSTANT-TIME comparison (`hash_equals`), over every candidate in the
 *   space-separated signature list the spec permits.
 * - FAIL CLOSED. An unconnected site (no secret), a missing/short/malformed
 *   header, a non-numeric timestamp, a non-decodable stored secret, and a
 *   forged signature are all a plain FALSE. There is no "best guess" arm.
 *
 * SINGLE-USE is deliberately NOT here: consuming a `webhook-id` is a stateful
 * side effect and this class is pure so its whole allow/deny matrix is provable
 * without Drupal. The consume lives in the access check
 * ({@see \Drupal\cinatra\Access\PreviewAccessCheck}), which performs it with an
 * ATOMIC key-value insert.
 */
final class PreviewAuth {

  /**
   * Replay/freshness window in seconds for a signed preview request.
   *
   * Mirrors the Standard-Webhooks recommended tolerance the cinatra host signs
   * with, and the WordPress adapter's identical constant.
   */
  public const TIMESTAMP_TOLERANCE_SECONDS = 300;

  /**
   * The scope-manifest field names this adapter marks as OWNED regions.
   *
   * `title` and `body` are the fixed Drupal content paths; every additional
   * region is a declared `field_*` on the node, recognised by prefix rather
   * than enumerated (the Drupal field set is open — unlike WordPress's four
   * fixed post fields). Kept next to the auth contract because the two together
   * ARE the host-facing preview contract.
   */
  public const REGION_TITLE = 'title';

  /**
   * The Drupal body-field region name (the field is literally named `body`).
   */
  public const REGION_BODY = 'body';

  /**
   * Prefix identifying a declared Drupal field region.
   */
  public const REGION_FIELD_PREFIX = 'field_';

  /**
   * Builds the canonical content the host signs for a node preview.
   *
   * BINDS the signature to this node id: the host signs exactly this string and
   * the module recomputes it from the id in the REQUESTED PATH, so a captured
   * signature cannot be pointed at a different node.
   *
   * @param int $nid
   *   The node id being previewed.
   *
   * @return string
   *   The canonical signed content.
   */
  public static function canonicalContent(int $nid): string {
    return 'preview.' . $nid;
  }

  /**
   * Verifies a host-signed preview request. PURE — no I/O, no state.
   *
   * @param string $secret
   *   The stored connect-provisioned webhook secret (whsec_-prefixed base64).
   *   An empty string means the site is not host-connected: always FALSE.
   * @param string $messageId
   *   The `webhook-id` header value.
   * @param string $timestampHeader
   *   The RAW `webhook-timestamp` header value (validated as digits here, so a
   *   caller can never smuggle a numeric-looking string past the check).
   * @param string $signatureHeader
   *   The RAW `webhook-signature` header value: one or more space-separated
   *   "v1,<base64>" entries, per the Standard-Webhooks spec.
   * @param int $nid
   *   The node id from the request path (the binding).
   * @param int $now
   *   The current time in seconds since the epoch (injected so freshness is
   *   provable without sleeping).
   *
   * @return bool
   *   TRUE only for a fresh, correctly-signed, id-bound request.
   */
  public static function verify(
    string $secret,
    string $messageId,
    string $timestampHeader,
    string $signatureHeader,
    int $nid,
    int $now,
  ): bool {
    if ($secret === '' || $messageId === '' || $timestampHeader === '' || $signatureHeader === '') {
      return FALSE;
    }
    if ($nid <= 0) {
      return FALSE;
    }
    // Strictly decimal: `ctype_digit` rejects a sign, whitespace, an exponent
    // and an empty string, so no lenient numeric coercion can widen the window.
    if (!ctype_digit($timestampHeader)) {
      return FALSE;
    }
    $timestamp = (int) $timestampHeader;
    if (abs($now - $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
      return FALSE;
    }

    $expected = PublishWebhook::sign($secret, $messageId, $timestamp, self::canonicalContent($nid));
    if ($expected === NULL) {
      // A malformed stored secret — fail closed, never guess a key.
      return FALSE;
    }

    // The spec permits a space-separated LIST of signatures (key rotation), so
    // every candidate is compared — always in constant time.
    $matched = FALSE;
    foreach (explode(' ', $signatureHeader) as $candidate) {
      if ($candidate !== '' && hash_equals($expected, $candidate)) {
        $matched = TRUE;
      }
    }
    return $matched;
  }

  /**
   * The OWNED-region name for a rendered Drupal field.
   *
   * Returns NULL when the field is not part of the adapter's scope manifest.
   *
   * The region vocabulary is joined to the connector's reviewable path set by
   * NAME (drupal-mcp-connector `resolveReviewablePaths`: `title`, `body`, and
   * every declared `field_*`), which is what lets the host place a proposed
   * field value into the region of the same name without core knowing a single
   * Drupal field literal. Anything else on the render — `links`, `comment`, an
   * extra-field, a contrib pseudo-field — is deliberately NOT anchored: it is
   * not reviewable content, so marking it would invite the host to compose a
   * value into chrome.
   *
   * @param string $fieldName
   *   The rendered element's `#field_name`.
   *
   * @return string|null
   *   The region name, or NULL when the field is not an owned region.
   */
  public static function regionForField(string $fieldName): ?string {
    if ($fieldName === self::REGION_TITLE || $fieldName === self::REGION_BODY) {
      return $fieldName;
    }
    if (str_starts_with($fieldName, self::REGION_FIELD_PREFIX) && strlen($fieldName) > strlen(self::REGION_FIELD_PREFIX)) {
      return $fieldName;
    }
    return NULL;
  }

}
