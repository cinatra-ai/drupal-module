<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

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
   * Wire-contract version sent to the instance token endpoint.
   */
  private const CONTRACT_VERSION = 'v2';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly AccountInterface $currentUser,
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
    $config = $this->configFactory->get('cinatra.settings');
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

    $endpoint = $cinatraUrl . '/api/agents/' . self::AGENT_SLUG . '/token';

    try {
      $response = $this->httpClient->post($endpoint, [
        'timeout' => 10,
        'http_errors' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'contractVersion' => self::CONTRACT_VERSION,
          'origin' => $origin,
          // Opaque editor identity for audit. uid 0 cannot reach this route
          // (permission gate), so this is always an authenticated editor.
          'sub' => 'drupal-uid-' . (string) $this->currentUser->id(),
        ],
      ]);
    }
    catch (GuzzleException $e) {
      // Never reflect the raw exception (it can echo the request — including the
      // Authorization header — depending on the Guzzle exception). Log the
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
      // The instance returns a designed structured {error} string (e.g. "origin
      // not configured"); pass that through to help the editor, but defensively
      // scrub the long-lived key from it and never reflect raw upstream bodies.
      $upstream = is_array($body) && isset($body['error']) && is_string($body['error'])
        ? $body['error']
        : NULL;
      $message = $upstream !== NULL
        ? $this->scrub($upstream, $apiKey)
        : 'Token exchange failed (HTTP ' . $status . ').';
      // Log the full (scrubbed) upstream detail server-side for diagnosis.
      $this->logger->warning('Cinatra token exchange rejected (HTTP @status): @body', [
        '@status' => $status,
        '@body' => $this->scrub(mb_substr($raw, 0, 500), $apiKey),
      ]);
      // Always report 502 to the browser: any non-2xx from the instance (a
      // rejected/misconfigured long-lived key, an unconfigured origin, an
      // upstream 5xx) is a server-side problem from the browser's perspective.
      // A raw 401 would otherwise be misread by the browser as its own Drupal
      // session expiring.
      return $this->jsonError($message, 502);
    }

    // Pass through only the short-lived token envelope. The browser streams to
    // the instance with Authorization: Bearer <token>.
    $payload = [
      'token' => $body['token'],
      'tokenType' => $body['tokenType'] ?? 'Bearer',
      'expiresIn' => $body['expiresIn'] ?? 300,
      'contractVersion' => $body['contractVersion'] ?? self::CONTRACT_VERSION,
      'scope' => $body['scope'] ?? (self::AGENT_SLUG . '.stream'),
    ];
    if (isset($body['expiresAt'])) {
      $payload['expiresAt'] = $body['expiresAt'];
    }

    return $this->noStore(new JsonResponse($payload));
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
