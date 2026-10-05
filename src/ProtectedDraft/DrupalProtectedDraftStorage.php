<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCache;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\TransactionManagerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Guards actual node revisions and workflow storage in one SQL transaction.
 */
final class DrupalProtectedDraftStorage implements ProtectedDraftStorageInterface {

  /**
   * Node identifier owned by the current root transaction.
   *
   * @var int|null
   */
  private ?int $lockedNode = NULL;
  /**
   * Stored published node used to validate the protected write.
   *
   * @var NodeInterface|null
   */
  private ?NodeInterface $preimage = NULL;
  /**
   * Fresh locked workflow configuration.
   *
   * @var array|null
   */
  private ?array $workflowConfig = NULL;
  /**
   * Complete locked configuration sets used by comparison tokens.
   *
   * @var array
   */
  private array $configuration = [];
  /**
   * Original cache bindings restored after commit or rollback.
   *
   * @var array
   */
  private array $privateCaches = [];
  /**
   * Native root transaction owned by this adapter.
   *
   * @var Transaction|null
   */
  private ?Transaction $ownedTransaction = NULL;
  /**
   * Identifier of the original native root transaction.
   *
   * @var string|null
   */
  private ?string $ownedTransactionId = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $account,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $activeConfig,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function withLockedNode(int $nid, callable $operation): array {
    if ($this->lockedNode !== NULL || $this->database->inTransaction()
      || $this->database->getClientConnection()->inTransaction()) {
      throw new ProtectedDraftRefusal('A protected edit requires its own root transaction.');
    }
    $this->assertConfigurationStorage();
    $storage = $this->nodeStorage();
    $type = $storage->getEntityType();
    $revision_table = $type->getRevisionTable();
    $base_table = $type->getBaseTable();
    $id = $type->getKey('id');
    $driver = $this->database->driver();
    if (!in_array($driver, ['pgsql', 'mysql', 'sqlite'], TRUE)) {
      throw new ProtectedDraftRefusal('The database cannot provide protected revision locking.');
    }
    $manager = $this->database->transactionManager();
    $expected_manager = 'Drupal\\' . $driver . '\\Driver\\Database\\' . $driver . '\\TransactionManager';
    if (!$manager instanceof TransactionManagerBase || get_class($manager) !== $expected_manager
      || $manager->stackDepth() !== 0) {
      throw new ProtectedDraftRefusal('The core transaction protocol is not available.');
    }
    if ($driver === 'mysql') {
      // READ COMMITTED does not lock a missing revision range against inserts.
      // This affects the next root transaction only, not the session default.
      foreach (array_unique(array_merge(['config'], $storage->getTableMapping()->getTableNames())) as $table) {
        $this->assertInnoDb($table);
      }
      $this->database->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    }
    elseif ($driver === 'sqlite') {
      $client = $this->database->getClientConnection();
      $method = new \ReflectionMethod($client, 'beginTransaction');
      if (!in_array($method->getDeclaringClass()->getName(), [
        'Drupal\sqlite\Driver\Database\sqlite\SqliteConnection',
        'Drupal\sqlite\Driver\Database\sqlite\PDOConnection',
      ], TRUE)) {
        throw new ProtectedDraftRefusal('SQLite must reserve its writer lock with the core immediate transaction.');
      }
    }

    $transaction = $this->database->startTransaction();
    $this->ownedTransaction = $transaction;
    $this->ownedTransactionId = (new \ReflectionProperty(Transaction::class, 'id'))->getValue($transaction);
    try {
      $this->assertActiveTransaction();
      if ($driver === 'pgsql') {
        // Covers ordinary Drupal config/revision writers too, including a NEW
        // workflow assignment or non-default revision with no existing row.
        $this->database->query('LOCK TABLE {config}, {' . $revision_table . '} IN SHARE ROW EXCLUSIVE MODE');
      }
      elseif ($driver === 'mysql') {
        // InnoDB SERIALIZABLE next-key locks protect both existing rows and
        // their gaps. Consume each cursor without retaining a site-wide array.
        $this->consume($this->database->query('SELECT [name] FROM {config} WHERE [collection] = :collection FOR UPDATE', [
          ':collection' => '',
        ]));
      }
      // Storage handlers retain field definitions/table mapping. Rebuild after
      // the configuration lock, and bind the fresh handlers to this connection.
      $this->configFactory->reset();
      $this->fieldManager->clearCachedFieldDefinitions();
      $this->bundleInfo->clearCachedBundles();
      $this->entityTypeManager->clearCachedDefinitions();
      $storage = $this->nodeStorage();
      $fresh_type = $storage->getEntityType();
      if ($fresh_type->getBaseTable() !== $base_table
        || $fresh_type->getRevisionTable() !== $revision_table
        || $fresh_type->getKey('id') !== $id) {
        throw new ProtectedDraftRefusal('The node storage mapping changed; read the page again.');
      }
      $mapped_tables = $storage->getTableMapping()->getTableNames();
      if ($this->moduleHandler->moduleExists('content_moderation')) {
        $moderation_storage = $this->entityTypeManager->getStorage('content_moderation_state');
        $this->assertSqlStorageConnection($moderation_storage);
        $mapped_tables = array_merge($mapped_tables, $moderation_storage->getTableMapping()->getTableNames());
      }
      // Core's normal loaders can publish uncommitted entities into shared
      // persistent caches. Keep BOTH handlers' caches local until commit or
      // rollback, including the loads performed by core moderation save hooks.
      $this->isolateCaches($storage);
      if (isset($moderation_storage)) {
        $this->isolateCaches($moderation_storage);
      }
      if ($driver === 'mysql') {
        foreach (array_unique($mapped_tables) as $table) {
          $this->assertInnoDb($table);
        }
        $this->consume($this->database->query('SELECT [' . $id . '] FROM {' . $revision_table . '} WHERE [' . $id . '] = :nid FOR UPDATE', [
          ':nid' => $nid,
        ]));
      }
      // SQLite's root BEGIN IMMEDIATE has already reserved the writer. The
      // other drivers also protect in-place/default saves through the node row.
      $query = $this->database->select($base_table, 'n')->fields('n', [$id])->condition($id, $nid);
      if ($driver !== 'sqlite') {
        $query->forUpdate();
      }
      if (!$query->execute()->fetchField()) {
        throw new ProtectedDraftRefusal('The page is not available for a protected edit.');
      }
      $this->lockedNode = $nid;
      $result = $operation();
      $this->assertActiveTransaction();
      if (method_exists($transaction, 'commitOrRelease')) {
        $transaction->commitOrRelease();
      }
      else {
        // The byte-grounded Drupal 10.3 protocol has no commitOrRelease().
        // Unpile explicitly while exceptions are catchable, not on destruction.
        $transaction_id = (new \ReflectionProperty(Transaction::class, 'id'))->getValue($transaction);
        $manager->unpile($transaction->name(), $transaction_id);
      }
      if ($this->transactionState() !== ClientConnectionTransactionState::Committed
        || $manager->stackDepth() !== 0
        || $this->database->inTransaction()
        || $this->database->getClientConnection()->inTransaction()) {
        throw new ProtectedDraftRefusal('The protected transaction did not finish its root commit.');
      }
      return $result;
    }
    catch (\Throwable $e) {
      // startTransaction() succeeded before entering this try block, so every
      // failure here has an owned transaction to roll back.
      try {
        $transaction->rollBack();
      }
      catch (\Throwable $rollback_error) {
        throw new ProtectedDraftRefusal('The edit could not be verified; inspect the page revision before retrying.', 0, $rollback_error);
      }
      throw $e;
    }
    finally {
      try {
        $this->restoreCaches();
      }
      finally {
        $this->lockedNode = NULL;
        $this->preimage = NULL;
        $this->workflowConfig = NULL;
        $this->configuration = [];
        $this->ownedTransaction = NULL;
        $this->ownedTransactionId = NULL;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readDefault(int $nid, string $language, array $fields): array {
    $this->assertLocked($nid);
    $storage = $this->nodeStorage();
    $node = $this->hydrateDefault($storage, $nid);
    if (!$node instanceof NodeInterface || !$node->hasTranslation($language)) {
      throw new ProtectedDraftRefusal('The exact page translation is not available.');
    }
    $workflow = $this->workflowFor($node);
    $this->assertFreshFieldConfiguration($node);
    $this->bindStoredModeration($node, $workflow);
    $raw_translation = $node->getTranslation($language);
    $stored_fields = $this->values($raw_translation, $fields);
    $published = [];
    foreach ($node->getTranslationLanguages() as $langcode => $lang) {
      // INTERNAL SQL-hydrated equality snapshot, never a load-hook echo.
      $published[$langcode] = $node->getTranslation($langcode)->toArray();
    }
    $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
    // Access hooks inspect a clone; they cannot mutate stored return values.
    $translation = $this->readableTranslation(clone $node, $language, $fields);
    $definitions = $raw_translation->getFieldDefinitions();
    $moderation = $definitions['moderation_state'] ?? NULL;
    $canonical = $moderation !== NULL
      && $moderation->getFieldStorageDefinition()->getProvider() === 'content_moderation'
      && !isset($definitions['field_moderation_state']);
    $states = [];
    $allowed = [];
    if ($workflow !== NULL) {
      $plugin = $workflow->getTypePlugin();
      foreach ($plugin->getStates() as $state) {
        $states[$state->id()] = [
          'published' => $state->isPublishedState(),
          'default_revision' => $state->isDefaultRevisionState(),
        ];
      }
      $current = $raw_translation->get('moderation_state')->value;
      if (is_string($current) && $current !== '' && $plugin->hasState($current)) {
        foreach ($plugin->getState($current)->getTransitions() as $transition) {
          if ($this->account->hasPermission('use ' . $workflow->id() . ' transition ' . $transition->id())) {
            $allowed[] = $transition->to()->id();
          }
        }
      }
    }
    $access = [];
    foreach ($fields as $field) {
      $definition = $definitions[$field];
      $access[$field] = $this->editableField($translation, $field)
        && $translation->get($field)->access('edit', $this->account, TRUE)->isAllowed()
        && $definition->getFieldStorageDefinition()->isRevisionable();
    }
    // Names only, so the connector can explicitly request the complete
    // readable review field set without guessing a bundle's field schema.
    $available_fields = [];
    foreach (array_keys($definitions) as $field) {
      if ($this->editableField($translation, $field)
        && $translation->get($field)->access('view', $this->account, TRUE)->isAllowed()) {
        $available_fields[] = $field;
      }
    }
    $this->preimage = $raw_translation;
    return [
      'node_id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'language' => $raw_translation->language()->getId(),
      'default_revision_id' => (int) $node->getRevisionId(),
      // Whole-node latest, not a per-language projection hiding another draft.
      'latest_revision_id' => $this->latestRevisionId($storage, $nid),
      'is_default_revision' => $node->isDefaultRevision(),
      'is_published' => $raw_translation->isPublished(),
      'moderated' => $workflow !== NULL,
      'canonical_moderation_field' => $canonical,
      'workflow_fingerprint' => $this->workflowFingerprint($definitions),
      'preimage_fingerprint' => $this->fingerprint($published),
      'can_read' => TRUE,
      'can_update' => $translation->access('update', $this->account, TRUE)->isAllowed(),
      'draft_states' => $states,
      'allowed_draft_states' => array_values(array_unique($allowed)),
      'field_access' => $access,
      'available_fields' => $available_fields,
      'fields' => $stored_fields,
      'published_values' => $published,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function saveDraft(int $nid, string $language, string $state, array $updates): int {
    $this->assertLocked($nid);
    if (!$this->preimage || (int) $this->preimage->id() !== $nid
      || $this->preimage->language()->getId() !== $language) {
      throw new ProtectedDraftRefusal('The edit is not bound to its authorized preimage.');
    }
    $before_content = $this->draftContent($this->preimage);
    $shared_fields = [];
    $node = clone $this->preimage;
    foreach ($updates as $field => $items) {
      if (!$this->editableField($node, $field) || !is_array($items) || !array_is_list($items)) {
        throw new ProtectedDraftRefusal('Updates must use editable revisioned fields and structured item values.');
      }
      if (!$node->getFieldDefinition($field)->isTranslatable()) {
        $shared_fields[] = $field;
      }
      $node->get($field)->setValue($items);
    }
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->setUnpublished();
    $node->set('moderation_state', $state);
    $node->setRevisionTranslationAffected(TRUE);
    $node->setRevisionUserId((int) $this->account->id());
    $node->setRevisionCreationTime(time());
    $node->setRevisionLogMessage('Protected draft awaiting review.');
    $this->assertDraftAccess($node, array_keys($updates));
    // Core moderation's workflow must still be exactly the raw locked one.
    $fingerprint = $this->workflowFingerprint($node->getFieldDefinitions());
    $this->workflowFor($node);
    $this->assertFreshFieldConfiguration($node);
    if (!hash_equals($fingerprint, $this->workflowFingerprint($node->getFieldDefinitions()))) {
      throw new ProtectedDraftRefusal('The workflow or field configuration changed before saving.');
    }
    $storage = $this->nodeStorage();
    $this->assertActiveTransaction();
    $storage->save($node);
    $this->assertActiveTransaction();
    $stored = $this->hydrateRevision($storage, (int) $node->getRevisionId());
    if (!$stored instanceof NodeInterface || (int) $stored->id() !== $nid
      || $stored->uuid() !== $this->preimage->uuid() || !$stored->hasTranslation($language)) {
      throw new ProtectedDraftRefusal('The stored draft identity could not be verified.');
    }
    $workflow = $this->workflowFor($stored);
    $this->assertFreshFieldConfiguration($stored);
    $this->bindStoredModeration($stored, $workflow);
    // Save hooks can rewrite values after the proposed candidate passed access.
    // Validate actual SQL content/permissions before the root may commit.
    ProtectedDraftFieldScope::assertMatches($before_content, $this->draftContent($stored), $language, array_keys($updates), $shared_fields);
    $this->assertDraftAccess(clone $stored->getTranslation($language), array_keys($updates));
    return (int) $node->getRevisionId();
  }

  /**
   * Checks stored draft edit, field and text-format access before commit.
   */
  private function assertDraftAccess(NodeInterface $node, array $fields): void {
    // Check actual text-format USE before entity access hooks inspect the
    // candidate. A later hook cannot substitute a safer format for this check.
    foreach ($fields as $field) {
      $this->assertTextFormatAccess($node, $field);
    }
    $access_handler = $this->entityTypeManager->getAccessControlHandler('node');
    $access_handler->resetCache();
    if (!$node->access('view revision', $this->account, TRUE)->isAllowed()
      || !$node->access('view', $this->account, TRUE)->isAllowed()
      || !$node->access('update', $this->account, TRUE)->isAllowed()) {
      throw new ProtectedDraftRefusal('The proposed draft is not readable; the live page was not edited.');
    }
    foreach ($fields as $field) {
      if (!$node->get($field)->access('view', $this->account, TRUE)->isAllowed()
        || !$node->get($field)->access('edit', $this->account, TRUE)->isAllowed()) {
        throw new ProtectedDraftRefusal('A proposed draft field is not readable or editable.');
      }
    }
    if (count($node->validate())) {
      throw new ProtectedDraftRefusal('The draft field values are invalid; the live page was not edited.');
    }
  }

  /**
   * Internal only: includes unreadable fields without exporting their values.
   */
  private function draftContent(NodeInterface $node): array {
    $volatile = [
      'vid',
      'revision_uid',
      'revision_timestamp',
      'revision_log',
      'revision_default',
      'revision_translation_affected',
      'changed',
    ];
    $content = [];
    foreach ($node->getTranslationLanguages() as $language => $unused) {
      $translation = $node->getTranslation($language);
      foreach ($translation->getFieldDefinitions() as $name => $definition) {
        if ($definition->getFieldStorageDefinition()->isRevisionable() && !$definition->isComputed() && !in_array($name, $volatile, TRUE)) {
          $content[$language][$name] = $translation->get($name)->getValue();
        }
      }
    }
    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function readRevision(int $nid, int $revision, string $language, array $fields): array {
    $this->assertLocked($nid);
    $storage = $this->nodeStorage();
    // Hydrate actual SQL field items without public cache/load-hook stages.
    $default = $this->hydrateDefault($storage, $nid);
    if (!$default instanceof NodeInterface) {
      throw new ProtectedDraftRefusal('The page is not available.');
    }
    $stored = $this->hydrateRevision($storage, $revision);
    if (!$stored instanceof NodeInterface || (int) $stored->id() !== $nid) {
      throw new ProtectedDraftRefusal('The exact page revision is not available.');
    }
    $default_id = (int) $default->getRevisionId();
    $workflow = $this->workflowFor($stored);
    $this->assertFreshFieldConfiguration($stored);
    $this->bindStoredModeration($stored, $workflow);
    if (!$stored->hasTranslation($language)) {
      throw new ProtectedDraftRefusal('The exact page translation is not available.');
    }
    $translation = $stored->getTranslation($language);
    $result = [
      'node_id' => (int) $stored->id(),
      'uuid' => $stored->uuid(),
      'language' => $translation->language()->getId(),
      'revision_id' => (int) $stored->getRevisionId(),
      'default_revision_id' => $default_id,
      'is_default_revision' => $revision === $default_id,
      'is_published' => $translation->isPublished(),
      'moderation_state' => $translation->hasField('moderation_state') ? $translation->get('moderation_state')->value : NULL,
      'fields' => $this->values($translation, $fields),
    ];
    $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
    $access_node = clone $stored->getTranslation($language);
    if (!$access_node->access('view revision', $this->account, TRUE)->isAllowed()) {
      throw new ProtectedDraftRefusal('Reading this stored revision is not permitted.');
    }
    $this->readableTranslation($access_node, $language, $fields);
    return $result;
  }

  /**
   * Verifies the locked configuration storage.
   *
   * Verifies active configuration and global access handlers use the locked
   * storage.
   */
  private function assertConfigurationStorage(): void {
    // Backend-overridable active storage cannot be assumed to use these rows.
    // Verify the actual native storage connection/table/collection, not just a
    // matching initial value from a different backend or cached wrapper.
    if (get_class($this->activeConfig) !== DatabaseStorage::class) {
      throw new ProtectedDraftRefusal('The workflow storage does not support protected transaction locking.');
    }
    self::assertSiteHandlers($this->configFactory, $this->entityTypeManager);
    foreach (['connection' => $this->database, 'table' => 'config', 'collection' => ''] as $property => $expected) {
      $actual = (new \ReflectionProperty(DatabaseStorage::class, $property))->getValue($this->activeConfig);
      if ($actual !== $expected) {
        throw new ProtectedDraftRefusal('The workflow storage is not bound to the locked database.');
      }
    }
    $options = (new \ReflectionProperty(DatabaseStorage::class, 'options'))->getValue($this->activeConfig);
    if ($options) {
      throw new ProtectedDraftRefusal('Workflow storage with alternate query options is not supported.');
    }
  }

  /**
   * Keeps transaction cache entries private.
   *
   * Keeps uncommitted node and moderation cache entries private to the
   * transaction.
   */
  private function isolateCaches(SqlContentEntityStorage $storage): void {
    $bindings = [];
    foreach ([
      [ContentEntityStorageBase::class, 'cacheBackend', new NullBackend('cinatra_protected_draft')],
      [EntityStorageBase::class, 'memoryCache', new MemoryCache($this->time)],
    ] as [$class, $name, $replacement]) {
      $property = new \ReflectionProperty($class, $name);
      $bindings[] = [$property, $property->getValue($storage), $replacement];
    }
    $this->privateCaches[] = [$storage, $bindings];
    foreach ($bindings as [$property, , $replacement]) {
      $property->setValue($storage, $replacement);
    }
  }

  /**
   * Compares injected handlers with the global handlers Core access uses.
   *
   * This stateless identity assertion deliberately reads the global registry.
   * Merely checking an injected container could miss alternate handlers while
   * Core moderation and text-format access still read the real site services.
   */
  private static function assertSiteHandlers(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager): void {
    if (\Drupal::service('config.factory') !== $config_factory
      || \Drupal::entityTypeManager() !== $entity_type_manager) {
      throw new ProtectedDraftRefusal('The protected edit must use the site handlers actual access checks consume.');
    }
  }

  /**
   * Restores shared cache backends safely.
   *
   * Restores shared cache backends after commit or rollback without copying
   * private entries.
   */
  private function restoreCaches(): void {
    foreach (array_reverse($this->privateCaches) as [$storage, $bindings]) {
      foreach ($bindings as [$property, $original]) {
        $property->setValue($storage, $original);
        if ($property->getName() === 'memoryCache') {
          // Shared request caches must not retain the pre-save entity. Only
          // invalidation is allowed; never copy private transaction entries.
          $original->invalidateTags(['entity.memory_cache:' . $storage->getEntityType()->id()]);
        }
      }
    }
    $this->privateCaches = [];
  }

  /**
   * Reads the actual stored default revision.
   *
   * Reads native stored default revision values without load hooks or shared
   * caches.
   */
  private function hydrateDefault(SqlContentEntityStorage $storage, int $id): ?ContentEntityInterface {
    return $this->hydrate($storage, 'getFromStorage', $id);
  }

  /**
   * Reads the actual stored exact revision.
   *
   * Reads native stored exact revision values without load hooks or shared
   * caches.
   */
  private function hydrateRevision(SqlContentEntityStorage $storage, int $revision): ?ContentEntityInterface {
    return $this->hydrate($storage, 'doLoadMultipleRevisionsFieldItems', $revision);
  }

  /**
   * Requires native SQL hydrators and reads mapped stored field items directly.
   */
  private function hydrate(SqlContentEntityStorage $storage, string $method, int $id): ?ContentEntityInterface {
    $this->assertSqlStorageConnection($storage);
    // These native hydrators deserialize mapped field items, but do not run
    // public entity-load hooks or publish entries in shared revision caches.
    $methods = [$method, 'mapFromStorageRecords', 'buildQuery', 'loadFromDedicatedTables'];
    // Drupal 10.3 calls this virtual loader during hydration. It is removed
    // in 12, so check native ownership when present rather than require it.
    if (method_exists($storage, 'loadFromSharedTables')) {
      $methods[] = 'loadFromSharedTables';
    }
    foreach ($methods as $name) {
      $reflection = new \ReflectionMethod($storage, $name);
      if ($reflection->getDeclaringClass()->getName() !== SqlContentEntityStorage::class) {
        throw new ProtectedDraftRefusal('The site overrides the native stored-value hydrator.');
      }
    }
    $entities = (new \ReflectionMethod($storage, $method))->invoke($storage, [$id]);
    $entity = $entities[$id] ?? NULL;
    if ($entity instanceof ContentEntityInterface) {
      $entity->updateLoadedRevisionId();
      return $entity;
    }
    return NULL;
  }

  /**
   * Reads the latest stored node revision identifier.
   */
  private function latestRevisionId(SqlContentEntityStorage $storage, int $nid): int {
    $type = $storage->getEntityType();
    $query = $this->database->select($type->getRevisionTable(), 'r');
    $query->addExpression('MAX([' . $type->getKey('revision') . '])', 'latest');
    return (int) $query->condition($type->getKey('id'), $nid)->execute()->fetchField();
  }

  /**
   * Binds actual stored moderation translations.
   *
   * Binds each translation to its actual stored moderation revision and
   * workflow.
   */
  private function bindStoredModeration(NodeInterface $node, mixed $workflow): void {
    if ($workflow === NULL) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('content_moderation_state');
    $this->assertSqlStorageConnection($storage);
    foreach ($node->getTranslationLanguages() as $language => $unused) {
      $revisions = $storage->getQuery()->accessCheck(FALSE)
        ->condition('content_entity_type_id', 'node')
        ->condition('content_entity_id', (int) $node->id())
        ->condition('content_entity_revision_id', (int) $node->getRevisionId())
        ->condition('workflow', $workflow->id())
        ->condition('langcode', $language)
        ->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute();
      $revision = key($revisions);
      $stored = $revision ? $this->hydrateRevision($storage, (int) $revision) : NULL;
      if (!$stored || !$stored->hasTranslation($language)) {
        throw new ProtectedDraftRefusal('A stored moderation state is missing in the exact page language.');
      }
      $translation = $stored->getTranslation($language);
      if ($translation->language()->getId() !== $language
        || $translation->get('content_entity_type_id')->value !== 'node'
        || (int) $translation->get('content_entity_id')->value !== (int) $node->id()
        || (int) $translation->get('content_entity_revision_id')->value !== (int) $node->getRevisionId()
        || $translation->get('workflow')->target_id !== $workflow->id()) {
        throw new ProtectedDraftRefusal('The stored moderation revision is not bound to this page revision and language.');
      }
      $state = $translation->get('moderation_state')->value;
      if (!is_string($state) || !$workflow->getTypePlugin()->hasState($state)) {
        throw new ProtectedDraftRefusal('The stored moderation state is not in the locked workflow.');
      }
      // Notify FALSE binds the computed value without changing stored status or
      // default-revision flags. Never synthesize a missing language
      // translation.
      $node->getTranslation($language)->get('moderation_state')->setValue([['value' => $state]], FALSE);
    }
  }

  /**
   * Checks the native dependency contract.
   *
   * Checks fresh filter configuration and access for every proposed text
   * format.
   */
  private function assertTextFormatAccess(NodeInterface $node, string $field): void {
    if (!in_array($node->getFieldDefinition($field)->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
      return;
    }
    $this->bindFilterSettings();
    $this->entityTypeManager->getAccessControlHandler('filter_format')->resetCache();
    foreach ($node->get($field)->getValue() as $item) {
      $format_id = $item['format'] ?? NULL;
      if (!is_string($format_id) || $format_id === '') {
        throw new ProtectedDraftRefusal('An explicit permitted text format is required.');
      }
      $raw = $this->activeConfig->read('filter.format.' . $format_id);
      $format = $this->entityTypeManager->getStorage('filter_format')->load($format_id);
      if (!$format || !is_array($raw) || $format->toArray() != $raw || !$format->status()
        || !$format->access('use', $this->account, TRUE)->isAllowed()) {
        throw new ProtectedDraftRefusal('The proposed text format is not permitted.');
      }
    }
  }

  /**
   * Returns the native node storage bound to the protected database.
   */
  private function nodeStorage(): SqlContentEntityStorage {
    $storage = $this->entityTypeManager->getStorage('node');
    $this->assertSqlStorageConnection($storage);
    return $storage;
  }

  /**
   * Rejects storage handlers connected to a different database.
   */
  private function assertSqlStorageConnection(mixed $storage): void {
    if (!$storage instanceof SqlContentEntityStorage) {
      throw new ProtectedDraftRefusal('The entity storage does not support protected revision locking.');
    }
    $connection = (new \ReflectionProperty(SqlContentEntityStorage::class, 'database'))->getValue($storage);
    if ($connection !== $this->database) {
      throw new ProtectedDraftRefusal('The entity storage is not bound to the locked database.');
    }
  }

  /**
   * Requires transactional InnoDB tables for protected MySQL writes.
   */
  private function assertInnoDb(string $table): void {
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table)) {
      throw new ProtectedDraftRefusal('The table mapping is not supported.');
    }
    $row = $this->database->query('SHOW CREATE TABLE {' . $table . '}')->fetchAssoc();
    if (!$row || !preg_match('/\bENGINE=InnoDB\b/i', (string) array_values($row)[1])) {
      throw new ProtectedDraftRefusal('Protected revisions require transactional InnoDB tables.');
    }
  }

  /**
   * Exhausts a locking query without retaining a full result set.
   */
  private function consume(object $statement): void {
    while ($statement->fetchField() !== FALSE) {
      // Hold SQL locks until the outer transaction commits, without a full
      // copy.
    }
  }

  /**
   * Requires the requested node to be owned by this transaction.
   */
  private function assertLocked(int $nid): void {
    if ($this->lockedNode !== $nid || !$this->database->inTransaction()) {
      throw new ProtectedDraftRefusal('The page is not inside its protected transaction.');
    }
    $this->assertActiveTransaction();
  }

  /**
   * Reads the native client transaction state.
   */
  private function transactionState(): ClientConnectionTransactionState {
    $property = new \ReflectionProperty(TransactionManagerBase::class, 'connectionTransactionState');
    return $property->getValue($this->database->transactionManager());
  }

  /**
   * Requires the original root transaction and native database connection.
   */
  private function assertActiveTransaction(): void {
    $stack = (new \ReflectionProperty(TransactionManagerBase::class, 'stack'))->getValue($this->database->transactionManager());
    if ($this->transactionState() !== ClientConnectionTransactionState::Active
      || !$this->database->getClientConnection()->inTransaction()
      || $this->database->transactionManager()->stackDepth() !== 1
      || $this->ownedTransaction === NULL || $this->ownedTransactionId === NULL
      || !isset($stack[$this->ownedTransactionId])
      || $stack[$this->ownedTransactionId]->name !== $this->ownedTransaction->name()) {
      throw new ProtectedDraftRefusal('The protected root transaction is no longer active; inspect the page before retrying.');
    }
  }

  /**
   * Checks the exact translation, node access and requested field access.
   */
  private function readableTranslation(mixed $node, string $language, array $fields): NodeInterface {
    if (!$node instanceof NodeInterface || !$node->hasTranslation($language)) {
      throw new ProtectedDraftRefusal('The exact page translation is not available.');
    }
    $translation = $node->getTranslation($language);
    if ($translation->language()->getId() !== $language
      || !$translation->access('view', $this->account, TRUE)->isAllowed()) {
      throw new ProtectedDraftRefusal('The page preimage is not readable in this language.');
    }
    foreach ($fields as $field) {
      if (!$translation->hasField($field)
        || !$translation->get($field)->access('view', $this->account, TRUE)->isAllowed()) {
        throw new ProtectedDraftRefusal('A requested stored field is not readable.');
      }
    }
    return $translation;
  }

  /**
   * Allows only supported, writable fields in the requested translation.
   */
  private function editableField(NodeInterface $node, string $field): bool {
    $reserved = [
      'nid',
      'vid',
      'uuid',
      'type',
      'langcode',
      'status',
      'moderation_state',
      'uid',
      'created',
      'changed',
      'revision_uid',
      'revision_timestamp',
      'revision_log',
      'revision_default',
      'revision_translation_affected',
      'default_langcode',
      'content_translation_source',
      'content_translation_outdated',
    ];
    if (in_array($field, $reserved, TRUE) || !$node->hasField($field)) {
      return FALSE;
    }
    $definition = $node->getFieldDefinition($field);
    return !$definition->isComputed() && !$definition->isReadOnly()
      && $definition->getFieldStorageDefinition()->isRevisionable()
      && ($node->isDefaultTranslation() || $definition->isTranslatable());
  }

  /**
   * Reads the requested stored field item values.
   */
  private function values(NodeInterface $node, array $fields): array {
    $values = [];
    foreach ($fields as $field) {
      $values[$field] = $node->get($field)->getValue();
    }
    return $values;
  }

  /**
   * Loads the unique effective workflow from fresh locked configuration.
   */
  private function workflowFor(NodeInterface $node): mixed {
    $this->workflowConfig = NULL;
    if (!$this->moduleHandler->moduleExists('content_moderation')) {
      return NULL;
    }
    $matched = [];
    $this->configuration['workflows'] = $this->rawConfiguration('workflows.workflow.');
    foreach ($this->configuration['workflows'] as $name => $data) {
      if (is_array($data) && ($data['type'] ?? NULL) === 'content_moderation'
        && in_array($node->bundle(), $data['type_settings']['entity_types']['node'] ?? [], TRUE)) {
        $matched[$name] = $data;
      }
    }
    if (!$matched) {
      return NULL;
    }
    if (count($matched) !== 1) {
      throw new ProtectedDraftRefusal('The page workflow assignment is ambiguous.');
    }
    $name = array_key_first($matched);
    $raw = $matched[$name];
    $bundle = $this->bundleInfo->getBundleInfo('node')[$node->bundle()] ?? [];
    if (($bundle['workflow'] ?? NULL) !== ($raw['id'] ?? NULL)) {
      throw new ProtectedDraftRefusal('The moderation handler has a stale workflow assignment.');
    }
    $this->configFactory->reset($name);
    $storage = $this->entityTypeManager->getStorage('workflow');
    $storage->resetCache([$raw['id']]);
    $workflow = $storage->load($raw['id']);
    if (!$workflow || $workflow->toArray() != $raw) {
      throw new ProtectedDraftRefusal('The cached or overridden workflow differs from its locked configuration.');
    }
    $this->workflowConfig = $raw;
    return $workflow;
  }

  /**
   * Binds workflow and complete field definitions to one comparison token.
   */
  private function workflowFingerprint(array $definitions): string {
    $fields = [];
    foreach ($definitions as $name => $definition) {
      $fields[$name] = [
        'type' => $definition->getType(),
        'revisionable' => $definition->getFieldStorageDefinition()->isRevisionable(),
        'translatable' => $definition->isTranslatable(),
        'computed' => $definition->isComputed(),
        'read_only' => $definition->isReadOnly(),
        'settings' => $definition->getSettings(),
        'required' => $definition->isRequired(),
        'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
        'constraints' => $definition->getConstraints(),
        'item_constraints' => $definition->getItemDefinition()->getConstraints(),
        'storage_constraints' => $definition->getFieldStorageDefinition()->getConstraints(),
        'default_value' => $definition->getDefaultValueLiteral(),
      ];
    }
    return $this->fingerprint([
      'workflow' => $this->workflowConfig,
      'fields' => $fields,
      'configuration' => $this->configuration,
    ]);
  }

  /**
   * Checks fresh complete field definitions.
   *
   * Rejects cached field definitions that differ from locked active
   * configuration.
   */
  private function assertFreshFieldConfiguration(NodeInterface $node): void {
    $definitions = $node->getFieldDefinitions();
    $fields = $overrides = $storages = [];
    foreach ($definitions as $definition) {
      if (method_exists($definition, 'getConfigDependencyName') && method_exists($definition, 'toArray')) {
        $name = $definition->getConfigDependencyName();
        if (str_starts_with($name, 'field.field.')) {
          $fields[$name] = $definition->toArray();
        }
        else {
          $overrides[$name] = $definition->toArray();
        }
      }
    }
    foreach ($this->fieldManager->getFieldStorageDefinitions('node') as $definition) {
      if (method_exists($definition, 'getConfigDependencyName') && method_exists($definition, 'toArray')) {
        $storages[$definition->getConfigDependencyName()] = $definition->toArray();
      }
    }
    $raw_fields = $this->rawConfiguration('field.field.node.' . $node->bundle() . '.');
    $raw_storages = $this->rawConfiguration('field.storage.node.');
    $raw_overrides = $this->rawConfiguration('core.base_field_override.node.' . $node->bundle() . '.');
    ProtectedDraftConfiguration::assertSameSet($raw_fields, $fields);
    ProtectedDraftConfiguration::assertSameSet($raw_storages, $storages);
    $active_storages = [];
    $actual_definitions = (new \ReflectionProperty(SqlContentEntityStorage::class, 'fieldStorageDefinitions'))->getValue($this->nodeStorage());
    foreach ($actual_definitions as $definition) {
      if (method_exists($definition, 'getConfigDependencyName') && method_exists($definition, 'toArray')) {
        $active_storages[$definition->getConfigDependencyName()] = $definition->toArray();
      }
    }
    // Actual installed SQL definitions must agree too, including additions.
    ProtectedDraftConfiguration::assertSameSet($raw_storages, $active_storages);
    ProtectedDraftConfiguration::assertSameSet($raw_overrides, $overrides);
    $bundle_name = 'node.type.' . $node->bundle();
    $raw_bundle = $this->activeConfig->read($bundle_name);
    $bundle = $this->entityTypeManager->getStorage('node_type')->load($node->bundle());
    if (!$bundle || !is_array($raw_bundle)) {
      throw new ProtectedDraftRefusal('The page bundle configuration is unavailable.');
    }
    ProtectedDraftConfiguration::assertSameSet([$bundle_name => $raw_bundle], [$bundle_name => $bundle->toArray()]);
    $mapping = $this->nodeStorage()->getTableMapping();
    $tables = [];
    foreach ($mapping->getTableNames() as $name) {
      $tables[$name] = ['columns' => $mapping->getAllColumns($name), 'fields' => $mapping->getFieldNames($name)];
    }
    $this->configuration += ['workflows' => []];
    $this->configuration['fields'] = $raw_fields;
    $this->configuration['storages'] = $raw_storages;
    $this->configuration['overrides'] = $raw_overrides;
    $this->configuration['bundle'] = $raw_bundle;
    $this->configuration['table_mapping'] = $tables;
    if ($this->moduleHandler->moduleExists('filter')) {
      $this->bindFilterSettings();
      $this->configuration['text_formats'] = $this->rawConfiguration('filter.format.');
    }
  }

  /**
   * Requires fresh filter settings before format access checks.
   */
  private function bindFilterSettings(): void {
    $raw = $this->activeConfig->read('filter.settings');
    if (!is_array($raw)) {
      throw new ProtectedDraftRefusal('The locked text-format configuration is unavailable.');
    }
    // get() includes overrides, exactly as isFallbackFormat() consumes it.
    // getRawData()/factory reset alone would miss an effective stale fallback.
    $effective = $this->configFactory->get('filter.settings')->get();
    ProtectedDraftConfiguration::assertSameSet(['filter.settings' => $raw], ['filter.settings' => $effective]);
    $this->configuration['filter_settings'] = $raw;
  }

  /**
   * Reads active configuration directly from the locked backend.
   */
  private function rawConfiguration(string $prefix): array {
    $result = [];
    foreach ($this->activeConfig->listAll($prefix) as $name) {
      $raw = $this->activeConfig->read($name);
      if (!is_array($raw)) {
        throw new ProtectedDraftRefusal('The locked configuration is incomplete.');
      }
      $result[$name] = $raw;
    }
    return $result;
  }

  /**
   * Creates a deterministic token for a complete configuration value.
   */
  private function fingerprint(array $value): string {
    return ProtectedDraftConfiguration::fingerprint($value);
  }

}
