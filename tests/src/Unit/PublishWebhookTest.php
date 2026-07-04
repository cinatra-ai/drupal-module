<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra\PublishWebhook;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the node-publish webhook emitter's signing and guards.
 *
 * The Standard-Webhooks signature is pinned against a GOLDEN VECTOR generated
 * with the reference `standardwebhooks` JavaScript library (the exact library
 * the cinatra host verifies with), so a drift in the PHP signing would fail
 * here before it could fail live verification.
 *
 * @coversDefaultClass \Drupal\cinatra\PublishWebhook
 *
 * @group cinatra
 */
final class PublishWebhookTest extends UnitTestCase {

  /**
   * The golden-vector secret (whsec_ + base64 of a fixed 32-byte key).
   */
  private const VECTOR_SECRET = 'whsec_MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

  /**
   * The golden-vector body (byte-exact; slashes unescaped as in the JS lib).
   */
  private const VECTOR_BODY = '{"event":"node_published","nodeId":42,"nodeType":"article","title":"Hello","siteUrl":"https://news.example.com","issuedAt":"2026-07-04T00:00:00+00:00","url":"https://news.example.com/node/42"}';

  /**
   * The signature the reference standardwebhooks library produces.
   *
   * Generated with: new Webhook(secret).sign('drupal-testmsgid',
   * new Date(1751587200 * 1000), body).
   */
  private const VECTOR_SIGNATURE = 'v1,XiBq8mWcL7+i2qMB50RXhk3caeJm99Un2KM7GYLhZks=';

  /**
   * Signing matches the reference library byte-for-byte (golden vector).
   *
   * @covers ::sign
   */
  public function testSignMatchesReferenceLibraryGoldenVector(): void {
    $this->assertSame(
      self::VECTOR_SIGNATURE,
      PublishWebhook::sign(self::VECTOR_SECRET, 'drupal-testmsgid', 1751587200, self::VECTOR_BODY),
    );
  }

  /**
   * The whsec_ prefix is optional — the same key signs identically without it.
   *
   * @covers ::sign
   */
  public function testSignAcceptsUnprefixedBase64Secret(): void {
    $unprefixed = substr(self::VECTOR_SECRET, strlen('whsec_'));
    $this->assertSame(
      self::VECTOR_SIGNATURE,
      PublishWebhook::sign($unprefixed, 'drupal-testmsgid', 1751587200, self::VECTOR_BODY),
    );
  }

  /**
   * A different key, id, timestamp, or body changes the signature.
   *
   * @covers ::sign
   */
  public function testSignBindsEveryInput(): void {
    $base = PublishWebhook::sign(self::VECTOR_SECRET, 'id', 1751587200, '{}');
    $this->assertNotNull($base);
    $this->assertNotSame($base, PublishWebhook::sign(self::VECTOR_SECRET, 'id2', 1751587200, '{}'));
    $this->assertNotSame($base, PublishWebhook::sign(self::VECTOR_SECRET, 'id', 1751587201, '{}'));
    $this->assertNotSame($base, PublishWebhook::sign(self::VECTOR_SECRET, 'id', 1751587200, '{ }'));
    $otherSecret = 'whsec_' . base64_encode(str_repeat('x', 32));
    $this->assertNotSame($base, PublishWebhook::sign($otherSecret, 'id', 1751587200, '{}'));
  }

  /**
   * A secret that is not valid base64 fails CLOSED (NULL, no signature).
   *
   * @covers ::sign
   */
  public function testSignRejectsNonBase64Secret(): void {
    $this->assertNull(PublishWebhook::sign('whsec_%%%not-base64%%%', 'id', 1751587200, '{}'));
    $this->assertNull(PublishWebhook::sign('', 'id', 1751587200, '{}'));
  }

  /**
   * BuildEmission stays a quiet no-op until url, secret and binding id exist.
   *
   * The secret and binding id are written as a PAIR by the connect exchange;
   * a partial configuration means "webhooks not provisioned" and must never
   * produce a delivery attempt.
   *
   * @covers ::buildEmission
   *
   * @dataProvider partialConfigurations
   */
  public function testBuildEmissionRequiresFullConfiguration(array $settings): void {
    $emitter = new PublishWebhook(
      $this->createMock(ClientInterface::class),
      $this->getConfigFactoryStub(['cinatra.settings' => $settings]),
      $this->createMock(LoggerInterface::class),
      new RequestStack(),
    );
    $node = $this->createMock(NodeInterface::class);
    // The node must not even be inspected when configuration is incomplete.
    $node->expects($this->never())->method('id');
    $this->assertNull($emitter->buildEmission($node));
  }

  /**
   * Partial cinatra.settings configurations that must not emit.
   *
   * @return array<string, array{array<string, string>}>
   *   Data sets keyed by scenario.
   */
  public static function partialConfigurations(): array {
    return [
      'nothing configured' => [[]],
      'url only' => [['cinatra_url' => 'https://cinatra.example.com']],
      'url + secret, no binding id' => [
        [
          'cinatra_url' => 'https://cinatra.example.com',
          'webhook_secret' => self::VECTOR_SECRET,
        ],
      ],
      'url + binding id, no secret' => [
        [
          'cinatra_url' => 'https://cinatra.example.com',
          'webhook_binding_id' => 'bnd_x',
        ],
      ],
      'secret + binding id, no url' => [
        [
          'webhook_secret' => self::VECTOR_SECRET,
          'webhook_binding_id' => 'bnd_x',
        ],
      ],
    ];
  }

  /**
   * A fully configured emitter builds the generic-route URL and signable body.
   *
   * @covers ::buildEmission
   */
  public function testBuildEmissionTargetsGenericRouteWithStableMessageId(): void {
    $settings = [
      'cinatra.settings' => [
        'cinatra_url' => 'https://cinatra.example.com',
        'webhook_secret' => self::VECTOR_SECRET,
        'webhook_binding_id' => 'bnd_opaque-123',
        'instance_id' => 'inst-1',
      ],
    ];
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://news.example.com/node/42/edit'));
    $emitter = new PublishWebhook(
      $this->createMock(ClientInterface::class),
      $this->getConfigFactoryStub($settings),
      $this->createMock(LoggerInterface::class),
      $requestStack,
    );

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('42');
    $node->method('bundle')->willReturn('article');
    $node->method('label')->willReturn('Hello');
    $node->method('getRevisionId')->willReturn('7');
    // toUrl() needs the routing container in a real site; the emitter treats a
    // failure as "omit the optional url field".
    $node->method('toUrl')->willThrowException(new \RuntimeException('no container'));

    $emission = $emitter->buildEmission($node);
    $this->assertNotNull($emission);
    $this->assertSame(
      'https://cinatra.example.com/webhook/cinatra-ai/drupal-mcp-connector/node-published/bnd_opaque-123',
      $emission['url'],
    );
    $payload = json_decode($emission['body'], TRUE);
    $this->assertIsArray($payload);
    $this->assertSame('node_published', $payload['event']);
    $this->assertSame(42, $payload['nodeId']);
    $this->assertSame('article', $payload['nodeType']);
    $this->assertSame('Hello', $payload['title']);
    $this->assertSame('https://news.example.com', $payload['siteUrl']);
    $this->assertArrayNotHasKey('url', $payload);
    $this->assertNotEmpty($payload['issuedAt']);
    // The idempotency id is STABLE per (instance, node, revision).
    $again = $emitter->buildEmission($node);
    $this->assertNotNull($again);
    $this->assertSame($emission['messageId'], $again['messageId']);
    $this->assertStringStartsWith('drupal-', $emission['messageId']);
    // The secret rides internally to the shutdown sender, never the payload.
    $this->assertStringNotContainsString(self::VECTOR_SECRET, $emission['body']);
  }

}
