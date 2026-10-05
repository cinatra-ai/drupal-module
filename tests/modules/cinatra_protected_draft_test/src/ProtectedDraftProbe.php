<?php

declare(strict_types=1);

namespace Drupal\cinatra_protected_draft_test;

use Drupal\node\NodeInterface;

/**
 * In-process observations survive a SQL rollback without replacing storage.
 */
final class ProtectedDraftProbe {

  /**
   * Node identifier targeted by the fixture.
   *
   * @var int|null
   */
  public static ?int $nid = NULL;
  /**
   * Mutation selected for the real save hook.
   *
   * @var string|null
   */
  public static ?string $mode = NULL;
  /**
   * Number of actual node pre-save hooks observed.
   *
   * @var int
   */
  public static int $presaves = 0;
  /**
   * Revision identifier observed after an actual SQL save.
   *
   * @var int|null
   */
  public static ?int $storedRevision = NULL;
  /**
   * Field whose edit access the test hook refuses.
   *
   * @var string|null
   */
  public static ?string $deniedField = NULL;

  /**
   * Exercises reset.
   */
  public static function reset(): void {
    self::$nid = NULL;
    self::$mode = NULL;
    self::$presaves = 0;
    self::$storedRevision = NULL;
    self::$deniedField = NULL;
  }

  /**
   * Exercises arm.
   */
  public static function arm(int $nid, string $mode): void {
    self::reset();
    self::$nid = $nid;
    self::$mode = $mode;
  }

  /**
   * Exercises before save.
   */
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

  /**
   * Exercises after save.
   */
  public static function afterSave(NodeInterface $node): void {
    if ((int) $node->id() === self::$nid && self::$presaves > 0) {
      self::$storedRevision = (int) $node->getRevisionId();
    }
  }

}
