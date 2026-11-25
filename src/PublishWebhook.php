<?php

declare(strict_types=1);

namespace Drupal\cinatra;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
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
 * - IDEMPOTENCY: webhook-id is STABLE per publish event and differs between
 *              publish events: it is derived from the instance id, node id,
 *              revision id, language, the stored "changed" time and the
 *              event's sequence number for that node and language (see
 *              buildEmission()). The emission is built once per event, so a
 *              retried delivery carries the same id and the host's
 *              idempotency ledger dedupes it; a later publish of the same
 *              revision (in place, after an unpublish) is a new event with a
 *              new id. The id stays an opaque "drupal-<sha256 hex>" string.
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
   * The key-value collection holding each node language's event sequence.
   */
  public const SEQUENCE_COLLECTION = 'cinatra.publish_webhook';

  /**
   * What the root transaction now running knows.
   *
   * The published state it started with, per node and language, and the
   * webhooks already queued. One object per root transaction, shared by
   * everything registered inside it; a boundary callback marks it ended when
   * the root finishes, so the next use belongs to a new root and gets a new
   * one.
   */
  private static ?\stdClass $root = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly ?Connection $database = NULL,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
    private readonly ?KeyValueStoreInterface $sequences = NULL,
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
      \Drupal::keyValue(self::SEQUENCE_COLLECTION),
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
    // Only a gate here (configured, buildable); what is sent is built when the
    // event is queued, with its sequence number.
    if ($this->buildEmission($node) === NULL) {
      return;
    }
    // SEMANTICS. A publish event is a CHANGE of the stored state across one
    // root transaction, not a root commit. Per root, node and language: at
    // most one webhook, queued only if the root committed, the stored default
    // revision is published, and either the page was not published when the
    // root began or its published default revision is a different one now. It
    // describes the stored default revision. A root that ends where it began
    // (a savepoint rolled back, in place or as a new revision, the root then
    // committed by a caller that caught the error) queues nothing; so does a
    // publish later undone. A save of an already published page registers
    // nothing, as before: only the unpublished-to-published step of a save
    // registers. The state at the root's start is recorded by
    // recordPreRootState() from hook_node_presave, before the first write of
    // the node in the root. fromContainer() always passes the site database.
    //
    // CONTENT, ONE STATE. After the commit the node is loaded ONCE, fresh,
    // and everything is decided on that one object: its translation must be
    // published, its default revision must be one that a save of THIS root
    // wrote (recordRootSave()), and the payload is built from that same
    // object. So a later save of the same revision in this root (an in-place
    // publish followed by an in-place save) is described by its final stored
    // values; and when another request has written in the meantime, its
    // state is never sent as this root's event: unpublished means nothing is
    // sent, a different default revision (another request's publish) means
    // nothing is sent, and a new draft revision of another request is not
    // the default revision and so never read.
    $nid = (int) $node->id();
    $langcode = $node->language()->getId();
    if ($this->database !== NULL && $this->database->inTransaction()) {
      $state = $this->rootState();
      $check = function (bool $committed) use ($state, $nid, $langcode): void {
        if (!$committed) {
          return;
        }
        $key = $nid . ':' . $langcode;
        if (isset($state->queued[$key])) {
          return;
        }
        $translation = $this->loadStoredTranslation($nid, $langcode);
        if ($translation === NULL || !$translation->isPublished()) {
          return;
        }
        $stored = (int) $translation->getRevisionId();
        if (!isset($state->saved[$nid][$stored])) {
          return;
        }
        // Unknown (a new page, or no recording) counts as not published.
        $before = $state->before[$nid][$langcode] ?? NULL;
        if ($before !== NULL && $before['published'] && $before['vid'] === $stored) {
          return;
        }
        // Built from the same object that was checked above.
        if ($this->buildEmission($translation) === NULL) {
          return;
        }
        $final = $this->buildEmission($translation, $this->nextSequence($nid, $langcode));
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
    $final = $this->buildEmission($node, $this->nextSequence($nid, $langcode));
    if ($final !== NULL) {
      drupal_register_shutdown_function(function () use ($final): void {
        $this->send($final);
      });
    }
  }

  /**
   * Takes the next event sequence number of a node language.
   *
   * Kept in the module's own key-value collection and taken once per queued
   * event, so two publish events of the same revision get different numbers.
   * 0 when no store is available (the id then rests on the other inputs).
   */
  private function nextSequence(int $nid, string $langcode): int {
    if ($this->sequences === NULL) {
      return 0;
    }
    try {
      $key = $nid . ':' . $langcode;
      $sequence = (int) $this->sequences->get($key, 0) + 1;
      $this->sequences->set($key, $sequence);
      return $sequence;
    }
    catch (\Throwable) {
      return 0;
    }
  }

  /**
   * Returns the state shared by everything registered in the current root.
   *
   * The first call in a root creates it and registers a boundary callback
   * that marks it ended when the root finishes, so the next root gets its own.
   * Later callbacks of the same root keep the object they captured.
   */
  private function rootState(): \stdClass {
    if (self::$root === NULL || self::$root->ended) {
      $state = (object) ['ended' => FALSE, 'queued' => [], 'before' => [], 'saved' => []];
      self::$root = $state;
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $committed) use ($state): void {
        $state->ended = TRUE;
      });
    }
    return self::$root;
  }

  /**
   * Records, once per root and node, the published state the root started with.
   *
   * Called from hook_node_presave, which runs before the save's own write: the
   * first call for a node in a root therefore reads the state the root began
   * with, from the same connection, before any write of that node in it. Later
   * calls in the root (a second save, one inside a savepoint) keep the first
   * record. A new node has no stored state and records nothing. A quiet no-op
   * outside a transaction or when webhooks are not configured.
   */
  public function recordPreRootState(NodeInterface $node): void {
    $nid = (int) $node->id();
    if ($nid === 0 || $this->database === NULL || !$this->database->inTransaction()) {
      return;
    }
    $config = $this->configFactory->get('cinatra.settings');
    if ((string) $config->get('cinatra_url') === '' || (string) $config->get('webhook_secret') === '' || (string) $config->get('webhook_binding_id') === '') {
      return;
    }
    $state = $this->rootState();
    if (isset($state->before[$nid])) {
      return;
    }
    $before = [];
    try {
      $rows = $this->database->select('node_field_data', 'n')
        ->fields('n', ['langcode', 'vid', 'status'])
        ->condition('nid', $nid)
        ->execute()
        ->fetchAll();
    }
    catch (\Throwable) {
      return;
    }
    foreach ($rows as $row) {
      $before[$row->langcode] = ['published' => (int) $row->status === 1, 'vid' => (int) $row->vid];
    }
    $state->before[$nid] = $before;
  }

  /**
   * Records a revision that a save of the current root wrote.
   *
   * Called from hook_node_insert() and hook_node_update() for every save, so
   * the commit check can tell this root's revisions from another request's.
   * A quiet no-op outside a transaction or when webhooks are not configured.
   */
  public function recordRootSave(NodeInterface $node): void {
    $nid = (int) $node->id();
    if ($nid === 0 || $this->database === NULL || !$this->database->inTransaction()) {
      return;
    }
    $config = $this->configFactory->get('cinatra.settings');
    if ((string) $config->get('cinatra_url') === '' || (string) $config->get('webhook_secret') === '' || (string) $config->get('webhook_binding_id') === '') {
      return;
    }
    $this->rootState()->saved[$nid][(int) $node->getRevisionId()] = TRUE;
  }

  /**
   * Loads the stored default revision of a translation, once and fresh.
   *
   * Never from an entity cache, which can still hold objects whose save was
   * rolled back. NULL when the node or the translation does not exist.
   */
  private function loadStoredTranslation(int $nid, string $langcode): ?NodeInterface {
    if ($this->entityTypeManager === NULL) {
      return NULL;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      // The static and the persistent cache: either can hold a rolled-back
      // object.
      $storage->resetCache([$nid]);
      $stored = $storage->loadUnchanged($nid);
      if (!$stored instanceof NodeInterface || !$stored->hasTranslation($langcode)) {
        return NULL;
      }
      return $stored->getTranslation($langcode);
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
   * @param int $sequence
   *   The event's sequence number for the node language (see nextSequence()).
   *
   * @return array{url: string, body: string, messageId: string, secret: string}|null
   *   The delivery inputs, or NULL when emission is not configured/possible.
   */
  public function buildEmission(NodeInterface $node, int $sequence = 0): ?array {
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

    // STABLE per publish event, DIFFERENT per publish event. The inputs:
    // instance, node, revision, language, the translation's stored changed
    // time and the event's sequence number. The emission is built once per
    // event, so a retried delivery carries the same webhook-id and the host's
    // idempotency ledger dedupes it. A later publish event gets a fresh id:
    // a new revision, or the same revision published again in place (the
    // sequence number moves, and core's ChangedItem moves the changed time
    // of a translation whose status changed). Non-secret inputs only.
    $instanceId = (string) $config->get('instance_id');
    $revisionId = (string) ($node->getRevisionId() ?? $node->getChangedTime());
    $messageId = 'drupal-' . hash('sha256', implode(':', [
      $instanceId,
      $node->id(),
      $revisionId,
      $node->language()->getId(),
      (string) $node->getChangedTime(),
      (string) $sequence,
    ]));

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
