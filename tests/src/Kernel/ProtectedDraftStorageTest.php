<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra\ProtectedDraft\DrupalProtectedDraftStorage;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftService;
use Drupal\cinatra_protected_draft_test\ProtectedDraftProbe;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Exercises the real SQL writer, moderation hooks, account and field policies.
 *
 * @group cinatra
 */
final class ProtectedDraftStorageTest extends KernelTestBase {

  use ContentModerationTestTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language',
    'workflows', 'content_moderation', 'cinatra', 'cinatra_protected_draft_test',
  ];

  private Node $page;
  private User $editor;
  private User $deniedUser;
  private ProtectedDraftService $protectedDraft;
  private DrupalProtectedDraftStorage $protectedStorage;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Ordinary accounts below must exercise their actual role permissions.
    $container->setParameter('security.enable_super_user', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'content_moderation', 'cinatra']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary',
      'revisionable' => TRUE, 'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'body',
      'label' => 'Body', 'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'field_shared_note', 'type' => 'string',
      'revisionable' => TRUE, 'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_shared_note',
      'label' => 'Shared note', 'translatable' => FALSE,
    ])->save();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $role = Role::create(['id' => 'protected_editor', 'label' => 'Protected editor']);
    foreach ([
      'access content', 'edit any page content', 'edit any plain content', 'view all revisions',
      'view latest version', 'view own unpublished content',
      'use editorial transition create_new_draft', 'use editorial transition publish', 'use editorial transition archive',
      'use text format plain_text',
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $this->editor = User::create(['uid' => 2, 'name' => 'editor', 'status' => TRUE, 'roles' => [$role->id()]]);
    $this->editor->save();
    $this->deniedUser = User::create(['uid' => 3, 'name' => 'denied', 'status' => TRUE]);
    $this->deniedUser->save();
    $this->container->get('current_user')->setAccount($this->editor);
    $this->assertFalse($this->editor->hasPermission('bypass node access'));
    $this->assertFalse($this->editor->hasPermission('administer nodes'));
    FilterFormat::create(['format' => 'restricted', 'name' => 'Restricted', 'status' => TRUE, 'weight' => 1, 'filters' => []])->save();
    $this->page = Node::create([
      'type' => 'page', 'langcode' => 'en', 'uid' => $this->editor->id(),
      'title' => 'English live title', 'status' => TRUE, 'moderation_state' => 'published',
      'body' => [['value' => 'English live body', 'summary' => 'English summary', 'format' => 'plain_text']],
      'field_shared_note' => [['value' => 'Shared live note']],
    ]);
    // Core creates the state for the ACTIVE language of each real save.
    $this->page->save();
    $french = $this->page->addTranslation('fr', [
      'title' => 'French live title', 'status' => TRUE, 'moderation_state' => 'published',
      'body' => [['value' => 'French live body', 'summary' => 'French summary', 'format' => 'plain_text']],
    ]);
    $french->save();
    $this->nodeStorage()->resetCache([(int) $this->page->id()]);
    $this->page = $this->nodeStorage()->load($this->page->id());
    $this->assertStoredPublishedModerationLanguages();
    ProtectedDraftProbe::reset();
    $this->protectedDraft = $this->container->get('cinatra.protected_draft');
    $this->protectedStorage = $this->container->get('cinatra.protected_draft_storage');
    $this->assertRootFinished();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ProtectedDraftProbe::reset();
    parent::tearDown();
  }

  public function testExactFrenchDraftKeepsPublishedTranslationsAndStoredMetadata(): void {
    $before = $this->snapshot();
    $preimage = $this->protectedDraft->prepare((int) $this->page->id(), 'fr', ['title', 'body']);
    $this->assertArrayNotHasKey('published_values', $preimage);
    $this->assertSame('fr', $preimage['language']);
    $this->assertRootFinished();
    $request = $this->request($preimage, [
      'title' => [['value' => 'French draft title']],
      'body' => [['value' => 'French draft body', 'summary' => 'Preserved summary', 'format' => 'plain_text']],
    ]);
    $stored = $this->protectedDraft->write($request);
    $this->assertRootFinished();
    $rid = $stored['revision_id'];
    $this->assertGreaterThan($before['default'], $rid);
    $this->assertSame($before['default'], $stored['default_revision_id']);
    $this->assertFalse($stored['is_default_revision']);
    $this->assertFalse($stored['is_published']);
    $this->assertSame('draft', $stored['moderation_state']);
    $this->assertSame($request['updates'], $stored['fields']);
    $exact = $this->protectedDraft->read((int) $this->page->id(), $rid, 'fr', ['title', 'body']);
    $this->assertSame($stored, $exact);
    $after = $this->snapshot();
    $this->assertSame($before['default'], $after['default']);
    $this->assertSame($before['published'], $after['published']);
    $this->assertSame($before['revisions'] + 1, $after['revisions']);
    $this->assertSame('English live title', $this->nodeStorage()->loadRevision($rid)->getTranslation('en')->label());
    $this->assertRootFinished();
  }

  public function testCustomModerationAliasRefusesBeforeSave(): void {
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_moderation_state', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_moderation_state', 'label' => 'Shadow state'])->save();
    $preimage = $this->prepareTitle();
    $this->assertFalse($preimage['canonical_moderation_field']);
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($this->titleRequest($preimage)), 'moderation field is ambiguous');
  }

  public function testMissingDraftStateRefusesBeforeSave(): void {
    $request = $this->titleRequest($this->prepareTitle());
    $request['draft_state'] = 'missing';
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'requested draft state must be unpublished and non-default');
  }

  public function testDefaultLikeStateRefusesBeforeSave(): void {
    $request = $this->titleRequest($this->prepareTitle());
    $request['draft_state'] = 'archived';
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'requested draft state must be unpublished and non-default');
  }

  public function testPendingDraftRefusesBeforeAnotherSave(): void {
    $this->protectedDraft->write($this->titleRequest($this->prepareTitle()));
    $preimage = $this->prepareTitle();
    $this->assertNotSame($preimage['default_revision_id'], $preimage['latest_revision_id']);
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($this->titleRequest($preimage)), 'draft already waits for review');
  }

  public function testUnmoderatedNodeRefusesBeforeSave(): void {
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $node = Node::create(['type' => 'plain', 'langcode' => 'en', 'uid' => $this->editor->id(), 'title' => 'Unmoderated live page', 'status' => TRUE]);
    $node->save();
    $preimage = $this->protectedDraft->prepare((int) $node->id(), 'en', ['title']);
    $this->assertFalse($preimage['moderated']);
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($this->titleRequest($preimage)), 'This page is not moderated', (int) $node->id());
  }

  public function testDeniedPublishedReadRefusesBeforeSave(): void {
    $this->container->get('current_user')->setAccount($this->deniedUser);
    $this->assertFalse($this->deniedUser->hasPermission('access content'));
    $this->assertRefusedBeforeSave(fn() => $this->prepareTitle(), 'page preimage is not readable');
  }

  public function testUnavailableTranslationRefusesBeforeSave(): void {
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->prepare((int) $this->page->id(), 'de', ['title']), 'exact page translation is not available');
  }

  public function testChangedWorkflowTokenRefusesBeforeSave(): void {
    $request = $this->titleRequest($this->prepareTitle());
    $this->container->get('config.factory')->getEditable('workflows.workflow.editorial')
      ->set('type_settings.states.draft.label', 'Changed draft label')->save();
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'workflow configuration changed');
  }

  public function testChangedOtherTranslationPreimageRefusesBeforeSave(): void {
    $request = $this->titleRequest($this->prepareTitle());
    $database = $this->container->get('database');
    $database->update('node_field_data')->fields(['title' => 'Concurrent French live change'])
      ->condition('nid', $this->page->id())->condition('langcode', 'fr')->execute();
    $database->update('node_field_revision')->fields(['title' => 'Concurrent French live change'])
      ->condition('nid', $this->page->id())->condition('vid', $request['expected_default_revision_id'])
      ->condition('langcode', 'fr')->execute();
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'live page values changed');
  }

  public function testDeniedFieldEditRefusesBeforeSave(): void {
    ProtectedDraftProbe::$deniedField = 'body';
    $preimage = $this->protectedDraft->prepare((int) $this->page->id(), 'en', ['body']);
    $request = $this->request($preimage, ['body' => [['value' => 'Draft body', 'summary' => 'Summary', 'format' => 'plain_text']]]);
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'requested field cannot be edited', NULL, 'body');
  }

  public function testDeniedProposedTextFormatRefusesBeforeSave(): void {
    $preimage = $this->protectedDraft->prepare((int) $this->page->id(), 'en', ['body']);
    $request = $this->request($preimage, ['body' => [['value' => 'Draft body', 'summary' => 'Summary', 'format' => 'restricted']]]);
    $this->assertRefusedBeforeSave(fn() => $this->protectedDraft->write($request), 'proposed text format is not permitted');
  }

  public function testOuterTransactionIsNotAccepted(): void {
    $before = $this->snapshot();
    $database = $this->container->get('database');
    $outer = $database->startTransaction();
    try {
      try {
        $this->prepareTitle();
        $this->fail('An existing root transaction must not be adopted.');
      }
      catch (ProtectedDraftRefusal $expected) {
        $this->assertStringContainsString('requires its own root transaction', $expected->getMessage());
        $this->assertTrue($database->getClientConnection()->inTransaction());
      }
    }
    finally {
      $outer->rollBack();
    }
    $this->assertSame($before, $this->snapshot());
    $this->assertRootFinished();
  }

  public function testUnrequestedSaveHookMutationRollsBackActualRevision(): void {
    $this->assertSaveHookRollsBack('unrequested_body', ['title' => [['value' => 'Draft title']]], 'stored draft changed an unrequested field');
  }

  public function testOtherTranslationSaveHookMutationRollsBackActualRevision(): void {
    $this->assertSaveHookRollsBack('other_translation', ['title' => [['value' => 'Draft title']]], 'stored draft changed an unrequested field');
  }

  public function testStoredUnauthorizedTextFormatRollsBackActualRevision(): void {
    $this->assertSaveHookRollsBack('restricted_format', ['body' => [['value' => 'Draft body', 'summary' => 'Summary', 'format' => 'plain_text']]], 'proposed text format is not permitted');
  }

  public function testStoredUpdatePolicyRefusalRollsBackActualRevision(): void {
    $this->assertSaveHookRollsBack('deny_stored_update', ['title' => [['value' => 'Draft title']]], 'proposed draft is not readable');
  }

  public function testPublishedDefaultSaveHookMutationRollsBackActualRevision(): void {
    $this->assertSaveHookRollsBack('published_default', ['title' => [['value' => 'Draft title']]], 'stored result is not the requested unpublished non-default draft');
  }

  public function testRequestedSharedFieldChangesInAllDraftTranslationsOnly(): void {
    $nid = (int) $this->page->id();
    $definition = $this->page->getFieldDefinition('field_shared_note');
    $this->assertTrue($definition->getFieldStorageDefinition()->isRevisionable());
    $this->assertFalse($definition->isTranslatable(), 'The positive must exercise a real shared field, not a translatable core field.');
    $before = $this->snapshot();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_shared_note']);
    $this->assertContains('field_shared_note', $preimage['available_fields']);
    $stored = $this->protectedDraft->write($this->request($preimage, ['field_shared_note' => [['value' => 'Shared draft note']]]));
    $this->assertSame('Shared draft note', $stored['fields']['field_shared_note'][0]['value']);
    $revision = $this->nodeStorage()->loadRevision($stored['revision_id']);
    foreach (['en', 'fr'] as $language) {
      $this->assertSame('Shared draft note', $revision->getTranslation($language)->get('field_shared_note')->value);
    }
    $after = $this->snapshot();
    $this->assertSame($before['default'], $after['default']);
    $this->assertSame($before['published'], $after['published']);
    $this->assertSame($before['revisions'] + 1, $after['revisions']);
    foreach (['en', 'fr'] as $language) {
      $this->assertSame('Shared live note', $this->nodeStorage()->load($nid)->getTranslation($language)->get('field_shared_note')->value);
    }
    $this->assertRootFinished();
  }

  public function testRollbackDoesNotLeaveCachedPhantomRevision(): void {
    $nid = (int) $this->page->id();
    $before = $this->snapshot();
    $rid = NULL;
    try {
      $this->protectedStorage->withLockedNode($nid, function () use ($nid, &$rid): array {
        $this->protectedStorage->readDefault($nid, 'en', ['title']);
        $rid = $this->protectedStorage->saveDraft($nid, 'en', 'draft', ['title' => [['value' => 'Rolled-back cached title']]]);
        // Exercise ordinary loaders while both storage handlers are private.
        $this->assertSame('Rolled-back cached title', $this->nodeStorage()->loadRevision($rid)->label());
        throw new ProtectedDraftRefusal('Deliberate failure after an actual stored draft.');
      });
      $this->fail('The deliberate failure must roll back.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('Deliberate failure after an actual stored draft', $expected->getMessage());
      $this->assertNotNull($rid, 'The test must reach the actual save before refusing.');
    }
    $this->assertNull($this->nodeStorage()->loadRevision($rid), 'The public loader must not return a rolled-back revision from cache.');
    // snapshot() resets node/revision cache tags, so it must follow this read.
    $this->assertSame($before, $this->snapshot());
    $this->assertRootFinished();
  }

  public function testModernRevisionCacheOracleDetectsAnUnisolatedLoader(): void {
    $storage = $this->nodeStorage();
    if (!method_exists($storage, 'setPersistentRevisionCache')) {
      $this->markTestSkipped('This controlled oracle targets modern revision caching; minimum-core cache behavior needs its version-specific proof.');
    }
    $nid = (int) $this->page->id();
    $before = $this->snapshot();
    $cache_property = new \ReflectionProperty(ContentEntityStorageBase::class, 'cacheBackend');
    $shared_cache = $cache_property->getValue($storage);
    $rid = NULL;
    try {
      $this->protectedStorage->withLockedNode($nid, function () use ($nid, $cache_property, $shared_cache, &$rid): array {
        $this->protectedStorage->readDefault($nid, 'en', ['title']);
        $rid = $this->protectedStorage->saveDraft($nid, 'en', 'draft', ['title' => [['value' => 'Deliberately leaked cache control']]]);
        $actual_storage = $this->nodeStorage();
        $private_cache = $cache_property->getValue($actual_storage);
        try {
          // Counterfactual: bypass only persistent-cache isolation for an
          // ordinary loader. The SQL writer and owned root remain real.
          $cache_property->setValue($actual_storage, $shared_cache);
          $memory_property = new \ReflectionProperty(EntityStorageBase::class, 'memoryCache');
          $memory_property->getValue($actual_storage)->invalidateTags(["node:$nid:revisions"]);
          $this->assertSame('Deliberately leaked cache control', $actual_storage->loadRevision($rid)->label());
          $cache_id = (new \ReflectionMethod($actual_storage, 'buildRevisionCacheId'))->invoke($actual_storage, $rid);
          $this->assertNotFalse($shared_cache->get($cache_id), 'The unsafe loader must really populate the shared persistent cache.');
        }
        finally {
          $cache_property->setValue($actual_storage, $private_cache);
        }
        throw new ProtectedDraftRefusal('Deliberate non-isolated cache control rollback.');
      });
      $this->fail('The control must roll back its SQL save.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('Deliberate non-isolated cache control rollback', $expected->getMessage());
      $this->assertNotNull($rid);
    }
    $database = $this->container->get('database');
    $this->assertFalse($database->select('node_revision', 'r')->fields('r', ['vid'])->condition('vid', $rid)->execute()->fetchField());
    // Read BEFORE any reset: unlike the protected path, this unsafe control
    // must expose the phantom. Otherwise the absence oracle is not causal.
    $phantom = $this->nodeStorage()->loadRevision($rid);
    $this->assertNotNull($phantom, 'The controlled shared cache must expose the rolled-back revision.');
    $this->assertSame('Deliberately leaked cache control', $phantom->label());
    $this->assertSame($before, $this->snapshot());
    $this->assertNull($this->nodeStorage()->loadRevision($rid), 'The fixture cleanup must remove its deliberate phantom.');
    $this->assertRootFinished();
  }

  private function prepareTitle(): array {
    return $this->protectedDraft->prepare((int) $this->page->id(), 'en', ['title']);
  }

  private function titleRequest(array $preimage): array {
    return $this->request($preimage, ['title' => [['value' => 'Draft title']]]);
  }

  private function request(array $preimage, array $updates): array {
    return [
      'nid' => $preimage['node_id'], 'language' => $preimage['language'], 'draft_state' => 'draft',
      'expected_default_revision_id' => $preimage['default_revision_id'],
      'expected_latest_revision_id' => $preimage['latest_revision_id'],
      'workflow_fingerprint' => $preimage['workflow_fingerprint'],
      'preimage_fingerprint' => $preimage['preimage_fingerprint'], 'updates' => $updates,
    ];
  }

  private function assertRefusedBeforeSave(callable $operation, string $message, ?int $nid = NULL, ?string $denied_field = NULL): void {
    $nid ??= (int) $this->page->id();
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'observe');
    ProtectedDraftProbe::$deniedField = $denied_field;
    try {
      $operation();
      $this->fail('The protected operation must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString($message, $expected->getMessage(), 'An unrelated refusal must not satisfy this named boundary.');
      $this->assertSame(0, ProtectedDraftProbe::$presaves, 'Refusal must precede an actual node save hook.');
    }
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  private function assertSaveHookRollsBack(string $mode, array $updates, string $message): void {
    $nid = (int) $this->page->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', array_keys($updates));
    $before = $this->snapshot();
    ProtectedDraftProbe::arm($nid, $mode);
    try {
      $this->protectedDraft->write($this->request($preimage, $updates));
      $this->fail('An unauthorized stored mutation must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString($message, $expected->getMessage(), 'The named stored guard must cause this rollback.');
      $this->assertGreaterThan(0, ProtectedDraftProbe::$presaves, 'The real pre-save hook must run.');
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede rollback.');
    }
    $this->assertNull($this->nodeStorage()->loadRevision(ProtectedDraftProbe::$storedRevision));
    // The ordinary rid read must happen before snapshot invalidates caches.
    $this->assertSame($before, $this->snapshot());
    $this->assertRootFinished();
  }

  /**
   * Reads SQL revision facts and fresh stored published translation values.
   */
  private function snapshot(?int $nid = NULL): array {
    $nid ??= (int) $this->page->id();
    $database = $this->container->get('database');
    $default = (int) $database->select('node', 'n')->fields('n', ['vid'])->condition('nid', $nid)->execute()->fetchField();
    $revisions = (int) $database->select('node_revision', 'r')->condition('nid', $nid)->countQuery()->execute()->fetchField();
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $published = [];
    foreach ($node->getTranslationLanguages() as $language => $unused) {
      $translation = $node->getTranslation($language);
      $published[$language] = ['title' => $translation->get('title')->getValue(), 'status' => $translation->get('status')->getValue()];
      if ($translation->hasField('body')) {
        $published[$language]['body'] = $translation->get('body')->getValue();
      }
      if ($translation->hasField('field_shared_note')) {
        $published[$language]['field_shared_note'] = $translation->get('field_shared_note')->getValue();
      }
    }
    ksort($published);
    return ['default' => $default, 'revisions' => $revisions, 'published' => $published];
  }

  private function nodeStorage(): NodeStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Requires real language rows in one shared moderation revision, not echoes.
   */
  private function assertStoredPublishedModerationLanguages(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('content_moderation_state');
    $type = $storage->getEntityType();
    $mapping = $storage->getTableMapping();
    $revision_key = $type->getKey('revision');
    $query = $this->container->get('database')->select($type->getRevisionDataTable(), 'd');
    $query->innerJoin($type->getRevisionTable(), 'r', 'r.' . $revision_key . ' = d.' . $revision_key);
    foreach ([
      'content_entity_type_id' => ['value', 'node'],
      'content_entity_id' => ['value', (int) $this->page->id()],
      'content_entity_revision_id' => ['value', (int) $this->page->getRevisionId()],
      'workflow' => ['target_id', 'editorial'],
    ] as $field => [$property, $value]) {
      $query->condition('r.' . $mapping->getColumnNames($field)[$property], $value);
    }
    $query->addField('d', $mapping->getColumnNames('langcode')['value'], 'stored_language');
    $query->addField('d', $mapping->getColumnNames('moderation_state')['value'], 'stored_state');
    $query->addField('r', $revision_key, 'moderation_revision');
    $query->orderBy('r.' . $revision_key, 'DESC');
    $languages = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $languages[$row->stored_language] ??= ['state' => $row->stored_state, 'revision' => (int) $row->moderation_revision];
    }
    ksort($languages);
    $this->assertSame(['en', 'fr'], array_keys($languages), 'Both active-language saves must create actual stored moderation translations.');
    $this->assertSame('published', $languages['en']['state']);
    $this->assertSame('published', $languages['fr']['state']);
    $this->assertSame($languages['en']['revision'], $languages['fr']['revision'], 'The node revision binding is shared; do not invent one CM revision per language.');
  }

  private function assertRootFinished(): void {
    $database = $this->container->get('database');
    $this->assertFalse($database->inTransaction());
    $this->assertFalse($database->getClientConnection()->inTransaction());
    $this->assertSame(0, $database->transactionManager()->stackDepth());
  }

}
