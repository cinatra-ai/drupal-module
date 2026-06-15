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
   * The CINATRA_BASE_URL env, when set, overrides the server-to-server base.
   *
   * In a containerized topology the configured (browser-facing) `cinatra_url`
   * is not reachable from the Drupal container; the env carries the
   * container-reachable base for THIS PHP->instance call only. The bound site
   * `origin` and the returned token envelope are unaffected.
   *
   * @covers ::mint
   */
  public function testServerBaseUrlEnvOverridesTokenEndpoint(): void {
    putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_env', 'expiresIn' => 300])),
      ], host: 'editor.example', scheme: 'https');

      $response = $controller->mint();
      $this->assertSame(200, $response->getStatusCode());

      // The outbound call went to the env base, NOT the configured cinatra_url.
      $this->assertCount(1, $this->captured);
      $out = $this->captured[0];
      $this->assertSame('http://host.docker.internal:3000/api/agents/drupal-content-editor/token', (string) $out->getUri());
      // Still authorized with the long-lived key and bound to the site origin.
      $this->assertSame('Bearer long-lived-secret-key', $out->getHeaderLine('Authorization'));
      $sent = json_decode((string) $out->getBody(), TRUE);
      $this->assertSame('https://editor.example', $sent['origin']);
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
  }

  /**
   * An empty or whitespace-only env falls back to the configured cinatra_url.
   *
   * @covers ::mint
   */
  public function testServerBaseUrlEnvBlankFallsBackToConfig(): void {
    foreach (['', '   '] as $blank) {
      putenv('CINATRA_BASE_URL=' . $blank);
      try {
        $controller = $this->buildController([
          new GuzzleResponse(200, [], json_encode(['token' => 'cit_blank', 'expiresIn' => 300])),
        ]);
        $controller->mint();
        $this->assertCount(1, $this->captured);
        $this->assertSame(
          'https://cinatra.example/api/agents/drupal-content-editor/token',
          (string) $this->captured[0]->getUri(),
          'A blank CINATRA_BASE_URL must fall back to the configured cinatra_url.',
        );
      }
      finally {
        putenv('CINATRA_BASE_URL');
      }
    }
  }

  /**
   * With the env unset the endpoint is exactly the configured cinatra_url.
   *
   * Production parity: nothing changes when CINATRA_BASE_URL is not present.
   *
   * @covers ::mint
   */
  public function testServerBaseUrlUnsetUsesConfig(): void {
    // Ensure the env is not present (no leakage from another test).
    putenv('CINATRA_BASE_URL');
    $controller = $this->buildController([
      new GuzzleResponse(200, [], json_encode(['token' => 'cit_cfg', 'expiresIn' => 300])),
    ]);
    $controller->mint();
    $this->assertCount(1, $this->captured);
    $this->assertSame(
      'https://cinatra.example/api/agents/drupal-content-editor/token',
      (string) $this->captured[0]->getUri(),
    );
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
