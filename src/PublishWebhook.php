<?php

declare(strict_types=1);

namespace Drupal\cinatra;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Node-publish webhook emitter onto cinatra's GENERIC inbound-webhook route.
 *
 * When a node transitions into the published state this emits a signed
 * `node_published` event to the connected Cinatra instance so the agent can
 * react to newly-published content — the Drupal twin of the WordPress
 * plugin's publish emitter (cinatra-ai/cinatra#974, drupal-module#72).
 *
 * WIRE CONTRACT (pinned to the cinatra generic inbound-webhook facility,
 * cinatra#340/#974):
 * - TARGET   : {base}/webhook/cinatra-ai/drupal-mcp-connector/node-published/
 *              {webhook_binding_id} — the host-owned generic route. The
 *              binding id is SERVER-ISSUED, returned by the connect exchange
 *              together with the signing secret (a paired write), and carries
 *              the connected-site identity on the cinatra side. The transport
 *              base resolves through ServerBase (the validated
 *              CINATRA_BASE_URL container override) and the POST is gated by
 *              the SSRF-safe Ssrf::isAllowedUrl with redirect-following
 *              disabled — never an operator-entered arbitrary target URL.
 * - SIGNING  : Standard-Webhooks. The stored `whsec_` secret is base64-decoded
 *              (after the prefix strip) into the HMAC key; the signed content
 *              is "{webhook-id}.{webhook-timestamp}.{body}" and the header set
 *              is webhook-id / webhook-timestamp / webhook-signature
 *              ("v1,<base64(hmac-sha256)>"). There is NO legacy
 *              `X-Cinatra-Sig-256` arm: Drupal has no field-deployed legacy
 *              fleet, so this module signs Standard-Webhooks from day one.
 * - PAYLOAD  : the exact strict schema the drupal-mcp-connector handler
 *              re-validates — { event:"node_published", nodeId:int>0,
 *              nodeType:string, title:string, url?:string, siteUrl:string,
 *              issuedAt:string }. `nodeId` casts Drupal's numeric-string
 *              $node->id(), exactly as the WordPress plugin casts
 *              (int) $post->ID. The body is JSON-encoded ONCE and the same
 *              exact bytes are signed and sent.
 * - IDEMPOTENCY: webhook-id is STABLE per publish event (derived from the
 *              instance id + node id + revision id) so a retried delivery
 *              carries the same id and the host's idempotency ledger dedupes.
 *
 * SAFETY: emission is deferred to a shutdown callback so the transport can
 * never block or fail the node save itself (codex: a synchronous 4s POST
 * inside a node-save hook is user-visible latency; under PHP-FPM the response
 * is flushed before shutdown callbacks run). Every failure path is quiet and
 * logs only fixed text plus an HTTP status — never the secret, signature,
 * body, or title.
 */
final class PublishWebhook {

  /**
   * The generic-route path for the drupal node-published hook.
   *
   * The trailing segment is the server-issued binding id, appended at build
   * time. Path segments before it are the connector package's vendor/slug and
   * the declared hook id (see the drupal-mcp-connector cinatra.webhooks
   * declaration).
   */
  private const HOOK_PATH = '/webhook/cinatra-ai/drupal-mcp-connector/node-published/';

  /**
   * Standard-Webhooks secret prefix, stripped before base64-decoding the key.
   */
  private const WHSEC_PREFIX = 'whsec_';

  /**
   * Transport timeout (seconds) for the fire-and-forget delivery.
   *
   * Mirrors the WordPress emitter's short cap. The POST runs in a shutdown
   * callback (after the response is flushed under PHP-FPM), so this bounds a
   * worker's tail time, not the user-visible save.
   */
  private const TIMEOUT_SECONDS = 4;

  /**
   * The webhooks already queued in the root transaction now ending.
   *
   * One object per root transaction, shared by every registration made inside
   * it; the first commit callback that runs marks it ended, so the next
   * registration after that belongs to a new root and gets a new one.
   */
  private static ?\stdClass $root = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly ?Connection $database = NULL,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
  }

  /**
   * Builds an emitter from the global container (for .module hook call sites).
   */
  public static function fromContainer(): self {
    return new self(
      \Drupal::httpClient(),
      \Drupal::configFactory(),
      \Drupal::logger('cinatra'),
      \Drupal::requestStack(),
      \Drupal::database(),
      \Drupal::entityTypeManager(),
    );
  }

  /**
   * Defers a node-published emission to request shutdown.
   *
   * The emission payload/target/key material is captured NOW (the entity may
   * be mutated later in the request), but the network call happens in a
   * shutdown callback so the node save is never transport-blocked. Inside a
   * database transaction it is registered only once that transaction has
   * committed, and then at most one event per node and language is queued,
   * for the stored published state (see the semantics note below). A quiet
   * no-op when the connection is not fully configured.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that just transitioned into the published state.
   */
  public function deferEmitNodePublished(NodeInterface $node): void {
    $emission = $this->buildEmission($node);
    if ($emission === NULL) {
      return;
    }
    $register = function () use ($emission): void {
      drupal_register_shutdown_function(function () use ($emission): void {
        $this->send($emission);
      });
    };
    // SEMANTICS: per root transaction, node and language, AT MOST ONE webhook,
    // queued only when the root committed and the stored state is published,
    // and describing the stored default revision. A root commit alone proves
    // nothing about the publishing save: it may have been in a savepoint that a
    // later hook rolled back, or in place on an existing revision that was
    // rolled back, with the root then committed by a caller that caught the
    // error. So each registration is checked against the stored state, and all
    // registrations of one node and language collapse into one event.
    // fromContainer() always passes the site database.
    if ($this->database !== NULL && $this->database->inTransaction()) {
      if (self::$root === NULL || self::$root->ended) {
        self::$root = (object) ['ended' => FALSE, 'queued' => []];
      }
      $state = self::$root;
      $nid = (int) $node->id();
      $revision_id = (int) $node->getRevisionId();
      $langcode = $node->language()->getId();
      $check = function (bool $committed) use ($state, $emission, $nid, $revision_id, $langcode): void {
        $state->ended = TRUE;
        if (!$committed) {
          return;
        }
        $stored = $this->storedPublishedRevision($nid, $langcode);
        // A stored revision older than this save's own is a state the root
        // fell back to (everything since was rolled back): not this publish.
        if ($stored === NULL || $stored < $revision_id) {
          return;
        }
        $key = $nid . ':' . $langcode;
        if (isset($state->queued[$key])) {
          return;
        }
        $final = $stored === $revision_id ? $emission : $this->emissionForStored($nid, $langcode);
        if ($final === NULL) {
          return;
        }
        $state->queued[$key] = TRUE;
        drupal_register_shutdown_function(function () use ($final): void {
          $this->send($final);
        });
      };
      $this->database->transactionManager()->addPostTransactionCallback($check);
      return;
    }
    $register();
  }

  /**
   * Reads the stored default revision of a translation if it is published.
   *
   * Straight from the database, never the entity cache, which still holds
   * objects whose save was rolled back. The translation's default revision
   * (node_field_data) must be published AND its own stored row
   * (node_field_revision, that revision and language) must be published.
   *
   * @param int $nid
   *   The node ID.
   * @param string $langcode
   *   The translation.
   *
   * @return int|null
   *   The stored default revision ID, or NULL when it is not published.
   */
  private function storedPublishedRevision(int $nid, string $langcode): ?int {
    try {
      $row = $this->database->select('node_field_data', 'n')
        ->fields('n', ['vid', 'status'])
        ->condition('nid', $nid)
        ->condition('langcode', $langcode)
        ->execute()
        ->fetchAssoc();
      if (!is_array($row) || (int) $row['status'] !== 1) {
        return NULL;
      }
      $revision_status = $this->database->select('node_field_revision', 'r')
        ->fields('r', ['status'])
        ->condition('vid', (int) $row['vid'])
        ->condition('langcode', $langcode)
        ->execute()
        ->fetchField();
      return $revision_status !== FALSE && (int) $revision_status === 1 ? (int) $row['vid'] : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Builds the emission of the stored default revision, loaded fresh.
   *
   * Used when the save that registered a callback is not the stored one (a
   * later save kept the page published): the one event describes the stored
   * state. NULL when that cannot be built.
   */
  private function emissionForStored(int $nid, string $langcode): ?array {
    if ($this->entityTypeManager === NULL) {
      return NULL;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $storage->resetCache([$nid]);
      $stored = $storage->load($nid);
      if (!$stored instanceof NodeInterface) {
        return NULL;
      }
      return $this->buildEmission($stored->hasTranslation($langcode) ? $stored->getTranslation($langcode) : $stored);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Builds the signed-delivery inputs for a published node.
   *
   * Guards (each a quiet no-op, mirroring the WordPress emitter's quiet
   * bails): the instance URL, the webhook signing secret, and the
   * server-issued binding id must ALL be configured — the secret and binding
   * id are a pair written together by the connect exchange, so a partial
   * configuration means "webhooks not provisioned".
   *
   * @param \Drupal\node\NodeInterface $node
   *   The published node.
   *
   * @return array{url: string, body: string, messageId: string, secret: string}|null
   *   The delivery inputs, or NULL when emission is not configured/possible.
   */
  public function buildEmission(NodeInterface $node): ?array {
    $config = $this->configFactory->get('cinatra.settings');
    $configUrl = rtrim((string) $config->get('cinatra_url'), '/');
    $secret = (string) $config->get('webhook_secret');
    $bindingId = (string) $config->get('webhook_binding_id');
    if ($configUrl === '' || $secret === '' || $bindingId === '') {
      return NULL;
    }

    // The site origin the payload reports (schema-required). Prefer the
    // current request; absent one (e.g. drush), fall back to the canonical
    // node URL's origin below — and bail quietly if neither is available.
    $siteUrl = '';
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $siteUrl = $request->getSchemeAndHttpHost();
    }

    // Canonical absolute URL is OPTIONAL in the payload — omit it when it
    // cannot be generated rather than failing the emission.
    $url = NULL;
    try {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Exception) {
      $url = NULL;
    }
    if ($siteUrl === '' && is_string($url) && $url !== '') {
      $parts = parse_url($url);
      if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
        $siteUrl = $parts['scheme'] . '://' . $parts['host']
          . (isset($parts['port']) ? ':' . $parts['port'] : '');
      }
    }
    if ($siteUrl === '') {
      return NULL;
    }

    $payload = [
      'event' => 'node_published',
      'nodeId' => (int) $node->id(),
      'nodeType' => $node->bundle(),
      'title' => (string) $node->label(),
      'siteUrl' => $siteUrl,
      'issuedAt' => date('c'),
    ];
    if (is_string($url) && $url !== '') {
      $payload['url'] = $url;
    }
    // Encode ONCE; the same exact bytes are signed and sent.
    $body = json_encode($payload);
    if (!is_string($body) || $body === '') {
      return NULL;
    }

    // STABLE per publish event (instance + node + revision): a retried
    // delivery of the same event carries the same webhook-id, so the host's
    // idempotency ledger dedupes; a LATER publish event (new revision) gets a
    // fresh id. Non-secret inputs only.
    $instanceId = (string) $config->get('instance_id');
    $revisionId = (string) ($node->getRevisionId() ?? $node->getChangedTime());
    $messageId = 'drupal-' . hash('sha256', $instanceId . ':' . $node->id() . ':' . $revisionId);

    $base = ServerBase::resolve($configUrl, $this->logger);
    return [
      'url' => rtrim($base, '/') . self::HOOK_PATH . rawurlencode($bindingId),
      'body' => $body,
      'messageId' => $messageId,
      'secret' => $secret,
    ];
  }

  /**
   * Signs and sends a built emission. Fire-and-forget; never throws.
   *
   * @param array{url: string, body: string, messageId: string, secret: string} $emission
   *   The delivery inputs from buildEmission().
   */
  public function send(array $emission): void {
    $timestamp = time();
    $signature = self::sign($emission['secret'], $emission['messageId'], $timestamp, $emission['body']);
    if ($signature === NULL) {
      // A malformed stored secret (not base64) — fixed text, never the value.
      $this->logger->warning('Cinatra publish webhook skipped: the stored webhook secret is not a valid Standard-Webhooks secret.');
      return;
    }

    // HTTP-layer SSRF guard (defense-in-depth over the configured-origin
    // validation): never POST to a loopback/private/link-local target (the
    // documented dev hosts stay permitted). Redirect-following is disabled so
    // a 3xx cannot retarget the request past this check.
    if (!Ssrf::isAllowedUrl($emission['url'])) {
      $this->logger->warning('Cinatra publish webhook blocked: the configured instance is not a public origin.');
      return;
    }

    try {
      $response = $this->httpClient->request('POST', $emission['url'], [
        'timeout' => self::TIMEOUT_SECONDS,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'webhook-id' => $emission['messageId'],
          'webhook-timestamp' => (string) $timestamp,
          'webhook-signature' => $signature,
        ],
        // The exact signed bytes — never re-encoded.
        'body' => $emission['body'],
      ]);
    }
    catch (\Throwable) {
      // Fixed text only — never the secret, signature, body, or transport
      // detail (it can echo the request).
      $this->logger->warning('Cinatra publish webhook transport failed.');
      return;
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      $this->logger->warning('Cinatra publish webhook rejected (HTTP @status).', [
        '@status' => $status,
      ]);
    }
  }

  /**
   * Computes the Standard-Webhooks v1 signature header value.
   *
   * The key is the base64-decoded secret (after the optional whsec_ prefix
   * strip); the signed content is "{id}.{timestamp}.{body}"; the header value
   * is "v1," followed by the base64 of the raw HMAC-SHA256. This matches the
   * standardwebhooks reference libraries byte-for-byte (the cinatra host
   * verifies with exactly that library).
   *
   * @param string $secret
   *   The stored webhook secret (whsec_-prefixed base64).
   * @param string $messageId
   *   The webhook-id header value.
   * @param int $timestamp
   *   Seconds since epoch (the webhook-timestamp header value).
   * @param string $body
   *   The exact request body bytes.
   *
   * @return string|null
   *   The "v1,<base64>" signature, or NULL when the secret does not decode.
   */
  public static function sign(string $secret, string $messageId, int $timestamp, string $body): ?string {
    $encoded = $secret;
    if (str_starts_with($encoded, self::WHSEC_PREFIX)) {
      $encoded = substr($encoded, strlen(self::WHSEC_PREFIX));
    }
    $key = base64_decode($encoded, TRUE);
    if (!is_string($key) || $key === '') {
      return NULL;
    }
    $content = $messageId . '.' . $timestamp . '.' . $body;
    return 'v1,' . base64_encode(hash_hmac('sha256', $content, $key, TRUE));
  }

}
