<?php

declare(strict_types=1);

namespace Drupal\cinatra_protected_draft_test\Plugin\Field\FieldType;

use Drupal\cinatra_protected_draft_test\ProtectedDraftProbe;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A text item whose save logic has an effect outside the database.
 *
 * Each preSave() call adds one to a counter that no rollback resets, like a
 * field that notifies another system when it is saved.
 */
#[FieldType(
  id: 'cinatra_test_counting_string',
  label: new TranslatableMarkup('Counting text (test)'),
  description: new TranslatableMarkup('Counts its save logic calls.'),
  default_widget: 'string_textfield',
  default_formatter: 'string',
)]
final class CountingStringItem extends StringItem {

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();
    ProtectedDraftProbe::$fieldPreSaves++;
  }

}
