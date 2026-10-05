<?php

declare(strict_types=1);

namespace Drupal\cinatra_protected_draft_test;

use Drupal\node\NodeInterface;

/**
 * In-process observations survive a SQL rollback without replacing storage.
 */
final class ProtectedDraftProbe {

  public static ?int $nid = NULL;
  public static ?string $mode = NULL;
  public static int $presaves = 0;
  public static ?int $storedRevision = NULL;
  public static ?string $deniedField = NULL;

  public static function reset(): void {
    self::$nid = NULL;
    self::$mode = NULL;
    self::$presaves = 0;
    self::$storedRevision = NULL;
    self::$deniedField = NULL;
  }

  public static function arm(int $nid, string $mode): void {
    self::reset();
    self::$nid = $nid;
    self::$mode = $mode;
  }

  public static function beforeSave(NodeInterface $node): void {
    if ((int) $node->id() !== self::$nid || !$node->isNewRevision()) {
      return;
    }
    self::$presaves++;
    switch (self::$mode) {
      case 'unrequested_body':
        $node->get('body')->value = 'A save hook changed an unrequested field.';
        break;

      case 'other_translation':
        $other = $node->language()->getId() === 'en' ? 'fr' : 'en';
        $node->getTranslation($other)->setTitle('A save hook changed the other translation.');
        break;

      case 'restricted_format':
        $node->get('body')->format = 'restricted';
        break;

      case 'published_default':
        $node->set('moderation_state', 'published');
        $node->setPublished();
        $node->isDefaultRevision(TRUE);
        break;
    }
  }

  public static function afterSave(NodeInterface $node): void {
    if ((int) $node->id() === self::$nid && self::$presaves > 0) {
      self::$storedRevision = (int) $node->getRevisionId();
    }
  }

}
