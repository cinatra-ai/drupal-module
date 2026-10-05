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
 * Reads a protected preimage or one exact stored page revision.
 */
#[Tool(
  id: 'cinatra_read_protected_revision',
  label: new TranslatableMarkup('Read protected page revision'),
  description: new TranslatableMarkup('Read exact stored fields in the requested language. Omit revision_id to read the published default preimage and its revision/workflow/content binding. Supply revision_id to read that exact revision; never substitutes the default or latest.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'nid' => new InputDefinition(data_type: 'integer', label: new TranslatableMarkup('Node ID'), required: TRUE),
    'language' => new InputDefinition(data_type: 'string', label: new TranslatableMarkup('Exact language code'), required: TRUE),
    'fields' => new InputDefinition(data_type: 'list', label: new TranslatableMarkup('Canonical stored field names'), required: TRUE),
    'revision_id' => new InputDefinition(data_type: 'integer', label: new TranslatableMarkup('Exact revision ID'), required: FALSE),
  ],
  output_definitions: [
    'contract' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Protected revision contract')),
    'result' => new ContextDefinition(data_type: 'map', label: new TranslatableMarkup('Stored revision and structured field values')),
  ],
)]
final class ReadProtectedRevision extends ProtectedDraftToolBase {

  protected function runProtectedOperation(array $values): array {
    if (isset($values['revision_id'])) {
      return $this->protectedDraft->read($values['nid'], $values['revision_id'], $values['language'], $values['fields']);
    }
    return $this->protectedDraft->prepare($values['nid'], $values['language'], $values['fields']);
  }

}
