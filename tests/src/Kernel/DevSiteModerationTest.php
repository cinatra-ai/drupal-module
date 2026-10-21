<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\Install\Requirements\CinatraDevSiteRequirements;
use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
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
use Drupal\workflows\WorkflowInterface;

/**
 * Exercises the development site's editorial binding and page migration.
 *
 * @group cinatra
 */
final class DevSiteModerationTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'language',
    'content_translation', 'workflows', 'content_moderation', 'cinatra',
    'cinatra_dev_site', 'cinatra_dev_site_test',
  ];

  /**
   * The seeded MCP configuration objects have no schema.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

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
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'language', 'content_moderation', 'cinatra']);
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    // Drupal 10 adds the body field when the node type is created; newer
    // versions may leave its creation to the caller. Reuse the real field.
    $body_storage = FieldStorageConfig::loadByName('node', 'body') ?? FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary',
      'revisionable' => TRUE, 'translatable' => TRUE,
    ]);
    $body_storage->setTranslatable(TRUE)->save();
    $body_field = FieldConfig::loadByName('node', 'page', 'body') ?? FieldConfig::create([
      'entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'body',
      'label' => 'Body', 'translatable' => TRUE,
    ]);
    $body_field->setTranslatable(TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);
    $this->config('mcp_tools.servers')->setData(['servers' => []])->save();
    $this->config('mcp_tools_remote.settings')->setData([
      'enabled' => FALSE,
      'uid' => 0,
      'allow_uid1' => FALSE,
      'allowed_ips' => [],
      'allowed_origins' => [],
      'server_name' => 'Drupal MCP Tools',
      'server_version' => '1.0.0',
      'server_id' => '',
      'pagination_limit' => 50,
      'include_all_tools' => FALSE,
      'gateway_mode' => FALSE,
    ])->save();
    $this->config('mcp_tools.settings')->setData(['access' => ['allowed_scopes' => ['read', 'write']]])->save();
    // The editor role is granted in case 14 only: its editorial transition
    // permissions do not exist before a workflow does.
    $this->editor = User::create(['uid' => 2, 'name' => 'editor', 'status' => TRUE]);
    $this->editor->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    parent::tearDown();
  }

  /**
   * Case 1: without a workflow, ensure() creates a bound editorial.
   */
  public function testCase01NoWorkflowCreatesBoundEditorial(): void {
    $this->assertSame([], $this->workflowIds());
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame(['editorial'], $this->workflowIds());
    $workflow = $this->editorial();
    $this->assertSame('content_moderation', $workflow->getTypePlugin()->getPluginId());
    $settings = $workflow->get('type_settings');
    $this->assertEquals(CinatraDevSite::EDITORIAL['states'], $settings['states']);
    $this->assertEquals(CinatraDevSite::EDITORIAL['transitions'], $settings['transitions']);
    $this->assertSame('draft', $settings['default_moderation_state']);
    $this->assertTrue($workflow->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * Case 2: a pre-existing editorial is reused, still one workflow.
   */
  public function testCase02ExistingEditorialIsReused(): void {
    $uuid = $this->createEditorialWorkflow()->uuid();
    $this->assertFalse($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame(['editorial'], $this->workflowIds());
    $this->assertSame($uuid, $this->editorial()->uuid());
    $this->assertTrue($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
  }

  /**
   * Case 3: a published page gets a published row on the same revision.
   */
  public function testCase03PublishedPageGetsPublishedRowOnSameRevision(): void {
    $nid = (int) $this->createPage('Published page', TRUE)->id();
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $before['default'], 'en'));
    $this->assertTrue($this->nodeStorage()->load($nid)->isDefaultRevision());
  }

  /**
   * Case 4: an en page with a later de default revision binds both rows.
   */
  public function testCase04EnglishThenGermanPageBindsBothRowsToDefault(): void {
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
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('published', $this->stateRow($nid, $default, 'de'));
  }

  /**
   * Case 5: mixed status keeps each status, the default and no pending flag.
   */
  public function testCase05MixedStatusKeepsStatusAndDefault(): void {
    $page = $this->addGerman($this->createPage('Mixed page', TRUE), FALSE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $before = $this->pageValues($nid);
    $this->assertTrue($before['languages']['en']['status']);
    $this->assertFalse($before['languages']['de']['status']);
    $this->assertEnsureOk($this->runEnsure());
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
   * Case 6: with the en row present, the missing de row is added.
   */
  public function testCase06PartialRowsAddMissingGermanRow(): void {
    $this->createEditorialWorkflow();
    $page = $this->addGerman($this->createPage('Partial page', TRUE), TRUE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $this->storeRow($nid, $default, 'en', 'published');
    $this->assertNull($this->stateRow($nid, $default, 'de'));
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $default, 'en'));
    $this->assertSame('published', $this->stateRow($nid, $default, 'de'));
  }

  /**
   * Case 7: an unpublished page gets a draft row and stays the default.
   */
  public function testCase07UnpublishedPageGetsDraftRow(): void {
    $nid = (int) $this->createPage('Unpublished page', FALSE)->id();
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('draft', $this->stateRow($nid, $before['default'], 'en'));
    $node = $this->nodeStorage()->load($nid);
    $this->assertTrue($node->isDefaultRevision());
    $this->assertFalse($node->isPublished());
  }

  /**
   * Case 8: a node-level pending revision refuses the run for every page.
   */
  public function testCase08NodePendingRevisionRefusesEveryPage(): void {
    $clean = (int) $this->createPage('Clean page', TRUE)->id();
    $pending = $this->createPage('Pending page', TRUE);
    $nid = (int) $pending->id();
    $pending->setNewRevision(TRUE);
    $pending->isDefaultRevision(FALSE);
    $pending->setTitle('Pending page draft');
    $pending->save();
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('pending_revision:' . $nid, $result['refused']);
    $this->assertNothingWritten($before);
    $this->assertNull($this->stateRow($clean, $this->pageValues($clean)['default'], 'en'));
  }

  /**
   * Case 9: an older non-default de revision refuses, as core's E8 test does.
   */
  public function testCase09TranslationPendingRevisionRefuses(): void {
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
    // The fixture meets core's pending test for de: the latest
    // translation-affected revision differs from the default, never default.
    $this->assertSame($default, (int) $storage->getLatestRevisionId($nid));
    $this->assertSame($pending, (int) $storage->getLatestTranslationAffectedRevisionId($nid, 'de'));
    $this->assertFalse($storage->loadRevision($pending)->wasDefaultRevision());
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('pending_revision:' . $nid . ':de', $result['refused']);
    $this->assertNotContains('pending_revision:' . $nid, $result['refused']);
    $this->assertNothingWritten($before);
  }

  /**
   * Case 10: a published row on an unpublished translation refuses.
   */
  public function testCase10StateConflictRefuses(): void {
    $this->createEditorialWorkflow();
    $page = $this->createPage('Conflict page', FALSE);
    $nid = (int) $page->id();
    $this->storeRow($nid, (int) $page->getRevisionId(), 'en', 'published');
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('state_conflict:' . $nid . ':en', $result['refused']);
    $this->assertNothingWritten($before);
  }

  /**
   * Case 11: a second ensure() writes nothing.
   */
  public function testCase11SecondRunWritesNothing(): void {
    $this->addGerman($this->createPage('Repeated page', TRUE), FALSE, FALSE);
    $this->assertEnsureOk($this->runEnsure());
    $before = $this->footprint();
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->footprint());
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * Case 12: release, unbind, edit, then ensure() binds the new revision.
   */
  public function testCase12ReleaseUnbindEditCycleRebinds(): void {
    $page = $this->createPage('Cycle page', TRUE);
    $nid = (int) $page->id();
    $first = (int) $page->getRevisionId();
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame('published', $this->stateRow($nid, $first, 'en'));
    \Drupal::service('cinatra_dev_site.ensure')->release(TRUE);
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
    // The import of a transformed sync removes the binding, as here. A syncing
    // save keeps the raw type settings, so they are written directly.
    $workflow = $this->editorial();
    $settings = $workflow->get('type_settings');
    $settings['entity_types']['node'] = array_values(array_diff($settings['entity_types']['node'], ['page']));
    if ($settings['entity_types']['node'] === []) {
      unset($settings['entity_types']['node']);
    }
    $workflow->setSyncing(TRUE);
    $workflow->set('type_settings', $settings);
    $workflow->save();
    $this->assertFalse($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    $edited = $storage->load($nid);
    $edited->setNewRevision(TRUE);
    $edited->setTitle('Cycle page edited');
    $edited->save();
    $second = (int) $edited->getRevisionId();
    $this->assertGreaterThan($first, $second);
    $this->assertNull($this->stateRow($nid, $second, 'en'));
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame('published', $this->stateRow($nid, $second, 'en'));
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $this->assertSame($second, (int) $node->getRevisionId());
    $this->assertTrue($node->isDefaultRevision());
    $this->assertTrue($node->isPublished());
    $this->assertTrue(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * Case 13: a seeded page under an unbound editorial gets a published row.
   */
  public function testCase13FreshSyncPageGetsPublishedRow(): void {
    $this->createEditorialWorkflow();
    $page = Node::create([
      'type' => 'page', 'uid' => $this->editor->id(), 'title' => 'Seeded page', 'status' => TRUE,
      'body' => [['value' => 'Seeded body', 'summary' => '', 'format' => 'plain_text']],
    ]);
    $page->save();
    $nid = (int) $page->id();
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->pageValues($nid));
    $this->assertSame('published', $this->stateRow($nid, $before['default'], 'en'));
    $this->assertTrue($this->nodeStorage()->load($nid)->isPublished());
  }

  /**
   * Case 14: protected drafts keep live values and need no new migration.
   */
  public function testCase14ProtectedDraftsKeepLiveValuesAndNeedNoMigration(): void {
    $english = (int) $this->createPage('Draft target en', TRUE)->id();
    $bilingual = (int) $this->addGerman($this->createPage('Draft target de', TRUE), TRUE, TRUE)->id();
    $this->assertEnsureOk($this->runEnsure());
    $this->actAsEditor();
    $live = [$english => $this->pageValues($english), $bilingual => $this->pageValues($bilingual)];
    // The protected draft allows one pending draft per page, so the en page
    // of case 3 takes the en draft and the en-then-de page of case 4 the de
    // draft: every language gets one.
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
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($before, $this->footprint());
  }

  /**
   * Case 15: a status-only write on a moderated page is refused when armed.
   */
  public function testCase15StatusWriteRefusedWhileArmed(): void {
    $nid = (int) $this->createPage('Guarded page', TRUE)->id();
    $this->assertEnsureOk($this->runEnsure());
    $this->assertTrue(CinatraDevSite::isArmed());
    $revisions = $this->pageValues($nid)['revisions'];
    $page = $this->nodeStorage()->load($nid);
    $page->setUnpublished();
    $message = NULL;
    try {
      $page->save();
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();
    }
    $this->assertSame(CinatraDevSite::STATUS_REFUSAL_TEXT, $message);
    $after = $this->pageValues($nid);
    $this->assertTrue($after['languages']['en']['status']);
    $this->assertSame($revisions, $after['revisions']);
    \Drupal::state()->delete(CinatraDevSite::ARMED_KEY);
    $this->assertFalse(CinatraDevSite::isArmed());
    $page = $this->nodeStorage()->load($nid);
    $page->setUnpublished();
    // Not armed: the save is not refused (an exception fails this test).
    $page->save();
    $this->assertGreaterThanOrEqual($revisions, $this->pageValues($nid)['revisions']);
  }

  /**
   * Case 16: the install requirement and ensure() both need the marker.
   */
  public function testCase16InstallRequirementAndEnsureNeedMarker(): void {
    $this->createPage('Unmarked page', TRUE);
    putenv('CINATRA_DEV_SITE');
    $this->assertFalse(CinatraDevSite::markerPresent());
    \Drupal::moduleHandler()->loadInclude('cinatra_dev_site', 'install');
    $legacy = cinatra_dev_site_requirements('install');
    $this->assertArrayHasKey('cinatra_dev_site_marker', $legacy);
    $this->assertSame(2, $legacy['cinatra_dev_site_marker']['severity']);
    $modern = class_exists(RequirementSeverity::class);
    if ($modern) {
      $requirements = CinatraDevSiteRequirements::getRequirements();
      $this->assertArrayHasKey('cinatra_dev_site_marker', $requirements);
      $this->assertSame(RequirementSeverity::Error, $requirements['cinatra_dev_site_marker']['severity']);
    }
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('no_marker', $result['refused']);
    $this->assertNothingWritten($before);
    putenv('CINATRA_DEV_SITE=1');
    $this->assertSame([], cinatra_dev_site_requirements('install'));
    if ($modern) {
      $this->assertSame([], CinatraDevSiteRequirements::getRequirements());
    }
  }

  /**
   * Case 17: a de-only state row keeps de and gains en as default language.
   */
  public function testCase17OrientationKeepsGermanRowAndDefaultLanguage(): void {
    $this->createEditorialWorkflow();
    $page = $this->addGerman($this->createPage('Oriented page', TRUE), TRUE, FALSE);
    $nid = (int) $page->id();
    $default = (int) $page->getRevisionId();
    $this->storeRow($nid, $default, 'de', 'published');
    $this->assertNull($this->stateRow($nid, $default, 'en'));
    $before = $this->pageValues($nid);
    $this->assertEnsureOk($this->runEnsure());
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
    $this->assertEnsureOk($this->runEnsure());
    $this->assertSame($repeat, $this->footprint());
  }

  /**
   * Case 18: an outer transaction fails the migration; normal runs own one.
   */
  public function testCase18OuterTransactionFailsMigration(): void {
    $nid = (int) $this->createPage('Transaction page', TRUE)->id();
    $before = $this->footprint();
    $database = \Drupal::database();
    $transaction = $database->startTransaction();
    try {
      $result = $this->runEnsure();
    }
    finally {
      $transaction->rollBack();
      unset($transaction);
    }
    $this->assertFalse($database->inTransaction());
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    $this->assertFalse($result['ok']);
    $this->assertContains('migration_failed:' . $nid, $result['refused']);
    $this->assertStringContainsString('A protected edit requires its own root transaction.', $result['error']);
    $this->assertNothingWritten($before);
    \Drupal::state()->delete('cinatra_dev_site_test.in_transaction');
    $this->assertEnsureOk($this->runEnsure());
    $flags = \Drupal::state()->get('cinatra_dev_site_test.in_transaction', []);
    $this->assertNotEmpty($flags);
    foreach ($flags as $flag) {
      $this->assertTrue($flag);
    }
  }

  /**
   * Case 19: another workflow on node:page refuses beside editorial.
   */
  public function testCase19OtherPageWorkflowRefusesWithEditorial(): void {
    $this->createOtherPageWorkflow();
    $this->createPage('Conflicted page', TRUE);
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('page_workflow_conflict:other', $result['refused']);
    $this->assertNotContains('other_workflow', $result['refused']);
    $this->assertNothingWritten($before);
  }

  /**
   * Case 19: without editorial, both workflow refusals are reported.
   */
  public function testCase19OtherPageWorkflowRefusesWithoutEditorial(): void {
    $this->createOtherPageWorkflow();
    $this->editorial()->delete();
    $this->assertSame(['other'], $this->workflowIds());
    $before = $this->footprint();
    $result = $this->runEnsure();
    $this->assertFalse($result['ok']);
    $this->assertContains('other_workflow', $result['refused']);
    $this->assertContains('page_workflow_conflict:other', $result['refused']);
    $this->assertNothingWritten($before);
  }

  /**
   * Case 20: an unmarked import may not bind node:page; a marked one may.
   */
  public function testCase20ImportGuardRefusesUnmarkedBinding(): void {
    putenv('CINATRA_DEV_SITE');
    // The importer validates the owner of every sync object, and the MCP
    // modules are not installed here, so their seeded objects are removed.
    foreach (['mcp_tools.servers', 'mcp_tools_remote.settings', 'mcp_tools.settings'] as $name) {
      $this->config($name)->delete();
    }
    $this->createEditorialWorkflow();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    $data = $sync->read('workflows.workflow.editorial');
    $data['type_settings']['entity_types']['node'] = ['page'];
    $data['dependencies']['config'][] = 'node.type.page';
    $data['dependencies']['config'] = array_values(array_unique($data['dependencies']['config']));
    sort($data['dependencies']['config']);
    $sync->write('workflows.workflow.editorial', $data);

    $errors = $this->importSync();
    $this->assertStringContainsString(CinatraDevSite::IMPORT_GUARD_TEXT, implode("\n", $errors));
    $this->assertFalse($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));

    putenv('CINATRA_DEV_SITE=1');
    $errors = $this->importSync();
    $this->assertSame([], $errors, implode("\n", $errors));
    $this->assertTrue($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * Case 20: non-syncing saves and stored bindings pass the import guard.
   */
  public function testCase20ImportGuardLeavesNonSyncingAndStoredBindings(): void {
    putenv('CINATRA_DEV_SITE');
    $workflow = $this->createEditorialWorkflow();
    // A non-syncing save without the marker is not refused.
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $this->assertTrue($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    // A syncing save whose stored editorial already lists the page is not
    // refused.
    $stored = $this->editorial();
    $stored->set('label', 'Editorial resynced');
    $stored->setSyncing(TRUE);
    $stored->save();
    $this->assertSame('Editorial resynced', $this->editorial()->label());
    $this->assertTrue($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
  }

  /**
   * Runs the development site's ensure service.
   *
   * @return array
   *   The result of ensure().
   */
  private function runEnsure(): array {
    return \Drupal::service('cinatra_dev_site.ensure')->ensure();
  }

  /**
   * Asserts a successful ensure() result.
   *
   * @param array $result
   *   The result of ensure().
   */
  private function assertEnsureOk(array $result): void {
    $reason = implode(', ', $result['refused'] ?? []) . ' ' . ($result['error'] ?? '');
    $this->assertTrue($result['ok'], 'Refused: ' . $reason);
    $this->assertSame([], $result['refused']);
  }

  /**
   * Asserts that a refused run wrote nothing and did not arm.
   *
   * @param array $before
   *   The footprint read before the run.
   */
  private function assertNothingWritten(array $before): void {
    $this->assertSame($before, $this->footprint());
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::ARMED_KEY));
    $this->assertNull(\Drupal::state()->get(CinatraDevSite::BOUND_KEY));
  }

  /**
   * Reads every stored value a run could write.
   *
   * @return array
   *   Node and state revision counts, raw workflow config and module State.
   */
  private function footprint(): array {
    $config = $this->container->get('config.storage');
    $state = \Drupal::keyValue('state')->getAll();
    $module_state = array_filter(
      $state,
      fn($key) => str_starts_with((string) $key, 'cinatra_dev_site.'),
      ARRAY_FILTER_USE_KEY,
    );
    ksort($module_state);
    return [
      'node_revisions' => $this->revisionCount('node'),
      'state_revisions' => $this->revisionCount('content_moderation_state'),
      'workflows' => $config->readMultiple($config->listAll('workflows.workflow.')),
      'module_state' => $module_state,
    ];
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
    $storage->resetCache();
    $revisions = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('content_entity_revision_id', $revision_id)
      ->condition('workflow', 'editorial')
      ->condition('langcode', $langcode)
      ->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute();
    $revision = key($revisions);
    if (!$revision) {
      return NULL;
    }
    $stored = $storage->loadRevision($revision);
    if (!$stored || !$stored->hasTranslation($langcode)) {
      return NULL;
    }
    return $stored->getTranslation($langcode)->get('moderation_state')->value;
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
   * @param string $state
   *   The moderation state.
   */
  private function storeRow(int $nid, int $revision_id, string $langcode, string $state): void {
    $row = ContentModerationState::create([
      'langcode' => $langcode,
      'workflow' => 'editorial',
      'moderation_state' => $state,
      'content_entity_type_id' => 'node',
      'content_entity_id' => $nid,
      'content_entity_revision_id' => $revision_id,
    ]);
    // The entity's own save() would save the node instead; this is its
    // realSave().
    ContentModerationState::updateOrCreateFromEntity($row);
  }

  /**
   * Creates an unmoderated en page with the seeder's fields.
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
      'type' => 'page', 'langcode' => 'en', 'uid' => $this->editor->id(),
      'title' => $title, 'status' => $published,
      'body' => [['value' => $title . ' body', 'summary' => '', 'format' => 'plain_text']],
    ]);
    $page->save();
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
    ]);
    $german->setNewRevision($new_revision);
    $german->save();
    $storage = $this->nodeStorage();
    $storage->resetCache([(int) $page->id()]);
    return $storage->load($page->id());
  }

  /**
   * Creates an unbound editorial and a bound workflow named other.
   */
  private function createOtherPageWorkflow(): void {
    $editorial = $this->createEditorialWorkflow();
    $other = $editorial->createDuplicate();
    $other->set('id', 'other');
    $other->set('label', 'Other');
    $this->addEntityTypeAndBundleToWorkflow($other, 'node', 'page');
    $this->assertFalse($this->editorial()->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'page'));
  }

  /**
   * Grants the editor the protected draft permissions and acts as it.
   */
  private function actAsEditor(): void {
    $role = Role::create(['id' => 'protected_editor', 'label' => 'Protected editor']);
    foreach ([
      'access content', 'edit any page content', 'view all revisions',
      'view latest version', 'view own unpublished content',
      'use editorial transition create_new_draft', 'use editorial transition publish',
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
      'nid' => $preimage['node_id'], 'language' => $preimage['language'], 'draft_state' => 'draft',
      'expected_default_revision_id' => $preimage['default_revision_id'],
      'expected_latest_revision_id' => $preimage['latest_revision_id'],
      'workflow_fingerprint' => $preimage['workflow_fingerprint'],
      'preimage_fingerprint' => $preimage['preimage_fingerprint'], 'updates' => $updates,
    ];
  }

  /**
   * Imports the sync storage and returns the importer's errors.
   *
   * @return array
   *   The logged import errors as strings.
   */
  private function importSync(): array {
    $importer = $this->configImporter();
    try {
      $importer->import();
    }
    catch (ConfigImporterException $exception) {
      // The importer logs every error; they are read below.
    }
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    return array_map('strval', $importer->getErrors());
  }

  /**
   * Loads the stored editorial workflow, bypassing caches.
   *
   * @return \Drupal\workflows\WorkflowInterface
   *   The editorial workflow.
   */
  private function editorial(): WorkflowInterface {
    $workflow = $this->container->get('entity_type.manager')->getStorage('workflow')->loadUnchanged('editorial');
    $this->assertInstanceOf(WorkflowInterface::class, $workflow);
    return $workflow;
  }

  /**
   * Lists the stored workflow ids.
   *
   * @return string[]
   *   The sorted workflow ids.
   */
  private function workflowIds(): array {
    $ids = array_keys($this->container->get('entity_type.manager')->getStorage('workflow')->loadMultiple());
    sort($ids);
    return $ids;
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
