<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cinatra\Controller\ConnectController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers the connect exchange's server-to-server base resolution.
 *
 * The connect exchange (POST /api/connect/token) must resolve its transport
 * base through \Drupal\cinatra\ServerBase — the validated CINATRA_BASE_URL
 * container-topology override — the same resolution every other
 * server-to-server call in this module applies (TokenController and
 * PublishWebhook via ServerBase::resolve(); WidgetAuthController via its
 * identical-contract private resolver). Before drupal-module#78 the exchange
 * was the ONE call that applied no override resolution at all,
 * so a containerized Drupal dialed the browser-facing origin (its own
 * loopback) and the connect flow could never complete in that topology.
 *
 * @coversDefaultClass \Drupal\cinatra\Controller\ConnectController
 *
 * @group cinatra
 */
final class ConnectControllerExchangeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user', 'system'];

  /**
   * Outbound Guzzle requests captured by the mock handler stack.
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
      ->set('cinatra_url', '')
      ->set('api_key', '')
      ->set('instance_id', '')
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Never leak the override into sibling tests.
    putenv('CINATRA_BASE_URL');
    parent::tearDown();
  }

  /**
   * Builds a controller whose Guzzle client replays the queued responses.
   */
  private function buildController(array $responses): ConnectController {
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
    $account->method('id')->willReturn(7);

    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://editor.example/admin/config/services/cinatra', 'POST'));

    return new ConnectController(
      $this->container->get('config.factory'),
      $client,
      $account,
      $requestStack,
      $this->container->get('keyvalue.expirable'),
      new NullLogger(),
    );
  }

  /**
   * A `cinatra-connect:` connection string for the given instance URL.
   */
  private static function connectionString(string $url): string {
    $payload = json_encode(['url' => $url, 'install_code' => 'cci_test_install_code']);
    return 'cinatra-connect:' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
  }

  /**
   * A successful exchange response envelope.
   */
  private static function credentialResponse(): GuzzleResponse {
    return new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
      'credential' => 'cnx_test_credential',
      'cinatraInstanceId' => 'inst-1',
    ]));
  }

  /**
   * CINATRA_BASE_URL routes the exchange transport to the container base.
   *
   * The browser-facing instance URL stays what the admin entered (it is what
   * gets persisted as `cinatra_url`); ONLY the transport destination of this
   * PHP->instance POST moves to the validated container-reachable base.
   *
   * @covers ::installCode
   */
  public function testExchangeUsesServerBaseOverride(): void {
    putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
    $controller = $this->buildController([self::credentialResponse()]);

    $controller->installCode(self::connectionString('https://cinatra.example'));

    $this->assertCount(1, $this->captured);
    $this->assertSame(
      'http://host.docker.internal:3000/api/connect/token',
      (string) $this->captured[0]->getUri(),
      'The connect exchange must reach the instance via the CINATRA_BASE_URL override, like every other server-to-server call.',
    );
    // The persisted browser-facing URL is untouched by the transport override.
    $this->assertSame('https://cinatra.example', $this->config('cinatra.settings')->get('cinatra_url'));
    $this->assertSame('cnx_test_credential', $this->config('cinatra.settings')->get('api_key'));
  }

  /**
   * Production parity: with the env unset the exchange dials the entered URL.
   *
   * @covers ::installCode
   */
  public function testExchangeUsesConfiguredUrlWithoutOverride(): void {
    putenv('CINATRA_BASE_URL');
    $controller = $this->buildController([self::credentialResponse()]);

    $controller->installCode(self::connectionString('https://cinatra.example'));

    $this->assertCount(1, $this->captured);
    $this->assertSame('https://cinatra.example/api/connect/token', (string) $this->captured[0]->getUri());
    $this->assertSame('https://cinatra.example', $this->config('cinatra.settings')->get('cinatra_url'));
  }

  /**
   * A non-allowlisted override host is rejected; the entered URL is used.
   *
   * ServerBase's validation is what keeps the override from redirecting the
   * install-code-bearing POST to an arbitrary host.
   *
   * @covers ::installCode
   */
  public function testExchangeRejectsNonAllowlistedOverrideHost(): void {
    putenv('CINATRA_BASE_URL=http://evil.example:3000');
    $controller = $this->buildController([self::credentialResponse()]);

    $controller->installCode(self::connectionString('https://cinatra.example'));

    $this->assertCount(1, $this->captured);
    $this->assertSame('https://cinatra.example/api/connect/token', (string) $this->captured[0]->getUri());
  }

}
