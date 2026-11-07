<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Page\PageMigrator;
use Drupal\cinatra_dev_site_page_test\PageMutation;
use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\workflows\Entity\Workflow;
use Drupal\workflows\WorkflowInterface;

/**
 * Tests the page migration of the development site and its status refusal.
 *
 * The migration binds each page of node:page to editorial state rows on its
 * own default revision, as a syncing save inside the protected draft
 * storage's lock. After the save it compares every field of every
 * translation; any change beyond the moderation state and the revision
 * bookkeeping rolls the page back. Most tests bind node:page to the
 * editorial workflow themselves, as the editorial binding will; the
 * no-workflow, wrong-type, unbound, other-bundle and unmoderated cases do
 * not.
 *
 * @group cinatra
 */
final class DevSitePageMigrationTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * The changed time of every fixture page, older than the request time.
   *
   * A migration save that bumped the changed time would show.
   */
  private const OLD_CHANGED = 1000000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'language',
    'content_translation',
    'path_alias',
    'path',
    'workflows',
    'content_moderation',
    'cinatra',
    'cinatra_dev_site',
    'cinatra_dev_site_page_test',
  ];

  /**
   * Owner of every fixture page.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('CINATRA_DEV_SITE=1');
    PageMutation::reset();
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language', 'content_moderation', 'cinatra']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('content_moderation_state');
    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    // Drupal 10 adds the body field when the node type is created; newer
    // versions may leave its creation to the caller. Reuse the real field.
    $body_storage = FieldStorageConfig::loadByName('node', 'body') ?? FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'body',
      'type' => 'text_with_summary',
      'revisionable' => TRUE,
      'translatable' => TRUE,
    ]);
    $body_storage->setTranslatable(TRUE)->save();
    $body_field = FieldConfig::loadByName('node', 'page', 'body') ?? FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'page',
      'field_name' => 'body',
      'label' => 'Body',
      'translatable' => TRUE,
    ]);
    $body_field->setTranslatable(TRUE)->save();
    // A custom field: the migration must keep it as every other field.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_note',
      'type' => 'string',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'page',
      'field_name' => 'field_note',
      'label' => 'Note',
      'translatable' => TRUE,
    ])->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);
    $this->editor = User::create(['uid' => 2, 'name' => 'editor', 'status' => TRUE]);
    $this->editor->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    PageMutation::reset();
    parent::tearDown();
  }

  /**
   * A published page gets a published row on the same revision.
   */
  public function testPublishedPageGetsPublishedRowOnSameRevision(): void {
    $nid = (int) $this->createPage('Published page', TRUE)->id();
    $this->bindPages();
    $before = $this->pageValues($nid);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $before['default'], 'en'));
    $this->assertTrue($this->nodeStorage()->load($nid)->isDefaultRevision());
  }

  /**
   * An en page with a later de default revision binds both rows.
   */
  public function testEnglishThenGermanBindsBothRowsToDefault(): void {
    $page = $this->createPage('English first', TRUE);
    $nid = (int) $page->id();
    $first = (int) $page->getRevisionId();
    $page = $this->addGerman($page, TRUE, TRUE);
    $default = (int) $page->getRevisionId();
    $this->assertGreaterThan($first, $default);
    $storage = $this->nodeStorage();
    // The en latest translation-affected revision is the older default.
    $this->assertSame($first, (int) $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));
    $this->assertTrue($storage->loadRevision($first)->wasDefaultRevision());
    $this->bindPages();
    $before = $this->pageValues($nid);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('published', $this->stateRow($nid, $default, 'de'));
  }

  /**
   * Mixed status keeps each status, the default and no pending revision.
   */
  public function testMixedStatusKeepsStatusAndDefault(): void {
    $page = $this->addGerman($this->createPage('Mixed page', TRUE), FALSE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $this->bindPages();
    $before = $this->pageValues($nid);
    $this->assertTrue($before['languages']['en']['status']);
    $this->assertFalse($before['languages']['de']['status']);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('draft', $this->stateRow($nid, $default, 'de'));
    $node = $this->nodeStorage()->load($nid);
    $this->assertTrue($node->isDefaultRevision());
    $this->assertSame($default, (int) $node->getRevisionId());
    $information = $this->container->get('content_moderation.moderation_information');
    foreach (['en', 'de'] as $langcode) {
      $this->assertFalse($information->hasPendingRevision($node->getTranslation($langcode)));
    }
  }

  /**
   * With the en row present, the missing de row is added.
   */
  public function testPartialRowsAddMissingGermanRow(): void {
    $page = $this->addGerman($this->createPage('Partial page', TRUE), TRUE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $this->bindPages();
    $this->storeRow($nid, $default, 'en', 'published');
    $this->assertNull($this->stateRow($nid, $default, 'de'));
    $before = $this->pageValues($nid);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('published', $this->stateRow($nid, $default, 'de'));
  }

  /**
   * An unpublished page gets a draft row and stays the default revision.
   */
  public function testUnpublishedPageGetsDraftRow(): void {
    $nid = (int) $this->createPage('Unpublished page', FALSE)->id();
    $this->bindPages();
    $before = $this->pageValues($nid);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('draft', $this->stateRow($nid, $before['default'], 'en'));
    $node = $this->nodeStorage()->load($nid);
    $this->assertTrue($node->isDefaultRevision());
    $this->assertFalse($node->isPublished());
  }

  /**
   * A node-level pending revision refuses the plan; the plan writes nothing.
   */
  public function testNodePendingRevisionRefuses(): void {
    $this->createPage('Clean page', TRUE);
    $pending = $this->createPage('Pending page', TRUE);
    $nid = (int) $pending->id();
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setTitle('Pending page draft');
    $pending->save();
    $this->bindPages();
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('pending_revision:' . $nid, $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A default revision that is not the latest refuses, as the draft does.
   */
  public function testDefaultNotLatestRefuses(): void {
    $page = $this->createPage('Reverted page', TRUE);
    $nid = (int) $page->id();
    $first = (int) $page->getRevisionId();
    $page->setNewRevision(TRUE);
    $page->setTitle('Reverted page second');
    $page->save();
    $second = (int) $page->getRevisionId();
    // Make the first revision the default again, in place.
    $storage = $this->nodeStorage();
    $old = $storage->loadRevision($first);
    $old->setNewRevision(FALSE);
    $old->isDefaultRevision(TRUE);
    $old->save();
    $storage->resetCache([$nid]);
    // The fixture's premises: the default is older than the latest, and the
    // latest was a default revision when it was saved.
    $this->assertSame($first, (int) $storage->load($nid)->getRevisionId());
    $this->assertSame($second, (int) $storage->getLatestRevisionId($nid));
    $this->assertTrue($storage->loadRevision($second)->wasDefaultRevision());
    $this->bindPages();
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('default_not_latest:' . $nid, $plan['refused']);
    $this->assertNotContains('pending_revision:' . $nid, $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * An older non-default de revision refuses, as core's pending test does.
   */
  public function testTranslationPendingRevisionRefuses(): void {
    $page = $this->addGerman($this->createPage('Translation pending', TRUE), TRUE, TRUE);
    $nid = (int) $page->id();
    $storage = $this->nodeStorage();
    $german = $storage->load($nid)->getTranslation('de');
    $german->setNewRevision(TRUE);
    $german->isDefaultRevision(FALSE);
    $german->setTitle('German pending draft');
    $german->save();
    $pending = (int) $german->getRevisionId();
    $storage->resetCache([$nid]);
    $english = $storage->load($nid)->getTranslation('en');
    $english->setNewRevision(TRUE);
    $english->setTitle('English new default');
    $english->save();
    $default = (int) $english->getRevisionId();
    $this->assertGreaterThan($pending, $default);
    $this->assertSame($default, (int) $storage->getLatestRevisionId($nid));
    $this->assertSame($pending, (int) $storage->getLatestTranslationAffectedRevisionId($nid, 'de'));
    $this->assertFalse($storage->loadRevision($pending)->wasDefaultRevision());
    $this->bindPages();
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('pending_revision:' . $nid . ':de', $plan['refused']);
    $this->assertNotContains('pending_revision:' . $nid, $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A published row on an unpublished translation refuses.
   */
  public function testStateConflictRefuses(): void {
    $page = $this->createPage('Conflict page', FALSE);
    $nid = (int) $page->id();
    $this->bindPages();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A stored state the editorial workflow does not know refuses.
   */
  public function testUnknownStoredStateRefuses(): void {
    $page = $this->createPage('Unknown state page', TRUE);
    $nid = (int) $page->id();
    $this->bindPages();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'no_such_state');
    $this->assertFalse($this->editorial()->hasState('no_such_state'));
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A stored row without any editorial workflow refuses.
   */
  public function testStoredRowWithoutEditorialRefuses(): void {
    $page = $this->createPage('Orphan row page', TRUE);
    $nid = (int) $page->id();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('workflow')->load('editorial'));
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A stored row without a moderation state refuses; it is not a missing row.
   *
   * Core's required constraint applies to validation only, so a row saved
   * the way core persists it may have no state.
   */
  public function testStoredRowWithoutStateRefuses(): void {
    $page = $this->createPage('Stateless row page', TRUE);
    $nid = (int) $page->id();
    $this->bindPages();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', NULL);
    // The premise: the row exists and has no state.
    $this->assertNotNull($this->stateRevisionId($nid, (int) $page->getRevisionId(), 'en'));
    $this->assertNull($this->stateRow($nid, (int) $page->getRevisionId(), 'en'));
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A stored row whose revision cannot be read refuses.
   */
  public function testUnreadableStoredRowRefuses(): void {
    $page = $this->createPage('Unreadable row page', TRUE);
    $nid = (int) $page->id();
    $this->bindPages();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $row = $this->stateRevisionId($nid, (int) $page->getRevisionId(), 'en');
    $this->assertNotNull($row);
    // The control: readable, the row is complete and the plan refuses
    // nothing.
    $this->assertSame([], $this->migrator()->plan()['refused']);
    PageMutation::$unreadable['content_moderation_state'] = [$row];
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A translation-affected revision that cannot be read refuses.
   *
   * On an en page with a later de default revision, the latest revision
   * that affected en is the older default; the plan must read it.
   */
  public function testUnreadableTranslationRevisionRefuses(): void {
    $page = $this->createPage('Unreadable revision', TRUE);
    $nid = (int) $page->id();
    $first = (int) $page->getRevisionId();
    $this->addGerman($page, TRUE, TRUE);
    $storage = $this->nodeStorage();
    $this->assertSame($first, (int) $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));
    $this->bindPages();
    // The control: readable, the older default refuses nothing.
    $this->assertSame([], $this->migrator()->plan()['refused']);
    PageMutation::$unreadable['node'] = [$first];
    $this->assertNull($storage->loadRevision($first));
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('revision_unreadable:' . $nid . ':en', $plan['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * An editorial workflow of another type refuses the rows and the run.
   */
  public function testWrongTypeEditorialWorkflowRefuses(): void {
    $this->enableModules(['workflow_type_test']);
    $page = $this->createPage('Wrong type page', TRUE);
    $nid = (int) $page->id();
    Workflow::create(['id' => 'editorial', 'label' => 'Editorial', 'type' => 'workflow_type_test'])->save();
    $this->assertNotInstanceOf(ContentModerationInterface::class, Workflow::load('editorial')->getTypePlugin());
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $before = $this->footprint();
    $plan = $this->migrator()->plan();
    $this->assertContains('state_conflict:' . $nid . ':en', $plan['refused']);
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['editorial_not_applied'], $result['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * The run stops at the first failure; pages saved before it stay complete.
   */
  public function testRunStopsAtTheFirstFailureAndKeepsEarlierPages(): void {
    $first = (int) $this->createPage('First page', TRUE)->id();
    $second = (int) $this->createPage('Second page', TRUE)->id();
    $third = (int) $this->createPage('Third page', TRUE)->id();
    $this->bindPages();
    $second_before = $this->fieldValues($second);
    PageMutation::$nid = $second;
    PageMutation::$field = 'title';
    $result = $this->runMigration();
    $this->assertFalse($result['ok']);
    $this->assertSame([$first], $result['migrated']);
    $this->assertSame(['migration_failed:' . $second], $result['refused']);
    $this->assertStringContainsString('page_changed:' . $second . ':en:title', $result['error']);
    $this->assertSame('published', $this->stateRow($first, $this->pageValues($first)['default'], 'en'));
    $this->assertNull($this->stateRow($second, $this->pageValues($second)['default'], 'en'));
    $this->assertSame($second_before, $this->fieldValues($second));
    // The run did not reach the third page.
    $this->assertNull($this->stateRow($third, $this->pageValues($third)['default'], 'en'));
    $this->assertMigrated([$second, $third], $this->runMigration());
  }

  /**
   * A refusal that appears between the plan and the lock fails the page.
   */
  public function testRefusalInsideTheLockFails(): void {
    $page = $this->createPage('Pending later', TRUE);
    $nid = (int) $page->id();
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused']);
    $page->setNewRevision(TRUE);
    $page->isDefaultRevision(FALSE);
    $page->setTitle('Pending later draft');
    $page->save();
    $this->bindPages();
    $before = $this->footprint();
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    // The translation row names the same draft: pending_revision:<nid>:en.
    $this->assertStringStartsWith('The page plan refused inside the lock: pending_revision:' . $nid, $result['error']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A second run writes nothing.
   */
  public function testSecondRunWritesNothing(): void {
    $nid = (int) $this->addGerman($this->createPage('Repeated page', TRUE), FALSE, FALSE)->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $before = $this->footprint();
    $this->assertMigrated([], $this->runMigration());
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A page created after the plan is planned inside its lock and migrated.
   *
   * The plan runs before the binding, as it will in ensure().
   */
  public function testPageCreatedAfterThePlanIsPlannedInItsLock(): void {
    $first = (int) $this->createPage('Planned page', TRUE)->id();
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused']);
    $this->assertSame([$first], array_keys($plan['plans']));
    $late = (int) $this->createPage('Late page', TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$first, $late], $this->migrator()->migrate($plan['plans']));
    $this->assertSame('published', $this->stateRow($late, $this->pageValues($late)['default'], 'en'));
  }

  /**
   * A page that changed between the plan and its lock fails the migration.
   */
  public function testPageChangedAfterThePlanFails(): void {
    $page = $this->createPage('Moving page', TRUE);
    $nid = (int) $page->id();
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused']);
    $page->setNewRevision(TRUE);
    $page->setTitle('Moving page, edited');
    $page->save();
    $this->bindPages();
    $before = $this->footprint();
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertSame('The page changed between the plan and its lock.', $result['error']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A de-only state row keeps de and gains en as the default language.
   */
  public function testOrientationKeepsGermanRowAndDefaultLanguage(): void {
    $page = $this->addGerman($this->createPage('Oriented page', TRUE), TRUE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $this->bindPages();
    $this->storeRow($nid, $default, 'de', 'published');
    $this->assertNull($this->stateRow($nid, $default, 'en'));
    $before = $this->pageValues($nid);
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('published', $this->stateRow($nid, $default, 'de'));
    $storage = $this->container->get('entity_type.manager')->getStorage('content_moderation_state');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->execute();
    $this->assertCount(1, array_unique($ids));
    $state = $storage->load(reset($ids));
    $this->assertSame('en', $state->getUntranslated()->language()->getId());
    $this->assertTrue($state->hasTranslation('de'));
    $this->assertSame($default, (int) $state->get('content_entity_revision_id')->value);
    $repeat = $this->footprint();
    $this->assertMigrated([], $this->runMigration());
    $this->assertSame($repeat, $this->footprint());
  }

  /**
   * An outer transaction fails the migration; a normal run owns its own.
   */
  public function testOuterTransactionFailsMigration(): void {
    $nid = (int) $this->createPage('Transaction page', TRUE)->id();
    $this->bindPages();
    $before = $this->footprint();
    $database = \Drupal::database();
    $transaction = $database->startTransaction();
    try {
      $result = $this->runMigration();
    }
    finally {
      $transaction->rollBack();
      unset($transaction);
    }
    $this->assertFalse($database->inTransaction());
    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertSame([], $result['migrated']);
    $this->assertStringContainsString('A protected edit requires its own root transaction.', $result['error']);
    $this->assertSame([], PageMutation::$inTransaction);
    $this->assertSame($before, $this->footprint());
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertSame([TRUE], PageMutation::$inTransaction);
  }

  /**
   * Without the development marker the migration writes nothing.
   */
  public function testMigrationNeedsTheMarker(): void {
    $this->createPage('Unmarked page', TRUE);
    $this->bindPages();
    $plan = $this->migrator()->plan();
    $before = $this->footprint();
    putenv('CINATRA_DEV_SITE');
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['no_marker'], $result['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * Without node:page bound to editorial the migration writes nothing.
   */
  public function testUnboundPagesFailTheMigration(): void {
    $this->createPage('Unbound page', TRUE);
    $this->createEditorialWorkflow();
    $before = $this->footprint();
    $result = $this->runMigration();
    $this->assertFalse($result['ok']);
    $this->assertSame(['editorial_not_applied'], $result['refused']);
    $this->assertSame([], $result['migrated']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * Unbound pages fail the migration also when no row is missing.
   */
  public function testUnboundPagesWithCompleteRowsFailTheMigration(): void {
    $page = $this->createPage('Complete unbound page', TRUE);
    $nid = (int) $page->id();
    $this->createEditorialWorkflow();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused']);
    $this->assertSame([], $plan['plans'][$nid]['missing']);
    $before = $this->footprint();
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['editorial_not_applied'], $result['refused']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * A site without pages migrates only with node:page bound to editorial.
   */
  public function testEmptySiteNeedsTheBinding(): void {
    $result = $this->migrator()->migrate([]);
    $this->assertFalse($result['ok']);
    $this->assertSame(['editorial_not_applied'], $result['refused']);
    $this->bindPages();
    $this->assertMigrated([], $this->runMigration());
  }

  /**
   * A page with no missing row in the plan is planned again in its lock.
   *
   * The page keeps valid rows from an earlier binding while node:page is
   * unbound; the plan finds no missing row. An editor then saves a new
   * default revision, which gets no row, and node:page is bound again. The
   * migration with the earlier plan reads the page again inside its lock
   * and fails; nothing is written.
   */
  public function testEarlierCompletePlanIsPlannedAgainInTheLock(): void {
    $page = $this->createPage('Rebound page', TRUE);
    $nid = (int) $page->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $workflow = Workflow::load('editorial');
    $workflow->getTypePlugin()->removeEntityTypeAndBundle('node', 'page');
    $workflow->save();
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused']);
    $this->assertSame([], $plan['plans'][$nid]['missing']);
    $page = $this->nodeStorage()->loadUnchanged($nid);
    $page->setNewRevision(TRUE);
    $page->setTitle('Rebound page, edited while unbound');
    $page->save();
    $default = (int) $this->nodeStorage()->loadUnchanged($nid)->getRevisionId();
    $this->assertNull($this->stateRow($nid, $default, 'en'));
    $this->addEntityTypeAndBundleToWorkflow(Workflow::load('editorial'), 'node', 'page');
    $before = $this->footprint();
    $result = $this->migrator()->migrate($plan['plans']);
    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertSame('The page changed between the plan and its lock.', $result['error']);
    $this->assertSame($before, $this->footprint());
  }

  /**
   * Any field change of a migration save rolls the page back.
   *
   * @param string $field
   *   The field the test hook changes on the migration save.
   *
   * @dataProvider changedFields
   */
  public function testChangedFieldRollsThePageBack(string $field): void {
    $nid = (int) $this->createPage('Guarded fields', TRUE)->id();
    $this->bindPages();
    $before = $this->footprint();
    PageMutation::$field = $field;
    $result = $this->runMigration();
    $this->assertNull(PageMutation::$field, 'The test hook ran on the migration save.');
    $this->assertFalse($result['ok']);
    $this->assertSame(['migration_failed:' . $nid], $result['refused']);
    $this->assertSame([], $result['migrated']);
    if ($field !== 'langcode') {
      // A changed language may also fail inside core's save; every other
      // change is found by the comparison.
      $this->assertStringContainsString('page_changed:' . $nid . ':en:' . $field, $result['error']);
    }
    $this->assertSame($before, $this->footprint());
    // The rolled back page migrates cleanly afterwards.
    $this->assertMigrated([$nid], $this->runMigration());
  }

  /**
   * The fields a migration save may not change.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function changedFields(): array {
    $cases = [];
    foreach (['uid', 'promote', 'sticky', 'title', 'body', 'field_note', 'path', 'langcode'] as $field) {
      $cases[$field] = [$field];
    }
    return $cases;
  }

  /**
   * A change to the revision bookkeeping passes; it is on the allow-list.
   */
  public function testRevisionBookkeepingMayChange(): void {
    $this->assertContains('revision_log', PageMigrator::MAY_CHANGE);
    $nid = (int) $this->createPage('Logged page', TRUE)->id();
    $this->bindPages();
    $before = $this->pageValues($nid);
    PageMutation::$field = 'revision_log';
    $this->assertMigrated([$nid], $this->runMigration());
    $this->assertNull(PageMutation::$field);
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('changed log', $this->nodeStorage()->load($nid)->getRevisionLogMessage());
  }

  /**
   * Protected drafts keep the live values and need no new migration.
   *
   * @param bool $active
   *   Whether the exposure is active during the draft writes.
   *
   * @dataProvider exposureStates
   */
  public function testProtectedDraftsKeepLiveValuesAndNeedNoMigration(bool $active): void {
    $english = (int) $this->createPage('Draft target en', TRUE)->id();
    $bilingual = (int) $this->addGerman($this->createPage('Draft target de', TRUE), TRUE, TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$english, $bilingual], $this->runMigration());
    if ($active) {
      // The status refusal is armed: the draft writes must still pass.
      $this->setExposure('1', TRUE);
    }
    $this->assertSame($active, CinatraDevSite::exposureActive());
    $this->actAsEditor();
    $live = [$english => $this->pageValues($english), $bilingual => $this->pageValues($bilingual)];
    // The protected draft allows one pending draft per page, so the en page
    // takes the en draft and the en-then-de page the de draft.
    $protected = $this->container->get('cinatra.protected_draft');
    foreach ([[$english, 'en'], [$bilingual, 'de']] as [$nid, $langcode]) {
      $preimage = $protected->prepare($nid, $langcode, ['title', 'body']);
      $stored = $protected->write($this->draftRequest($preimage, [
        'title' => [['value' => 'Draft title ' . $langcode]],
        'body' => [['value' => 'Draft body ' . $langcode, 'summary' => '', 'format' => 'plain_text']],
      ]));
      $this->assertFalse($stored['is_default_revision']);
      $this->assertSame('draft', $stored['moderation_state']);
      $this->assertSame($live[$nid]['default'], $stored['default_revision_id']);
    }
    foreach ($live as $nid => $values) {
      $after = $this->pageValues($nid);
      $this->assertSame($values['default'], $after['default']);
      $this->assertSame($values['languages'], $after['languages']);
      $this->assertSame($values['revisions'] + 1, $after['revisions']);
    }
    $before = $this->footprint();
    $this->assertMigrated([], $this->runMigration());
    $this->assertSame($before, $this->footprint());
  }

  /**
   * The exposure inactive and active.
   *
   * @return array<string, array{bool}>
   *   The cases.
   */
  public static function exposureStates(): array {
    return ['exposure inactive' => [FALSE], 'exposure active' => [TRUE]];
  }

  /**
   * A status-only write of one translation is refused while active.
   *
   * The refusal compares each translation with the same translation of the
   * original: a state change of en does not cover a status-only change of
   * de.
   */
  public function testTranslationStatusWriteRefusedWhileActive(): void {
    $nid = (int) $this->addGerman($this->createPage('Bilingual guarded', TRUE), TRUE, TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $this->setExposure('1', TRUE);
    $before = $this->pageValues($nid);
    // Only de changes its status.
    $page = $this->nodeStorage()->loadUnchanged($nid);
    $page->getTranslation('de')->setUnpublished();
    $this->assertSame(PageMigrator::STATUS_REFUSAL_TEXT, $this->saveMessage($page));
    $this->assertSame($before, $this->pageValues($nid));
    // The en translation changes its state, de only its status.
    $page = $this->nodeStorage()->loadUnchanged($nid);
    $page->getTranslation('en')->set('moderation_state', 'archived');
    $page->getTranslation('de')->setUnpublished();
    $this->assertSame(PageMigrator::STATUS_REFUSAL_TEXT, $this->saveMessage($page));
    $this->assertSame($before, $this->pageValues($nid));
    // The control: de changes its status through its state, and passes.
    $page = $this->nodeStorage()->loadUnchanged($nid);
    $page->getTranslation('de')->set('moderation_state', 'archived');
    $this->assertNull($this->saveMessage($page));
    $this->assertFalse($this->nodeStorage()->loadUnchanged($nid)->getTranslation('de')->isPublished());
  }

  /**
   * A translation added while active passes: the original has none to compare.
   */
  public function testNewTranslationPassesWhileActive(): void {
    $nid = (int) $this->createPage('Translated later', TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $this->setExposure('1', TRUE);
    $this->addGerman($this->nodeStorage()->loadUnchanged($nid), FALSE, TRUE);
    $storage = $this->nodeStorage();
    $latest = $storage->loadRevision($storage->getLatestRevisionId($nid));
    $this->assertTrue($latest->hasTranslation('de'));
  }

  /**
   * A status-only write on a moderated page is refused only while active.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   * @param bool $refused
   *   Whether the write must be refused.
   *
   * @dataProvider guardCases
   */
  public function testStatusWriteRefusedOnlyWhileActive(?string $marker, mixed $armed, bool $refused): void {
    $nid = (int) $this->createPage('Guarded page', TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $this->setExposure($marker, $armed);
    $this->assertSame($refused, CinatraDevSite::exposureActive());
    $before = $this->pageValues($nid);
    $page = $this->nodeStorage()->load($nid);
    $page->setUnpublished();
    $message = NULL;
    try {
      $page->save();
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();
    }
    if ($refused) {
      $this->assertSame(PageMigrator::STATUS_REFUSAL_TEXT, $message);
      $this->assertSame($before, $this->pageValues($nid));
      return;
    }
    $this->assertNull($message);
  }

  /**
   * The marker and flag cases of the exposure guard.
   *
   * The same 17 rows as the exposure gate's own table; a test below checks
   * that the two tables stay equal.
   *
   * @return array<string, array{?string, mixed, bool}>
   *   The cases.
   */
  public static function guardCases(): array {
    return [
      'marker 1, armed TRUE' => ['1', TRUE, TRUE],
      'no marker, armed TRUE' => [NULL, TRUE, FALSE],
      'empty marker, armed TRUE' => ['', TRUE, FALSE],
      'marker 0, armed TRUE' => ['0', TRUE, FALSE],
      'marker true, armed TRUE' => ['true', TRUE, FALSE],
      'marker TRUE, armed TRUE' => ['TRUE', TRUE, FALSE],
      'marker yes, armed TRUE' => ['yes', TRUE, FALSE],
      'marker 01, armed TRUE' => ['01', TRUE, FALSE],
      'marker space 1, armed TRUE' => [' 1', TRUE, FALSE],
      'marker 1 space, armed TRUE' => ['1 ', TRUE, FALSE],
      'marker 1, no flag' => ['1', NULL, FALSE],
      'marker 1, flag FALSE' => ['1', FALSE, FALSE],
      'marker 1, flag 1' => ['1', 1, FALSE],
      'marker 1, flag string 1' => ['1', '1', FALSE],
      'marker 1, flag string TRUE' => ['1', 'TRUE', FALSE],
      'marker 1, flag list' => ['1', [TRUE], FALSE],
      'no marker, no flag' => [NULL, NULL, FALSE],
    ];
  }

  /**
   * The guard table here is the exposure gate's table, row for row.
   */
  public function testGuardTableIsTheExposureGateTable(): void {
    $this->assertSame(DevSiteExposureGateTest::guardCases(), self::guardCases());
  }

  /**
   * While active, the status refusal skips what it does not guard.
   *
   * A new page, a syncing save, a page of another bundle and an unmoderated
   * page each save a status change without the refusal.
   *
   * @param string $case
   *   The save that must pass.
   *
   * @dataProvider skippedSaves
   */
  public function testStatusRefusalSkipsWhileActive(string $case): void {
    $this->setExposure('1', TRUE);
    $this->assertTrue(CinatraDevSite::exposureActive());
    $information = $this->container->get('content_moderation.moderation_information');
    switch ($case) {
      case 'new page':
        $this->bindPages();
        $existing = Node::create([
          'type' => 'page',
          'langcode' => 'en',
          'title' => 'Existing page',
          'uid' => $this->editor->id(),
          'moderation_state' => 'published',
        ]);
        $existing->save();
        $existing = $this->nodeStorage()->loadUnchanged($existing->id());
        $page = Node::create([
          'type' => 'page',
          'langcode' => 'en',
          'title' => 'New page',
          'uid' => $this->editor->id(),
          'moderation_state' => 'published',
        ]);
        // Setting the moderation state also sets the status to match it, so
        // the status is set afterwards: unpublished in the published state.
        $page->setUnpublished();
        // Give the new page an original whose state is equal and whose status
        // differs: only the isNew() test keeps the hook from refusing it.
        if (method_exists($page, 'setOriginal')) {
          $page->setOriginal($existing);
        }
        else {
          $page->original = $existing;
        }
        $this->assertSame('published', $existing->get('moderation_state')->value);
        $this->assertTrue($existing->isPublished());
        $this->assertFalse($page->isPublished());
        $this->assertTrue($information->isModeratedEntity($page));
        $page->save();
        $this->assertFalse($page->isNew());
        return;

      case 'syncing save':
        $nid = (int) $this->createPage('Syncing page', TRUE)->id();
        $this->bindPages();
        $this->assertMigrated([$nid], $this->runMigration());
        $page = $this->nodeStorage()->load($nid);
        $page->setSyncing(TRUE);
        break;

      case 'other bundle':
        NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
        $workflow = $this->createEditorialWorkflow();
        $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
        $page = Node::create([
          'type' => 'article',
          'title' => 'Article',
          'uid' => $this->editor->id(),
          'moderation_state' => 'published',
        ]);
        $page->save();
        $page = $this->nodeStorage()->load($page->id());
        $this->assertTrue($page->isPublished());
        break;

      default:
        $this->createEditorialWorkflow();
        $page = $this->createPage('Unmoderated page', TRUE);
    }
    $moderated = $case !== 'unmoderated page';
    $this->assertSame($moderated, $information->isModeratedEntity($page));
    $page->setUnpublished();
    // Not refused: an exception fails this test. For an existing node, the
    // save changes the status and keeps the moderation state.
    $page->save();
    if (!$moderated) {
      $this->assertFalse($this->nodeStorage()->loadUnchanged($page->id())->isPublished());
    }
  }

  /**
   * The saves the status refusal skips.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function skippedSaves(): array {
    $cases = [];
    foreach (['new page', 'syncing save', 'other bundle', 'unmoderated page'] as $case) {
      $cases[$case] = [$case];
    }
    return $cases;
  }

  /**
   * A status change through the moderation state passes while active.
   */
  public function testStatusThroughModerationStatePasses(): void {
    $nid = (int) $this->createPage('Archived page', TRUE)->id();
    $this->bindPages();
    $this->assertMigrated([$nid], $this->runMigration());
    $this->setExposure('1', TRUE);
    $page = $this->nodeStorage()->load($nid);
    $page->set('moderation_state', 'archived');
    $page->save();
    $this->assertFalse($this->nodeStorage()->loadUnchanged($nid)->isPublished());
  }

  /**
   * Runs the plan and, when it refuses nothing, the migration.
   *
   * @return array
   *   The migration result.
   */
  private function runMigration(): array {
    $plan = $this->migrator()->plan();
    $this->assertSame([], $plan['refused'], 'The plan refused.');
    return $this->migrator()->migrate($plan['plans']);
  }

  /**
   * Asserts a successful migration and the pages it saved.
   *
   * @param int[] $nids
   *   The node ids that must have been saved.
   * @param array $result
   *   The migration result.
   */
  private function assertMigrated(array $nids, array $result): void {
    $this->assertTrue($result['ok'], 'Refused: ' . implode(', ', $result['refused']) . ' ' . $result['error']);
    $this->assertSame([], $result['refused']);
    $this->assertSame($nids, $result['migrated']);
  }

  /**
   * Returns the page migrator service.
   *
   * @return \Drupal\cinatra_dev_site\Page\PageMigrator
   *   The service.
   */
  private function migrator(): PageMigrator {
    return $this->container->get('cinatra_dev_site.page_migrator');
  }

  /**
   * Binds node:page to editorial, as the editorial binding will.
   */
  private function bindPages(): void {
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
  }

  /**
   * Sets the marker and the armed State flag of the exposure guard.
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param mixed $armed
   *   The State value, or NULL for no State entry.
   */
  private function setExposure(?string $marker, mixed $armed): void {
    putenv($marker === NULL ? 'CINATRA_DEV_SITE' : 'CINATRA_DEV_SITE=' . $marker);
    if ($armed === NULL) {
      \Drupal::state()->delete(CinatraDevSite::ARMED_KEY);
      return;
    }
    \Drupal::state()->set(CinatraDevSite::ARMED_KEY, $armed);
  }

  /**
   * Reads every stored value a migration could write.
   *
   * @return array
   *   Revision counts, path aliases, and every field of every page.
   */
  private function footprint(): array {
    $pages = [];
    foreach ($this->nodeStorage()->getQuery()->accessCheck(FALSE)->sort('nid')->execute() as $nid) {
      $pages[(int) $nid] = $this->fieldValues((int) $nid);
    }
    return [
      'node_revisions' => $this->revisionCount('node'),
      'state_revisions' => $this->revisionCount('content_moderation_state'),
      'aliases' => (int) \Drupal::database()->select('path_alias')->countQuery()->execute()->fetchField(),
      'pages' => $pages,
    ];
  }

  /**
   * Reads every field of a page's default revision, per language.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The field values, without the computed moderation state.
   */
  private function fieldValues(int $nid): array {
    $node = $this->nodeStorage()->loadUnchanged($nid);
    $languages = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      // The field values as toArray() reads them, but the moderation state
      // is skipped before it is computed: on Drupal 10, computing it with a
      // state row that cannot be loaded calls a method on NULL.
      $values = [];
      foreach (array_keys($translation->getFieldDefinitions()) as $name) {
        if ($name !== 'moderation_state') {
          $values[$name] = $translation->get($name)->getValue();
        }
      }
      ksort($values);
      $languages[$langcode] = $values;
    }
    ksort($languages);
    return $languages;
  }

  /**
   * Counts stored revisions of an entity type.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param int|null $nid
   *   A node id to limit the count to, or NULL for all entities.
   *
   * @return int
   *   The revision count.
   */
  private function revisionCount(string $entity_type_id, ?int $nid = NULL): int {
    $query = $this->container->get('entity_type.manager')->getStorage($entity_type_id)
      ->getQuery()->allRevisions()->accessCheck(FALSE);
    if ($nid !== NULL) {
      $query->condition('nid', $nid);
    }
    return (int) $query->count()->execute();
  }

  /**
   * Reads the default revision facts and live values of one page.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The default revision id, revision count and per-language values.
   */
  private function pageValues(int $nid): array {
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $languages = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      $languages[$langcode] = [
        'status' => $translation->isPublished(),
        'changed' => (int) $translation->getChangedTime(),
        'title' => $translation->label(),
        'body' => $translation->get('body')->getValue(),
      ];
    }
    ksort($languages);
    return [
      'default' => (int) $node->getRevisionId(),
      'revisions' => $this->revisionCount('node', $nid),
      'languages' => $languages,
    ];
  }

  /**
   * Reads the editorial state row bound to a node revision and language.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string $langcode
   *   The language.
   *
   * @return string|null
   *   The stored moderation state, or NULL when no row is bound.
   */
  private function stateRow(int $nid, int $revision_id, string $langcode): ?string {
    $storage = $this->container->get('entity_type.manager')->getStorage('content_moderation_state');
    $revision = $this->stateRevisionId($nid, $revision_id, $langcode);
    if ($revision === NULL) {
      return NULL;
    }
    $stored = $storage->loadRevision($revision);
    if (!$stored || !$stored->hasTranslation($langcode)) {
      return NULL;
    }
    return $stored->getTranslation($langcode)->get('moderation_state')->value;
  }

  /**
   * Finds the latest editorial state row revision bound to a node revision.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string $langcode
   *   The language.
   *
   * @return int|null
   *   The state row revision id, or NULL when no row is bound.
   */
  private function stateRevisionId(int $nid, int $revision_id, string $langcode): ?int {
    $storage = $this->container->get('entity_type.manager')->getStorage('content_moderation_state');
    $storage->resetCache();
    $revisions = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('content_entity_revision_id', $revision_id)
      ->condition('workflow', 'editorial')
      ->condition('langcode', $langcode)
      ->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute();
    $revision = key($revisions);
    return $revision ? (int) $revision : NULL;
  }

  /**
   * Saves a page and returns the message of the failure, if any.
   *
   * @param \Drupal\node\NodeInterface $page
   *   The page.
   *
   * @return string|null
   *   The exception message, or NULL when the save passed.
   */
  private function saveMessage(NodeInterface $page): ?string {
    try {
      $page->save();
    }
    catch (\Exception $exception) {
      return $exception->getMessage();
    }
    return NULL;
  }

  /**
   * Stores one state row the way core persists it.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string $langcode
   *   The row language.
   * @param string|null $state
   *   The moderation state, or NULL to store the row without one.
   */
  private function storeRow(int $nid, int $revision_id, string $langcode, ?string $state): void {
    $values = [
      'langcode' => $langcode,
      'workflow' => 'editorial',
      'moderation_state' => $state,
      'content_entity_type_id' => 'node',
      'content_entity_id' => $nid,
      'content_entity_revision_id' => $revision_id,
    ];
    $row = ContentModerationState::create(array_filter($values, static fn($value): bool => $value !== NULL));
    // The entity's own save() would save the node instead; this is its
    // realSave().
    ContentModerationState::updateOrCreateFromEntity($row);
  }

  /**
   * Creates an en page with the seeder's fields and a note.
   *
   * @param string $title
   *   The title.
   * @param bool $published
   *   The status.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved page.
   */
  private function createPage(string $title, bool $published): NodeInterface {
    $page = Node::create([
      'type' => 'page',
      'langcode' => 'en',
      'uid' => $this->editor->id(),
      'title' => $title,
      'status' => $published,
      'body' => [['value' => $title . ' body', 'summary' => '', 'format' => 'plain_text']],
      'field_note' => $title . ' note',
      'changed' => self::OLD_CHANGED,
    ]);
    $page->save();
    // The premise of the changed-time checks: core keeps the value set here.
    $this->assertSame(self::OLD_CHANGED, (int) $page->getChangedTime());
    return $page;
  }

  /**
   * Adds a de translation to a page.
   *
   * @param \Drupal\node\NodeInterface $page
   *   The page.
   * @param bool $published
   *   The status of the translation.
   * @param bool $new_revision
   *   TRUE to save the translation as a new default revision.
   *
   * @return \Drupal\node\NodeInterface
   *   The reloaded default revision.
   */
  private function addGerman(NodeInterface $page, bool $published, bool $new_revision): NodeInterface {
    // The translation keeps the page's owner, as an editor's would.
    $german = $page->addTranslation('de', [
      'uid' => $page->getOwnerId(),
      'title' => $page->label() . ' (de)',
      'body' => [['value' => $page->label() . ' de body', 'summary' => '', 'format' => 'plain_text']],
      'status' => $published,
      'changed' => self::OLD_CHANGED,
    ]);
    $german->setNewRevision($new_revision);
    $german->save();
    $storage = $this->nodeStorage();
    $storage->resetCache([(int) $page->id()]);
    return $storage->load($page->id());
  }

  /**
   * Grants the editor the protected draft permissions and acts as it.
   */
  private function actAsEditor(): void {
    $role = Role::create(['id' => 'protected_editor', 'label' => 'Protected editor']);
    foreach ([
      'access content',
      'edit any page content',
      'view all revisions',
      'view latest version',
      'view own unpublished content',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
      'use editorial transition archive',
      'use text format plain_text',
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $this->editor->addRole($role->id());
    $this->editor->save();
    $this->container->get('current_user')->setAccount($this->editor);
    $this->assertFalse($this->editor->hasPermission('bypass node access'));
  }

  /**
   * Builds a protected draft request from a preimage.
   *
   * @param array $preimage
   *   The result of prepare().
   * @param array $updates
   *   The proposed field values.
   *
   * @return array
   *   The write request.
   */
  private function draftRequest(array $preimage, array $updates): array {
    return [
      'nid' => $preimage['node_id'],
      'language' => $preimage['language'],
      'draft_state' => 'draft',
      'expected_default_revision_id' => $preimage['default_revision_id'],
      'expected_latest_revision_id' => $preimage['latest_revision_id'],
      'workflow_fingerprint' => $preimage['workflow_fingerprint'],
      'preimage_fingerprint' => $preimage['preimage_fingerprint'],
      'updates' => $updates,
    ];
  }

  /**
   * Loads the stored editorial workflow's type plugin, bypassing caches.
   *
   * @return \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface
   *   The plugin.
   */
  private function editorial(): ContentModerationInterface {
    $workflow = $this->container->get('entity_type.manager')->getStorage('workflow')->loadUnchanged('editorial');
    $this->assertInstanceOf(WorkflowInterface::class, $workflow);
    return $workflow->getTypePlugin();
  }

  /**
   * Returns the node storage.
   *
   * @return \Drupal\node\NodeStorageInterface
   *   The node storage.
   */
  private function nodeStorage(): NodeStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
