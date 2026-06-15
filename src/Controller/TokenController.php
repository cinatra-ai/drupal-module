<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

use Drupal\cinatra\Cinatra;
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

    try {
      $response = $this->httpClient->post($endpoint, [
        'timeout' => 10,
        'http_errors' => FALSE,
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
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
   * Precedence: the CINATRA_BASE_URL environment variable when it is set to a
   * non-empty value AND passes validation, otherwise the configured
   * (browser-facing) Cinatra URL. The env override exists so a containerized
   * Drupal (which cannot reach the host's Cinatra via the browser-facing
   * origin) can be pointed at the container-reachable base WITHOUT changing
   * what the browser is told. It is applied ONLY to this server-to-server
   * token-exchange call; never to the site-origin binding nor to any URL
   * handed to client JavaScript.
   *
   * SECURITY: this base is concatenated with the token-exchange path and used
   * as the destination of the POST that carries the long-lived API key in its
   * Authorization header. An unvalidated env value would let an operator-set
   * or environment-injected CINATRA_BASE_URL redirect that key-bearing request
   * to an arbitrary host/scheme/path (credential exfiltration). So the env
   * value is validated and canonicalized to a bare scheme://host[:port] origin
   * before it is trusted; anything that fails validation is rejected (no use,
   * no leak of its value) and the configured `cinatra_url` is used instead.
   *
   * Production parity: when the env is unset/blank the configured URL is
   * returned exactly as the caller passed it (already trailing-slash-trimmed),
   * with no additional normalization — so a configured trailing-slash URL
   * yields the identical pre-existing endpoint.
   *
   * @param string $configUrl
   *   The configured Cinatra URL (already trailing-slash-trimmed).
   *
   * @return string
   *   The server-to-server base origin, trailing slash trimmed.
   */
  private function serverBaseUrl(string $configUrl): string {
    $env = getenv('CINATRA_BASE_URL');
    if (!is_string($env) || trim($env) === '') {
      // Unset or blank: production path — use the configured URL verbatim.
      return $configUrl;
    }

    $canonical = $this->canonicalizeServerBase($env);
    if ($canonical === NULL) {
      // The override failed validation. Never use it and never echo its value
      // (it may be hostile and is adjacent to a key-bearing request); log only
      // that the override was rejected, then fall back to the configured URL.
      $this->logger->warning('Ignoring invalid CINATRA_BASE_URL override; using the configured Cinatra URL for the server-to-server token call.');
      return $configUrl;
    }

    return $canonical;
  }

  /**
   * Validates and canonicalizes a CINATRA_BASE_URL override to a base origin.
   *
   * A valid override is a bare base URL: scheme is http or https, a non-empty
   * host, and NO userinfo (user:pass@), NO query string, NO fragment, NO
   * control characters or whitespace, and no meaningful path. The result is
   * canonicalized to scheme://host[:port] (any "/" path is dropped, since a
   * base URL has no path and the token path is appended by the caller). Returns
   * NULL when the value is not a safe base origin so the caller can reject it.
   *
   * @param string $value
   *   The raw environment value.
   *
   * @return string|null
   *   The canonical scheme://host[:port] origin, or NULL when invalid.
   */
  private function canonicalizeServerBase(string $value): ?string {
    // Reject any control characters or whitespace anywhere in the value (a URL
    // must not contain them; their presence signals injection/corruption).
    if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
      return NULL;
    }

    $parts = parse_url($value);
    if ($parts === FALSE) {
      return NULL;
    }

    // Scheme must be explicitly http or https.
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
    if ($scheme !== 'http' && $scheme !== 'https') {
      return NULL;
    }

    // A non-empty host is required.
    $host = $parts['host'] ?? '';
    if ($host === '') {
      return NULL;
    }

    // Reject userinfo (user[:pass]@host) — a key-bearing request must not be
    // sent to a URL that smuggles credentials or obscures the real host.
    if (isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }

    // Reject a query string or fragment: a base URL carries neither.
    if (isset($parts['query']) || isset($parts['fragment'])) {
      return NULL;
    }

    // Path policy: a base URL has no meaningful path. Allow only an absent path
    // or a bare "/"; anything else (e.g. "/evil") is rejected rather than
    // silently stripped, so a misconfigured path can never be ignored quietly.
    $path = $parts['path'] ?? '';
    if ($path !== '' && $path !== '/') {
      return NULL;
    }

    // Validate the port when present (parse_url already rejects most garbage,
    // but guard the documented range explicitly).
    $origin = $scheme . '://' . $host;
    if (isset($parts['port'])) {
      $port = $parts['port'];
      if ($port < 1 || $port > 65535) {
        return NULL;
      }
      $origin .= ':' . $port;
    }

    return $origin;
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
