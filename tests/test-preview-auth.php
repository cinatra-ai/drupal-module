<?php

/**
 * @file
 * Standalone harness for the authenticated-preview AUTH + ANCHOR contract.
 *
 * WHY A STANDALONE HARNESS. The module's PHPUnit suite under tests/src needs a
 * bootstrapped Drupal (the drupal.org runner is currently skipped — see
 * .gitlab-ci.yml SKIP_PHPUNIT — and the GitHub gates are php -l / phpcs /
 * phpstan). The preview auth boundary is the security-critical part of
 * cinatra#2046, so it gets a gate that actually RUNS on every pull request,
 * with no Drupal install: the classes under test are deliberately free of
 * Drupal service dependencies, so they can be exercised directly.
 *
 * Run: php tests/test-preview-auth.php
 *
 * Covered here (the parts provable without a kernel):
 *  - Standard-Webhooks verification: accept, and every deny arm.
 *  - Id binding: a signature minted for node A never verifies for node B.
 *  - Freshness: both edges of the +/-300s window.
 *  - The signature LIST form the spec permits.
 *  - Region vocabulary: exactly title / body / field_*, nothing else.
 *  - Anchor markup: shape, escaping, idempotency, and inertness of the render
 *    flag (no target => nothing is ever a target).
 *  - The response subscriber's anchored path match.
 *
 * The Drupal-integration half (route 401/200, draft rendering, replay consume,
 * anchors present in a preview and ABSENT on the public node page) lives in
 * tests/src/Functional/PreviewRouteTest.php and runs against a real Drupal.
 */

declare(strict_types=1);

// Minimal stand-ins for the two framework symbols the classes under test
// reference, so no Drupal install is needed. See each shim's own header.
require_once __DIR__ . '/shims/Html.php';
require_once __DIR__ . '/shims/EventSubscriberInterface.php';

require_once __DIR__ . '/../src/PublishWebhook.php';
require_once __DIR__ . '/../src/PreviewAuth.php';
require_once __DIR__ . '/../src/PreviewRender.php';
require_once __DIR__ . '/../src/EventSubscriber/PreviewResponseSubscriber.php';

use Drupal\cinatra\PreviewAuth;
use Drupal\cinatra\PreviewRender;
use Drupal\cinatra\PublishWebhook;
use Drupal\cinatra\EventSubscriber\PreviewResponseSubscriber;

$checks = 0;
$failures = 0;

// A closure over by-reference counters: no globals (the Drupal standard forbids
// them) and no throwaway class in a test script.
$check = function (bool $ok, string $label) use (&$checks, &$failures): void {
  $checks++;
  if ($ok) {
    echo "  ok   $label\n";
    return;
  }
  $failures++;
  echo "  FAIL $label\n";
};

// Signs a preview request exactly as the cinatra host does.
$sign = function (string $secret, string $id, int $ts, int $nid): string {
  $sig = PublishWebhook::sign($secret, $id, $ts, PreviewAuth::canonicalContent($nid));
  if ($sig === NULL) {
    throw new \RuntimeException('the test secret does not decode');
  }
  return $sig;
};

$secret = 'whsec_MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
$now = 1751587200;
$nid = 42;
$id = 'preview-msg-1';
$good = $sign($secret, $id, $now, $nid);

echo "\n[1] canonical signed content is id-bound\n";
$check(PreviewAuth::canonicalContent(42) === 'preview.42', 'canonical content is preview.<nid>');
$check(PreviewAuth::canonicalContent(7) !== PreviewAuth::canonicalContent(8), 'a different node signs different content');

echo "\n[2] the accept path\n";
$check(PreviewAuth::verify($secret, $id, (string) $now, $good, $nid, $now), 'a fresh, correctly signed request verifies');
$check(PreviewAuth::verify($secret, $id, (string) $now, 'v1,bogus ' . $good, $nid, $now), 'the space-separated signature LIST form verifies');
$check(PreviewAuth::verify($secret, $id, (string) ($now - 299), $sign($secret, $id, $now - 299, $nid), $nid, $now), 'inside the freshness window (-299s) verifies');
$check(PreviewAuth::verify($secret, $id, (string) ($now + 299), $sign($secret, $id, $now + 299, $nid), $nid, $now), 'inside the freshness window (+299s) verifies');
$check(PreviewAuth::verify(substr($secret, strlen('whsec_')), $id, (string) $now, $good, $nid, $now), 'the whsec_ prefix is optional (bare base64 key)');

echo "\n[3] the deny arms — fail CLOSED, every one\n";
$check(!PreviewAuth::verify('', $id, (string) $now, $good, $nid, $now), 'site not host-connected (no secret) denies');
$check(!PreviewAuth::verify($secret, '', (string) $now, $good, $nid, $now), 'missing webhook-id denies');
$check(!PreviewAuth::verify($secret, $id, '', $good, $nid, $now), 'missing webhook-timestamp denies');
$check(!PreviewAuth::verify($secret, $id, (string) $now, '', $nid, $now), 'missing webhook-signature denies');
$check(!PreviewAuth::verify($secret, $id, 'not-a-number', $good, $nid, $now), 'non-numeric timestamp denies');
$check(!PreviewAuth::verify($secret, $id, '-' . $now, $good, $nid, $now), 'signed/negative timestamp denies (ctype_digit is strict)');
$check(!PreviewAuth::verify($secret, $id, ' ' . $now, $good, $nid, $now), 'whitespace-padded timestamp denies');
$check(!PreviewAuth::verify($secret, $id, (string) ($now - 301), $sign($secret, $id, $now - 301, $nid), $nid, $now), 'stale beyond -300s denies');
$check(!PreviewAuth::verify($secret, $id, (string) ($now + 301), $sign($secret, $id, $now + 301, $nid), $nid, $now), 'future beyond +300s denies');
$check(!PreviewAuth::verify($secret, $id, (string) $now, 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', $nid, $now), 'forged signature denies');
$check(!PreviewAuth::verify($secret, $id, (string) $now, $good, 0, $now), 'node id 0 denies');
$check(!PreviewAuth::verify($secret, $id, (string) $now, $good, -1, $now), 'negative node id denies');
$check(!PreviewAuth::verify('whsec_not base64!!', $id, (string) $now, $good, $nid, $now), 'malformed stored secret denies (never guesses a key)');
$check(!PreviewAuth::verify($secret, 'other-msg-id', (string) $now, $good, $nid, $now), 'a signature bound to another message id denies');

echo "\n[4] ID BINDING — the property that makes one signature useless elsewhere\n";
$check(!PreviewAuth::verify($secret, $id, (string) $now, $good, 43, $now), 'a signature minted for node 42 does not verify for node 43');
$check(!PreviewAuth::verify($secret, $id, (string) $now, $sign($secret, $id, $now, 43), $nid, $now), 'and the converse: node 43 signature refused for node 42');
$check(PreviewAuth::TIMESTAMP_TOLERANCE_SECONDS === 300, 'the freshness window is the Standard-Webhooks recommended 300s');

echo "\n[5] the owned-region vocabulary (joins to the connector's reviewable paths)\n";
$check(PreviewAuth::regionForField('title') === 'title', 'title is an owned region');
$check(PreviewAuth::regionForField('body') === 'body', 'body is an owned region');
$check(PreviewAuth::regionForField('field_tags') === 'field_tags', 'a declared field_* is an owned region, named after itself');
$check(PreviewAuth::regionForField('field_image') === 'field_image', 'every declared field_* is included by prefix, not by enumeration');
$check(PreviewAuth::regionForField('links') === NULL, 'node links are chrome, never an owned region');
$check(PreviewAuth::regionForField('comment') === NULL, 'comments are chrome, never an owned region');
$check(PreviewAuth::regionForField('field_') === NULL, 'a bare field_ prefix with no name is not a region');
$check(PreviewAuth::regionForField('') === NULL, 'an empty field name is not a region');
$check(PreviewAuth::regionForField('status') === NULL, 'status is a publish effect with no rendered region — reported unplaced, never guessed');

echo "\n[6] anchor markup — what the host keys on\n";
$open = PreviewRender::anchorOpen('body', 7);
$check($open === '<div class="cinatra-region" data-cinatra-region="body" data-cinatra-node="7">', 'block anchor is the exact contract markup');
$check(PreviewRender::anchorClose() === '</div>', 'block anchor closes');
$check(PreviewRender::anchorOpen('title', 7, 'span') === '<span class="cinatra-region" data-cinatra-region="title" data-cinatra-node="7">', 'the title anchor is inline (span) so it stays valid inside a heading');
$check(PreviewRender::anchorClose('span') === '</span>', 'inline anchor closes');
$check(str_contains(PreviewRender::anchorOpen('a"><script>x</script>', 1), '&quot;'), 'a hostile region name is escaped, never breaking out of the attribute');
$check(!str_contains(PreviewRender::anchorOpen('a"><script>x</script>', 1), '<script>'), 'and no executable construct survives into the anchor');
$check(PreviewRender::alreadyAnchored($open, 'body'), 'an already-anchored region is detected (idempotency guard)');
$check(!PreviewRender::alreadyAnchored($open, 'title'), 'a different region is not confused for an anchored one');

echo "\n[7] render-flag INERTNESS — nothing is a target outside a preview render\n";
$check(PreviewRender::target() === 0, 'no preview target by default');
$check(!PreviewRender::isTarget(7), 'with no target, no node is a target (the hooks return untouched)');
$check(!PreviewRender::isTarget(NULL) && !PreviewRender::isTarget(''), 'an absent id is never a target');
$inside = PreviewRender::renderTarget(7, static function (): array {
  return [PreviewRender::target(), PreviewRender::isTarget(7), PreviewRender::isTarget('7'), PreviewRender::isTarget(8)];
});
$check($inside === [7, TRUE, TRUE, FALSE], 'inside a preview render ONLY the previewed node is a target (numeric-string ids included)');
$check(PreviewRender::target() === 0, 'the flag is restored after the render');
try {
  PreviewRender::renderTarget(9, static function (): void {
    throw new RuntimeException('theme blew up');
  });
}
catch (RuntimeException) {
  // Expected.
}
$check(PreviewRender::target() === 0, 'the flag is restored even when the render THROWS (no anchor leak into a later render)');

echo "\n[8] the response subscriber matches THIS route and nothing else\n";
$check(PreviewResponseSubscriber::isPreviewPath('/cinatra/preview/42'), 'the preview path matches');
$check(!PreviewResponseSubscriber::isPreviewPath('/cinatra/preview/42x'), 'a trailing-garbage look-alike does not match');
$check(!PreviewResponseSubscriber::isPreviewPath('/cinatra/preview/42/edit'), 'a deeper path does not match');
$check(!PreviewResponseSubscriber::isPreviewPath('/x/cinatra/preview/42'), 'a prefixed look-alike does not match');
$check(!PreviewResponseSubscriber::isPreviewPath('/cinatra/token'), 'a sibling module route does not match');
$check(PreviewResponseSubscriber::ROUTE_NAME === 'cinatra.preview', 'the subscriber names the preview route');

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
