<?php

declare(strict_types=1);

namespace Drupal\cinatra_protected_draft_test;

use Drupal\content_moderation\Entity\ContentModerationStateInterface;
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
   * File identifier a save hook swaps into the image field.
   *
   * @var int|null
   */
  public static ?int $swapFile = NULL;
  /**
   * Whether untranslatable fields affect only the default translation.
   *
   * Read by the test module's bundle info alter for the page bundle.
   *
   * @var bool
   */
  public static bool $defaultTranslationAffectedOnly = FALSE;
  /**
   * Calls of the counting test field type's save logic.
   *
   * @var int
   */
  public static int $fieldPreSaves = 0;

  /**
   * Exercises reset.
   */
  public static function reset(): void {
    self::$nid = NULL;
    self::$mode = NULL;
    self::$presaves = 0;
    self::$storedRevision = NULL;
    self::$deniedField = NULL;
    self::$swapFile = NULL;
    self::$defaultTranslationAffectedOnly = FALSE;
    self::$fieldPreSaves = 0;
  }

  /**
   * Exercises arm.
   */
  public static function arm(int $nid, string $mode): void {
    // A new run's observations start empty; what the test configured for
    // the run (a denied field, a file to swap in, the bundle setting) stays.
    self::$presaves = 0;
    self::$storedRevision = NULL;
    self::$fieldPreSaves = 0;
    self::$nid = $nid;
    self::$mode = $mode;
  }

  /**
   * Moves another translation's state in the new moderation state record.
   *
   * In mode other_moderation: when the record of the new page revision is
   * saved, the other translation gets another state with the same
   * publication flags. The old revision's record is left as it was.
   */
  public static function beforeModerationSave(ContentModerationStateInterface $record): void {
    if (self::$mode !== 'other_moderation' || (int) $record->get('content_entity_id')->value !== self::$nid
      || !$record->isNewRevision()) {
      return;
    }
    $other = $record->language()->getId() === 'en' ? 'fr' : 'en';
    if ($record->hasTranslation($other)) {
      $record->getTranslation($other)->set('moderation_state', 'featured');
    }
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

      case 'other_changed':
        // A save hook moving another translation's timestamp.
        $other = $node->language()->getId() === 'en' ? 'fr' : 'en';
        $node->getTranslation($other)->setChangedTime(time() + 1000);
        break;

      case 'other_changed_to_request_time':
        // A save hook moving another translation's timestamp to the value core
        // gives an affected translation.
        $other = $node->language()->getId() === 'en' ? 'fr' : 'en';
        $node->getTranslation($other)->setChangedTime(\Drupal::time()->getRequestTime());
        break;

      case 'other_affected':
        // A save hook marking another translation as affected by this revision.
        $other = $node->language()->getId() === 'en' ? 'fr' : 'en';
        $node->getTranslation($other)->setRevisionTranslationAffected(TRUE);
        break;

      case 'other_unaffected':
        // A save hook clearing another translation's affected flag, which
        // core would set for a change of an untranslatable field.
        $other = $node->language()->getId() === 'en' ? 'fr' : 'en';
        $node->getTranslation($other)->setRevisionTranslationAffected(FALSE);
        break;

      case 'replace_title':
        // A valid value the person never reviewed.
        $node->setTitle('Replaced by a save hook');
        break;

      case 'short_number_title':
        // Text that only looks like the reviewed number.
        $node->setTitle('1.5');
        break;

      case 'refill_cleared':
        // Puts a value back into a field the person reviewed as cleared.
        foreach (['field_note', 'field_shared_note'] as $field) {
          if ($node->hasField($field)) {
            $node->set($field, 'Put back by a save hook');
          }
        }
        break;

      case 'restricted_format':
        $node->get('body')->format = 'restricted';
        break;

      case 'marker':
        // A save hook changing a stored field that is not revisionable.
        $node->set('cinatra_test_marker', 'Changed by a save hook');
        break;

      case 'inject_summary':
        // A summary the person never sent.
        $node->get('body')->summary = 'Injected by a save hook';
        break;

      case 'inject_link_title':
        // A link title the person never sent.
        $node->get('field_link')->title = 'Injected by a save hook';
        break;

      case 'alter_image_size':
        // Dimensions other than the file's own, after the field derived them.
        $node->get('field_image')->width = 999;
        break;

      case 'self_unaffected':
        // Clears the edited translation's affected mark.
        $node->setRevisionTranslationAffected(FALSE);
        break;

      case 'swap_image':
        // A valid image file other than the reviewed one, with its alt text.
        if (self::$swapFile === NULL) {
          throw new \LogicException('The swap_image mode needs the file to swap in.');
        }
        $node->get('field_image')->target_id = self::$swapFile;
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
