<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\cinatra\PreviewAuth;
use Drupal\cinatra\PublishWebhook;
use Drupal\node\NodeInterface;

/**
 * Tests the host-facing authenticated preview route end to end (cinatra#2046).
 *
 * This is the integration half of the preview contract; the pure auth matrix
 * (every accept/deny arm, id binding, freshness edges, the region vocabulary,
 * anchor markup and the render-flag inertness) is proven without a kernel in
 * tests/test-preview-auth.php, which runs on every pull request.
 *
 * What only a real request can prove, and is proven here:
 *  - 401 for every unauthenticated shape, WITHOUT the node ever being loaded.
 *  - 200 for a correctly signed request, including for an UNPUBLISHED node.
 *  - The owned regions carry server-emitted anchors in that render.
 *  - The SAME node's public page is anchor-free — the inertness property.
 *  - A replayed webhook-id is refused (single-use consume).
 *
 * @group cinatra
 */
final class PreviewRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The connect-provisioned shared secret under test.
   */
  private const SECRET = 'whsec_MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'https://cinatra.example')
      ->set('instance_id', 'inst-1')
      ->set('webhook_secret', self::SECRET)
      ->set('webhook_binding_id', 'binding-1')
      ->save();
  }

  /**
   * Builds the Standard-Webhooks headers the cinatra host sends.
   *
   * @param int $nid
   *   The node id the signature is bound to.
   * @param string|null $messageId
   *   The webhook id, or NULL for a fresh random one.
   * @param int|null $timestamp
   *   The signing timestamp, or NULL for now.
   *
   * @return array<string, string>
   *   The request headers.
   */
  private function signedHeaders(int $nid, ?string $messageId = NULL, ?int $timestamp = NULL): array {
    $messageId = $messageId ?? 'msg-' . bin2hex(random_bytes(8));
    $timestamp = $timestamp ?? time();
    $signature = PublishWebhook::sign(self::SECRET, $messageId, $timestamp, PreviewAuth::canonicalContent($nid));
    $this->assertNotNull($signature);
    return [
      'webhook-id' => $messageId,
      'webhook-timestamp' => (string) $timestamp,
      'webhook-signature' => (string) $signature,
    ];
  }

  /**
   * Creates an UNPUBLISHED article with a body — the staged-write shape.
   *
   * @return \Drupal\node\NodeInterface
   *   The unpublished node.
   */
  private function createDraft(): NodeInterface {
    return $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'A staged headline',
      'body' => [['value' => 'A staged paragraph the reviewer must see.', 'format' => 'plain_text']],
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);
  }

  /**
   * An unsigned or badly signed request is refused with 401 and no body.
   */
  public function testUnauthenticatedRequestsAreRefused(): void {
    $node = $this->createDraft();
    $path = 'cinatra/preview/' . $node->id();

    // No headers at all.
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseNotContains('A staged paragraph');

    // Forged signature.
    $this->drupalGet($path, [], [
      'webhook-id' => 'msg-forged',
      'webhook-timestamp' => (string) time(),
      'webhook-signature' => 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    ]);
    $this->assertSession()->statusCodeEquals(401);

    // Stale timestamp, correctly signed for that stale time.
    $stale = time() - (PreviewAuth::TIMESTAMP_TOLERANCE_SECONDS + 60);
    $this->drupalGet($path, [], $this->signedHeaders((int) $node->id(), 'msg-stale', $stale));
    $this->assertSession()->statusCodeEquals(401);

    // A signature minted for a DIFFERENT node.
    $other = $this->createDraft();
    $this->drupalGet($path, [], $this->signedHeaders((int) $other->id(), 'msg-otherid'));
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseNotContains('A staged paragraph');
  }

  /**
   * A signed request renders an UNPUBLISHED node with its regions anchored.
   */
  public function testSignedRequestRendersDraftWithRegionAnchors(): void {
    $node = $this->createDraft();
    $this->drupalGet('cinatra/preview/' . $node->id(), [], $this->signedHeaders((int) $node->id()));

    $this->assertSession()->statusCodeEquals(200);
    // The draft's actual content is rendered — the point of the route.
    $this->assertSession()->responseContains('A staged paragraph the reviewer must see.');
    $this->assertSession()->responseContains('A staged headline');
    // Server-emitted anchors for the owned regions, keyed to this node.
    $this->assertSession()->responseContains('data-cinatra-region="title"');
    $this->assertSession()->responseContains('data-cinatra-region="body"');
    $this->assertSession()->responseContains('data-cinatra-node="' . $node->id() . '"');
    $this->assertSession()->responseContains('data-cinatra-preview-status="unpublished"');
    // Never cached, never indexed.
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->responseHeaderContains('X-Robots-Tag', 'noindex');
  }

  /**
   * The SAME message id cannot be replayed — single-use consume.
   */
  public function testReplayOfConsumedMessageIsRefused(): void {
    $node = $this->createDraft();
    $headers = $this->signedHeaders((int) $node->id(), 'msg-replay-once');

    $this->drupalGet('cinatra/preview/' . $node->id(), [], $headers);
    $this->assertSession()->statusCodeEquals(200);

    // Byte-identical request, still inside the freshness window.
    $this->drupalGet('cinatra/preview/' . $node->id(), [], $headers);
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * A published node's PUBLIC page carries no anchors — the inertness property.
   *
   * The anchor hooks are registered globally, so this is the check that they
   * really are inert: the same node an authenticated preview anchors renders
   * completely unmarked for an ordinary visitor.
   */
  public function testPublicNodePageCarriesNoAnchors(): void {
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'A published headline',
      'body' => [['value' => 'Public body copy.', 'format' => 'plain_text']],
      'status' => NodeInterface::PUBLISHED,
    ]);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Public body copy.');
    $this->assertSession()->responseNotContains('data-cinatra-region');
    $this->assertSession()->responseNotContains('cinatra-region');

    // And the front page, which the widget surface also applies to.
    $this->drupalGet('<front>');
    $this->assertSession()->responseNotContains('data-cinatra-region');

    // The preview of the very same node DOES carry them, so the difference is
    // the render flag and nothing else.
    $this->drupalGet('cinatra/preview/' . $node->id(), [], $this->signedHeaders((int) $node->id()));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('data-cinatra-region="body"');
    $this->assertSession()->responseContains('data-cinatra-preview-status="published"');
  }

  /**
   * A missing node is a 404 — but only for an authenticated caller.
   *
   * An UNSIGNED request for a missing node must still answer 401, or the route
   * would be an existence oracle for unpublished content.
   */
  public function testMissingNodeIsNotAnExistenceOracle(): void {
    $missing = 999999;
    $this->drupalGet('cinatra/preview/' . $missing);
    $this->assertSession()->statusCodeEquals(401);

    $this->drupalGet('cinatra/preview/' . $missing, [], $this->signedHeaders($missing));
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * With no connect-provisioned secret, the route is closed entirely.
   */
  public function testUnconnectedSiteRefusesEveryPreview(): void {
    $node = $this->createDraft();
    $headers = $this->signedHeaders((int) $node->id());
    $this->config('cinatra.settings')->set('webhook_secret', '')->save();

    $this->drupalGet('cinatra/preview/' . $node->id(), [], $headers);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseNotContains('A staged paragraph');
  }

}
