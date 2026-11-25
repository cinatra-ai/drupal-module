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
 * Publishes an unpublished page as it is, only if it is unchanged.
 */
#[Tool(
  id: 'cinatra_publish_unpublished',
  label: new TranslatableMarkup('Publish unpublished page'),
  description: new TranslatableMarkup('Atomically publish an unpublished page in the exact translation as it is, only if the page is unchanged since the cinatra_read_protected_revision read: revision, changed time, workflow and content bindings must all match. Changes no field value. A moderated page is published through the workflow transition to the published state that the user may use. Refuses published pages, a page without such a transition, a changed page and missing permission before saving; returns the new revision ID and the new changed time.'),
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
  ],
  output_definitions: [
    'contract' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Protected revision contract')),
    'result' => new ContextDefinition(data_type: 'map', label: new TranslatableMarkup('Stored published default revision of the page')),
  ],
)]
final class PublishUnpublished extends ProtectedDraftToolBase {

  /**
   * Runs the requested protected revision operation.
   */
  protected function runProtectedOperation(array $values): array {
    return $this->protectedDraft->publishUnpublished($values);
  }

}
