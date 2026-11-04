<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Page;

use Drupal\cinatra\ProtectedDraft\ProtectedDraftStorageInterface;
use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\workflows\WorkflowInterface;

/**
 * Binds the pages of node:page to editorial state rows on their own revision.
 *
 * The plan reads only. The migration saves each page in place, as a syncing
 * save inside the protected draft storage's locked root transaction, and
 * compares every field of every translation before that transaction commits.
 * Nothing in this class arms the site. The 'error' of a failed migration is
 * the raw failure message, which may carry storage details: a caller that
 * records it publicly must pass it through a leak gate first.
 */
final class PageMigrator {

  /**
   * The fixed answer to a status write that the moderation state did not make.
   */
  public const STATUS_REFUSAL_TEXT = 'cinatra_dev_site: the status of a moderated page changes only through its moderation state.';

  /**
   * The workflow the pages are bound to.
   */
  public const WORKFLOW_ID = 'editorial';

  /**
   * The fields a migration save may change: the state and revision metadata.
   */
  public const MAY_CHANGE = [
    'moderation_state',
    'vid',
    'revision_timestamp',
    'revision_uid',
    'revision_log',
    'revision_translation_affected',
  ];

  /**
   * Constructs the page migrator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager; every storage is fetched afresh from it.
   * @param \Drupal\cinatra\ProtectedDraft\ProtectedDraftStorageInterface $protectedDraftStorage
   *   The protected draft storage, whose lock each page migration runs in.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProtectedDraftStorageInterface $protectedDraftStorage,
  ) {
  }

  /**
   * Plans every page; reads only.
   *
   * @return array
   *   'plans' keyed by node id; 'refused', which must be empty to migrate.
   */
  public function plan(): array {
    $plans = $refused = [];
    foreach ($this->pageIds() as $nid) {
      [$plan, $page_refusals] = $this->pagePlan($nid);
      array_push($refused, ...$page_refusals);
      if ($plan !== NULL) {
        $plans[$nid] = $plan;
      }
    }
    return ['plans' => $plans, 'refused' => array_values(array_unique($refused))];
  }

  /**
   * Migrates every page, each in its own locked root transaction.
   *
   * It refuses unless editorial applies to node:page. The id list is read
   * again, and every page is planned again inside its lock, so a page
   * created or changed after the plan is caught. The run stops at the first
   * failure; that page is rolled back, and the pages saved before it stay
   * complete.
   *
   * @param array $plans
   *   The 'plans' of plan().
   *
   * @return array
   *   'ok', 'migrated' (the saved node ids), 'refused' (no_marker,
   *   editorial_not_applied or migration_failed:<nid>) and 'error' (the
   *   failure message).
   */
  public function migrate(array $plans): array {
    $migrated = [];
    if (!CinatraDevSite::markerPresent()) {
      return ['ok' => FALSE, 'migrated' => [], 'refused' => ['no_marker'], 'error' => ''];
    }
    if (!$this->applies()) {
      return ['ok' => FALSE, 'migrated' => [], 'refused' => ['editorial_not_applied'], 'error' => ''];
    }
    foreach ($this->pageIds() as $nid) {
      $planned = $plans[$nid] ?? NULL;
      try {
        $saved = $this->protectedDraftStorage->withLockedNode($nid, fn(): array => $this->migratePage($nid, $planned));
      }
      catch (\Throwable $e) {
        return ['ok' => FALSE, 'migrated' => $migrated, 'refused' => ['migration_failed:' . $nid], 'error' => $e->getMessage()];
      }
      finally {
        $this->storage('node')->resetCache([$nid]);
        $this->storage('content_moderation_state')->resetCache();
      }
      if ($saved !== []) {
        $migrated[] = $nid;
      }
    }
    return ['ok' => TRUE, 'migrated' => $migrated, 'refused' => [], 'error' => ''];
  }

  /**
   * Lists the page ids in id order, without access checks.
   *
   * @return int[]
   *   The node ids.
   */
  private function pageIds(): array {
    $ids = $this->storage('node')->getQuery()->accessCheck(FALSE)->condition('type', 'page')->sort('nid')->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Builds the plan of one page: its rows, the pending guard, the orientation.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The plan (or NULL when the page is gone) and the page's refusals.
   */
  private function pagePlan(int $nid): array {
    $storage = $this->storage('node');
    $node = $storage->load($nid);
    if (!$node instanceof NodeInterface) {
      return [NULL, []];
    }
    $plugin = $this->editorial();
    $refused = $missing = $existing = $resave = [];
    $default = (int) $node->getRevisionId();
    $default_langcode = $node->getUntranslated()->language()->getId();
    $languages = array_keys($node->getTranslationLanguages());
    sort($languages);
    foreach ($languages as $langcode) {
      $published = $node->getTranslation($langcode)->isPublished();
      $row = $this->stateRow($nid, $default, $langcode);
      if ($row === NULL) {
        $missing[$langcode] = $published ? 'published' : 'draft';
        continue;
      }
      // An unreadable row, a row without a state and a state the stored
      // workflow does not know are conflicts too.
      $state = $row ? $row->getTranslation($langcode)->get('moderation_state')->value : NULL;
      if (!is_string($state) || $plugin === NULL || !$plugin->hasState($state)
        || $plugin->getState($state)->isPublishedState() !== $published) {
        $refused[] = 'state_conflict:' . $nid . ':' . $langcode;
        continue;
      }
      $existing[$langcode] = $state;
    }
    if ($missing !== []) {
      // The pending guard, core's test, only for a page with a missing row.
      $latest = (int) $storage->getLatestRevisionId($nid);
      if ($latest !== $default) {
        $revision = $storage->loadRevision($latest);
        $pending = $revision instanceof ContentEntityInterface && !$revision->wasDefaultRevision();
        $refused[] = ($pending ? 'pending_revision:' : 'default_not_latest:') . $nid;
      }
      foreach ($languages as $langcode) {
        $affected = $storage->getLatestTranslationAffectedRevisionId($nid, $langcode);
        if ($affected !== NULL && (int) $affected !== $default) {
          $revision = $storage->loadRevision((int) $affected);
          if (!$revision instanceof ContentEntityInterface) {
            $refused[] = 'revision_unreadable:' . $nid . ':' . $langcode;
          }
          elseif (!$revision->wasDefaultRevision()) {
            $refused[] = 'pending_revision:' . $nid . ':' . $langcode;
          }
        }
      }
      // The orientation: core's default-language save rewrites the row of the
      // state entity's own default language, which is then saved again.
      $own_row = isset($missing[$default_langcode]) ? $this->stateRow($nid, $default, NULL) : NULL;
      if ($own_row === FALSE) {
        $refused[] = 'state_conflict:' . $nid . ':' . $default_langcode;
      }
      $own = $own_row ? $own_row->getUntranslated()->language()->getId() : NULL;
      if ($own !== NULL && $own !== $default_langcode && isset($existing[$own])) {
        $resave = [$own => $existing[$own]];
      }
      if (isset($missing[$default_langcode])) {
        // The default language is saved first.
        $missing = [$default_langcode => $missing[$default_langcode]] + $missing;
      }
    }
    $plan = ['default' => $default, 'default_langcode' => $default_langcode, 'missing' => $missing, 'existing' => $existing, 'resave' => $resave];
    return [$plan, $refused];
  }

  /**
   * Loads the latest editorial state row bound to a node revision.
   *
   * @param int $nid
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string|null $langcode
   *   The row language, or NULL for core's lookup without a language.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|false|null
   *   The state entity revision; NULL when no row is bound, FALSE when the
   *   bound row cannot be read or lacks the language.
   */
  private function stateRow(int $nid, int $revision_id, ?string $langcode): ContentEntityInterface|false|null {
    $storage = $this->storage('content_moderation_state');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('content_entity_revision_id', $revision_id)
      ->condition('workflow', self::WORKFLOW_ID);
    if ($langcode !== NULL) {
      $query->condition('langcode', $langcode);
    }
    $revision = key($query->allRevisions()->sort('revision_id', 'DESC')->range(0, 1)->execute());
    if (!$revision) {
      return NULL;
    }
    $row = $storage->loadRevision((int) $revision);
    if (!$row instanceof ContentEntityInterface || ($langcode !== NULL && !$row->hasTranslation($langcode))) {
      return FALSE;
    }
    return $row;
  }

  /**
   * Returns the type plugin of the stored editorial workflow.
   *
   * @return \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface|null
   *   The plugin, or NULL when there is no editorial moderation workflow.
   */
  private function editorial(): ?ContentModerationInterface {
    $workflow = $this->entityTypeManager->getStorage('workflow')->load(self::WORKFLOW_ID);
    $plugin = $workflow instanceof WorkflowInterface ? $workflow->getTypePlugin() : NULL;
    return $plugin instanceof ContentModerationInterface ? $plugin : NULL;
  }

  /**
   * Tells whether the stored editorial workflow applies to node:page.
   *
   * @return bool
   *   TRUE when editorial is a moderation workflow bound to node:page.
   */
  private function applies(): bool {
    return (bool) $this->editorial()?->appliesToEntityTypeAndBundle('node', 'page');
  }

  /**
   * Migrates one page; runs inside the protected draft storage's lock.
   *
   * @param int $nid
   *   The node id.
   * @param array|null $planned
   *   The plan read before the lock, or NULL for a page that was not in it.
   *
   * @return array
   *   The languages saved; empty when the page needed no save.
   */
  private function migratePage(int $nid, ?array $planned): array {
    // The storage rebuilt the handlers after taking its locks: every storage
    // is fetched again, and the plan read here is the one applied.
    $storage = $this->storage('node');
    $storage->resetCache([$nid]);
    if (!$this->applies()) {
      throw new \RuntimeException('The editorial workflow does not apply to node:page.');
    }
    [$plan, $refused] = $this->pagePlan($nid);
    if ($refused !== []) {
      throw new \RuntimeException('The page plan refused inside the lock: ' . implode(', ', $refused));
    }
    if ($plan === NULL) {
      return [];
    }
    if ($planned !== NULL && $plan !== $planned) {
      throw new \RuntimeException('The page changed between the plan and its lock.');
    }
    if ($plan['missing'] === []) {
      return [];
    }
    $before = $this->snapshot($nid);
    $sequence = [];
    foreach ($plan['missing'] as $langcode => $state) {
      $sequence[$langcode] = $state;
      if ($langcode === $plan['default_langcode']) {
        $sequence += $plan['resave'];
      }
    }
    foreach ($sequence as $langcode => $state) {
      $node = $storage->loadUnchanged($nid);
      if (!$node instanceof NodeInterface || !$node->hasTranslation((string) $langcode)) {
        throw new \RuntimeException('The page lost its translation ' . $langcode . ' inside the lock.');
      }
      $translation = $node->getTranslation((string) $langcode);
      // Syncing first: the save keeps the revision, its default flag and the
      // changed time. No new revision is requested.
      $translation->setSyncing(TRUE);
      $translation->set('moderation_state', $state);
      $translation->save();
    }
    $this->storage('content_moderation_state')->resetCache();
    $after = $this->snapshot($nid);
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
      if (($before[$key] ?? NULL) !== ($after[$key] ?? NULL)) {
        throw new \RuntimeException('page_changed:' . $nid . ':' . $key);
      }
    }
    foreach ($plan['existing'] + $plan['missing'] as $langcode => $state) {
      $row = $this->stateRow($nid, $plan['default'], (string) $langcode);
      if (!$row || $row->getTranslation((string) $langcode)->get('moderation_state')->value !== $state) {
        throw new \RuntimeException('The state row of ' . $langcode . ' is not bound to the default revision.');
      }
    }
    return array_keys($sequence);
  }

  /**
   * Reads every field of the stored default revision of a page.
   *
   * @param int $nid
   *   The node id.
   *
   * @return array
   *   The default revision id, the revision count, the default language and
   *   every field value but MAY_CHANGE (computed too), keyed langcode:field.
   */
  private function snapshot(int $nid): array {
    $storage = $this->storage('node');
    $node = $storage->loadUnchanged($nid);
    if (!$node instanceof NodeInterface) {
      throw new \RuntimeException('page_changed:' . $nid . ':gone');
    }
    $snapshot = [
      'default' => (int) $node->getRevisionId(),
      'revisions' => (int) $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $nid)->count()->execute(),
      'default_langcode' => $node->getUntranslated()->language()->getId(),
    ];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      foreach ($node->getTranslation($langcode) as $name => $items) {
        if (!in_array($name, self::MAY_CHANGE, TRUE)) {
          $snapshot[$langcode . ':' . $name] = $items->getValue();
        }
      }
    }
    return $snapshot;
  }

  /**
   * Returns a content entity storage, fetched afresh.
   *
   * @param string $entity_type_id
   *   The entity type id.
   *
   * @return \Drupal\Core\Entity\ContentEntityStorageInterface
   *   The storage.
   */
  private function storage(string $entity_type_id): ContentEntityStorageInterface {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    assert($storage instanceof ContentEntityStorageInterface);
    return $storage;
  }

}
