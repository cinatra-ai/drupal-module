<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Workflow;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\workflows\WorkflowInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Binds node:page to core's editorial workflow and records its ownership.
 *
 * The preflight compares the stored editorial record with core's standard
 * one, but for uuid, _core, langcode, dependencies and the bound entity
 * types. When bind() binds the page, it writes an intent before its save
 * and, when the record reads back with the saved uuid, bound and standard,
 * the ownership record after it; when it finds the page bound, it saves
 * nothing and writes at most the ownership record that completes an
 * intent. Release and the export remove the binding only when the record
 * they read (the stored one, or the export copy's) has the owned id, uuid
 * and hash. The export also leaves the submodule out of the copy's
 * extension list when that list names it; the active storage keeps both.
 */
final class EditorialBinder implements EventSubscriberInterface {

  /**
   * The workflow id.
   */
  public const ID = 'editorial';

  /**
   * The configuration name of the workflow.
   */
  public const CONFIG_NAME = 'workflows.workflow.editorial';

  /**
   * The State key of the ownership record (id, uuid, hash) or the intent.
   */
  public const OWNED_KEY = 'cinatra_dev_site.owned_binding';

  /**
   * The refusal of an imported node:page binding without the marker.
   */
  public const IMPORT_GUARD_TEXT = 'cinatra_dev_site: a configuration import may not bind node:page to a workflow on a site without the development marker.';

  /**
   * Core's standard editorial type settings, with no entity type bound.
   */
  public const EDITORIAL = [
    'states' => [
      'archived' => ['label' => 'Archived', 'weight' => 5, 'published' => FALSE, 'default_revision' => TRUE],
      'draft' => ['label' => 'Draft', 'weight' => -5, 'published' => FALSE, 'default_revision' => FALSE],
      'published' => ['label' => 'Published', 'weight' => 0, 'published' => TRUE, 'default_revision' => TRUE],
    ],
    'transitions' => [
      'archive' => ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived', 'weight' => 2],
      'archived_draft' => ['label' => 'Restore to Draft', 'from' => ['archived'], 'to' => 'draft', 'weight' => 3],
      'archived_published' => ['label' => 'Restore', 'from' => ['archived'], 'to' => 'published', 'weight' => 4],
      'create_new_draft' => [
        'label' => 'Create New Draft',
        'from' => ['draft', 'published'],
        'to' => 'draft',
        'weight' => 0,
      ],
      'publish' => ['label' => 'Publish', 'from' => ['draft', 'published'], 'to' => 'published', 'weight' => 1],
    ],
    'entity_types' => [],
    'default_moderation_state' => 'draft',
  ];

  /**
   * The expected record; a missing editorial is created from it.
   *
   * It has no uuid, _core, langcode or dependencies. Its type settings hold
   * an empty entity_types list, which compared() leaves out.
   */
  private const EXPECTED = [
    'status' => TRUE,
    'id' => self::ID,
    'label' => 'Editorial',
    'type' => 'content_moderation',
    'type_settings' => self::EDITORIAL,
  ];

  /**
   * Constructs the binder.
   *
   * @param \Drupal\Core\Config\StorageInterface $configStorage
   *   The active configuration storage.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   The moderation information service.
   */
  public function __construct(
    private readonly StorageInterface $configStorage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly ModerationInformationInterface $moderationInformation,
  ) {
  }

  /**
   * Collects the workflow refusals. It writes no configuration and no State.
   *
   * @return string[]
   *   The codes, in this order: editorial_mismatch or other_workflow, then
   *   page_workflow_conflict:<id> for each other workflow on node:page, then
   *   unreadable_workflow:<id> for each listed record that is not an array.
   */
  public function preflight(): array {
    $refused = [];
    $names = $this->configStorage->listAll('workflows.workflow.');
    $records = $this->configStorage->readMultiple($names);
    if (is_array($records[self::CONFIG_NAME] ?? NULL)) {
      if (self::compared($records[self::CONFIG_NAME]) !== self::compared(self::EXPECTED)) {
        $refused[] = 'editorial_mismatch';
      }
    }
    elseif ($names !== [] && !in_array(self::CONFIG_NAME, $names, TRUE)) {
      $refused[] = 'other_workflow';
    }
    $conflicts = [];
    $unreadable = [];
    foreach ($names as $name) {
      $id = substr((string) $name, strlen('workflows.workflow.'));
      // A record that cannot be read could bind the page: it refuses.
      if (!is_array($records[$name] ?? NULL)) {
        $unreadable[] = 'unreadable_workflow:' . $id;
      }
      elseif ($name !== self::CONFIG_NAME && self::bindsPage($records[$name])) {
        $conflicts[] = $id;
      }
    }
    // The bundle info also counts: another module may name a workflow there.
    $workflow = $this->moderationInformation->getWorkflowForEntityTypeAndBundle('node', 'page');
    if ($workflow instanceof WorkflowInterface && $workflow->id() !== self::ID) {
      $conflicts[] = (string) $workflow->id();
    }
    $conflicts = array_values(array_unique($conflicts));
    sort($conflicts);
    sort($unreadable);
    foreach ($conflicts as $id) {
      $refused[] = 'page_workflow_conflict:' . $id;
    }
    return array_merge($refused, $unreadable);
  }

  /**
   * Binds node:page to editorial, and creates editorial when it is absent.
   *
   * @return array{created: bool, bound: bool, before: mixed}
   *   What undo() needs: whether this call created editorial, whether it
   *   bound the page, and the State value before it. A binding stored before
   *   is left as it is; it is owned only when State holds exactly the intent
   *   for its uuid and the stored record reads back bound and standard.
   *
   * @throws \RuntimeException
   *   When the preflight refuses; nothing is written then. A failure while
   *   it completes an intent (the page was bound already) is thrown without
   *   an undo; State keeps the intent unless the ownership write persisted
   *   before it threw. Any other failure before the intent write passes up
   *   with nothing written. A failure from the intent write to the ownership
   *   write is passed to undo() and then thrown again; when the undo fails
   *   too, the exception names the undo failure, and the original failure
   *   is its previous exception.
   */
  public function bind(): array {
    $refused = $this->preflight();
    if ($refused !== []) {
      throw new \RuntimeException('cinatra_dev_site: the workflow checks refused: ' . implode(', ', $refused));
    }
    $workflow = $this->entityTypeManager->getStorage('workflow')->loadUnchanged(self::ID);
    $applied = ['created' => $workflow === NULL, 'bound' => FALSE, 'before' => $this->state->get(self::OWNED_KEY)];
    $workflow ??= $this->entityTypeManager->getStorage('workflow')->create(self::EXPECTED);
    $plugin = $workflow->getTypePlugin();
    assert($plugin instanceof ContentModerationInterface);
    $intent = ['id' => self::ID, 'uuid' => $workflow->uuid(), 'intent' => TRUE];
    if ($plugin->appliesToEntityTypeAndBundle('node', 'page')) {
      if ($applied['before'] === $intent) {
        $this->recordOwnership($intent['uuid']);
      }
      return $applied;
    }
    $applied['bound'] = TRUE;
    try {
      // A kill after the save leaves the intent, which the next call reads.
      $this->state->set(self::OWNED_KEY, $intent);
      $plugin->addEntityTypeAndBundle('node', 'page');
      $workflow->save();
      $this->recordOwnership($intent['uuid']);
    }
    catch (\Throwable $e) {
      try {
        $this->undo($applied);
      }
      catch (\Throwable $undoFailure) {
        $text = 'cinatra_dev_site: bind() failed and its undo failed too: ' . $undoFailure->getMessage();
        throw new \RuntimeException($text, 0, $e);
      }
      throw $e;
    }
    return $applied;
  }

  /**
   * Undoes the binding of one bind() call, not checking what it finds.
   *
   * After a call that bound nothing, it returns at once: a call that only
   * completed an intent keeps the ownership record it wrote. Otherwise it
   * deletes the editorial it then loads when that call created editorial,
   * or else removes the page binding it then finds. Then, also when that
   * revert throws, it attempts to restore the State value from before that
   * call (it deletes the key when there was none).
   *
   * @param array $applied
   *   The result of that bind() call.
   */
  public function undo(array $applied): void {
    if (empty($applied['bound'])) {
      return;
    }
    try {
      $workflow = $this->entityTypeManager->getStorage('workflow')->loadUnchanged(self::ID);
      $plugin = $workflow?->getTypePlugin();
      if (!empty($applied['created'])) {
        $workflow?->delete();
      }
      elseif ($plugin instanceof ContentModerationInterface && $plugin->appliesToEntityTypeAndBundle('node', 'page')) {
        $plugin->removeEntityTypeAndBundle('node', 'page');
        $workflow->save();
      }
    }
    finally {
      $before = $applied['before'] ?? NULL;
      $before === NULL ? $this->state->delete(self::OWNED_KEY) : $this->state->set(self::OWNED_KEY, $before);
    }
  }

  /**
   * Records the stored editorial record as the owned one.
   *
   * @param mixed $uuid
   *   The uuid of the workflow that bind() saved or found bound.
   *
   * @throws \RuntimeException
   *   When the stored record does not read back with that uuid, bound to
   *   node:page and equal to the standard record in every compared key; State
   *   is not changed then.
   */
  private function recordOwnership(mixed $uuid): void {
    $record = $this->configStorage->read(self::CONFIG_NAME);
    // A save that did not persist fails this read back, and so does a writer
    // since who unbinds the page or changes the uuid or a compared key; one
    // who changes only _core, langcode, dependencies or the other bound
    // bundles does not.
    $standard = is_array($record) && self::compared($record) === self::compared(self::EXPECTED);
    if (!$standard || !self::bindsPage($record) || !is_string($uuid) || ($record['uuid'] ?? NULL) !== $uuid) {
      throw new \RuntimeException('cinatra_dev_site: the stored editorial record did not read back bound and standard with the saved uuid.');
    }
    $this->state->set(self::OWNED_KEY, ['id' => self::ID, 'uuid' => $uuid, 'hash' => self::hash($record)]);
  }

  /**
   * Removes the node:page binding only when the record it reads is owned.
   *
   * @param bool $is_syncing
   *   TRUE during a configuration import, which applies the workflow itself.
   *
   * @return string
   *   'syncing' or 'none' (nothing written), 'foreign_binding' (not the owned
   *   record; it stays) or 'unbound', which also deletes the ownership record.
   *
   * @throws \RuntimeException
   *   When the stored record still binds node:page after the save; the
   *   ownership record is not deleted then. A failure of a read, the load or
   *   the save passes up.
   */
  public function release(bool $is_syncing): string {
    if ($is_syncing) {
      return 'syncing';
    }
    $record = $this->configStorage->read(self::CONFIG_NAME);
    if (!self::bindsPage($record)) {
      return 'none';
    }
    $workflow = $this->entityTypeManager->getStorage('workflow')->loadUnchanged(self::ID);
    $plugin = $workflow?->getTypePlugin();
    // A workflow recreated since the read with another uuid is foreign.
    $same = $plugin instanceof ContentModerationInterface && $workflow->uuid() === ($record['uuid'] ?? NULL);
    if (!$same || !$this->owns($record)) {
      return 'foreign_binding';
    }
    $plugin->removeEntityTypeAndBundle('node', 'page');
    $workflow->save();
    // A save that did not persist, or a writer since who binds the page
    // again, leaves it bound in this read.
    // A record that is gone by now binds nothing: it counts as unbound.
    if (self::bindsPage($this->configStorage->read(self::CONFIG_NAME))) {
      throw new \RuntimeException('cinatra_dev_site: the stored editorial record still binds node:page after the release save.');
    }
    $this->state->delete(self::OWNED_KEY);
    return 'unbound';
  }

  /**
   * Whether a record matches the ownership record: id, uuid and hash.
   *
   * @param mixed $record
   *   The record, as a configuration storage returns it.
   *
   * @return bool
   *   TRUE only for the owned id, the owned uuid and a byte-equal hash, and
   *   only while the ownership value has exactly these three keys.
   */
  public function owns(mixed $record): bool {
    $owned = $this->state->get(self::OWNED_KEY);
    return is_array($record) && is_array($owned) && count($owned) === 3
      && ($record['id'] ?? NULL) === self::ID && ($owned['id'] ?? NULL) === self::ID
      && is_string($record['uuid'] ?? NULL) && ($owned['uuid'] ?? NULL) === $record['uuid']
      && is_string($owned['hash'] ?? NULL) && hash_equals($owned['hash'], self::hash($record));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::STORAGE_TRANSFORM_EXPORT => ['onExportTransform']];
  }

  /**
   * Leaves the submodule and an owned page binding out of the export copy.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transform event; only its copy is changed.
   *
   * @throws \RuntimeException
   *   When the copy's write() does not return TRUE for one of these writes.
   */
  public function onExportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    $extension = $storage->read('core.extension');
    if (is_array($extension) && isset($extension['module']['cinatra_dev_site'])) {
      unset($extension['module']['cinatra_dev_site']);
      self::writeCopy($storage, 'core.extension', $extension);
    }
    $record = $storage->read(self::CONFIG_NAME);
    if (!is_array($record) || !$this->owns($record)) {
      return;
    }
    $nodes = array_values(array_diff((array) ($record['type_settings']['entity_types']['node'] ?? []), ['page']));
    $config = array_values(array_diff((array) ($record['dependencies']['config'] ?? []), ['node.type.page']));
    unset($record['type_settings']['entity_types']['node'], $record['dependencies']['config']);
    if ($nodes !== []) {
      $record['type_settings']['entity_types']['node'] = $nodes;
    }
    if ($config !== []) {
      $record['dependencies']['config'] = $config;
    }
    self::writeCopy($storage, self::CONFIG_NAME, $record);
  }

  /**
   * Writes a record to the export copy, and throws when the copy refuses it.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The export copy.
   * @param string $name
   *   The configuration name.
   * @param array $data
   *   The record.
   *
   * @throws \RuntimeException
   *   When write() does not return TRUE.
   */
  private static function writeCopy(StorageInterface $storage, string $name, array $data): void {
    if ($storage->write($name, $data) !== TRUE) {
      throw new \RuntimeException('cinatra_dev_site: the export copy refused the write of ' . $name . '.');
    }
  }

  /**
   * The sha256 of a record's canonical JSON, keys sorted at every level.
   *
   * @param array $record
   *   The record.
   *
   * @return string
   *   The hash.
   */
  public static function hash(array $record): string {
    return hash('sha256', json_encode(self::sorted($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Whether a record is a content moderation workflow on node:page.
   *
   * @param mixed $record
   *   The record.
   *
   * @return bool
   *   TRUE when its node bundles list page; a scalar counts as a list of one.
   */
  private static function bindsPage(mixed $record): bool {
    return is_array($record) && ($record['type'] ?? NULL) === 'content_moderation'
      && in_array('page', (array) ($record['type_settings']['entity_types']['node'] ?? []), TRUE);
  }

  /**
   * The part of a record that the preflight compares, keys sorted.
   *
   * @param array $record
   *   The record.
   *
   * @return array
   *   The record without uuid, _core, langcode, dependencies and
   *   type_settings.entity_types.
   */
  private static function compared(array $record): array {
    unset($record['uuid'], $record['_core'], $record['langcode'], $record['dependencies']);
    if (is_array($record['type_settings'] ?? NULL)) {
      unset($record['type_settings']['entity_types']);
    }
    return self::sorted($record);
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
      $data[$key] = is_array($value) ? self::sorted($value) : $value;
    }
    return $data;
  }

}
