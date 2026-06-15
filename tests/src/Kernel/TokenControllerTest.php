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
use Psr\Log\AbstractLogger;
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
   * The in-memory logger the controller under test writes to.
   *
   * Used to assert that a rejected CINATRA_BASE_URL override is logged without
   * ever including the long-lived API key or the raw env value.
   */
  private CinatraSpyLogger $spyLogger;

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

    // A real in-memory PSR-3 logger so log output can be inspected; the
    // controller must never write the API key or the raw env into it.
    $this->spyLogger = new CinatraSpyLogger();

    return new TokenController(
      $this->container->get('config.factory'),
      $client,
      $account,
      $requestStack,
      $this->spyLogger,
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
   * A configured trailing-slash URL still yields the unchanged endpoint.
   *
   * Production parity: the env-override hardening must NOT alter how a
   * configured `cinatra_url` (already trailing-slash-trimmed by mint()) builds
   * the server-to-server endpoint — it stays byte-identical to pre-PR.
   *
   * @covers ::mint
   */
  public function testServerBaseUrlConfigTrailingSlashUnchanged(): void {
    putenv('CINATRA_BASE_URL');
    $this->config('cinatra.settings')->set('cinatra_url', 'https://cinatra.example/')->save();
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
   * A clean http override (the documented docker base) is accepted as-is.
   *
   * @covers ::mint
   */
  public function testServerBaseUrlAcceptsCleanHttpDockerBase(): void {
    putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_ok', 'expiresIn' => 300])),
      ]);
      $controller->mint();
      $this->assertCount(1, $this->captured);
      $this->assertSame(
        'http://host.docker.internal:3000/api/agents/drupal-content-editor/token',
        (string) $this->captured[0]->getUri(),
      );
      // A valid override is not a rejection — nothing is logged.
      $this->assertSame([], $this->spyLogger->records);
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
  }

  /**
   * A trailing-slash override is canonicalized to a bare origin (no extra "/").
   *
   * @covers ::mint
   */
  public function testServerBaseUrlAcceptsTrailingSlashOverride(): void {
    putenv('CINATRA_BASE_URL=https://cinatra.internal:8443/');
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_ok', 'expiresIn' => 300])),
      ]);
      $controller->mint();
      $this->assertSame(
        'https://cinatra.internal:8443/api/agents/drupal-content-editor/token',
        (string) $this->captured[0]->getUri(),
      );
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
  }

  /**
   * An IPv6-literal override is accepted and canonicalized with its brackets.
   *
   * Locks down that a valid bracketed IPv6 host + port survives canonicalize
   * (parse_url keeps the brackets on the host, which the appended path needs).
   *
   * @covers ::mint
   */
  public function testServerBaseUrlAcceptsIpv6LiteralHost(): void {
    putenv('CINATRA_BASE_URL=http://[::1]:3000');
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_ok', 'expiresIn' => 300])),
      ]);
      $controller->mint();
      $this->assertSame(
        'http://[::1]:3000/api/agents/drupal-content-editor/token',
        (string) $this->captured[0]->getUri(),
      );
      $this->assertSame([], $this->spyLogger->records);
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
  }

  /**
   * A hostile/malformed override is REJECTED and falls back to the config URL.
   *
   * This is the credential-exfiltration guard: the key-bearing POST must never
   * be redirected to an env-supplied host/scheme/path that fails validation.
   * Each case must (a) keep the endpoint on the configured cinatra_url and
   * (b) leave the long-lived key and the raw env value out of all log output.
   *
   * @dataProvider hostileBaseUrlProvider
   *
   * @covers ::mint
   */
  public function testServerBaseUrlRejectsHostileOverride(string $hostile): void {
    putenv('CINATRA_BASE_URL=' . $hostile);
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_cfg', 'expiresIn' => 300])),
      ]);
      $response = $controller->mint();

      // The exchange still happened, but ONLY against the configured URL —
      // never the hostile env host/scheme/path.
      $this->assertSame(200, $response->getStatusCode());
      $this->assertCount(1, $this->captured);
      $endpoint = (string) $this->captured[0]->getUri();
      $this->assertSame(
        'https://cinatra.example/api/agents/drupal-content-editor/token',
        $endpoint,
        sprintf('Hostile CINATRA_BASE_URL "%s" must not redirect the token call.', $hostile),
      );
      // The Authorization header still carries the key, but the wire never went
      // to the attacker-controlled origin.
      $this->assertSame('Bearer long-lived-secret-key', $this->captured[0]->getHeaderLine('Authorization'));

      // Exactly one warning was logged about the rejection.
      $this->assertCount(1, $this->spyLogger->records);
      $logged = $this->spyLogger->renderedLine(0);
      // The secret never appears in any logged output.
      $this->assertStringNotContainsString('long-lived-secret-key', $logged);
      // The raw (possibly hostile) env value is never echoed into the log.
      $this->assertStringNotContainsString($hostile, $logged);
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
  }

  /**
   * Hostile / malformed CINATRA_BASE_URL values that must be rejected.
   *
   * @return array<string, array{string}>
   *   Provider rows keyed by the threat they represent.
   */
  public static function hostileBaseUrlProvider(): array {
    return [
      'userinfo (credential smuggling)' => ['http://user:pass@evil.example:3000'],
      'embedded userinfo at attacker host' => ['https://attacker.example@cinatra.example'],
      'query string' => ['https://evil.example/?x=1'],
      'fragment' => ['https://evil.example/#frag'],
      'meaningful path (exfil endpoint)' => ['https://evil.example/collect'],
      'non-http scheme (file)' => ['file:///etc/passwd'],
      'non-http scheme (gopher)' => ['gopher://evil.example:70'],
      'scheme-relative (no scheme)' => ['//evil.example'],
      'bare host (no scheme)' => ['evil.example:3000'],
      'control char (CR injection)' => ["http://evil.example\r\nX-Inject: 1"],
      'embedded space' => ['http://evil .example'],
      'no host' => ['https://'],
      'port zero (out of range)' => ['http://evil.example:0'],
      'port too high (parse_url rejects)' => ['http://evil.example:65536'],
    ];
  }

  /**
   * On a rejected override the warning carries no secret and no raw env.
   *
   * Explicit secret-boundary assertion on the log surface, independent of the
   * data-provider cases above.
   *
   * @covers ::mint
   */
  public function testRejectedOverrideLogsNoSecretAndNoRawEnv(): void {
    $hostile = 'http://user:pass@evil.example/collect?key=long-lived-secret-key';
    putenv('CINATRA_BASE_URL=' . $hostile);
    try {
      $controller = $this->buildController([
        new GuzzleResponse(200, [], json_encode(['token' => 'cit_cfg', 'expiresIn' => 300])),
      ]);
      $controller->mint();

      $this->assertCount(1, $this->spyLogger->records);
      $line = $this->spyLogger->renderedLine(0);
      $this->assertSame('warning', $this->spyLogger->records[0]['level']);
      $this->assertStringNotContainsString('long-lived-secret-key', $line);
      $this->assertStringNotContainsString('evil.example', $line);
      $this->assertStringNotContainsString($hostile, $line);
      // It DOES record that the override was rejected (for diagnosability).
      $this->assertStringContainsString('CINATRA_BASE_URL', $line);
    }
    finally {
      putenv('CINATRA_BASE_URL');
    }
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

/**
 * A minimal in-memory PSR-3 logger used to inspect what the controller logs.
 *
 * Records every log call so tests can assert that a rejected CINATRA_BASE_URL
 * override is logged without ever exposing the long-lived key or the raw env
 * value. Kept tiny and dependency-free so the test is portable.
 */
final class CinatraSpyLogger extends AbstractLogger {

  /**
   * The recorded log calls.
   *
   * @var array<int, array{level: string, message: string, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => (string) $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

  /**
   * Renders a recorded entry (message + interpolated context) to one string.
   *
   * The assertion target: everything that could end up in a real log line for
   * the given record, so a leaked secret in EITHER the message or a placeholder
   * value is caught.
   *
   * @param int $index
   *   The record index.
   *
   * @return string
   *   The flattened message + context.
   */
  public function renderedLine(int $index): string {
    $record = $this->records[$index];
    $parts = [$record['message']];
    foreach ($record['context'] as $key => $value) {
      $parts[] = $key . '=' . (is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value));
    }
    return implode(' ', $parts);
  }

}
