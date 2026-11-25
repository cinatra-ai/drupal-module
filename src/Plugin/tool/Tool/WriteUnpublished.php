<?php

declare(strict_types=1);

namespace Drupal\cinatra\Plugin\tool\Tool;

use Drupal\cinatra\Tool\ProtectedDraftToolBase;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;

/**
 * Writes reviewed field values to an unpublished page only if it is unchanged.
 */
#[Tool(
  id: 'cinatra_write_unpublished',
  label: new TranslatableMarkup('Write unpublished page'),
  description: new TranslatableMarkup('Atomically save field values as a new default revision of an unpublished page in the exact translation, only if the page is unchanged since the cinatra_read_protected_revision read: revision, changed time, workflow and content bindings must all match. With publish true the same save also publishes the page. A moderated page keeps its workflow: without publish the page stays in its current unpublished state, which a transition the user may use must lead back to (Draft to Draft in the editorial workflow); with publish true the page moves to the one published state that a transition the user may use leads to. On a moderated page the user also needs edit access to the moderation_state field. Refuses published pages, a moderated page without such a transition, a changed page, missing permission and unknown fields before saving; returns the stored structured field values, the new revision ID and the new changed time.'),
  operation: ToolOperation::Write,
  input_definitions: [
    'nid' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('Identifier of the existing unpublished page.'),
      required: TRUE,
    ),
    'language' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Exact language code'),
      description: new TranslatableMarkup('Exact language code of the page translation that was read; no fallback language is used.'),
      required: TRUE,
    ),
    'expected_default_revision_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Read default revision'),
      description: new TranslatableMarkup('default_revision_id from the read that the person reviewed.'),
      required: TRUE,
    ),
    'expected_latest_revision_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Read latest revision'),
      description: new TranslatableMarkup('latest_revision_id from the read that the person reviewed.'),
      required: TRUE,
    ),
    'expected_changed' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Read changed time'),
      description: new TranslatableMarkup('changed from the read that the person reviewed.'),
      required: TRUE,
    ),
    'workflow_fingerprint' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Read workflow fingerprint'),
      description: new TranslatableMarkup('workflow_fingerprint from the read that the person reviewed.'),
      required: TRUE,
    ),
    'preimage_fingerprint' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Read stored-content fingerprint'),
      description: new TranslatableMarkup('preimage_fingerprint from the read that the person reviewed.'),
      required: TRUE,
    ),
    'updates' => new InputDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Canonical fields with structured item values'),
      description: new TranslatableMarkup('Canonical field names mapped to the structured field item values that the person reviewed: a list of items, each a map of stored property names of the field to single values. Each item replaces the stored item; a property left out is stored empty, except the width and height of an image, which are taken from the file. A number may be a JSON number or text; one its field cannot store exactly is refused before the save. To clear a field, send an empty list or an item with an empty value; both store the field empty.'),
      required: TRUE,
    ),
    'publish' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Publish in the same save'),
      description: new TranslatableMarkup('TRUE publishes the page in the same save as the field values. Requires the permission to change the published status.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'contract' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Protected revision contract')),
    'result' => new ContextDefinition(data_type: 'map', label: new TranslatableMarkup('Stored default revision of the page')),
  ],
)]
final class WriteUnpublished extends ProtectedDraftToolBase {

  /**
   * Runs the requested protected revision operation.
   */
  protected function runProtectedOperation(array $values): array {
    // An omitted optional input may arrive as NULL; it means no publish.
    if (array_key_exists('publish', $values) && $values['publish'] === NULL) {
      unset($values['publish']);
    }
    return $this->protectedDraft->writeUnpublished($values);
  }

}
