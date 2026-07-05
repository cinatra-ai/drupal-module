<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

use Drupal\cinatra\Cinatra;
use Drupal\cinatra\ServerBase;
use Drupal\cinatra\Ssrf;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Server-to-server token broker for the Cinatra assistant widget.
 *
 * The browser POSTs here (same-origin, CSRF-protected, gated on the
 * "use cinatra assistant" permission). This controller holds the long-lived
 * integration key from cinatra.settings, exchanges it for a short-lived,
 * origin/audience/scope-bound token at the Cinatra instance, and returns ONLY
 * that short-lived token JSON to the browser. The long-lived key never reaches
 * client JavaScript.
 *
 * See the cinatra repo: POST /api/agents/drupal-content-editor/token.
 */
final class TokenController extends ControllerBase {

  /**
   * Cinatra agent slug whose stream the minted token is scoped to.
   */
  private const AGENT_SLUG = 'drupal-content-editor';

  /**
   * Constructs the token broker controller.
   *
   * Properties that exist on ControllerBase (config factory, current user) are
   * given distinct names here so the promoted readonly constructor parameters
   * do not collide with the parent's read-write protected properties.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $cinatraConfigFactory,
    private readonly ClientInterface $httpClient,
    private readonly AccountInterface $account,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('cinatra'),
    );
  }

  /**
   * Mints a short-lived stream token via the Cinatra token-exchange endpoint.
   */
  public function mint(): JsonResponse {
    $config = $this->cinatraConfigFactory->get('cinatra.settings');
    $cinatraUrl = rtrim((string) $config->get('cinatra_url'), '/');
    $apiKey = (string) $config->get('api_key');

    if ($cinatraUrl === '' || $apiKey === '') {
      // Never echo the (missing) credential; just report misconfiguration.
      return $this->jsonError('Cinatra is not fully configured. Set the Cinatra URL and API key at /admin/config/services/cinatra.', 503);
    }

    // The token is bound to this site's exact origin (scheme://host[:port]).
    $origin = $this->siteOrigin();
    if ($origin === '') {
      return $this->jsonError('Could not determine the site origin for token binding.', 500);
    }

    // Resolve the base the BROKER reaches the instance at server-to-server.
    // This is NOT necessarily the same origin the browser uses: in a
    // containerized topology (e.g. the dev docker stack) the Drupal container
    // cannot reach the host's cinatra via the browser-facing `cinatra_url`
    // (that host is the container's own loopback), so the container-reachable
    // base is provided out-of-band as the CINATRA_BASE_URL environment
    // variable. When that env is set we use it for this PHP->cinatra call ONLY;
    // the browser-facing `cinatra_url` (in cinatra.module's drupalSettings) is
    // left untouched. In production the env is unset, so this is exactly the
    // configured `cinatra_url` and behavior is unchanged.
    $serverBase = $this->serverBaseUrl($cinatraUrl);
    $endpoint = $serverBase . '/api/agents/' . self::AGENT_SLUG . '/token';

    // HTTP-layer SSRF guard (defense-in-depth over CinatraUrl::normalize): the
    // API-key-bearing POST must never be sent to a loopback/private/link-local
    // address (e.g. cloud metadata). The configured dev/container hosts are
    // still permitted. Redirect-following is disabled below so a 3xx cannot
    // retarget the request past this check.
    if (!Ssrf::isAllowedUrl($endpoint)) {
      $this->logger->warning('Cinatra token exchange blocked: the configured instance is not a public origin.');
      return $this->jsonError('Could not reach the Cinatra instance.', 502);
    }

    try {
      $response = $this->httpClient->post($endpoint, [
        'timeout' => 10,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
          // Assert THIS site's origin on the server-to-server mint. The
          // instance's cnx_ arm on /api/agents/{slug}/token enforces a paired
          // Origin === the credential's bound connect-site origin and FAILS
          // CLOSED on a missing Origin — without this header every
          // cnx_-paired site gets a 401 on the cit_ mint. Same identity
          // assertion WidgetAuthController::relay() already sends (the
          // credential hash must also match the same connect-site row, so the
          // header grants no trust). $origin is validated non-empty above.
          'Origin' => $origin,
        ],
        'json' => [
          'contractVersion' => Cinatra::CONTRACT_VERSION,
          'origin' => $origin,
          // Opaque editor identity for audit. uid 0 cannot reach this route
          // (permission gate), so this is always an authenticated editor.
          'sub' => 'drupal-uid-' . (string) $this->account->id(),
        ],
      ]);
    }
    catch (GuzzleException $e) {
      // Never reflect the raw exception (it can echo the request, including
      // the Authorization header, depending on the Guzzle exception). Log the
      // detail server-side, scrubbed of the key, and return a generic message.
      $this->logger->error('Cinatra token exchange failed (transport): @msg', [
        '@msg' => $this->scrub($e->getMessage(), $apiKey),
      ]);
      return $this->jsonError('Could not reach the Cinatra instance.', 502);
    }

    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();
    $body = json_decode($raw, TRUE);

    if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['token'])) {
      // Never reflect the upstream error to the browser — even the designed,
      // scrubbed {error} string is internal detail. Log the full (scrubbed)
      // upstream body server-side for diagnosis and return a fixed generic
      // message, consistent with WidgetAuthController::relay and the WordPress
      // broker.
      $this->logger->warning('Cinatra token exchange rejected (HTTP @status): @body', [
        '@status' => $status,
        '@body' => $this->scrub(mb_substr($raw, 0, 500), $apiKey),
      ]);
      // Always report 502 to the browser: any non-2xx from the instance (a
      // rejected/misconfigured long-lived key, an unconfigured origin, an
      // upstream 5xx) is a server-side problem from the browser's perspective.
      // A raw 401 would otherwise be misread by the browser as its own Drupal
      // session expiring.
      return $this->jsonError('Cinatra could not issue an assistant token. Check the connector settings, or contact your administrator.', 502);
    }

    // Pass through only the short-lived token envelope. The browser streams to
    // the instance with Authorization: Bearer <token>.
    $payload = [
      'token' => $body['token'],
      'tokenType' => $body['tokenType'] ?? 'Bearer',
      'expiresIn' => $body['expiresIn'] ?? 300,
      'contractVersion' => $body['contractVersion'] ?? Cinatra::CONTRACT_VERSION,
      'scope' => $body['scope'] ?? (self::AGENT_SLUG . '.stream'),
    ];
    if (isset($body['expiresAt'])) {
      $payload['expiresAt'] = $body['expiresAt'];
    }

    return $this->noStore(new JsonResponse($payload));
  }

  /**
   * Resolves the base URL this broker reaches the instance at (server-side).
   *
   * Delegates to the shared ServerBase resolver (the CINATRA_BASE_URL
   * container-topology override with its anchored bare-origin grammar +
   * container-host allowlist), which the node-publish webhook emitter shares
   * (cinatra#974). See \Drupal\cinatra\ServerBase for the full security
   * posture; behavior here is unchanged.
   *
   * @param string $configUrl
   *   The configured Cinatra URL (already trailing-slash-trimmed).
   *
   * @return string
   *   The server-to-server base origin, trailing slash trimmed.
   */
  private function serverBaseUrl(string $configUrl): string {
    return ServerBase::resolve($configUrl, $this->logger);
  }

  /**
   * Removes the long-lived key from any string before it is surfaced or logged.
   */
  private function scrub(string $text, string $apiKey): string {
    if ($apiKey === '') {
      return $text;
    }
    return str_replace($apiKey, '[redacted]', $text);
  }

  /**
   * Returns the exact site origin (scheme://host[:port]) for token binding.
   */
  private function siteOrigin(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    $scheme = $request->getScheme();
    $host = $request->getHost();
    if ($host === '') {
      return '';
    }
    $port = $request->getPort();
    $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    $origin = $scheme . '://' . $host;
    if ($port !== NULL && !$isDefaultPort) {
      $origin .= ':' . $port;
    }
    return $origin;
  }

  /**
   * Builds a structured, non-cacheable JSON error response.
   */
  private function jsonError(string $message, int $status): JsonResponse {
    return $this->noStore(new JsonResponse(['error' => $message], $status));
  }

  /**
   * Marks a token response as private and non-cacheable.
   *
   * The body carries a bearer token (success) or a diagnostic message (error);
   * neither may be stored by shared/browser caches.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
