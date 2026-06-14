<?php

declare(strict_types=1);

namespace Drupal\cinatra\Controller;

use Drupal\cinatra\CinatraUrl;
use Drupal\cinatra\Connect;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Connect-with-Cinatra one-click provisioning (cinatra#221, drupal half).
 *
 * The redirect handshake and the install-code fallback are STARTED from the
 * settings form (real Form API submit handlers, so Drupal's form CSRF token
 * protects them). This controller owns:
 *   - callback(): the OAuth redirect target cinatra-core 302s back to. It is a
 *     GET route gated on 'administer site configuration'; the single-use,
 *     uid-bound, server-stored `state` is the CSRF control for the round-trip
 *     (a Drupal form token cannot survive an off-site redirect).
 *   - the shared server-to-server exchange + credential store.
 *
 * The long-lived per-site credential returned by the exchange is written to
 * cinatra.settings server-side and NEVER returned to the browser. The PKCE
 * verifier, code, and credential are never logged.
 */
final class ConnectController extends ControllerBase {

  /**
   * The keyvalue-expirable collection holding pending connect handshakes.
   */
  private const STATE_COLLECTION = 'cinatra_connect';

  public function __construct(
    private readonly ConfigFactoryInterface $cinatraConfigFactory,
    private readonly ClientInterface $httpClient,
    private readonly AccountInterface $account,
    private readonly RequestStack $requestStack,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
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
      $container->get('keyvalue.expirable'),
      $container->get('logger.factory')->get('cinatra'),
    );
  }

  /**
   * Starts the redirect handshake: persists state + PKCE, 302s to cinatra-core.
   *
   * Called from the settings form's Connect submit handler (not a public route)
   * so it inherits the form's CSRF protection. Returns a redirect response
   * to the admin-supplied (CinatraUrl-validated) instance authorize URL.
   *
   * @param string $rawInstanceUrl
   *   The instance URL the admin entered.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect either to the instance authorize URL or back to settings.
   */
  public function start(string $rawInstanceUrl): RedirectResponse {
    $base = CinatraUrl::normalize($rawInstanceUrl);
    if ($base === NULL) {
      $this->messenger()->addError($this->t('Enter a valid HTTPS Cinatra instance URL (for example https://app.example.com).'));
      return $this->backToSettings();
    }

    $redirectUri = $this->callbackUri();
    if (!Connect::isValidCallbackUri($redirectUri)) {
      // The generated callback URL does not match the contract-pinned shape
      // (e.g. a language prefix or base path mangled it). Refuse rather than
      // hand cinatra-core a redirect_uri it will reject.
      $this->logger->error('Cinatra connect: generated callback URI does not match the pinned contract path; aborting.');
      $this->messenger()->addError($this->t('Could not start the connection from this site configuration. Use the manual configuration below.'));
      return $this->backToSettings();
    }

    $pkce = Connect::pkce();
    $state = Connect::newState();
    $this->stateStore()->setWithExpire(
      Connect::stateKey($state),
      [
        'uid' => (int) $this->account->id(),
        'instance_url' => $base,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $pkce['verifier'],
        'created' => time(),
      ],
      Connect::STATE_TTL,
    );

    $authorizeUrl = Connect::authorizeUrl($base, $redirectUri, $this->siteOrigin(), $state, $pkce['challenge']);
    // Off-site redirect: Drupal requires a TrustedRedirectResponse for external
    // URLs. The host was validated by CinatraUrl::normalize above. The response
    // carries a bound state token in the URL; mark it non-cacheable.
    $response = new TrustedRedirectResponse($authorizeUrl);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * OAuth redirect target: exchanges the returned code for the credential.
   */
  public function callback(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $error = (string) ($request?->query->get('error') ?? '');
    $code = (string) ($request?->query->get('code') ?? '');
    $state = (string) ($request?->query->get('state') ?? '');

    // Consume the pending handshake up front (read then delete) so the state +
    // PKCE verifier are truly single-use across ALL terminal outcomes (success,
    // cancel, or a malformed return), not just the success path.
    $store = $this->stateStore();
    $pending = NULL;
    if ($state !== '') {
      $key = Connect::stateKey($state);
      $pending = $store->get($key);
      $store->delete($key);
    }

    if ($error !== '') {
      // The admin denied (or cinatra-core rejected) the request. Do not reflect
      // the raw error value back into the page.
      $this->messenger()->addWarning($this->t('The connection was cancelled on the Cinatra side. No changes were made.'));
      return $this->backToSettings();
    }
    if ($code === '' || $state === '') {
      $this->messenger()->addError($this->t('Cinatra did not return an authorization code. The connection was not completed.'));
      return $this->backToSettings();
    }

    if (!is_array($pending)
      || (int) ($pending['uid'] ?? -1) !== (int) $this->account->id()
      || empty($pending['instance_url'])
      || empty($pending['code_verifier'])) {
      $this->messenger()->addError($this->t('This connection request expired or did not match. Please click Connect again.'));
      return $this->backToSettings();
    }

    $instanceUrl = (string) $pending['instance_url'];
    $result = $this->exchange($instanceUrl, [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'client' => Connect::CLIENT,
      'redirect_uri' => (string) ($pending['redirect_uri'] ?? $this->callbackUri()),
      'code_verifier' => (string) $pending['code_verifier'],
    ]);

    $this->applyResult($instanceUrl, $result);
    // Redirect immediately back to settings so `code`/`state` are stripped from
    // the address bar (they must not linger or be cached / shared).
    return $this->backToSettings();
  }

  /**
   * Exchanges a pasted connection string (install_code fallback).
   *
   * Called from the settings form's install-code submit handler.
   *
   * @param string $connectionString
   *   The raw connection string the admin pasted.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect back to the settings form.
   */
  public function installCode(string $connectionString): RedirectResponse {
    $parsed = Connect::parseConnectionString($connectionString);
    if ($parsed === NULL) {
      $this->messenger()->addError($this->t('That connection string is not valid. Copy it again from Cinatra.'));
      return $this->backToSettings();
    }
    $result = $this->exchange($parsed['instance_url'], [
      'grant_type' => 'install_code',
      'install_code' => $parsed['install_code'],
      'client' => Connect::CLIENT,
    ]);
    $this->applyResult($parsed['instance_url'], $result);
    return $this->backToSettings();
  }

  /**
   * Performs the server-to-server token exchange at the instance.
   *
   * @param string $instanceUrl
   *   The CinatraUrl-normalized instance origin we POST to (the SSRF gate).
   * @param array $body
   *   The exchange request body (grant_type + code/install_code + client …).
   *
   * @return array{ok: bool, response: array|null}
   *   Normalized outcome; the raw upstream body is never surfaced.
   */
  private function exchange(string $instanceUrl, array $body): array {
    $base = CinatraUrl::normalize($instanceUrl);
    if ($base === NULL) {
      return ['ok' => FALSE, 'response' => NULL];
    }
    try {
      $response = $this->httpClient->post($base . '/api/connect/token', [
        'timeout' => 15,
        'http_errors' => FALSE,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => $body,
      ]);
    }
    catch (GuzzleException $e) {
      // Never reflect the transport error (it can echo the request body, which
      // carries the code/install_code). Log a generic line server-side.
      $this->logger->error('Cinatra connect exchange transport error (grant=@grant).', [
        '@grant' => (string) ($body['grant_type'] ?? 'unknown'),
      ]);
      return ['ok' => FALSE, 'response' => NULL];
    }

    $status = $response->getStatusCode();
    $json = json_decode((string) $response->getBody(), TRUE);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['credential'])) {
      // cinatra-core returns a generic invalid_grant on any failure;
      // log only the status (no body — it could echo a secret) and report a
      // generic message to the admin.
      $this->logger->warning('Cinatra connect exchange rejected (HTTP @status, grant=@grant).', [
        '@status' => $status,
        '@grant' => (string) ($body['grant_type'] ?? 'unknown'),
      ]);
      return ['ok' => FALSE, 'response' => NULL];
    }
    return ['ok' => TRUE, 'response' => $json];
  }

  /**
   * Persists a successful exchange to cinatra.settings (server-side only).
   *
   * The stored URL is the instance we POSTed to — NOT response.url. If the
   * response advertises a different origin we ignore it (and log a scrubbed
   * warning) so a hostile authorize page cannot retarget the stored credential
   * to another origin.
   */
  private function applyResult(string $instanceUrl, array $result): void {
    if (empty($result['ok']) || !is_array($result['response'] ?? NULL)) {
      $this->messenger()->addError($this->t('Could not complete the connection. Check the URL and try again, or use the manual configuration below.'));
      return;
    }
    $r = $result['response'];
    $credential = (string) ($r['credential'] ?? '');
    if ($credential === '') {
      $this->messenger()->addError($this->t('Cinatra did not return a credential. The connection was not completed.'));
      return;
    }

    // Bind the stored URL to the instance we actually talked to.
    if (isset($r['url']) && is_string($r['url'])) {
      $advertised = CinatraUrl::normalize((string) $r['url']);
      if ($advertised !== NULL && $advertised !== $instanceUrl) {
        $this->logger->warning('Cinatra connect: response url differs from the connected instance; keeping the connected instance origin.');
      }
    }

    $config = $this->cinatraConfigFactory->getEditable('cinatra.settings');
    $config->set('cinatra_url', $instanceUrl);
    $config->set('api_key', $credential);
    // Always overwrite instance_id from THIS connection so a reconnect to a
    // different (or older, instance-id-less) instance never leaves a stale
    // value behind in config or in the browser-facing drupalSettings payload.
    $instanceId = (!empty($r['cinatraInstanceId']) && is_string($r['cinatraInstanceId']))
      ? $r['cinatraInstanceId']
      : '';
    $config->set('instance_id', $instanceId);
    $config->save();

    $this->messenger()->addStatus($this->t('Connected to Cinatra. The integration credential is stored on this server and is never sent to the browser.'));
  }

  /**
   * The absolute, contract-pinned callback URI for this site.
   *
   * Built from the request scheme+host and the literal pinned path so a base
   * path / language prefix cannot mangle it (start() re-asserts the shape via
   * Connect::isValidCallbackUri before use).
   */
  private function callbackUri(): string {
    return $this->siteOrigin() . Connect::CALLBACK_PATH;
  }

  /**
   * This site's origin (scheme://host[:port]) for widget_origin / callback.
   */
  private function siteOrigin(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    return $request->getSchemeAndHttpHost();
  }

  /**
   * The pending-handshake state store.
   */
  private function stateStore() {
    return $this->keyValueExpirable->get(self::STATE_COLLECTION);
  }

  /**
   * A non-cacheable redirect back to the settings form.
   */
  private function backToSettings(): RedirectResponse {
    $response = $this->redirect('cinatra.settings_form');
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
