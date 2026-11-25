<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftService;
use Drupal\cinatra_protected_draft_test\ProtectedDraftProbe;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Writes and publishes an unpublished page only while it is unchanged.
 *
 * Exercises the real SQL writer, the node lock of the protected draft and
 * the core access checks of an editor's own save.
 *
 * @group cinatra
 */
final class UnpublishedWriteTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use ImageFieldFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language',
    'file', 'image', 'link', 'workflows', 'content_moderation', 'cinatra', 'cinatra_protected_draft_test',
  ];

  /**
   * The editor: edits pages, but may not change the published status.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $editor;
  /**
   * The publisher: the editor's permissions and the published status.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $publisher;
  /**
   * The viewer: views unpublished pages and edits nothing.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $viewer;
  /**
   * The drafter: edits moderated pages, but may not publish them.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $drafter;
  /**
   * The service under test.
   *
   * @var \Drupal\cinatra\ProtectedDraft\ProtectedDraftService
   */
  private ProtectedDraftService $protectedDraft;
  /**
   * The changed time every fixture page starts with, before this request.
   *
   * @var int
   */
  private int $oldChanged;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // The accounts below must exercise their actual role permissions.
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
    // An unmoderated bundle and a moderated one.
    NodeType::create(['type' => 'plain', 'name' => 'Plain', 'new_revision' => TRUE])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'field_note', 'type' => 'string',
      'revisionable' => TRUE, 'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'field_price', 'type' => 'decimal',
      'revisionable' => TRUE, 'translatable' => TRUE,
      'settings' => ['precision' => 10, 'scale' => 2],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node', 'bundle' => 'plain', 'field_name' => 'field_price',
      'label' => 'Price', 'translatable' => TRUE,
    ])->save();
    foreach (['plain', 'page'] as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node', 'bundle' => $bundle, 'field_name' => 'field_note',
        'label' => 'Note', 'translatable' => TRUE,
      ])->save();
    }
    $this->createImageField(['plain', 'page']);
    $this->createClearableFields(['plain']);
    $this->createNumberFields(['plain']);
    $body_storage = FieldStorageConfig::loadByName('node', 'body') ?? FieldStorageConfig::create([
      'entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary',
      'revisionable' => TRUE, 'translatable' => TRUE,
    ]);
    $body_storage->setTranslatable(TRUE)->save();
    $body_field = FieldConfig::loadByName('node', 'plain', 'body') ?? FieldConfig::create([
      'entity_type' => 'node', 'bundle' => 'plain', 'field_name' => 'body',
      'label' => 'Body', 'translatable' => TRUE,
    ]);
    $body_field->setTranslatable(TRUE)->save();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');

    $base = [
      'access content', 'view own unpublished content', 'view any unpublished content',
      'edit any plain content', 'edit any page content', 'use editorial transition create_new_draft',
    ];
    // Drupal 11 has a permission for the published status alone; older
    // releases need "administer nodes" for it.
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $status = isset($permissions['administer node published status']) ? 'administer node published status' : 'administer nodes';
    $roles = [
      'editor' => array_merge($base, ['use editorial transition publish']),
      'publisher' => array_merge($base, ['use editorial transition publish', $status]),
      'viewer' => ['access content', 'view own unpublished content', 'view any unpublished content'],
      'drafter' => $base,
    ];
    $uid = 2;
    foreach ($roles as $name => $granted) {
      $role = Role::create(['id' => $name, 'label' => ucfirst($name)]);
      foreach ($granted as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $account = User::create(['uid' => $uid++, 'name' => $name, 'status' => TRUE, 'roles' => [$name]]);
      $account->save();
      $this->{$name} = $account;
    }
    $this->assertFalse($this->editor->hasPermission($status));
    $this->assertFalse($this->editor->hasPermission('bypass node access'));
    $this->container->get('current_user')->setAccount($this->editor);
    $this->oldChanged = $this->container->get('datetime.time')->getRequestTime() - 100;
    ProtectedDraftProbe::reset();
    $this->protectedDraft = $this->container->get('cinatra.protected_draft');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ProtectedDraftProbe::reset();
    parent::tearDown();
  }

  /**
   * An unchanged page is written as one new default revision.
   */
  public function testUnchangedPageIsWritten(): void {
    $node = $this->plainPage();
    $nid = (int) $node->id();
    $before = $this->snapshot($nid);
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title', 'field_note']);
    $this->assertFalse($preimage['is_published']);
    $this->assertSame($this->oldChanged, $preimage['changed']);
    $this->assertArrayNotHasKey('published_values', $preimage);
    $this->assertArrayNotHasKey('published_languages', $preimage);

    $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
      'updates' => ['title' => [['value' => 'Reviewed title']]],
    ]);

    $after = $this->snapshot($nid);
    $this->assertSame($before['revisions'] + 1, $after['revisions']);
    $this->assertSame($result['revision_id'], $after['default']);
    $this->assertGreaterThan($before['default'], $result['revision_id']);
    $this->assertFalse($result['is_published']);
    $this->assertSame(['en' => FALSE], $after['published']);
    $this->assertSame(['title' => [['value' => 'Reviewed title']]], $result['fields']);
    $this->assertSame($before['notes'], $after['notes']);
    $this->assertSame('Reviewed title', $after['titles']['en']);
    $this->assertGreaterThan($this->oldChanged, $result['changed']);
    $this->assertSame($result['changed'], $after['changed']['en']);
    $this->assertRootFinished();
  }

  /**
   * With publish, the reviewed values and the publication are one save.
   */
  public function testWriteAndPublishInTheSameSave(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $before = $this->snapshot($nid);
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
      'updates' => ['title' => [['value' => 'Reviewed and published']]],
      'publish' => TRUE,
    ]);
    $after = $this->snapshot($nid);
    $this->assertSame($before['revisions'] + 1, $after['revisions'], 'Publishing must not take a second save.');
    $this->assertSame($result['revision_id'], $after['default']);
    $this->assertTrue($result['is_published']);
    $this->assertSame(['en' => TRUE], $after['published']);
    $this->assertSame('Reviewed and published', $after['titles']['en']);
    $this->assertGreaterThan($this->oldChanged, $result['changed']);
    $this->assertRootFinished();
  }

  /**
   * The publish step publishes the page as it is.
   */
  public function testPublishStepPublishesAsItIs(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $before = $this->snapshot($nid);
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $result = $this->protectedDraft->publishUnpublished($this->binding($preimage));
    $after = $this->snapshot($nid);
    $this->assertSame($before['revisions'] + 1, $after['revisions']);
    $this->assertSame($result['revision_id'], $after['default']);
    $this->assertTrue($result['is_published']);
    $this->assertSame([], $result['fields']);
    $this->assertSame(['en' => TRUE], $after['published']);
    $this->assertSame($before['titles'], $after['titles']);
    $this->assertSame($before['notes'], $after['notes']);
    $this->assertGreaterThan($this->oldChanged, $result['changed']);
    $this->assertRootFinished();
  }

  /**
   * Another save between the read and the write refuses; nothing is written.
   */
  public function testSaveBetweenReadAndWriteRefuses(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    // Another editor saves a new revision after the read.
    $other = $this->nodeStorage()->load($nid);
    $other->setTitle('Saved by someone else');
    $other->setNewRevision(TRUE);
    $other->save();
    $request = $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Reviewed title']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'The page revision changed');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished(['publish' => TRUE] + $request), 'The page revision changed');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)), 'The page revision changed');
    $this->assertSame('Saved by someone else', $this->snapshot($nid)['titles']['en']);
  }

  /**
   * A save in place, without a new revision, is caught by the content binding.
   */
  public function testSaveInPlaceBetweenReadAndWriteRefuses(): void {
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $other = $this->nodeStorage()->load($nid);
    $other->set('field_note', 'Changed in place');
    $other->setNewRevision(FALSE);
    $other->save();
    $this->assertSame($preimage['default_revision_id'], $this->snapshot($nid)['default']);
    $request = $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Reviewed title']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'The page values changed');
  }

  /**
   * A published page is refused: the protected draft covers it.
   */
  public function testPublishedPageRefuses(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $plain = Node::create([
      'type' => 'plain', 'langcode' => 'en', 'uid' => $this->publisher->id(),
      'title' => 'Live plain page', 'status' => TRUE,
    ]);
    $plain->save();
    $moderated = Node::create([
      'type' => 'page', 'langcode' => 'en', 'uid' => $this->publisher->id(),
      'title' => 'Live moderated page', 'moderation_state' => 'published',
    ]);
    $moderated->save();
    foreach ([$plain, $moderated] as $node) {
      $nid = (int) $node->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
      $this->assertTrue($preimage['is_published']);
      $request = $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Reviewed title']]]];
      $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'This page is published');
      $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)), 'This page is published');
    }
  }

  /**
   * Writing needs update access; publishing needs the published status.
   */
  public function testPermissionsAreTheEditorsOwn(): void {
    // The viewer may read its own unpublished page but not edit it.
    $this->container->get('current_user')->setAccount($this->viewer);
    $nid = (int) $this->plainPage($this->viewer)->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $request = $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Reviewed title']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'Editing this page is not permitted');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)), 'Editing this page is not permitted');

    // The editor may write, but may not change the published status.
    $this->container->get('current_user')->setAccount($this->editor);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $request = $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Reviewed title']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished(['publish' => TRUE] + $request), 'Publishing this page is not permitted');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)), 'Publishing this page is not permitted');
    $result = $this->protectedDraft->writeUnpublished($request);
    $this->assertFalse($result['is_published']);
    $this->assertRootFinished();
  }

  /**
   * The binding names its translation; another language refuses.
   *
   * Both translations share one changed time, so only the binding itself can
   * tell them apart.
   */
  public function testTranslationMismatchRefuses(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $node = $this->plainPage();
    $node->addTranslation('fr', [
      'title' => 'Titre', 'status' => FALSE, 'changed' => $this->oldChanged,
    ])->save();
    $nid = (int) $node->id();
    $snapshot = $this->snapshot($nid);
    $this->assertSame($snapshot['changed']['en'], $snapshot['changed']['fr']);
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    // The English binding sent for the French translation.
    $request = ['language' => 'fr'] + $this->binding($preimage) + ['updates' => ['title' => [['value' => 'Titre revu']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'The page values changed');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished(['publish' => TRUE] + $request), 'The page values changed');
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished(['language' => 'fr'] + $this->binding($preimage)), 'The page values changed');
    // A language the page has no translation in.
    $request['language'] = 'de';
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'The exact page translation is not available');
    // The French binding for the French translation writes only French.
    $french = $this->protectedDraft->prepare($nid, 'fr', ['title']);
    $result = $this->protectedDraft->writeUnpublished($this->binding($french) + ['updates' => ['title' => [['value' => 'Titre revu']]]]);
    $after = $this->snapshot($nid);
    $this->assertSame('fr', $result['language']);
    $this->assertSame(['en' => 'Unpublished page', 'fr' => 'Titre revu'], $after['titles']);
    // Core moves only the edited translation's timestamp.
    $this->assertSame($snapshot['changed']['en'], $after['changed']['en']);
    $this->assertGreaterThan($snapshot['changed']['fr'], $after['changed']['fr']);
    $this->assertRootFinished();
  }

  /**
   * A hook moving another translation's timestamp during a write rolls back.
   */
  public function testWriteRollsBackWhenSaveHookChangesOtherTranslationTimestamp(): void {
    $this->assertOtherTranslationTimestampChangeRollsBack(TRUE);
  }

  /**
   * A hook marking another translation affected during a write rolls back.
   */
  public function testWriteRollsBackWhenSaveHookMarksOtherTranslationAffected(): void {
    $this->assertOtherTranslationTimestampChangeRollsBack(TRUE, 'other_affected');
  }

  /**
   * The same during the publish step.
   */
  public function testPublishRollsBackWhenSaveHookChangesOtherTranslationTimestamp(): void {
    $this->assertOtherTranslationTimestampChangeRollsBack(FALSE);
  }

  /**
   * The same for the affected flag during the publish step.
   */
  public function testPublishRollsBackWhenSaveHookMarksOtherTranslationAffected(): void {
    $this->assertOtherTranslationTimestampChangeRollsBack(FALSE, 'other_affected');
  }

  /**
   * English is written, a hook moves the French timestamp: the save is undone.
   *
   * The timestamps are per translation and snapshotted per language, so the
   * comparison with the state before shows the French one back as it was.
   */
  private function assertOtherTranslationTimestampChangeRollsBack(bool $write, string $mode = 'other_changed'): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $node = $this->plainPage();
    $node->addTranslation('fr', [
      'title' => 'Titre', 'status' => FALSE, 'changed' => $this->oldChanged,
    ])->save();
    $nid = (int) $node->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, $mode);
    try {
      if ($write) {
        $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => ['title' => [['value' => 'Reviewed title']]],
        ]);
      }
      else {
        $this->protectedDraft->publishUnpublished($this->binding($preimage));
      }
      $this->fail('Another translation bookkeeping moved by a hook must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored draft changed an unrequested field or another translation', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $after = $this->snapshot($nid);
    $this->assertSame($before['changed']['fr'], $after['changed']['fr']);
    $this->assertSame($before, $after);
    $this->assertRootFinished();
  }

  /**
   * Unknown and reserved fields refuse before the save.
   */
  public function testUnknownOrReservedFieldRefuses(): void {
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $request = $this->binding($preimage) + ['updates' => ['field_missing' => [['value' => 'x']]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'A requested field does not exist on this page');
    $request['updates'] = ['status' => [['value' => 1]]];
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($request), 'A requested field cannot be edited');
  }

  /**
   * A moderated draft keeps its state; it is published only by a transition.
   */
  public function testModeratedDraftMovesOnlyThroughItsWorkflow(): void {
    $node = Node::create([
      'type' => 'page', 'langcode' => 'en', 'uid' => $this->editor->id(),
      'title' => 'Moderated draft', 'moderation_state' => 'draft', 'changed' => $this->oldChanged,
    ]);
    $node->save();
    $nid = (int) $node->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $this->assertTrue($preimage['moderated']);
    $this->assertFalse($preimage['is_published']);

    // The drafter has no publish transition.
    $this->container->get('current_user')->setAccount($this->drafter);
    $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)), 'does not allow publishing it');

    // The editor writes the draft: it stays a draft and becomes the default.
    $this->container->get('current_user')->setAccount($this->editor);
    $before = $this->snapshot($nid);
    $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
      'updates' => ['title' => [['value' => 'Reviewed draft']]],
    ]);
    $after = $this->snapshot($nid);
    $this->assertSame($before['revisions'] + 1, $after['revisions']);
    $this->assertSame($result['revision_id'], $after['default']);
    $this->assertFalse($result['is_published']);
    $this->assertSame('draft', $this->nodeStorage()->load($nid)->get('moderation_state')->value);

    // The editor publishes it through the publish transition, in one save.
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $result = $this->protectedDraft->publishUnpublished($this->binding($preimage));
    $published = $this->snapshot($nid);
    $this->assertSame($after['revisions'] + 1, $published['revisions']);
    $this->assertSame($result['revision_id'], $published['default']);
    $this->assertTrue($result['is_published']);
    $this->assertSame(['en' => TRUE], $published['published']);
    $this->assertSame('Reviewed draft', $published['titles']['en']);
    $this->assertSame('published', $this->nodeStorage()->load($nid)->get('moderation_state')->value);
    $this->assertRootFinished();
  }

  /**
   * A save hook replacing a reviewed value rolls back; nothing goes live.
   */
  public function testSaveHookReplacingReviewedValueRollsBack(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'replace_title');
    try {
      $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['title' => [['value' => 'Reviewed title']]],
        'publish' => TRUE,
      ]);
      $this->fail('A replaced reviewed value must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored value differs from the reviewed value', $expected->getMessage());
      $this->assertGreaterThan(0, ProtectedDraftProbe::$presaves, 'The real pre-save hook must run.');
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    $this->assertNull($this->nodeStorage()->loadRevision(ProtectedDraftProbe::$storedRevision));
    ProtectedDraftProbe::reset();
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * Text is compared byte for byte; a decimal by its number.
   *
   * A save hook turning the reviewed title "1.50" into "1.5" is refused,
   * while a decimal field's 1.50 stored in its column's own form is the same.
   */
  public function testStoredValuesCompareByFieldType(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'short_number_title');
    try {
      $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['title' => [['value' => '1.50']]],
        'publish' => TRUE,
      ]);
      $this->fail('Text that only looks like the reviewed number must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored value differs from the reviewed value', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $this->assertSame($before, $this->snapshot($nid));

    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_price']);
    $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
      'updates' => ['field_price' => [['value' => '1.50']]],
    ]);
    $this->assertSame($result['revision_id'], $this->snapshot($nid)['default']);
    $this->assertEquals(1.5, (float) $result['fields']['field_price'][0]['value']);
    $this->assertRootFinished();
  }

  /**
   * A decimal sent with leading zeroes is the stored decimal, not a mismatch.
   *
   * Drupal stores "01.50" in a scale 2 column as 1.50. Both spellings are one
   * number, so the saved write is kept, with and without publishing.
   */
  public function testDecimalWithLeadingZeroesIsNotRolledBack(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    foreach ([FALSE, TRUE] as $publish) {
      $nid = (int) $this->plainPage()->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_price']);
      $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['field_price' => [['value' => '01.50']]],
        'publish' => $publish,
      ]);
      $this->assertSame($result['revision_id'], $this->snapshot($nid)['default']);
      $this->assertEquals(1.5, (float) $result['fields']['field_price'][0]['value']);
      $this->assertRootFinished();
    }
  }

  /**
   * A save hook changing a non-revisionable field during a write rolls back.
   *
   * The write names no such field; only the post-save comparison of every
   * unrequested field sees it.
   */
  public function testWriteRollsBackWhenSaveHookChangesNonRevisionableField(): void {
    $this->assertNonRevisionableChangeRollsBack(TRUE);
  }

  /**
   * The same during the publish step, which names no field at all.
   */
  public function testPublishRollsBackWhenSaveHookChangesNonRevisionableField(): void {
    $this->assertNonRevisionableChangeRollsBack(FALSE);
  }

  /**
   * Arms the marker hook for a write or a publish and requires the rollback.
   */
  private function assertNonRevisionableChangeRollsBack(bool $write): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'marker');
    try {
      if ($write) {
        $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => ['title' => [['value' => 'Reviewed title']]],
        ]);
      }
      else {
        $this->protectedDraft->publishUnpublished($this->binding($preimage));
      }
      $this->fail('A changed non-revisionable field must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored draft changed an unrequested field', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $after = $this->snapshot($nid);
    $this->assertSame([], $after['marker']);
    $this->assertSame($before, $after);
    $this->assertRootFinished();
  }

  /**
   * An image given as a file and alt text is stored with the derived size.
   */
  public function testImageWithoutDimensionsIsNotRolledBack(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $file = $this->createImageFile('reviewed.png', 3, 2);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_image']);
    $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
      'updates' => ['field_image' => [['target_id' => $file->id(), 'alt' => 'A reviewed alt']]],
      'publish' => TRUE,
    ]);
    $stored = $this->snapshot($nid)['image']['en'][0];
    $this->assertSame($result['revision_id'], $this->snapshot($nid)['default']);
    $this->assertEquals($file->id(), $stored['target_id']);
    $this->assertSame('A reviewed alt', $stored['alt']);
    $this->assertEquals(3, $stored['width']);
    $this->assertEquals(2, $stored['height']);
    $this->assertRootFinished();
  }

  /**
   * A save hook swapping the image file for another valid one rolls back.
   */
  public function testSaveHookSwappingImageTargetRollsBack(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $reviewed = $this->createImageFile('reviewed.png', 3, 2);
    $other = $this->createImageFile('other.png', 5, 4);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_image']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'swap_image');
    ProtectedDraftProbe::$swapFile = (int) $other->id();
    try {
      $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['field_image' => [['target_id' => $reviewed->id(), 'alt' => 'A reviewed alt']]],
      ]);
      $this->fail('A swapped image file must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored value differs from the reviewed value', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * Every clear of a formatted text, link or reference field is stored empty.
   *
   * An item the field treats as empty is dropped before any check reads it, so
   * a clear needs no text format and is not examined as a value.
   */
  public function testClearOfFormattedTextLinkAndReferenceIsStored(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    foreach ($this->clearCases() as $field => $case) {
      foreach ($case['clears'] as $form => $clear) {
        $page = $this->plainPage();
        $page->set($field, $case['initial'])->save();
        $nid = (int) $page->id();
        $this->assertFalse($this->nodeStorage()->load($nid)->get($field)->isEmpty(), "$field starts filled");
        $preimage = $this->protectedDraft->prepare($nid, 'en', [$field]);
        $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => [$field => $clear],
          'publish' => TRUE,
        ]);
        $this->assertSame([$field => []], $result['fields'], "$field, $form");
        $this->nodeStorage()->resetCache([$nid]);
        $this->assertTrue($this->nodeStorage()->load($nid)->get($field)->isEmpty(), "$field, $form is stored empty");
        $this->assertRootFinished();
      }
    }
  }

  /**
   * A number the field cannot store exactly is refused before the save.
   *
   * The values go through the real setter: the field would round, coerce or
   * truncate each of them, so what it stores is not what was reviewed.
   */
  public function testNumbersTheFieldCannotStoreExactlyRefuseBeforeTheSave(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    foreach (array_values($this->inexactNumbers()) as [$field, $value]) {
      $nid = (int) $this->plainPage()->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', [$field]);
      $this->assertRefusedBeforeSave($nid, fn() => $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => [$field => [['value' => $value]]],
        'publish' => TRUE,
      ]), 'cannot be stored exactly by its field');
    }
  }

  /**
   * A float the database does not hold as sent rolls back after the save.
   *
   * 123456789012345 is a double, so the request passes the check before the
   * save; the float column returns it to 14 digits. What is stored is not what
   * was reviewed, so the existing refusal undoes the save.
   */
  public function testFloatTheDatabaseDoesNotHoldAsSentRollsBack(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_float']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'observe');
    try {
      $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['field_float' => [['value' => '123456789012345']]],
      ]);
      $this->fail('A float stored with fewer digits than reviewed must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored value differs from the reviewed value', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * Numbers a field stores exactly are written as sent, spelling aside.
   */
  public function testNumbersTheFieldStoresExactlyAreWritten(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    foreach ($this->exactNumbers() as $name => [$field, $value, $stored]) {
      $nid = (int) $this->plainPage()->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', [$field]);
      $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => [$field => [['value' => $value]]],
      ]);
      $this->assertEquals($stored, $result['fields'][$field][0]['value'], $name);
      $this->nodeStorage()->resetCache([$nid]);
      $this->assertEquals($stored, $this->nodeStorage()->load($nid)->get($field)->value, $name);
      $this->assertRootFinished();
    }
  }

  /**
   * A clear sent as [] or as an empty item is stored empty; a refill refuses.
   */
  public function testClearIsStoredAndRefillRefuses(): void {
    $this->container->get('current_user')->setAccount($this->publisher);
    foreach (['empty list' => [], 'empty item' => [['value' => '']]] as $form => $clear) {
      $nid = (int) $this->plainPage()->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_note']);
      $result = $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['field_note' => $clear],
        'publish' => TRUE,
      ]);
      $this->assertSame(['field_note' => []], $result['fields'], $form);
      $after = $this->snapshot($nid);
      $this->assertSame(['en' => []], $after['notes'], $form);
      $this->assertSame(['en' => TRUE], $after['published'], $form);
    }
    // A save hook that puts a value back into the cleared field.
    $nid = (int) $this->plainPage()->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['field_note']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'refill_cleared');
    try {
      $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
        'updates' => ['field_note' => [['value' => '']]],
        'publish' => TRUE,
      ]);
      $this->fail('A refilled cleared field must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString('The stored value differs from the reviewed value', $expected->getMessage());
      $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The real SQL save must precede the rollback.');
    }
    ProtectedDraftProbe::reset();
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * A moderated publish needs edit access to moderation_state too.
   */
  public function testDeniedModerationStateEditRefusesPublish(): void {
    $node = Node::create([
      'type' => 'page', 'langcode' => 'en', 'uid' => $this->editor->id(),
      'title' => 'Moderated draft', 'moderation_state' => 'draft', 'changed' => $this->oldChanged,
    ]);
    $node->save();
    $nid = (int) $node->id();
    $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'observe');
    // The editor has the publish transition; a field access hook denies the
    // moderation_state field.
    ProtectedDraftProbe::$deniedField = 'moderation_state';
    try {
      foreach ([
        fn() => $this->protectedDraft->publishUnpublished($this->binding($preimage)),
        fn() => $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => ['title' => [['value' => 'Reviewed draft']]],
          'publish' => TRUE,
        ]),
        fn() => $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => ['title' => [['value' => 'Reviewed draft']]],
        ]),
      ] as $operation) {
        try {
          $operation();
          $this->fail('A denied moderation_state field must refuse.');
        }
        catch (ProtectedDraftRefusal $expected) {
          $this->assertStringContainsString('Changing the workflow state of this page is not permitted', $expected->getMessage());
        }
        $this->assertSame(0, ProtectedDraftProbe::$presaves, 'Refusal must precede an actual node save hook.');
      }
    }
    finally {
      ProtectedDraftProbe::reset();
    }
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * The publish webhook is queued only when the publishing save committed.
   */
  public function testPublishWebhookOnlyForCommittedSave(): void {
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'https://cinatra.example.test')
      ->set('webhook_secret', 'whsec_' . base64_encode(str_repeat('k', 32)))
      ->set('webhook_binding_id', 'binding-1')
      ->save();
    $this->container->get('current_user')->setAccount($this->publisher);
    $callbacks = &drupal_register_shutdown_function();
    $count = count($callbacks);
    try {
      // A rolled-back publish queues nothing.
      $nid = (int) $this->plainPage()->id();
      $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
      ProtectedDraftProbe::arm($nid, 'replace_title');
      try {
        $this->protectedDraft->writeUnpublished($this->binding($preimage) + [
          'updates' => ['title' => [['value' => 'Reviewed title']]],
          'publish' => TRUE,
        ]);
        $this->fail('A replaced reviewed value must refuse.');
      }
      catch (ProtectedDraftRefusal) {
        $this->assertNotNull(ProtectedDraftProbe::$storedRevision, 'The publishing save must have run before the rollback.');
      }
      ProtectedDraftProbe::reset();
      $this->assertCount($count, $callbacks, 'A rolled-back publish must not queue its webhook.');
      // A committed publish queues one.
      $preimage = $this->protectedDraft->prepare($nid, 'en', ['title']);
      $this->protectedDraft->publishUnpublished($this->binding($preimage));
      $this->assertCount($count + 1, $callbacks, 'A committed publish queues its webhook once.');
    }
    finally {
      // Never send from the test process.
      array_splice($callbacks, $count);
    }
  }

  /**
   * Runs a scenario, then sends what it queued; returns the webhooks sent.
   *
   * The sender is a recording client, so each event is seen as the host would
   * see it: its webhook-id and its payload.
   *
   * @return array
   *   One entry per request: id (the webhook-id) and body (the payload).
   */
  private function webhooksSentBy(callable $scenario, int $deliveries = 1): array {
    $this->config('cinatra.settings')
      ->set('cinatra_url', 'http://localhost:3000')
      ->set('webhook_secret', 'whsec_' . base64_encode(str_repeat('k', 32)))
      ->set('webhook_binding_id', 'binding-1')
      ->save();
    $this->container->get('current_user')->setAccount($this->publisher);
    $history = [];
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(202))));
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $callbacks = &drupal_register_shutdown_function();
    $initial = count($callbacks);
    try {
      $scenario();
      foreach (array_slice($callbacks, $initial) as $queued) {
        // More than one delivery stands for a retry of the same event.
        for ($delivery = 0; $delivery < $deliveries; $delivery++) {
          call_user_func_array($queued['callback'], $queued['arguments']);
        }
      }
    }
    finally {
      // Never send from the test process.
      array_splice($callbacks, $initial);
    }
    return array_map(static fn(array $sent): array => [
      'id' => $sent['request']->getHeaderLine('webhook-id'),
      'body' => json_decode((string) $sent['request']->getBody(), TRUE),
    ], $history);
  }

  /**
   * The webhook-id of a publish event, as the emitter derives it.
   *
   * @param int $nid
   *   The page.
   * @param int $revision_id
   *   The published revision.
   * @param int $sequence
   *   The event's sequence number for the page language.
   * @param string $langcode
   *   The language.
   */
  private function webhookId(int $nid, int $revision_id, int $sequence = 1, string $langcode = 'en'): string {
    $instance = (string) $this->config('cinatra.settings')->get('instance_id');
    $this->nodeStorage()->resetCache([$nid]);
    $changed = (int) $this->nodeStorage()->loadRevision($revision_id)->getTranslation($langcode)->getChangedTime();
    return 'drupal-' . hash('sha256', implode(':', [$instance, $nid, $revision_id, $langcode, $changed, $sequence]));
  }

  /**
   * Saves the page in one step of a webhook scenario; returns its revision.
   *
   * @param int $nid
   *   The page.
   * @param bool $published
   *   The published state to save.
   * @param bool $new_revision
   *   FALSE saves the default revision in place.
   * @param string $title
   *   The title to save.
   */
  private function savePage(int $nid, bool $published, bool $new_revision, string $title): int {
    $this->nodeStorage()->resetCache([$nid]);
    $node = $this->nodeStorage()->load($nid);
    $node->set('status', $published);
    $node->setTitle($title);
    $node->setNewRevision($new_revision);
    $node->save();
    return (int) $node->getRevisionId();
  }

  /**
   * A publish in a rolled-back savepoint sends nothing, root commit or not.
   */
  public function testPublishWebhookNotSentForRolledBackSavepointInCommittedRoot(): void {
    $sent = $this->webhooksSentBy(function (): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $savepoint = $database->startTransaction();
      $this->savePage($nid, TRUE, TRUE, 'Lost');
      $savepoint->rollBack();
      unset($savepoint);
      // The caller catches the failure and commits the outer transaction.
      unset($root);
      $this->nodeStorage()->resetCache([$nid]);
      $this->assertFalse($this->nodeStorage()->load($nid)->isPublished());
    });
    $this->assertSame([], $sent);
  }

  /**
   * A kept publish in a savepoint sends exactly one event, for its revision.
   */
  public function testPublishWebhookSentOnceForKeptSavepoint(): void {
    $revision = 0;
    $nid = 0;
    $sent = $this->webhooksSentBy(function () use (&$revision, &$nid): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $savepoint = $database->startTransaction();
      $revision = $this->savePage($nid, TRUE, TRUE, 'Kept');
      unset($savepoint);
      unset($root);
    });
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
    $this->assertSame('Kept', $sent[0]['body']['title']);
  }

  /**
   * An in-place publish rolled back, then a real publish: one event, the real.
   *
   * The rolled-back publish was on an existing revision, which still exists
   * and is published when the root commits; only the stored state of its own
   * save tells them apart.
   */
  public function testInPlacePublishRolledBackThenRealPublishSendsOneEvent(): void {
    $revision = 0;
    $nid = 0;
    $sent = $this->webhooksSentBy(function () use (&$revision, &$nid): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $savepoint = $database->startTransaction();
      $in_place = $this->savePage($nid, TRUE, FALSE, 'Lost in place');
      $savepoint->rollBack();
      unset($savepoint);
      $revision = $this->savePage($nid, TRUE, TRUE, 'Real publish');
      $this->assertNotSame($in_place, $revision);
      unset($root);
    });
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
    $this->assertSame('Real publish', $sent[0]['body']['title']);
  }

  /**
   * Two publishes in one root, with an unpublish between: one event, the last.
   */
  public function testTwoPublishesInOneRootSendOneEvent(): void {
    $revision = 0;
    $nid = 0;
    $sent = $this->webhooksSentBy(function () use (&$revision, &$nid): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $this->savePage($nid, TRUE, TRUE, 'First publish');
      $this->savePage($nid, FALSE, TRUE, 'Unpublished between');
      $revision = $this->savePage($nid, TRUE, TRUE, 'Second publish');
      unset($root);
    });
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
    $this->assertSame('Second publish', $sent[0]['body']['title']);
  }

  /**
   * A later save that keeps the page published leaves one event, for it.
   *
   * The publishing save is superseded; the page is published in the stored
   * default revision, so the single event describes that revision.
   */
  public function testSupersededPublishSendsOneEventForTheStoredRevision(): void {
    $revision = 0;
    $nid = 0;
    $sent = $this->webhooksSentBy(function () use (&$revision, &$nid): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $this->savePage($nid, TRUE, TRUE, 'Publishing save');
      $revision = $this->savePage($nid, TRUE, TRUE, 'Later save');
      unset($root);
    });
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
    $this->assertSame('Later save', $sent[0]['body']['title']);
  }

  /**
   * A publish that a later save in the same root unpublished sends nothing.
   */
  public function testPublishThenUnpublishInOneRootSendsNothing(): void {
    $sent = $this->webhooksSentBy(function (): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $this->savePage($nid, TRUE, TRUE, 'Published');
      $this->savePage($nid, FALSE, TRUE, 'Unpublished');
      unset($root);
      $this->nodeStorage()->resetCache([$nid]);
      $this->assertFalse($this->nodeStorage()->load($nid)->isPublished());
    });
    $this->assertSame([], $sent);
  }

  /**
   * A root that falls back to the earlier published state sends nothing.
   *
   * The page is published before the root. Inside it a savepoint unpublishes
   * and publishes again, and is rolled back as a whole: the stored state is
   * the old published revision, which no save of this root produced.
   */
  public function testFallbackToEarlierPublishedStateSendsNothing(): void {
    $nid = (int) $this->plainPage()->id();
    // Before any webhook is configured, so this publish registers nothing.
    $this->savePage($nid, TRUE, TRUE, 'Published before the root');
    $sent = $this->webhooksSentBy(function () use ($nid): void {
      $database = $this->container->get('database');
      $root = $database->startTransaction();
      $savepoint = $database->startTransaction();
      $this->savePage($nid, FALSE, TRUE, 'Unpublished');
      $this->savePage($nid, TRUE, TRUE, 'Published again');
      $savepoint->rollBack();
      unset($savepoint);
      unset($root);
      $this->nodeStorage()->resetCache([$nid]);
      $this->assertSame('Published before the root', $this->nodeStorage()->load($nid)->label());
    });
    $this->assertSame([], $sent);
  }

  /**
   * A root that ends on the revision it began with sends nothing.
   *
   * The page is published in revision R before the root. A savepoint
   * unpublishes and republishes R in place, then is rolled back, and the outer
   * transaction commits: the database is back at the published R, so the
   * registered publish did not change the stored state.
   */
  public function testRootEndingOnTheSamePublishedRevisionSendsNothing(): void {
    $nid = (int) $this->plainPage()->id();
    // Before any webhook is configured, so this publish registers nothing.
    $this->savePage($nid, TRUE, TRUE, 'Published before the root');
    $sent = $this->webhooksSentBy(function () use ($nid): void {
      $database = $this->container->get('database');
      $root = $database->startTransaction();
      $savepoint = $database->startTransaction();
      $this->savePage($nid, FALSE, FALSE, 'Unpublished in place');
      $this->savePage($nid, TRUE, FALSE, 'Republished in place');
      $savepoint->rollBack();
      unset($savepoint);
      unset($root);
      $this->nodeStorage()->resetCache([$nid]);
      $this->assertSame('Published before the root', $this->nodeStorage()->load($nid)->label());
    });
    $this->assertSame([], $sent);
  }

  /**
   * An unpublished page published in place sends one event.
   *
   * The revision does not change, but the page was not published when the root
   * began, so the stored state did change.
   */
  public function testInPlacePublishOfAnUnpublishedPageSendsOneEvent(): void {
    $revision = 0;
    $nid = 0;
    $sent = $this->webhooksSentBy(function () use (&$revision, &$nid): void {
      $nid = (int) $this->plainPage()->id();
      $revision = $this->savePage($nid, TRUE, FALSE, 'Published in place');
    });
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
  }

  /**
   * Saving a published page again sends nothing, as before.
   *
   * The page was published and is published: no publish step, no event.
   */
  public function testResaveOfPublishedPageSendsNothing(): void {
    $nid = (int) $this->plainPage()->id();
    $this->savePage($nid, TRUE, TRUE, 'Published before the root');
    $sent = $this->webhooksSentBy(function () use ($nid): void {
      $database = $this->container->get('database');
      $root = $database->startTransaction();
      $this->savePage($nid, TRUE, TRUE, 'Saved again');
      unset($root);
    });
    $this->assertSame([], $sent);
  }

  /**
   * Unpublish and publish again in one root: one event, a new revision.
   *
   * The page was published in R0 and is published in another revision when the
   * root commits: the published default revision changed.
   */
  public function testRepublishInOneRootSendsOneEventForTheNewRevision(): void {
    $nid = (int) $this->plainPage()->id();
    $first = $this->savePage($nid, TRUE, TRUE, 'Published before the root');
    $revision = 0;
    $sent = $this->webhooksSentBy(function () use ($nid, &$revision): void {
      $database = $this->container->get('database');
      $root = $database->startTransaction();
      $this->savePage($nid, FALSE, TRUE, 'Unpublished');
      $revision = $this->savePage($nid, TRUE, TRUE, 'Republished');
      unset($root);
    });
    $this->assertNotSame($first, $revision);
    $this->assertCount(1, $sent);
    $this->assertSame($this->webhookId($nid, $revision), $sent[0]['id']);
    $this->assertSame('Republished', $sent[0]['body']['title']);
  }

  /**
   * Each root transaction sends its own event: publish, unpublish, publish.
   */
  public function testEachRootSendsItsOwnEvent(): void {
    $sent = $this->webhooksSentBy(function (): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      foreach ([TRUE, FALSE, TRUE] as $published) {
        $root = $database->startTransaction();
        $this->savePage($nid, $published, TRUE, $published ? 'Published' : 'Unpublished');
        unset($root);
      }
    });
    $this->assertCount(2, $sent);
    $this->assertNotSame($sent[0]['id'], $sent[1]['id']);
  }

  /**
   * An in-place publish, then an in-place save in the same root: final title.
   *
   * Both saves write the same revision; the one event carries what is stored
   * when the root commits, not what the publishing save saw.
   */
  public function testInPlacePublishThenInPlaceSaveSendsTheStoredTitle(): void {
    $sent = $this->webhooksSentBy(function (): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      $root = $database->startTransaction();
      $first = $this->savePage($nid, TRUE, FALSE, 'Published in place');
      $second = $this->savePage($nid, TRUE, FALSE, 'Final stored title');
      $this->assertSame($first, $second);
      unset($root);
    });
    $this->assertCount(1, $sent);
    $this->assertSame('Final stored title', $sent[0]['body']['title']);
  }

  /**
   * The same revision published, unpublished and published again in place.
   *
   * Each publish is its own event with its own webhook-id, so the receiver's
   * idempotency ledger keeps both; a retry of one event keeps its id.
   */
  public function testInPlaceRepublishOfTheSameRevisionGetsAnotherWebhookId(): void {
    $revisions = [];
    $sent = $this->webhooksSentBy(function () use (&$revisions): void {
      $database = $this->container->get('database');
      $nid = (int) $this->plainPage()->id();
      foreach ([TRUE, FALSE, TRUE] as $published) {
        $root = $database->startTransaction();
        $revisions[] = $this->savePage($nid, $published, FALSE, $published ? 'Published' : 'Unpublished');
        unset($root);
      }
    }, 2);
    $this->assertCount(1, array_unique($revisions));
    $this->assertCount(4, $sent);
    // Two deliveries of each event: a retry keeps the id of its event.
    $this->assertSame($sent[0]['id'], $sent[1]['id']);
    $this->assertSame($sent[2]['id'], $sent[3]['id']);
    $this->assertNotSame($sent[0]['id'], $sent[2]['id']);
  }

  /**
   * Creates an unpublished, unmoderated page with an old changed time.
   */
  private function plainPage(?User $owner = NULL): Node {
    $node = Node::create([
      'type' => 'plain', 'langcode' => 'en', 'uid' => ($owner ?? $this->editor)->id(),
      'title' => 'Unpublished page', 'status' => FALSE,
      'field_note' => [['value' => 'Old note']],
      'changed' => $this->oldChanged,
    ]);
    $node->save();
    return $node;
  }

  /**
   * The binding of an unpublished write, from the read.
   */
  private function binding(array $preimage): array {
    return [
      'nid' => $preimage['node_id'],
      'language' => $preimage['language'],
      'expected_default_revision_id' => $preimage['default_revision_id'],
      'expected_latest_revision_id' => $preimage['latest_revision_id'],
      'expected_changed' => $preimage['changed'],
      'workflow_fingerprint' => $preimage['workflow_fingerprint'],
      'preimage_fingerprint' => $preimage['preimage_fingerprint'],
    ];
  }

  /**
   * Requires the named refusal, no save hook and unchanged stored revisions.
   */
  private function assertRefusedBeforeSave(int $nid, callable $operation, string $message): void {
    $before = $this->snapshot($nid);
    ProtectedDraftProbe::arm($nid, 'observe');
    try {
      $operation();
      $this->fail('The unpublished write must refuse.');
    }
    catch (ProtectedDraftRefusal $expected) {
      $this->assertStringContainsString($message, $expected->getMessage(), 'An unrelated refusal must not satisfy this named boundary.');
      $this->assertSame(0, ProtectedDraftProbe::$presaves, 'Refusal must precede an actual node save hook.');
    }
    finally {
      ProtectedDraftProbe::reset();
    }
    $this->assertSame($before, $this->snapshot($nid));
    $this->assertRootFinished();
  }

  /**
   * Reads SQL revision facts and fresh stored translation values.
   */
  private function snapshot(int $nid): array {
    $database = $this->container->get('database');
    $default = (int) $database->select('node', 'n')->fields('n', ['vid'])->condition('nid', $nid)->execute()->fetchField();
    $revisions = (int) $database->select('node_revision', 'r')->condition('nid', $nid)->countQuery()->execute()->fetchField();
    $storage = $this->nodeStorage();
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    $result = ['default' => $default, 'revisions' => $revisions];
    foreach (['published', 'titles', 'notes', 'changed'] as $key) {
      $result[$key] = [];
    }
    $result['marker'] = $node->get('cinatra_test_marker')->getValue();
    $result['image'] = [];
    foreach (array_keys($node->getTranslationLanguages()) as $language) {
      $translation = $node->getTranslation($language);
      $result['published'][$language] = $translation->isPublished();
      $result['titles'][$language] = $translation->label();
      $result['notes'][$language] = $translation->get('field_note')->getValue();
      $result['changed'][$language] = (int) $translation->getChangedTime();
      $result['image'][$language] = $translation->get('field_image')->getValue();
    }
    foreach (['published', 'titles', 'notes', 'changed', 'image'] as $key) {
      ksort($result[$key]);
    }
    return $result;
  }

  /**
   * Returns the node storage.
   */
  private function nodeStorage(): NodeStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Requires the database client and root transaction stack to be idle.
   */
  private function assertRootFinished(): void {
    $database = $this->container->get('database');
    $this->assertFalse($database->inTransaction());
    $this->assertFalse($database->getClientConnection()->inTransaction());
    $this->assertSame(0, $database->transactionManager()->stackDepth());
    if ($database->driver() === 'sqlite') {
      $client = $database->getClientConnection();
      $this->assertNotFalse($client->exec('BEGIN DEFERRED TRANSACTION'));
      $this->assertNotFalse($client->exec('ROLLBACK'));
    }
  }

}
