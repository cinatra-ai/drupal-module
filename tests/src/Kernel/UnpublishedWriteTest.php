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

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language',
    'workflows', 'content_moderation', 'cinatra', 'cinatra_protected_draft_test',
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
    foreach (array_keys($node->getTranslationLanguages()) as $language) {
      $translation = $node->getTranslation($language);
      $result['published'][$language] = $translation->isPublished();
      $result['titles'][$language] = $translation->label();
      $result['notes'][$language] = $translation->get('field_note')->getValue();
      $result['changed'][$language] = (int) $translation->getChangedTime();
    }
    foreach (['published', 'titles', 'notes', 'changed'] as $key) {
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
