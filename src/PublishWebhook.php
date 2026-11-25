<?php

declare(strict_types=1);

namespace Drupal\cinatra;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\DatabaseStorage;
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
 * - IDEMPOTENCY: webhook-id is STABLE per publish event and never shared by
 *              two events: sha256 over the instance id, node id, revision id,
 *              language and a random value drawn once when the emission is
 *              built (see buildEmission()). The built emission is what is
 *              sent, so a retried delivery carries the same id and the host's
 *              idempotency ledger dedupes it; another event, even of the same
 *              revision in the same second, has its own random value and so
 *              its own id. The id stays an opaque "drupal-<sha256 hex>".
 * - TIMING   : an event says what one transaction published, as that
 *              transaction stored it. It can arrive after a later request has
 *              already changed or unpublished the page; that later state is
 *              not in this event (a later publish sends its own).
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
   * The module's key-value collection: the capture markers of the outbox.
   */
  public const COLLECTION = 'cinatra.publish_webhook';

  /**
   * What the root transaction now running knows.
   *
   * The published state it started with, per node and language; the node
   * languages a save of the root published; and, per node language, the
   * captures of its saves in order. One object per root transaction, shared
   * by everything registered inside it; the root callback marks it ended
   * when the root finishes, so the next use belongs to a new root.
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
   * Called for a save that moves the node into the published state. Inside a
   * database transaction it only marks the node language as published by the
   * root; what is sent is decided and taken from the root's captures when the
   * root commits (see the semantics note below). Outside a transaction the
   * emission is built now and sent at shutdown. The network call always
   * happens in a shutdown callback so the node save is never
   * transport-blocked. A quiet no-op when the connection is not fully
   * configured.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that just transitioned into the published state.
   */
  public function deferEmitNodePublished(NodeInterface $node): void {
    if ($this->buildEmission($node) === NULL) {
      return;
    }
    // SEMANTICS. A publish event is a CHANGE of the stored state across one
    // root transaction, not a root commit. Per root, node and language: at
    // most one webhook, queued only if the root committed, a save of the root
    // moved the node into the published state, the root's last kept capture
    // is published, and either the page was not published when the root
    // began or its published default revision is a different one now. A
    // root that ends where it began (a savepoint rolled back, in place or as
    // a new revision, the root then committed by a caller that caught the
    // error) queues nothing; so does a publish later undone.
    //
    // CONTENT, THE OUTBOX. The payload is taken INSIDE the root, at each save
    // of the node (recordRootSave()): the stored default revision read back
    // right after that save, presave and other hooks' changes included. Each
    // save replaces the capture, so the last save of the root wins; a save
    // that leaves the page unpublished leaves an unpublished capture, which
    // sends nothing. Each capture has a marker row written in the same
    // transaction, so a savepoint rolled back removes its captures' markers
    // and the capture before it stands again. At commit the last capture
    // whose marker exists is queued and sent unchanged. Nothing is loaded
    // after the commit to build or check the payload, so nothing another
    // request does after this root committed can change this root's event.
    $nid = (int) $node->id();
    $langcode = $node->language()->getId();
    if ($this->database !== NULL && $this->database->inTransaction()) {
      $this->rootState()->published[$nid . ':' . $langcode] = TRUE;
      return;
    }
    $final = $this->buildEmission($node);
    if ($final !== NULL) {
      drupal_register_shutdown_function(function () use ($final): void {
        $this->send($final);
      });
    }
  }

  /**
   * Returns the state shared by everything registered in the current root.
   *
   * The first call in a root creates it and registers the root callback: it
   * marks the state ended and, when the root committed, queues the root's
   * events from its kept captures and removes the markers.
   */
  private function rootState(): \stdClass {
    if (self::$root === NULL || self::$root->ended) {
      $state = (object) [
        'ended' => FALSE,
        'before' => [],
        'published' => [],
        'captures' => [],
      ];
      self::$root = $state;
      $this->database->transactionManager()->addPostTransactionCallback(function (bool $committed) use ($state): void {
        $state->ended = TRUE;
        if ($committed) {
          $this->queueRootEvents($state);
        }
      });
    }
    return self::$root;
  }

  /**
   * Queues the events of a committed root from its kept captures.
   *
   * Per node language a save of the root published: the last capture whose
   * marker survived the commit (a rolled-back savepoint took its markers
   * with it). It is sent unchanged if it is published and the published
   * default revision changed across the root. The markers are removed.
   */
  private function queueRootEvents(\stdClass $state): void {
    $outbox = $this->outbox();
    if ($outbox === NULL) {
      return;
    }
    $markers = [];
    foreach ($state->captures as $captures) {
      foreach ($captures as $capture) {
        $markers[] = $capture['marker'];
      }
    }
    try {
      $kept = array_flip(array_keys($outbox->getMultiple($markers)));
    }
    catch (\Throwable) {
      return;
    }
    foreach (array_keys($state->published) as $key) {
      $capture = NULL;
      foreach (array_reverse($state->captures[$key] ?? []) as $candidate) {
        if (isset($kept[$candidate['marker']])) {
          $capture = $candidate;
          break;
        }
      }
      if ($capture === NULL || $capture['emission'] === NULL) {
        continue;
      }
      [$nid, $langcode] = explode(':', $key, 2);
      // Unknown (a new page, or no recording) counts as not published.
      $before = $state->before[(int) $nid][$langcode] ?? NULL;
      if ($before !== NULL && $before['published'] && $before['vid'] === $capture['vid']) {
        continue;
      }
      $final = $capture['emission'];
      drupal_register_shutdown_function(function () use ($final): void {
        $this->send($final);
      });
    }
    try {
      $outbox->deleteMultiple($markers);
    }
    catch (\Throwable) {
      // Leftover markers are inert: each key is random and read only by the
      // root that wrote it.
    }
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
    if ($nid === 0 || !$this->configuredInTransaction()) {
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
   * Captures, inside the root, what a save of the node stored.
   *
   * Called from hook_node_insert() and hook_node_update() for every save. It
   * reads the stored default revision of the saved translation back on the
   * root's own connection, so the values are those this transaction wrote,
   * with every presave hook's change, and builds the emission from them (an
   * unpublished translation is captured as "nothing to send"). The capture
   * gets a marker row in the module's key-value collection, written in the
   * same transaction: if a savepoint holding this save is rolled back, the
   * marker goes with it. Without a marker there is no capture, so nothing
   * can be sent from it. A quiet no-op outside a transaction or when webhooks
   * are not configured.
   */
  public function recordRootSave(NodeInterface $node): void {
    $nid = (int) $node->id();
    $outbox = $this->outbox();
    if ($nid === 0 || $outbox === NULL || $this->entityTypeManager === NULL || !$this->configuredInTransaction()) {
      return;
    }
    $langcode = $node->language()->getId();
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $storage->resetCache([$nid]);
      $stored = $storage->loadUnchanged($nid);
      $storage->resetCache([$nid]);
      if (!$stored instanceof NodeInterface || !$stored->hasTranslation($langcode)) {
        return;
      }
      $translation = $stored->getTranslation($langcode);
      $marker = 'outbox:' . bin2hex(random_bytes(16));
      $outbox->set($marker, TRUE);
    }
    catch (\Throwable) {
      return;
    }
    $this->rootState()->captures[$nid . ':' . $langcode][] = [
      'marker' => $marker,
      'vid' => (int) $translation->getRevisionId(),
      'emission' => $translation->isPublished() ? $this->buildEmission($translation) : NULL,
    ];
  }

  /**
   * Tells whether webhooks are configured and a transaction is open.
   */
  private function configuredInTransaction(): bool {
    if ($this->database === NULL || !$this->database->inTransaction()) {
      return FALSE;
    }
    $config = $this->configFactory->get('cinatra.settings');
    return (string) $config->get('cinatra_url') !== '' && (string) $config->get('webhook_secret') !== '' && (string) $config->get('webhook_binding_id') !== '';
  }

  /**
   * Returns the outbox markers' store, on the site database connection.
   *
   * Always the database, on the same connection as the node save, so a
   * marker is written and rolled back with the save it belongs to.
   */
  private function outbox(): ?DatabaseStorage {
    return $this->database === NULL ? NULL : new DatabaseStorage(self::COLLECTION, new PhpSerialize(), $this->database);
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

    // STABLE per publish event, NEVER SHARED by two events: one built
    // emission is one event, and its id has a random value drawn here, once.
    // The built emission is what is sent, so a retried delivery carries the
    // same id and the host's idempotency ledger dedupes it; any other event,
    // even of the same revision in the same second, draws its own value.
    // Non-secret inputs only.
    $instanceId = (string) $config->get('instance_id');
    $revisionId = (string) ($node->getRevisionId() ?? $node->getChangedTime());
    try {
      $nonce = bin2hex(random_bytes(16));
    }
    catch (\Throwable) {
      return NULL;
    }
    $messageId = 'drupal-' . hash('sha256', implode(':', [
      $instanceId,
      $node->id(),
      $revisionId,
      $node->language()->getId(),
      $nonce,
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
