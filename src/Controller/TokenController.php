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
   * The override is accepted ONLY if the ENTIRE raw string matches an exact,
   * anchored bare-origin grammar — parse_url() is NOT trusted to police the
   * value, because it is too permissive (it accepts and partially canonicalizes
   * malformed ports such as ":80x", ":+80", ":1.2" to a real port, and it lets
   * backslash hosts, unbracketed IPv6, and extra-colon forms through to
   * downstream parsers instead of a clean rejection). Since this base becomes
   * the destination of the POST that carries the long-lived API key, the value
   * must be proven safe by construction before it is trusted.
   *
   * Accepted grammar (anchored ^...$, scheme case-insensitive; no whitespace,
   * control chars, userinfo, query, or fragment anywhere):
   * - scheme: exactly "http://" or "https://";
   * - host: ONE of —
   *     (i)   a DNS hostname of dot-separated labels, each matching
   *           [A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])? (so "localhost",
   *           "host.docker.internal", "safe.example" pass; a trailing dot,
   *           a backslash, or an empty label fails);
   *     (ii)  a dotted IPv4 d{1,3}(.d{1,3}){3} with each octet 0–255;
   *     (iii) a BRACKETED IPv6 literal \[[0-9A-Fa-f:]+\] (unbracketed IPv6
   *           is rejected);
   * - optional port: a single ":" followed by purely digits, value 1–65535
   *   (so ":0", ":80x", ":+80", ":1.2", ":65536", and an empty ":" all fail);
   * - optional path: nothing, or exactly a single "/".
   *
   * On a match the canonical bare origin "scheme://host[:port]" is returned
   * (any trailing "/" is dropped, since the token path is appended by the
   * caller). Returns NULL when the value is not a safe bare origin so the
   * caller rejects it and falls back to the configured URL.
   *
   * The regex is deliberately linear (anchored, no nested quantifiers / no
   * "(X+)+" backtracking traps) to avoid any ReDoS exposure on env input.
   *
   * @param string $value
   *   The raw environment value.
   *
   * @return string|null
   *   The canonical scheme://host[:port] origin, or NULL when invalid.
   */
  private function canonicalizeServerBase(string $value): ?string {
    // Reject any control characters or whitespace anywhere in the value before
    // anything else (a bare origin contains none; their presence signals
    // injection/corruption). The grammar below also forbids them, but this is
    // an explicit, cheap first gate.
    if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
      return NULL;
    }

    // Anchored, linear bare-origin grammar. Each alternative is a simple
    // character class with a bounded or single quantifier (no nesting), so
    // matching is O(n) in the input length. Captures: 1=scheme, 2=host,
    // 3=port digits when present.
    //
    //   scheme   : https? (case-insensitive via the /i flag)
    //   host     : IPv4-shaped | bracketed IPv6 | dot-separated DNS labels
    //   port     : : followed by 1–5 digits (range checked numerically below)
    //   path     : optional single /
    //
    // Note: the IPv4 octet range (0–255) and the port range (1–65535) are not
    // expressible cleanly without nesting, so the shape is matched here and the
    // numeric ranges are verified explicitly afterwards.
    $pattern = '#^(https?)://'
      // host: one of the three forms (each linear, no nested quantifiers).
      . '('
      // (ii) IPv4 shape: four 1–3 digit octets (range checked below).
      . '[0-9]{1,3}(?:\.[0-9]{1,3}){3}'
      . '|'
      // (iii) bracketed IPv6 literal: [ hexdigits-and-colons ].
      . '\[[0-9A-Fa-f:]+\]'
      . '|'
      // (i) DNS hostname: dot-separated labels, each starting and ending with
      // an alphanumeric, hyphens only in the interior. The repeated label group
      // is a single (?:...)* over a linear label — no (X+)+ backtracking.
      . '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*'
      . ')'
      // Optional port: a single colon then 1–5 digits (range checked below).
      . '(?::([0-9]{1,5}))?'
      // Optional path: nothing or exactly one slash.
      . '/?$#i';

    if (preg_match($pattern, $value, $m) !== 1) {
      return NULL;
    }

    $scheme = strtolower($m[1]);
    $host = $m[2];

    // IPv4 octet range: if the host is the dotted-quad shape, every octet must
    // be 0–255 (the grammar only bounds it to 1–3 digits). A bracketed IPv6 or
    // a DNS label that merely looks numeric is left untouched.
    if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,3}){3}$/', $host) === 1) {
      foreach (explode('.', $host) as $octet) {
        if ((int) $octet > 255) {
          return NULL;
        }
      }
    }

    $origin = $scheme . '://' . $host;

    // Port range: present only when the optional group matched. Reject 0 and
    // anything above 65535 (the grammar bounds it to 1–5 digits).
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
