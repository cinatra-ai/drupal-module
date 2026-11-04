<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_page_test;

use Drupal\node\NodeInterface;

/**
 * The change a test asks for, applied to a syncing node save.
 *
 * Static on purpose: the hook runs inside the migration's transaction, and a
 * State write there would be rolled back with it.
 */
final class PageMutation {

  /**
   * The field to change on the next syncing save, or NULL for none.
   *
   * @var string|null
   */
  public static ?string $field = NULL;

  /**
   * The only node id the change applies to, or NULL for any node.
   *
   * @var int|null
   */
  public static ?int $nid = NULL;

  /**
   * Whether each syncing save ran inside a database transaction.
   *
   * @var bool[]
   */
  public static array $inTransaction = [];

  /**
   * The revision ids that cannot be loaded, keyed by entity type id.
   *
   * @var array<string, int[]>
   */
  public static array $unreadable = [];

  /**
   * Resets the double.
   */
  public static function reset(): void {
    self::$field = NULL;
    self::$nid = NULL;
    self::$inTransaction = [];
    self::$unreadable = [];
  }

  /**
   * Tells whether a test made a revision unreadable.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param mixed $revision_id
   *   The revision id.
   *
   * @return bool
   *   TRUE when the revision must not load.
   */
  public static function unreadable(string $entity_type_id, mixed $revision_id): bool {
    return in_array((int) $revision_id, self::$unreadable[$entity_type_id] ?? [], TRUE);
  }

  /**
   * Applies the requested change to a syncing save.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   */
  public static function apply(NodeInterface $node): void {
    if (!$node->isSyncing()) {
      return;
    }
    self::$inTransaction[] = \Drupal::database()->inTransaction();
    if (self::$nid !== NULL && self::$nid !== (int) $node->id()) {
      return;
    }
    $field = self::$field;
    self::$field = NULL;
    switch ($field) {
      case 'uid':
        $node->set('uid', (int) $node->getOwnerId() + 1);
        break;

      case 'promote':
        $node->setPromoted(!$node->isPromoted());
        break;

      case 'sticky':
        $node->setSticky(!$node->isSticky());
        break;

      case 'title':
        $node->setTitle($node->label() . ' changed');
        break;

      case 'body':
        $node->set('body', [['value' => 'changed body', 'summary' => '', 'format' => 'plain_text']]);
        break;

      case 'field_note':
        $node->set('field_note', 'changed note');
        break;

      case 'path':
        $node->set('path', ['alias' => '/changed-page-' . $node->id(), 'langcode' => $node->language()->getId()]);
        break;

      case 'langcode':
        $node->set('langcode', 'de');
        break;

      case 'revision_log':
        // A field the migration may change: the save must still pass.
        $node->setRevisionLogMessage('changed log');
        break;
    }
  }

}
