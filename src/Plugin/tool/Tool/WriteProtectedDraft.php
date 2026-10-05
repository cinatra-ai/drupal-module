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
    'nid' => new InputDefinition(data_type: 'integer', label: new TranslatableMarkup('Node ID'), required: TRUE),
    'language' => new InputDefinition(data_type: 'string', label: new TranslatableMarkup('Exact language code'), required: TRUE),
    'draft_state' => new InputDefinition(data_type: 'string', label: new TranslatableMarkup('Non-default unpublished workflow state'), required: TRUE),
    'expected_default_revision_id' => new InputDefinition(data_type: 'integer', label: new TranslatableMarkup('Preimage default revision'), required: TRUE),
    'expected_latest_revision_id' => new InputDefinition(data_type: 'integer', label: new TranslatableMarkup('Preimage latest revision'), required: TRUE),
    'workflow_fingerprint' => new InputDefinition(data_type: 'string', label: new TranslatableMarkup('Preimage workflow fingerprint'), required: TRUE),
    'preimage_fingerprint' => new InputDefinition(data_type: 'string', label: new TranslatableMarkup('Preimage stored-content fingerprint'), required: TRUE),
    'updates' => new InputDefinition(data_type: 'map', label: new TranslatableMarkup('Canonical fields with structured item values'), required: TRUE),
  ],
  output_definitions: [
    'contract' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Protected revision contract')),
    'result' => new ContextDefinition(data_type: 'map', label: new TranslatableMarkup('Stored unpublished non-default revision')),
  ],
)]
final class WriteProtectedDraft extends ProtectedDraftToolBase {

  protected function runProtectedOperation(array $values): array {
    return $this->protectedDraft->write($values);
  }

}
