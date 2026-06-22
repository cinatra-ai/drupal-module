<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Server-to-server broker for the per-user widget-auth handshake (cinatra#410).
 *
 * The required-login widget runs a PKCE handshake against the Cinatra-hosted
 * /widget-auth surface (cinatra#407). The browser CANNOT call the instance's
 * /api/widget-auth/{init,token} endpoints directly — they need the long-lived
 * integration key (cnx_) and are not CORS-enabled. So the browser POSTs to
 * these same-origin routes (CSRF-protected via the route-seeded ?token= query,
 * gated on the "use cinatra assistant" permission), and this controller
 * presents the long-lived key server-to-server, returning ONLY the whitelisted
 * upstream envelope to the browser. The long-lived key never reaches client JS.
 *
 * This mirrors the cit_ stream-token broker (TokenController::mint): same
 * config read, same CINATRA_BASE_URL container override + host allowlist, same
 * generic error model (upstream bodies are never reflected; the key is scrubbed
 * from any surfaced/logged text). See the cinatra repo: POST
 * /api/widget-auth/{init,token}.
 */
final class WidgetAuthController extends ControllerBase {

  /**
   * Host allowlist for the OPTIONAL CINATRA_BASE_URL server-to-server override.
   *
   * Identical to TokenController::SERVER_BASE_ALLOWED_HOSTS. CINATRA_BASE_URL
   * is a dev/container-topology override (the container reaching the host's
   * Cinatra via loopback / the Docker host-gateway alias); after the anchored
   * grammar validates + canonicalizes the origin, the host is required to be
   * EXACTLY one of these. Anything else is discarded and the configured
   * cinatra_url is used.
   */
  private const SERVER_BASE_ALLOWED_HOSTS = [
    'localhost',
    '127.0.0.1',
    'host.docker.internal',
    '::1',
  ];

  /**
   * Constructs the widget-auth broker controller.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $cinatraConfigFactory,
    private readonly ClientInterface $httpClient,
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
      $container->get('request_stack'),
      $container->get('logger.factory')->get('cinatra'),
    );
  }

  /**
   * Starts the per-user PKCE handshake (relays to /api/widget-auth/init).
   *
   * Forwards the whitelisted PKCE challenge + state and returns the
   * {txnId, authorizeUrl, instanceId} envelope. The browser opens authorizeUrl
   * as the hosted login popup; raw credentials never touch this CMS DOM.
   */
  public function init(): JsonResponse {
    return $this->relay(
      'init',
      ['client', 'agentSlug', 'codeChallenge', 'codeChallengeMethod', 'state', 'instanceId'],
      ['txnId', 'authorizeUrl', 'instanceId'],
    );
  }

  /**
   * Redeems the authorization code for the opaque per-user token.
   *
   * Relays to /api/widget-auth/token and returns the
   * {token: cwu_..., tokenType, expiresIn, scope} envelope. The browser sends
   * that token on the dual-token stream (cinatra#408).
   */
  public function token(): JsonResponse {
    return $this->relay(
      'token',
      ['grantType', 'client', 'agentSlug', 'code', 'codeVerifier'],
      ['token', 'tokenType', 'expiresIn', 'scope'],
    );
  }

  /**
   * Shared server-to-server relay for the widget-auth handshake.
   *
   * @param string $segment
   *   The upstream path segment ('init' or 'token').
   * @param string[] $fields
   *   Whitelisted request-field names to forward (others are dropped).
   * @param string[] $passthrough
   *   Whitelisted response-field names to return (others are dropped).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The whitelisted upstream envelope, or a generic error response.
   */
  private function relay(string $segment, array $fields, array $passthrough): JsonResponse {
    $config = $this->cinatraConfigFactory->get('cinatra.settings');
    $cinatraUrl = rtrim((string) $config->get('cinatra_url'), '/');
    $apiKey = (string) $config->get('api_key');

    if ($cinatraUrl === '' || $apiKey === '') {
      // Never echo the (missing) credential; just report misconfiguration.
      return $this->jsonError('Cinatra is not fully configured. Set the Cinatra URL and API key at /admin/config/services/cinatra.', 503);
    }

    // Forward ONLY whitelisted JSON fields the widget is allowed to set. The
    // instance derives the rest (txn binding, the agent's instances config key,
    // the user identity from the authenticated login). We never forward
    // arbitrary keys, and never echo the long-lived key.
    $request = $this->requestStack->getCurrentRequest();
    $params = $this->decodeJsonBody($request);
    $forward = [];
    foreach ($fields as $field) {
      if (is_array($params) && array_key_exists($field, $params) && $params[$field] !== NULL) {
        $forward[$field] = $params[$field];
      }
    }

    // Route the TRANSPORT to the container-reachable base when CINATRA_BASE_URL
    // is set (dev/container topology), else the configured cinatra_url. The
    // browser-facing cinatra_url handed to JS is never affected.
    $serverBase = $this->serverBaseUrl($cinatraUrl);
    $endpoint = $serverBase . '/api/widget-auth/' . $segment;

    // Assert THIS site's BROWSER origin on the server-to-server relay. The
    // instance's /api/widget-auth/{init,token} enforces a paired Origin === the
    // `cnx_` credential's bound connect-site origin (fail-closed: a missing
    // Origin is rejected). The widget called this same-origin broker from the
    // browser, so the incoming request's Origin header is the canonical
    // browser-facing site origin (the container host the app sees would be the
    // wrong, internal one in a reverse-proxied/containerized topology). Falls
    // back to the request scheme+host. The relay cannot spoof another site
    // because the credential_hash must ALSO match the same connect-site row, so
    // this is identity assertion, not a trust grant; the browser never reaches
    // this endpoint (server-to-server only).
    $relayHeaders = [
      'Authorization' => 'Bearer ' . $apiKey,
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
    $browserOrigin = '';
    if ($request !== NULL) {
      // Prefer the browser's incoming Origin, but ONLY when it is a strict
      // scheme://host[:port] (no path/query/userinfo/fragment) — a permissive
      // URL validator would let a path/credential-bearing value through. Fall
      // back to the request scheme+host (also normalized the same way).
      $browserOrigin = $this->strictOrigin((string) $request->headers->get('Origin', ''));
      if ($browserOrigin === '') {
        $scheme = $request->getScheme();
        $host = $request->getHost();
        if ($host !== '') {
          $port = $request->getPort();
          $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
          $browserOrigin = $scheme . '://' . $host . (($port !== NULL && !$isDefaultPort) ? ':' . $port : '');
        }
      }
    }
    if ($browserOrigin !== '') {
      $relayHeaders['Origin'] = $browserOrigin;
    }

    try {
      $response = $this->httpClient->post($endpoint, [
        'timeout' => 10,
        'http_errors' => FALSE,
        'headers' => $relayHeaders,
        'json' => $forward,
      ]);
    }
    catch (GuzzleException $e) {
      // Never reflect the raw exception (it can echo the request, including the
      // Authorization header). Log the detail server-side, scrubbed of the key.
      $this->logger->error('Cinatra widget-auth @segment failed (transport): @msg', [
        '@segment' => $segment,
        '@msg' => $this->scrub($e->getMessage(), $apiKey),
      ]);
      return $this->jsonError('Could not reach the Cinatra instance.', 502);
    }

    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();
    $body = json_decode($raw, TRUE);

    if ($status < 200 || $status >= 300 || !is_array($body)) {
      // Never reflect raw upstream bodies. Log the scrubbed detail server-side;
      // always report 502 to the browser (any non-2xx from the instance is a
      // server-side problem from the browser's perspective — a raw 401 would
      // be misread as the Drupal session expiring).
      $this->logger->warning('Cinatra widget-auth @segment rejected (HTTP @status): @body', [
        '@segment' => $segment,
        '@status' => $status,
        '@body' => $this->scrub(mb_substr($raw, 0, 500), $apiKey),
      ]);
      $message = 'Cinatra could not complete sign-in. Check the connector settings, or contact your administrator.';
      return $this->jsonError($message, 502);
    }

    // Pass through ONLY the whitelisted upstream fields.
    $payload = [];
    foreach ($passthrough as $key) {
      if (array_key_exists($key, $body)) {
        $payload[$key] = $body[$key];
      }
    }
    return $this->noStore(new JsonResponse($payload));
  }

  /**
   * Decodes the request JSON body to an associative array, or NULL.
   */
  private function decodeJsonBody(?Request $request): ?array {
    if ($request === NULL) {
      return NULL;
    }
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Resolves the base URL this broker reaches the instance at (server-side).
   *
   * Precedence: a VALIDATED CINATRA_BASE_URL env override, else the configured
   * (browser-facing) Cinatra URL. Identical contract to
   * TokenController::serverBaseUrl — the override is applied ONLY to this
   * server-to-server call and never to any URL handed to client JavaScript.
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
      return $configUrl;
    }
    $canonical = $this->canonicalizeServerBase($env);
    if ($canonical === NULL) {
      // Never use or echo a rejected override (it is adjacent to a key-bearing
      // request); log only that it was rejected, then fall back.
      $this->logger->warning('Ignoring invalid CINATRA_BASE_URL override; using the configured Cinatra URL for the server-to-server widget-auth call.');
      return $configUrl;
    }
    return $canonical;
  }

  /**
   * Validates and canonicalizes a CINATRA_BASE_URL override to a base origin.
   *
   * Identical grammar + host allowlist to
   * TokenController::canonicalizeServerBase (the override becomes the
   * destination of a POST carrying the long-lived key, so it must be proven
   * safe by construction). The ENTIRE raw string must match an anchored
   * bare-origin grammar (scheme http(s); host = IPv4 | bracketed IPv6 |
   * dot-separated DNS labels; optional :port 1–65535; optional single trailing
   * slash), AND the host must be one of self::SERVER_BASE_ALLOWED_HOSTS.
   * Returns the canonical scheme://host[:port] on success, NULL otherwise.
   *
   * @param string $value
   *   The raw environment value.
   *
   * @return string|null
   *   The canonical origin, or NULL when invalid.
   */
  private function canonicalizeServerBase(string $value): ?string {
    // Cheap first gate: reject control chars / whitespace anywhere.
    if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
      return NULL;
    }

    // Anchored, linear bare-origin grammar (no nested quantifiers => no ReDoS).
    $pattern = '#^(https?)://'
      . '('
      . '[0-9]{1,3}(?:\.[0-9]{1,3}){3}'
      . '|'
      . '\[[0-9A-Fa-f:]+\]'
      . '|'
      . '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*'
      . ')'
      . '(?::([0-9]{1,5}))?'
      . '/?\z#i';

    if (preg_match($pattern, $value, $m) !== 1) {
      return NULL;
    }

    $scheme = strtolower($m[1]);
    $host = $m[2];

    // Container-host allowlist (a clean origin can still name any host).
    $hostForAllowlist = strtolower($host);
    if (str_starts_with($hostForAllowlist, '[') && str_ends_with($hostForAllowlist, ']')) {
      $hostForAllowlist = substr($hostForAllowlist, 1, -1);
    }
    if (!in_array($hostForAllowlist, self::SERVER_BASE_ALLOWED_HOSTS, TRUE)) {
      return NULL;
    }

    // IPv4 octet range 0–255 (the grammar only bounds it to 1–3 digits).
    if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,3}){3}$/', $host) === 1) {
      foreach (explode('.', $host) as $octet) {
        if ((int) $octet > 255) {
          return NULL;
        }
      }
    }

    $origin = $scheme . '://' . $host;

    if (isset($m[3]) && $m[3] !== '') {
      $port = (int) $m[3];
      if ($port < 1 || $port > 65535) {
        return NULL;
      }
      $origin .= ':' . $port;
    }

    return $origin;
  }

  /**
   * Canonicalizes a value to a strict `scheme://host[:port]` origin, or ''.
   *
   * Rejects anything carrying a path, query, fragment, or userinfo (i.e. only
   * an http(s) origin is accepted) so the relay's asserted Origin can never
   * smuggle a non-origin value. The host is lowercased; a default port
   * (80/443) is dropped. Returns '' on any non-conforming input.
   */
  private function strictOrigin(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    $parts = parse_url($value);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      return '';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
      return '';
    }
    // No path (other than none), query, fragment, user, or pass may be present.
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      return '';
    }
    if (isset($parts['path']) && $parts['path'] !== '') {
      return '';
    }
    $host = strtolower($parts['host']);
    $origin = $scheme . '://' . $host;
    if (isset($parts['port'])) {
      $port = (int) $parts['port'];
      $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
      if ($port >= 1 && $port <= 65535 && !$isDefaultPort) {
        $origin .= ':' . $port;
      }
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
   * Builds a structured, non-cacheable JSON error response.
   */
  private function jsonError(string $message, int $status): JsonResponse {
    return $this->noStore(new JsonResponse(['error' => $message], $status));
  }

  /**
   * Marks a response as private and non-cacheable.
   *
   * The token redeem body carries the opaque per-user bearer token; neither it
   * nor a diagnostic message may be stored by shared/browser caches.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
