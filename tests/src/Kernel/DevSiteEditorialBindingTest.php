<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\cinatra_dev_site\Workflow\EditorialBinder;
use Drupal\cinatra_dev_site_binding_test\FailingState;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\ExportStorageManager;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\workflows\Entity\Workflow;
use Drupal\workflows\WorkflowInterface;

/**
 * Tests the editorial binding of the development site and its ownership.
 *
 * The preflight compares the stored editorial record with core's standard
 * record, but for uuid, _core, langcode, dependencies and the bound entity
 * types. In these cases release and the export remove an owned page binding
 * (same id, uuid and hash as the ownership record) and keep a foreign one,
 * and the export leaves the submodule out of the copy. When bind() binds the
 * page, it writes an intent before its save; after a killed run that left
 * the matching intent, the next bind() that the preflight accepts ends with
 * the binding owned when the record reads back with its uuid, bound and
 * standard.
 *
 * @group cinatra
 */
final class DevSiteEditorialBindingTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'workflows',
    'workflow_type_test',
    'content_moderation',
    'cinatra',
    'cinatra_dev_site',
    'cinatra_dev_site_binding_test',
  ];

  /**
   * A stale ownership record that matches no stored record.
   */
  private const STALE = ['id' => 'editorial', 'uuid' => 'stale-uuid', 'hash' => 'stale-hash'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('CINATRA_DEV_SITE=1');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'content_moderation']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FailingState::$failNext = FALSE;
    FailingState::$failDelete = FALSE;
    FailingState::$writes = [];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    FailingState::$failNext = FALSE;
    FailingState::$failDelete = FALSE;
    FailingState::$writes = [];
    parent::tearDown();
  }

  /**
   * No workflow, core's standard editorial and a bound one all pass.
   */
  public function testPreflightAcceptsTheStandardRecord(): void {
    $this->assertSame([], $this->binder()->preflight());
    $this->createStandardEditorial();
    $stored = $this->stored();
    $this->assertSame('draft', $stored['type_settings']['default_moderation_state']);
    $this->assertSame([], $this->binder()->preflight());
    $this->binder()->bind();
    $this->assertSame(['page'], $this->stored()['type_settings']['entity_types']['node']);
    $this->assertSame([], $this->binder()->preflight());
  }

  /**
   * The keys the preflight leaves out change nothing.
   *
   * @param array $edits
   *   The edits of the stored record.
   *
   * @dataProvider uncomparedEdits
   */
  public function testPreflightLeavesOutTheUncomparedKeys(array $edits): void {
    $this->createStandardEditorial();
    $this->writeRecord(EditorialBinder::CONFIG_NAME, self::edited($this->stored(), $edits));
    $this->assertSame([], $this->binder()->preflight());
  }

  /**
   * Edits of keys the preflight does not compare.
   *
   * @return array<string, array{array}>
   *   The cases.
   */
  public static function uncomparedEdits(): array {
    return [
      'uuid' => [[['set', ['uuid'], 'another-uuid']]],
      'langcode' => [[['set', ['langcode'], 'de']]],
      'dependencies' => [[['set', ['dependencies'], ['module' => ['content_moderation', 'node']]]]],
      'core' => [[['set', ['_core'], ['default_config_hash' => 'abc']]]],
      'entity types' => [[['set', ['type_settings', 'entity_types'], ['node' => ['page']]]]],
    ];
  }

  /**
   * Each listed difference from the standard record refuses, alone.
   *
   * @param array $edits
   *   The edits of the stored record.
   *
   * @dataProvider differences
   */
  public function testPreflightRefusesEachListedDifference(array $edits): void {
    $this->createStandardEditorial();
    $this->writeRecord(EditorialBinder::CONFIG_NAME, self::edited($this->stored(), $edits));
    $this->assertSame(['editorial_mismatch'], $this->binder()->preflight());
  }

  /**
   * Differences from the standard record.
   *
   * @return array<string, array{array}>
   *   The cases.
   */
  public static function differences(): array {
    $retire = ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived', 'weight' => 2];
    $review = ['label' => 'Review', 'weight' => 1, 'published' => FALSE, 'default_revision' => FALSE];
    return [
      'published default' => [[['set', ['type_settings', 'default_moderation_state'], 'published']]],
      'no default' => [[['unset', ['type_settings', 'default_moderation_state']]]],
      'extra state' => [[['set', ['type_settings', 'states', 'review'], $review]]],
      'missing state' => [[['unset', ['type_settings', 'states', 'archived']]]],
      'state label' => [[['set', ['type_settings', 'states', 'draft', 'label'], 'Rough']]],
      'state weight' => [[['set', ['type_settings', 'states', 'draft', 'weight'], 7]]],
      'state weight as text' => [[['set', ['type_settings', 'states', 'draft', 'weight'], '-5']]],
      'state flag' => [[['set', ['type_settings', 'states', 'archived', 'published'], TRUE]]],
      'extra transition' => [[['set', ['type_settings', 'transitions', 'retire'], $retire]]],
      'renamed transition' => [
        [
          ['unset', ['type_settings', 'transitions', 'archive']],
          ['set', ['type_settings', 'transitions', 'retire'], $retire],
        ],
      ],
      'transition label' => [[['set', ['type_settings', 'transitions', 'publish', 'label'], 'Go live']]],
      'transition weight' => [[['set', ['type_settings', 'transitions', 'publish', 'weight'], 9]]],
      'transition from' => [[['set', ['type_settings', 'transitions', 'publish', 'from'], ['draft']]]],
      'transition to' => [[['set', ['type_settings', 'transitions', 'archive', 'to'], 'draft']]],
      'status false' => [[['set', ['status'], FALSE]]],
      'other type' => [[['set', ['type'], 'workflow_type_test']]],
      'workflow label' => [[['set', ['label'], 'Site editorial']]],
      'extra key' => [[['set', ['third_party_settings'], ['other' => ['key' => 1]]]]],
    ];
  }

  /**
   * Other workflows in the preflight, read from the stored records.
   */
  public function testPreflightRefusesOtherWorkflows(): void {
    $this->createStandardEditorial();
    $this->createOtherWorkflow(TRUE);
    $this->assertSame(['page_workflow_conflict:other'], $this->binder()->preflight());
    $this->workflow(EditorialBinder::ID)->delete();
    $this->assertSame(['other_workflow', 'page_workflow_conflict:other'], $this->binder()->preflight());
    $this->removePage($this->workflow('other'));
    $this->assertSame(['other_workflow'], $this->binder()->preflight());

    // A record of another workflow type that lists the page binds nothing.
    $this->createStandardEditorial();
    $record = $this->storage()->read('workflows.workflow.other');
    $record['type'] = 'workflow_type_test';
    $record['type_settings']['entity_types'] = ['node' => ['page']];
    $this->writeRecord('workflows.workflow.other', $record);
    $this->assertSame([], $this->binder()->preflight());
  }

  /**
   * A workflow that only the bundle info names for node:page refuses.
   */
  public function testPreflightReadsTheBundleInfo(): void {
    $this->createStandardEditorial();
    $this->createOtherWorkflow(FALSE);
    $this->assertSame([], $this->binder()->preflight());
    foreach (['other' => ['page_workflow_conflict:other'], 'editorial' => [], 'gone' => []] as $id => $expected) {
      \Drupal::state()->set('cinatra_dev_site_binding_test.workflow', $id);
      $this->container->get('entity_type.bundle.info')->clearCachedBundles();
      $this->assertSame($expected, $this->binder()->preflight(), $id);
    }
    // Without editorial the bundle info still counts.
    $this->workflow(EditorialBinder::ID)->delete();
    \Drupal::state()->set('cinatra_dev_site_binding_test.workflow', 'other');
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->assertSame(['other_workflow', 'page_workflow_conflict:other'], $this->binder()->preflight());
  }

  /**
   * A listed workflow record that cannot be read, or is not an array, refuses.
   *
   * A read double leaves the record out of readMultiple(), or returns a value
   * that is not an array. Such a record could bind the page, so the preflight
   * refuses, and bind() writes nothing.
   */
  public function testPreflightRefusesAnUnreadableWorkflow(): void {
    $this->createStandardEditorial();
    $this->createOtherWorkflow(FALSE);
    $other = 'workflows.workflow.other';
    // The control: the same double with nothing replaced passes.
    $this->assertSame([], $this->binderReading([], [])->preflight());
    foreach (['missing' => FALSE, 'not an array' => 'other'] as $label => $value) {
      $this->assertSame(['unreadable_workflow:other'], $this->binderReading([], [$other => $value])->preflight(), $label);
      // An unreadable editorial is not an absent one: no other_workflow.
      $editorial = [EditorialBinder::CONFIG_NAME => $value];
      $this->assertSame(['unreadable_workflow:editorial'], $this->binderReading([], $editorial)->preflight(), $label);
    }
    $before = $this->stored();
    $this->assertBindThrows('unreadable_workflow:other', $this->binderReading([], [$other => FALSE]));
    $this->assertSame($before, $this->stored());
    $this->assertSame(['editorial', 'other'], $this->workflowIds());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * Without a workflow, bind() creates editorial, binds and owns it.
   */
  public function testBindCreatesAndOwnsEditorial(): void {
    $applied = $this->binder()->bind();
    $this->assertSame(['created' => TRUE, 'bound' => TRUE, 'before' => NULL], $applied);
    $this->assertSame([EditorialBinder::ID], $this->workflowIds());
    $stored = $this->stored();
    $settings = $stored['type_settings'];
    $this->assertSame(['node' => ['page']], $settings['entity_types']);
    unset($settings['entity_types']);
    $expected = EditorialBinder::EDITORIAL;
    unset($expected['entity_types']);
    $this->assertSame(self::sorted($expected), self::sorted($settings));
    $owned = ['id' => EditorialBinder::ID, 'uuid' => $stored['uuid'], 'hash' => EditorialBinder::hash($stored)];
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertTrue($this->binder()->owns($stored));

    // A second call writes nothing.
    $this->assertSame(['created' => FALSE, 'bound' => FALSE, 'before' => $owned], $this->binder()->bind());
    $this->assertSame($stored, $this->stored());
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * An existing unbound editorial is reused and owned.
   */
  public function testBindReusesTheStandardEditorial(): void {
    $uuid = $this->createStandardEditorial()->uuid();
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, self::STALE);
    $applied = $this->binder()->bind();
    $this->assertSame(['created' => FALSE, 'bound' => TRUE, 'before' => self::STALE], $applied);
    $this->assertSame([EditorialBinder::ID], $this->workflowIds());
    $stored = $this->stored();
    $this->assertSame($uuid, $stored['uuid']);
    $this->assertSame(['page'], $stored['type_settings']['entity_types']['node']);
    $this->assertSame(EditorialBinder::hash($stored), \Drupal::state()->get(EditorialBinder::OWNED_KEY)['hash']);
  }

  /**
   * A binding stored before, with no intent in State, is left unowned.
   */
  public function testBindLeavesForeignBindingUnowned(): void {
    $this->addEntityTypeAndBundleToWorkflow($this->createStandardEditorial(), 'node', 'page');
    $before = $this->stored();
    $this->assertSame(['created' => FALSE, 'bound' => FALSE, 'before' => NULL], $this->binder()->bind());
    $this->assertSame($before, $this->stored());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame('foreign_binding', $this->binder()->release(FALSE));
    $this->assertSame($before, $this->stored());
    $exported = $this->export()->read(EditorialBinder::CONFIG_NAME);
    $this->assertSame(self::sorted($before), self::sorted($exported));
  }

  /**
   * A refusing preflight stops bind() before any write.
   */
  public function testBindRefusesBeforeAnyWrite(): void {
    $this->createStandardEditorial();
    $mismatch = self::edited($this->stored(), [['set', ['type_settings', 'default_moderation_state'], 'published']]);
    $this->writeRecord(EditorialBinder::CONFIG_NAME, $mismatch);
    $this->assertBindThrows('editorial_mismatch');
    $this->assertSame($mismatch, $this->stored());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));

    $this->createOtherWorkflow(FALSE);
    $this->workflow(EditorialBinder::ID)->delete();
    $this->assertBindThrows('other_workflow');
    $this->assertSame(['other'], $this->workflowIds());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * A failure of the ownership write after the save is undone.
   *
   * @param bool $existing
   *   Whether editorial exists before bind().
   *
   * @dataProvider undoCases
   */
  public function testBindFailureIsUndone(bool $existing): void {
    $uuid = $existing ? $this->createStandardEditorial()->uuid() : NULL;
    $before = $existing ? self::STALE : NULL;
    if ($before !== NULL) {
      \Drupal::state()->set(EditorialBinder::OWNED_KEY, $before);
    }
    FailingState::$failNext = TRUE;
    $binder = new EditorialBinder(
      $this->storage(),
      $this->container->get('entity_type.manager'),
      new FailingState($this->container->get('state'), $this->storage()),
      $this->container->get('content_moderation.moderation_information'),
    );
    try {
      $binder->bind();
      $this->fail('bind() did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame(FailingState::MESSAGE, $e->getMessage());
    }
    $this->assertFalse(FailingState::$failNext);
    // The intent was written while the page was not bound yet, and the
    // ownership record, which failed, after the save had bound it.
    [$intent, $record] = FailingState::$writes;
    $this->assertSame([TRUE, FALSE], [$intent[0]['intent'] ?? NULL, $intent[1]]);
    $this->assertSame([TRUE, TRUE], [is_string($record[0]['hash'] ?? NULL), $record[1]]);
    $this->assertSame($before, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    if (!$existing) {
      $this->assertSame([], $this->workflowIds());
      return;
    }
    $this->assertSame($uuid, $this->stored()['uuid']);
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);
  }

  /**
   * The undo cases.
   *
   * @return array<string, array{bool}>
   *   The cases.
   */
  public static function undoCases(): array {
    return ['created' => [FALSE], 'reused' => [TRUE]];
  }

  /**
   * A failing undo keeps the original failure as the previous exception.
   *
   * The State double fails the ownership write of a created editorial, and
   * then the State delete of undo(). bind() throws an exception that names
   * the undo failure, with the original failure as its previous exception.
   * The created editorial is deleted again; the intent stays.
   */
  public function testFailingUndoKeepsTheOriginalFailure(): void {
    FailingState::$failNext = TRUE;
    FailingState::$failDelete = TRUE;
    $binder = new EditorialBinder(
      $this->storage(),
      $this->container->get('entity_type.manager'),
      new FailingState($this->container->get('state'), $this->storage()),
      $this->container->get('content_moderation.moderation_information'),
    );
    try {
      $binder->bind();
      $this->fail('bind() did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('its undo failed too: ' . FailingState::DELETE_MESSAGE, $e->getMessage());
      $this->assertSame(FailingState::MESSAGE, $e->getPrevious()?->getMessage());
    }
    $this->assertSame([FALSE, FALSE], [FailingState::$failNext, FailingState::$failDelete]);
    $this->assertSame([], $this->workflowIds());
    $intent = FailingState::$writes[0][0];
    $this->assertTrue($intent['intent'] ?? NULL);
    $this->assertSame($intent, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * A stored record that does not read back with its uuid is not recorded.
   *
   * After its save, or when it completes an intent, bind() reads the stored
   * record again. A read double returns no record, or the bound standard
   * record without its uuid or with another uuid. A bind() that bound the
   * page throws and undoes its write, State included, for a reused and for
   * a created editorial; a bind() that completes an intent throws and the
   * intent stays.
   *
   * @param string $readBack
   *   What that read returns.
   *
   * @dataProvider readBacks
   */
  public function testBindNeedsTheRecordReadBack(string $readBack): void {
    $uuid = $this->createStandardEditorial()->uuid();
    // The bound standard record, as bind() stores it.
    $this->binder()->bind();
    $bound = $this->stored();
    $this->assertSame('unbound', $this->binder()->release(FALSE));
    $values = [
      'unreadable' => FALSE,
      'no uuid' => array_diff_key($bound, ['uuid' => TRUE]),
      'another uuid' => ['uuid' => 'another-uuid'] + $bound,
    ];
    $binder = $this->binderReading([EditorialBinder::CONFIG_NAME => $values[$readBack]], []);
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, self::STALE);
    $this->assertBindThrows('did not read back', $binder);
    $this->assertSame($uuid, $this->stored()['uuid']);
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);
    $this->assertSame(self::STALE, \Drupal::state()->get(EditorialBinder::OWNED_KEY));

    // Completing an intent: the intent and the binding stay.
    $this->addEntityTypeAndBundleToWorkflow($this->workflow(EditorialBinder::ID), 'node', 'page');
    $before = $this->stored();
    $intent = ['id' => EditorialBinder::ID, 'uuid' => $uuid, 'intent' => TRUE];
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $intent);
    $this->assertBindThrows('did not read back', $binder);
    $this->assertSame($intent, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame($before, $this->stored());

    // A created editorial is deleted again.
    $this->workflow(EditorialBinder::ID)->delete();
    \Drupal::state()->delete(EditorialBinder::OWNED_KEY);
    $this->assertBindThrows('did not read back', $binder);
    $this->assertSame([], $this->workflowIds());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * The read-back cases.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function readBacks(): array {
    return ['unreadable' => ['unreadable'], 'no uuid' => ['no uuid'], 'another uuid' => ['another uuid']];
  }

  /**
   * The read back must be the standard record, bound, with its uuid.
   *
   * A read double returns the record of the same uuid, but unbound (as after
   * a save that did not persist) or bound with another label (as after a
   * second writer). A bind() that bound the page throws and undoes its
   * write. A bind() that completes an intent, with the record read back
   * unbound, throws and the intent stays. The control: the double returning
   * the bound standard record.
   */
  public function testBindNeedsTheBoundStandardRecordReadBack(): void {
    $uuid = $this->createStandardEditorial()->uuid();
    $unbound = $this->stored();
    $this->binder()->bind();
    $bound = $this->stored();
    $this->assertSame('unbound', $this->binder()->release(FALSE));
    foreach (['unbound' => $unbound, 'another label' => ['label' => 'Site editorial'] + $bound] as $label => $value) {
      \Drupal::state()->set(EditorialBinder::OWNED_KEY, self::STALE);
      $this->assertBindThrows('did not read back', $this->binderReading([EditorialBinder::CONFIG_NAME => $value], []));
      $this->assertSame([], $this->stored()['type_settings']['entity_types'], $label);
      $this->assertSame(self::STALE, \Drupal::state()->get(EditorialBinder::OWNED_KEY), $label);
    }
    // The control: the bound standard record read back is owned.
    $this->binderReading([EditorialBinder::CONFIG_NAME => $bound], [])->bind();
    $this->assertTrue($this->binder()->owns($bound));

    // Completing an intent: the intent stays.
    $intent = ['id' => EditorialBinder::ID, 'uuid' => $uuid, 'intent' => TRUE];
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $intent);
    $this->assertBindThrows('did not read back', $this->binderReading([EditorialBinder::CONFIG_NAME => $unbound], []));
    $this->assertSame($intent, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame(['page'], $this->stored()['type_settings']['entity_types']['node']);
  }

  /**
   * An intent whose record does not read back stays for the next bind().
   */
  public function testIntentStaysWhenTheRecordDoesNotReadBack(): void {
    $workflow = $this->createStandardEditorial();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $intent = ['id' => EditorialBinder::ID, 'uuid' => $workflow->uuid(), 'intent' => TRUE];
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $intent);
    $bound = $this->stored();
    $this->assertBindThrows('did not read back', $this->binderReading([EditorialBinder::CONFIG_NAME => FALSE], []));
    $this->assertSame($intent, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame($bound, $this->stored());
    // The control: with the record read back, the next bind() completes it.
    $this->binder()->bind();
    $this->assertTrue($this->binder()->owns($this->stored()));
  }

  /**
   * Undo after a bind() that created, reused or bound nothing.
   *
   * After a bind() that created editorial, undo() deletes it. After one that
   * bound a reused editorial, it removes the page binding and keeps the
   * record. Both times State has no ownership value then. After a bind() that
   * bound nothing (the page was bound already), the stored record stays.
   */
  public function testUndoAfterABindThatCreatedReusedOrBoundNothing(): void {
    $this->binder()->undo($this->binder()->bind());
    $this->assertSame([], $this->workflowIds());
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));

    $uuid = $this->createStandardEditorial()->uuid();
    $this->binder()->undo($this->binder()->bind());
    $this->assertSame($uuid, $this->stored()['uuid']);
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));

    // A call that bound nothing: undo() returns at once.
    $this->addEntityTypeAndBundleToWorkflow($this->workflow(EditorialBinder::ID), 'node', 'page');
    $before = $this->stored();
    $this->binder()->undo($this->binder()->bind());
    $this->assertSame($before, $this->stored());
  }

  /**
   * The intent of a killed bind() is completed by the next bind().
   *
   * A kill is played by writing what it leaves. Before the save: the intent
   * only. After the save: the stored binding and the intent, with no
   * ownership record. In both cases the next bind() ends with the binding
   * owned. After the kill after the save, undo() of the next call keeps the
   * ownership record.
   */
  public function testIntentOfAKilledBindIsCompleted(): void {
    $workflow = $this->createStandardEditorial();
    $intent = ['id' => EditorialBinder::ID, 'uuid' => $workflow->uuid(), 'intent' => TRUE];

    // Killed before the save: the next bind() binds and owns as usual.
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $intent);
    $this->assertSame(['created' => FALSE, 'bound' => TRUE, 'before' => $intent], $this->binder()->bind());
    $this->assertTrue($this->binder()->owns($this->stored()));
    $this->assertSame('unbound', $this->binder()->release(FALSE));

    // Killed after the save: until the next bind() the binding is not owned,
    // so release keeps it.
    $this->addEntityTypeAndBundleToWorkflow($this->workflow(EditorialBinder::ID), 'node', 'page');
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $intent);
    $bound = $this->stored();
    $this->assertFalse($this->binder()->owns($bound));
    $this->assertSame('foreign_binding', $this->binder()->release(FALSE));
    $applied = $this->binder()->bind();
    $this->assertSame(['created' => FALSE, 'bound' => FALSE, 'before' => $intent], $applied);
    $this->assertSame($bound, $this->stored());
    $owned = ['id' => EditorialBinder::ID, 'uuid' => $bound['uuid'], 'hash' => EditorialBinder::hash($bound)];
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->binder()->undo($applied);
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame($bound, $this->stored());
    $this->assertSame('unbound', $this->binder()->release(FALSE));
  }

  /**
   * An intent of another record, or of another shape, completes nothing.
   */
  public function testOtherIntentsAreNotCompleted(): void {
    $workflow = $this->createStandardEditorial();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $bound = $this->stored();
    $uuid = $workflow->uuid();
    $others = [
      'other uuid' => ['id' => EditorialBinder::ID, 'uuid' => 'other-uuid', 'intent' => TRUE],
      'other id' => ['id' => 'other', 'uuid' => $uuid, 'intent' => TRUE],
      'intent not TRUE' => ['id' => EditorialBinder::ID, 'uuid' => $uuid, 'intent' => 1],
      'extra key' => ['id' => EditorialBinder::ID, 'uuid' => $uuid, 'intent' => TRUE, 'hash' => NULL],
    ];
    foreach ($others as $label => $value) {
      \Drupal::state()->set(EditorialBinder::OWNED_KEY, $value);
      $this->assertSame(['created' => FALSE, 'bound' => FALSE, 'before' => $value], $this->binder()->bind(), $label);
      $this->assertSame($value, \Drupal::state()->get(EditorialBinder::OWNED_KEY), $label);
      $this->assertSame('foreign_binding', $this->binder()->release(FALSE), $label);
      $this->assertSame($bound, $this->stored(), $label);
    }
  }

  /**
   * Only a whole ownership record of the right shape owns.
   */
  public function testOwnershipNeedsTheWholeRecord(): void {
    $this->binder()->bind();
    $stored = $this->stored();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    $this->assertTrue($this->binder()->owns($stored));
    // A record that is not an array, or has no uuid, is never owned.
    $this->assertFalse($this->binder()->owns(FALSE));
    $no_uuid = $stored;
    unset($no_uuid['uuid']);
    $this->assertFalse($this->binder()->owns($no_uuid));
    $values = [
      'a string' => 'owned',
      'an empty list' => [],
      'no hash' => ['id' => $owned['id'], 'uuid' => $owned['uuid']],
      'hash not a string' => ['hash' => 0] + $owned,
      'uuid not a string' => ['uuid' => 0] + $owned,
      'other id' => ['id' => 'other'] + $owned,
      'other hash' => ['hash' => hash('sha256', 'other')] + $owned,
      'an extra key' => $owned + ['intent' => TRUE],
    ];
    foreach ($values as $label => $value) {
      \Drupal::state()->set(EditorialBinder::OWNED_KEY, $value);
      $this->assertFalse($this->binder()->owns($stored), $label);
      $this->assertSame('foreign_binding', $this->binder()->release(FALSE), $label);
      $this->assertSame($stored, $this->stored(), $label);
      $exported = $this->export()->read(EditorialBinder::CONFIG_NAME);
      $this->assertSame(self::sorted($stored), self::sorted($exported), $label);
    }
    // The control: the record itself owns again.
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, $owned);
    $this->assertSame('unbound', $this->binder()->release(FALSE));
  }

  /**
   * Release removes the owned binding and then the ownership record.
   */
  public function testReleaseRemovesTheOwnedBinding(): void {
    $this->createStandardEditorial();
    $unbound = $this->stored();
    $this->binder()->bind();
    $this->assertSame('unbound', $this->binder()->release(FALSE));
    $this->assertSame(self::sorted($unbound), self::sorted($this->stored()));
    $this->assertNull(\Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $this->assertSame('none', $this->binder()->release(FALSE));
  }

  /**
   * Release keeps the ownership record while the page reads back bound.
   *
   * A read double returns the owned bound record also after the release
   * save, as after a save that did not persist or a writer that bound the
   * page again: release() throws and the ownership record stays.
   */
  public function testReleaseKeepsTheOwnershipWhileThePageReadsBound(): void {
    $this->binder()->bind();
    $bound = $this->stored();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    try {
      $this->binderReading([EditorialBinder::CONFIG_NAME => $bound], [])->release(FALSE);
      $this->fail('release() did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('still binds node:page', $e->getMessage());
    }
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    // The save itself went through: only the read back still binds.
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);
  }

  /**
   * Release with nothing bound writes nothing and keeps the record.
   */
  public function testReleaseWithoutBinding(): void {
    $this->assertSame('none', $this->binder()->release(FALSE));
    $this->createStandardEditorial();
    \Drupal::state()->set(EditorialBinder::OWNED_KEY, self::STALE);
    $before = $this->stored();
    $this->assertSame('none', $this->binder()->release(FALSE));
    $this->assertSame($before, $this->stored());
    $this->assertSame(self::STALE, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * An editorial deleted and recreated, bound, with another uuid is foreign.
   */
  public function testRecreatedEditorialIsForeign(): void {
    $this->binder()->bind();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    $this->workflow(EditorialBinder::ID)->delete();
    $this->addEntityTypeAndBundleToWorkflow($this->createStandardEditorial(), 'node', 'page');
    $this->assertNotSame($owned['uuid'], $this->stored()['uuid']);
    $this->assertKeptAsForeign($owned);
  }

  /**
   * Release keeps the binding of a workflow recreated after its read.
   *
   * A read double plays the race: release() reads the owned record, but the
   * workflow it then loads was deleted and recreated with a page binding.
   */
  public function testReleaseKeepsAWorkflowRecreatedAfterItsRead(): void {
    $this->binder()->bind();
    $read = $this->stored();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    $this->workflow(EditorialBinder::ID)->delete();
    $this->addEntityTypeAndBundleToWorkflow($this->createStandardEditorial(), 'node', 'page');
    $recreated = $this->stored();
    $this->assertNotSame($read['uuid'], $recreated['uuid']);
    $binder = $this->binderReading([EditorialBinder::CONFIG_NAME => $read], []);
    $this->assertTrue($binder->owns($read));
    $this->assertSame('foreign_binding', $binder->release(FALSE));
    $this->assertSame($recreated, $this->stored());
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
  }

  /**
   * An owned record edited since (a label, another bundle) is foreign.
   *
   * @param string $edit
   *   The edit.
   *
   * @dataProvider ownedEdits
   */
  public function testEditedOwnedRecordIsForeign(string $edit): void {
    $this->binder()->bind();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    $workflow = $this->workflow(EditorialBinder::ID);
    if ($edit === 'label') {
      $workflow->set('label', 'Editorial edited')->save();
    }
    else {
      NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
      $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    }
    $this->assertSame($owned['uuid'], $this->stored()['uuid']);
    $this->assertKeptAsForeign($owned);
  }

  /**
   * Edits of an owned record.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function ownedEdits(): array {
    return ['label' => ['label'], 'another bundle' => ['bundle']];
  }

  /**
   * A syncing release writes nothing and keeps the ownership record.
   */
  public function testSyncingReleaseKeepsTheRecord(): void {
    $this->binder()->bind();
    $owned = \Drupal::state()->get(EditorialBinder::OWNED_KEY);
    $before = $this->stored();
    $this->assertSame('syncing', $this->binder()->release(TRUE));
    $this->assertSame($before, $this->stored());
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    // A later release still finds the binding owned.
    $this->assertSame('unbound', $this->binder()->release(FALSE));
  }

  /**
   * The export leaves out the submodule and the owned binding.
   */
  public function testExportLeavesOutTheSubmoduleAndTheOwnedBinding(): void {
    $export = $this->export();
    $this->assertArrayNotHasKey('cinatra_dev_site', $export->read('core.extension')['module']);
    $this->assertArrayHasKey('cinatra_dev_site_binding_test', $export->read('core.extension')['module']);
    $this->assertFalse($export->read(EditorialBinder::CONFIG_NAME));

    $this->createStandardEditorial();
    $unbound = $this->stored();
    $this->binder()->bind();
    $bound = $this->stored();
    $this->assertContains('node.type.page', $bound['dependencies']['config']);
    $exported = $this->export()->read(EditorialBinder::CONFIG_NAME);
    $this->assertSame(self::sorted($unbound), self::sorted($exported));
    // The active storage keeps both.
    $this->assertSame($bound, $this->stored());
    $this->assertArrayHasKey('cinatra_dev_site', $this->storage()->read('core.extension')['module']);
  }

  /**
   * The export and release take only the page out of an owned record.
   *
   * The preflight leaves the bound entity types out, so editorial may bind
   * another bundle too; that bundle stays in the export and after release.
   */
  public function testOtherBundlesStayInTheExportAndAfterRelease(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->addEntityTypeAndBundleToWorkflow($this->createStandardEditorial(), 'node', 'article');
    $this->assertSame([], $this->binder()->preflight());
    $article = $this->stored();
    $this->binder()->bind();
    $bound = $this->stored();
    $this->assertSame(['article', 'page'], $bound['type_settings']['entity_types']['node']);
    $this->assertTrue($this->binder()->owns($bound));
    $exported = $this->export()->read(EditorialBinder::CONFIG_NAME);
    $this->assertSame(['article'], $exported['type_settings']['entity_types']['node']);
    $this->assertSame(['node.type.article'], $exported['dependencies']['config']);
    $this->assertSame('unbound', $this->binder()->release(FALSE));
    $this->assertSame(self::sorted($article), self::sorted($this->stored()));
  }

  /**
   * The transform leaves alone a copy without editorial and the submodule.
   *
   * The copy holds no editorial record, and no extension list or one whose
   * module list is missing.
   */
  public function testExportTransformOfACopyWithoutThem(): void {
    $copy = new MemoryStorage();
    $this->binder()->onExportTransform(new StorageTransformEvent($copy));
    $this->assertSame([], $copy->listAll());
    $copy->write('core.extension', ['theme' => ['stark' => 0]]);
    $this->binder()->onExportTransform(new StorageTransformEvent($copy));
    $this->assertSame(['core.extension'], $copy->listAll());
    $this->assertSame(['theme' => ['stark' => 0]], $copy->read('core.extension'));
  }

  /**
   * The transform throws when the copy refuses one of its writes.
   *
   * A copy double returns FALSE for one name and writes nothing then. The two
   * writes are not atomic: a refused editorial write leaves the extension list
   * that the transform wrote first. The control: the same double, refusing
   * nothing, ends with both left out.
   */
  public function testExportTransformThrowsWhenTheCopyRefusesAWrite(): void {
    $this->binder()->bind();
    $copy = new class() extends MemoryStorage {

      /**
       * The name whose write returns FALSE.
       *
       * @var string|null
       */
      public ?string $refused = NULL;

      /**
       * {@inheritdoc}
       */
      public function write($name, array $data) {
        return $name === $this->refused ? FALSE : parent::write($name, $data);
      }

    };
    $names = ['core.extension', EditorialBinder::CONFIG_NAME];
    foreach ($names as $name) {
      $copy->write($name, $this->storage()->read($name));
    }
    foreach ($names as $name) {
      $copy->refused = $name;
      try {
        $this->binder()->onExportTransform(new StorageTransformEvent($copy));
        $this->fail('The transform did not throw.');
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('refused the write of ' . $name, $e->getMessage());
      }
    }
    // After the refused editorial write, the copy no longer lists the
    // submodule but keeps the binding; the active storage keeps both.
    $this->assertArrayNotHasKey('cinatra_dev_site', $copy->read('core.extension')['module']);
    $this->assertContains('page', $copy->read(EditorialBinder::CONFIG_NAME)['type_settings']['entity_types']['node']);
    $this->assertArrayHasKey('cinatra_dev_site', $this->storage()->read('core.extension')['module']);
    $this->assertContains('page', $this->stored()['type_settings']['entity_types']['node']);
    $copy->refused = NULL;
    $this->binder()->onExportTransform(new StorageTransformEvent($copy));
    $this->assertArrayNotHasKey('cinatra_dev_site', $copy->read('core.extension')['module']);
    $this->assertArrayNotHasKey('node', $copy->read(EditorialBinder::CONFIG_NAME)['type_settings']['entity_types']);
  }

  /**
   * An unmarked import that binds node:page is refused; a marked one passes.
   */
  public function testImportGuardRefusesAnUnmarkedBinding(): void {
    putenv('CINATRA_DEV_SITE');
    $this->createStandardEditorial();
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->storage(), $sync);
    $data = $sync->read(EditorialBinder::CONFIG_NAME);
    $data['type_settings']['entity_types']['node'] = ['page'];
    $data['dependencies']['config'] = ['node.type.page'];
    $sync->write(EditorialBinder::CONFIG_NAME, $data);

    $errors = $this->importSync();
    $this->assertStringContainsString(EditorialBinder::IMPORT_GUARD_TEXT, implode("\n", $errors));
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);

    putenv('CINATRA_DEV_SITE=1');
    $errors = $this->importSync();
    $this->assertSame([], $errors, implode("\n", $errors));
    $this->assertSame(['page'], $this->stored()['type_settings']['entity_types']['node']);
  }

  /**
   * An unmarked import that creates editorial bound to node:page is refused.
   */
  public function testImportGuardRefusesANewBinding(): void {
    putenv('CINATRA_DEV_SITE');
    $this->createStandardEditorial();
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->storage(), $sync);
    $data = $sync->read(EditorialBinder::CONFIG_NAME);
    $data['type_settings']['entity_types']['node'] = ['page'];
    $data['dependencies']['config'] = ['node.type.page'];
    $sync->write(EditorialBinder::CONFIG_NAME, $data);
    $this->workflow(EditorialBinder::ID)->delete();

    $errors = $this->importSync();
    $this->assertStringContainsString(EditorialBinder::IMPORT_GUARD_TEXT, implode("\n", $errors));
    $this->assertSame([], $this->workflowIds());
  }

  /**
   * A stored record of another workflow type does not bind the page.
   *
   * The stored record lists node:page but is not a content moderation
   * workflow, so an unmarked syncing save that binds the page is refused.
   */
  public function testImportGuardReadsTheStoredType(): void {
    putenv('CINATRA_DEV_SITE');
    $this->createStandardEditorial();
    $workflow = $this->workflow(EditorialBinder::ID);
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'page');
    // The serialized settings bind the page too, as in an imported record.
    $workflow->set('type_settings', $workflow->getTypePlugin()->getConfiguration());
    $record = $this->stored();
    $record['type'] = 'workflow_type_test';
    $record['type_settings']['entity_types'] = ['node' => ['page']];
    $this->writeRecord(EditorialBinder::CONFIG_NAME, $record);
    $workflow->setSyncing(TRUE);
    $refused = NULL;
    try {
      $workflow->save();
    }
    catch (\RuntimeException $e) {
      $refused = $e->getMessage();
    }
    $this->assertSame(EditorialBinder::IMPORT_GUARD_TEXT, $refused);
    $this->assertSame($record, $this->stored());
  }

  /**
   * These other saves pass the import guard.
   */
  public function testImportGuardLeavesOtherSaves(): void {
    putenv('CINATRA_DEV_SITE');
    $workflow = $this->createStandardEditorial();
    // A save that is not syncing is not refused.
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $this->assertSame(['page'], $this->stored()['type_settings']['entity_types']['node']);
    // A syncing save whose stored record already binds the page is not
    // refused.
    $stored = $this->workflow(EditorialBinder::ID);
    $stored->set('label', 'Editorial resynced');
    $stored->setSyncing(TRUE);
    $stored->save();
    $this->assertSame('Editorial resynced', $this->stored()['label']);
    // A syncing save of another workflow type is not refused.
    $test = Workflow::create(['id' => 'test', 'label' => 'Test', 'type' => 'workflow_type_test']);
    $test->setSyncing(TRUE);
    $test->save();
    $this->assertContains('test', $this->workflowIds());
    // A syncing save of a moderation workflow without the page is not refused.
    $other = $this->workflow(EditorialBinder::ID)->createDuplicate();
    $other->set('id', 'other');
    $this->removePage($other, FALSE);
    $other->setSyncing(TRUE);
    $other->save();
    $this->assertContains('other', $this->workflowIds());
  }

  /**
   * Asserts that release and the export keep a binding that is foreign.
   *
   * @param array $owned
   *   The ownership record before.
   */
  private function assertKeptAsForeign(array $owned): void {
    $before = $this->stored();
    $this->assertFalse($this->binder()->owns($before));
    $this->assertSame('foreign_binding', $this->binder()->release(FALSE));
    $this->assertSame($before, $this->stored());
    $this->assertSame($owned, \Drupal::state()->get(EditorialBinder::OWNED_KEY));
    $exported = $this->export()->read(EditorialBinder::CONFIG_NAME);
    $this->assertSame(self::sorted($before), self::sorted($exported));
  }

  /**
   * Asserts that bind() throws and names a code or a text.
   *
   * @param string $code
   *   The refusal code, or a part of the message.
   * @param \Drupal\cinatra_dev_site\Workflow\EditorialBinder|null $binder
   *   The binder; the service when NULL.
   */
  private function assertBindThrows(string $code, ?EditorialBinder $binder = NULL): void {
    try {
      ($binder ?? $this->binder())->bind();
      $this->fail('bind() did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString($code, $e->getMessage());
    }
  }

  /**
   * Creates core's standard editorial from the standard profile's file.
   *
   * @return \Drupal\workflows\WorkflowInterface
   *   The saved workflow.
   */
  private function createStandardEditorial(): WorkflowInterface {
    $path = $this->root . '/core/profiles/standard/config/optional/' . EditorialBinder::CONFIG_NAME . '.yml';
    $this->assertFileExists($path);
    $workflow = Workflow::create(Yaml::decode((string) file_get_contents($path)));
    $workflow->save();
    return $workflow;
  }

  /**
   * Creates a copy of the stored editorial named other.
   *
   * @param bool $bound
   *   Whether other binds node:page.
   */
  private function createOtherWorkflow(bool $bound): void {
    $other = $this->workflow(EditorialBinder::ID)->createDuplicate();
    $other->set('id', 'other');
    $other->set('label', 'Other');
    $other->save();
    if ($bound) {
      $this->addEntityTypeAndBundleToWorkflow($other, 'node', 'page');
    }
    $this->assertSame([], $this->stored()['type_settings']['entity_types']);
  }

  /**
   * Removes node:page from a workflow.
   *
   * @param \Drupal\workflows\WorkflowInterface $workflow
   *   The workflow.
   * @param bool $save
   *   Whether to save it.
   */
  private function removePage(WorkflowInterface $workflow, bool $save = TRUE): void {
    $workflow->getTypePlugin()->removeEntityTypeAndBundle('node', 'page');
    if ($save) {
      $workflow->save();
    }
  }

  /**
   * Writes a raw record to the active storage and resets the caches.
   *
   * @param string $name
   *   The configuration name.
   * @param array $record
   *   The record.
   */
  private function writeRecord(string $name, array $record): void {
    $this->storage()->write($name, $record);
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
  }

  /**
   * Builds a fresh export storage, as a configuration export does.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The transformed export copy.
   */
  private function export(): StorageInterface {
    $manager = new ExportStorageManager(
      $this->storage(),
      $this->container->get('database'),
      $this->container->get('event_dispatcher'),
      $this->container->get('lock'),
    );
    return $manager->getStorage();
  }

  /**
   * Imports the sync storage and returns the importer's errors.
   *
   * @return string[]
   *   The logged import errors.
   */
  private function importSync(): array {
    $importer = $this->configImporter();
    try {
      $importer->import();
    }
    catch (ConfigImporterException $exception) {
      // The importer logs its errors; they are read below.
    }
    $this->container->get('config.factory')->reset();
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    return array_map('strval', $importer->getErrors());
  }

  /**
   * Returns a binder whose configuration storage replaces some reads.
   *
   * @param array $read
   *   The values read() returns instead, by configuration name.
   * @param array $multiple
   *   The values readMultiple() returns instead, by configuration name; FALSE
   *   leaves the name out, as for a record that cannot be read.
   *
   * @return \Drupal\cinatra_dev_site\Workflow\EditorialBinder
   *   The binder. It writes nothing to that storage; its writes go to the
   *   site's State and workflow storage.
   */
  private function binderReading(array $read, array $multiple): EditorialBinder {
    $storage = new class($this->container->get('database'), 'config') extends DatabaseStorage {

      /**
       * The values read() returns instead.
       *
       * @var array
       */
      public array $read = [];

      /**
       * The values readMultiple() returns instead.
       *
       * @var array
       */
      public array $multiple = [];

      /**
       * {@inheritdoc}
       */
      public function read($name) {
        return array_key_exists($name, $this->read) ? $this->read[$name] : parent::read($name);
      }

      /**
       * {@inheritdoc}
       */
      public function readMultiple(array $names) {
        $records = parent::readMultiple($names);
        foreach (array_intersect_key($this->multiple, array_flip($names)) as $name => $value) {
          if ($value === FALSE) {
            unset($records[$name]);
          }
          else {
            $records[$name] = $value;
          }
        }
        return $records;
      }

    };
    $storage->read = $read;
    $storage->multiple = $multiple;
    return new EditorialBinder(
      $storage,
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
      $this->container->get('content_moderation.moderation_information'),
    );
  }

  /**
   * Returns the binder service.
   *
   * @return \Drupal\cinatra_dev_site\Workflow\EditorialBinder
   *   The binder.
   */
  private function binder(): EditorialBinder {
    return $this->container->get('cinatra_dev_site.editorial_binder');
  }

  /**
   * Returns the active configuration storage.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage.
   */
  private function storage(): StorageInterface {
    return $this->container->get('config.storage');
  }

  /**
   * Reads the stored editorial record.
   *
   * @return array
   *   The record.
   */
  private function stored(): array {
    $record = $this->storage()->read(EditorialBinder::CONFIG_NAME);
    $this->assertIsArray($record);
    return $record;
  }

  /**
   * Loads a workflow afresh.
   *
   * @param string $id
   *   The workflow id.
   *
   * @return \Drupal\workflows\WorkflowInterface
   *   The workflow.
   */
  private function workflow(string $id): WorkflowInterface {
    $workflow = $this->container->get('entity_type.manager')->getStorage('workflow')->loadUnchanged($id);
    $this->assertInstanceOf(WorkflowInterface::class, $workflow);
    return $workflow;
  }

  /**
   * Lists the stored workflow ids.
   *
   * @return string[]
   *   The sorted ids.
   */
  private function workflowIds(): array {
    $prefix = 'workflows.workflow.';
    $ids = array_map(static fn(string $name): string => substr($name, strlen($prefix)), $this->storage()->listAll($prefix));
    sort($ids);
    return $ids;
  }

  /**
   * Applies edits to a record.
   *
   * @param array $record
   *   The record.
   * @param array $edits
   *   Each edit is ['set', path, value] or ['unset', path].
   *
   * @return array
   *   The edited record.
   */
  private static function edited(array $record, array $edits): array {
    foreach ($edits as $edit) {
      if ($edit[0] === 'unset') {
        NestedArray::unsetValue($record, $edit[1]);
      }
      else {
        NestedArray::setValue($record, $edit[1], $edit[2], TRUE);
      }
    }
    return $record;
  }

  /**
   * Sorts the keys of an array at every level.
   *
   * @param array $data
   *   The array.
   *
   * @return array
   *   The sorted array.
   */
  private static function sorted(array $data): array {
    ksort($data);
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = self::sorted($value);
      }
    }
    return $data;
  }

}
