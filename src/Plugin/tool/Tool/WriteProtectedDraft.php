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
 * Creates one unpublished non-default draft of a published page.
 */
#[Tool(
  id: 'cinatra_write_protected_draft',
  label: new TranslatableMarkup('Write protected page draft'),
  description: new TranslatableMarkup('Atomically create a non-default unpublished revision of the same page and exact translation. Requires revision, workflow and live-content bindings from cinatra_read_protected_revision. Refuses pending drafts and unsafe workflows before saving; returns actual stored structured field values.'),
  operation: ToolOperation::Write,
  input_definitions: [
    'nid' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('Identifier of the existing page to read or edit.'),
      required: TRUE,
    ),
    'language' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Exact language code'),
      description: new TranslatableMarkup('Exact language code of an existing page translation; no fallback language is used.'),
      required: TRUE,
    ),
    'draft_state' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Non-default unpublished workflow state'),
      description: new TranslatableMarkup('Workflow state verified by the preimage as unpublished and non-default.'),
      required: TRUE,
    ),
    'expected_default_revision_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Preimage default revision'),
      description: new TranslatableMarkup('Default revision identifier from the protected preimage.'),
      required: TRUE,
    ),
    'expected_latest_revision_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Preimage latest revision'),
      description: new TranslatableMarkup('Latest revision identifier from the protected preimage.'),
      required: TRUE,
    ),
    'workflow_fingerprint' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Preimage workflow fingerprint'),
      description: new TranslatableMarkup('Workflow and configuration binding returned by the protected preimage.'),
      required: TRUE,
    ),
    'preimage_fingerprint' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Preimage stored-content fingerprint'),
      description: new TranslatableMarkup('Stored page content binding returned by the protected preimage.'),
      required: TRUE,
    ),
    'updates' => new InputDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Canonical fields with structured item values'),
      description: new TranslatableMarkup('Canonical field names mapped to structured field item values to store.'),
      required: TRUE,
    ),
  ],
  output_definitions: [
    'contract' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Protected revision contract')),
    'result' => new ContextDefinition(data_type: 'map', label: new TranslatableMarkup('Stored unpublished non-default revision')),
  ],
)]
final class WriteProtectedDraft extends ProtectedDraftToolBase {

  /**
   * Runs the requested protected revision operation.
   */
  protected function runProtectedOperation(array $values): array {
    return $this->protectedDraft->write($values);
  }

}
