<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cinatra\Controller\TokenController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\cinatra\Controller\TokenController
 *
 * @group cinatra
 */
final class TokenControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user'];

  /**
   * Captured outbound requests to the instance token endpoint.
   *
   * @var \GuzzleHttp\Psr7\Request[]
   */
  private array $captured = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'https://cinatra.example')
      ->set('api_key', 'long-lived-secret-key')
      ->set('instance_id', 'inst-1')
      ->save();
  }

  /**
   * Builds a controller whose Guzzle client replays the queued responses.
   */
  private function buildController(array $responses, int $uid = 7, string $host = 'editor.example', string $scheme = 'https'): TokenController {
    $this->captured = [];
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) {
      return function (GuzzleRequest $request, array $options) use ($handler) {
        $this->captured[] = $request;
        return $handler($request, $options);
      };
    });
    $client = new Client(['handler' => $stack]);

    $account = $this->createMock('Drupal\\Core\\Session\\AccountInterface');
    $account->method('id')->willReturn($uid);

    $request = Request::create($scheme . '://' . $host . '/cinatra/token', 'POST');
    $requestStack = new RequestStack();
    $requestStack->push($request);

    return new TokenController(
      $this->container->get('config.factory'),
      $client,
      $account,
      $requestStack,
      $this->container->get('logger.factory')->get('cinatra'),
    );
  }

  /**
   * Tests the happy-path mint.
   *
   * The broker forwards the long-lived key server-side, binds the site origin,
   * and returns only the short-lived token envelope.
   *
   * @covers ::mint
   */
  public function testMintHappyPath(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'token' => 'cit_abc123',
        'tokenType' => 'Bearer',
        'expiresIn' => 300,
        'expiresAt' => '2026-06-13T20:05:00.000Z',
        'contractVersion' => 'v2',
        'scope' => 'drupal-content-editor.stream',
      ])),
    ], uid: 7, host: 'editor.example', scheme: 'https');

    $response = $controller->mint();
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);

    // Only the short-lived envelope is returned — never the long-lived key.
    $this->assertSame('cit_abc123', $body['token']);
    $this->assertSame('drupal-content-editor.stream', $body['scope']);
    $this->assertStringNotContainsString('long-lived-secret-key', $response->getContent());

    // Outbound request carried the long-lived key server-to-server, bound the
    // exact site origin, and pinned v2.
    $this->assertCount(1, $this->captured);
    $out = $this->captured[0];
    $this->assertSame('https://cinatra.example/api/agents/drupal-content-editor/token', (string) $out->getUri());
    $this->assertSame('Bearer long-lived-secret-key', $out->getHeaderLine('Authorization'));
    $sent = json_decode((string) $out->getBody(), TRUE);
    $this->assertSame('v2', $sent['contractVersion']);
    $this->assertSame('https://editor.example', $sent['origin']);
    $this->assertSame('drupal-uid-7', $sent['sub']);
  }

  /**
   * A non-default port is preserved in the bound origin.
   *
   * @covers ::mint
   */
  public function testOriginIncludesNonDefaultPort(): void {
    $controller = $this->buildController([
      new GuzzleResponse(200, [], json_encode(['token' => 'cit_x', 'expiresIn' => 300])),
    ], host: 'localhost:8443', scheme: 'https');

    $controller->mint();
    $sent = json_decode((string) $this->captured[0]->getBody(), TRUE);
    $this->assertSame('https://localhost:8443', $sent['origin']);
  }

  /**
   * Tests that an unconfigured key short-circuits with no outbound call.
   *
   * It must not echo a credential.
   *
   * @covers ::mint
   */
  public function testUnconfiguredKeyReturns503(): void {
    $this->config('cinatra.settings')->set('api_key', '')->save();
    $controller = $this->buildController([]);

    $response = $controller->mint();
    $this->assertSame(503, $response->getStatusCode());
    $this->assertCount(0, $this->captured);
  }

  /**
   * Tests that a non-2xx from the instance maps to a 502.
   *
   * It surfaces the structured message without leaking the long-lived key.
   *
   * @covers ::mint
   */
  public function testUpstreamRejectionMapsTo502(): void {
    $controller = $this->buildController([
      new GuzzleResponse(401, [], json_encode(['error' => 'invalid integration key'])),
    ]);

    $response = $controller->mint();
    $this->assertSame(502, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid integration key', $body['error']);
    $this->assertStringNotContainsString('long-lived-secret-key', $response->getContent());
  }

}
