<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Protects published pages and returns actual stored draft field values.
 */
final class ProtectedDraftService {

  public function __construct(private readonly ProtectedDraftStorageInterface $storage) {
  }

  /**
   * Reads the protected preimage needed to bind a subsequent edit.
   */
  public function prepare(int $nid, string $language, array $fields): array {
    $this->validateIdentity($nid, $language, $fields);
    return $this->storage->withLockedNode($nid, function () use ($nid, $language, $fields): array {
      $snapshot = $this->storage->readDefault($nid, $language, $fields);
      $this->assertReadableIdentity($snapshot, $nid, $language);
      $allowed = [
        'node_id', 'uuid', 'language', 'default_revision_id',
        'latest_revision_id', 'is_default_revision', 'is_published',
        'moderated', 'canonical_moderation_field', 'workflow_fingerprint', 'preimage_fingerprint',
        'draft_states', 'allowed_draft_states', 'fields',
        'available_fields',
      ];
      $result = array_intersect_key($snapshot, array_flip($allowed));
      $result['fields'] = array_intersect_key($snapshot['fields'] ?? [], array_flip($fields));
      return $result;
    });
  }

  /**
   * Creates a draft only while the complete protected preimage still holds.
   */
  public function write(array $request): array {
    $allowed = [
      'nid',
      'language',
      'draft_state',
      'expected_default_revision_id',
      'expected_latest_revision_id',
      'workflow_fingerprint',
      'preimage_fingerprint',
      'updates',
    ];
    if (array_diff(array_keys($request), $allowed)
      || !is_int($request['nid'] ?? NULL)
      || !is_string($request['language'] ?? NULL)
      || !is_string($request['draft_state'] ?? NULL)
      || $request['draft_state'] === ''
      || !is_int($request['expected_default_revision_id'] ?? NULL)
      || $request['expected_default_revision_id'] < 1
      || !is_int($request['expected_latest_revision_id'] ?? NULL)
      || $request['expected_latest_revision_id'] < 1
      || !is_string($request['workflow_fingerprint'] ?? NULL)
      || !preg_match('/\A[a-f0-9]{64}\z/', $request['workflow_fingerprint'])
      || !is_string($request['preimage_fingerprint'] ?? NULL)
      || !preg_match('/\A[a-f0-9]{64}\z/', $request['preimage_fingerprint'])
      || !is_array($request['updates'] ?? NULL)
      || !$request['updates']) {
      throw new ProtectedDraftRefusal('A complete language-bound revision and workflow preimage is required.');
    }
    $nid = $request['nid'];
    $language = $request['language'];
    $fields = array_keys($request['updates']);
    $this->validateIdentity($nid, $language, $fields);

    return $this->storage->withLockedNode($nid, function () use ($request, $nid, $language, $fields): array {
      $before = $this->storage->readDefault($nid, $language, $fields);
      $this->assertReadableIdentity($before, $nid, $language);
      if (($before['is_default_revision'] ?? NULL) !== TRUE
        || ($before['is_published'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('The protected edit must start from a published default revision.');
      }
      if (($before['moderated'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('This page is not moderated; the live page was not edited.');
      }
      if (($before['canonical_moderation_field'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('The moderation field is ambiguous or maps to a custom field.');
      }
      if (($before['latest_revision_id'] ?? NULL) !== ($before['default_revision_id'] ?? NULL)) {
        throw new ProtectedDraftRefusal('A draft already waits for review; review or discard it first.');
      }
      if (($before['default_revision_id'] ?? NULL) !== $request['expected_default_revision_id']
        || ($before['latest_revision_id'] ?? NULL) !== $request['expected_latest_revision_id']) {
        throw new ProtectedDraftRefusal('The page revision changed; read it again before editing.');
      }
      if (!is_string($before['workflow_fingerprint'] ?? NULL)
        || !hash_equals($before['workflow_fingerprint'], $request['workflow_fingerprint'])) {
        throw new ProtectedDraftRefusal('The workflow configuration changed; read it again before editing.');
      }
      if (!is_string($before['preimage_fingerprint'] ?? NULL)
        || !hash_equals($before['preimage_fingerprint'], $request['preimage_fingerprint'])) {
        throw new ProtectedDraftRefusal('The live page values changed; read them again before editing.');
      }
      $state = $before['draft_states'][$request['draft_state']] ?? NULL;
      if (!is_array($state)
        || ($state['published'] ?? NULL) !== FALSE
        || ($state['default_revision'] ?? NULL) !== FALSE) {
        throw new ProtectedDraftRefusal('The requested draft state must be unpublished and non-default.');
      }
      if (($before['can_update'] ?? NULL) !== TRUE
        || !in_array($request['draft_state'], $before['allowed_draft_states'] ?? [], TRUE)) {
        throw new ProtectedDraftRefusal('The page edit or draft transition is not permitted.');
      }
      foreach ($fields as $field) {
        if (($before['field_access'][$field] ?? NULL) !== TRUE) {
          throw new ProtectedDraftRefusal('A requested field cannot be edited in this translation.');
        }
      }

      // The adapter validates typed field values and entity constraints before
      // its single save. It uses canonical definitions, never an alias
      // resolver.
      $revision_id = $this->storage->saveDraft($nid, $language, $request['draft_state'], $request['updates']);
      if ($revision_id < 1 || $revision_id === $before['default_revision_id']) {
        throw new ProtectedDraftRefusal('A new non-default draft revision was not stored.');
      }
      $stored = $this->storage->readRevision($nid, $revision_id, $language, $fields);
      $this->assertStoredIdentity($stored, $nid, $revision_id, $language, $fields);
      if (($stored['uuid'] ?? NULL) !== ($before['uuid'] ?? NULL)
        || ($stored['default_revision_id'] ?? NULL) !== $before['default_revision_id']
        || ($stored['is_default_revision'] ?? NULL) !== FALSE
        || ($stored['is_published'] ?? NULL) !== FALSE
        || ($stored['moderation_state'] ?? NULL) !== $request['draft_state']) {
        throw new ProtectedDraftRefusal('The stored result is not the requested unpublished non-default draft.');
      }
      $after = $this->storage->readDefault($nid, $language, $fields);
      if (($after['default_revision_id'] ?? NULL) !== $before['default_revision_id']
        || !array_key_exists('published_values', $before)
        || ($after['published_values'] ?? NULL) !== $before['published_values']
        || ($after['workflow_fingerprint'] ?? NULL) !== $before['workflow_fingerprint']) {
        throw new ProtectedDraftRefusal('The live preimage changed during the save; the edit was rolled back.');
      }
      return $stored;
    });
  }

  /**
   * Reads this exact stored revision without substituting default or latest.
   */
  public function read(int $nid, int $revision, string $language, array $fields): array {
    $this->validateIdentity($nid, $language, $fields);
    if ($revision < 1) {
      throw new ProtectedDraftRefusal('An exact stored revision is required.');
    }
    return $this->storage->withLockedNode($nid, function () use ($nid, $revision, $language, $fields): array {
      $stored = $this->storage->readRevision($nid, $revision, $language, $fields);
      $this->assertStoredIdentity($stored, $nid, $revision, $language, $fields);
      return $stored;
    });
  }

  /**
   * Validates the node, exact language and requested field names.
   */
  private function validateIdentity(int $nid, string $language, array $fields): void {
    if ($nid < 1 || $language === '' || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $language)
      || !$fields || !array_is_list($fields)) {
      throw new ProtectedDraftRefusal('A page, its exact language and canonical field names are required.');
    }
    foreach ($fields as $field) {
      if (!is_string($field) || !preg_match('/\A[a-z][a-z0-9_]*\z/', $field)) {
        throw new ProtectedDraftRefusal('Canonical field names are required.');
      }
    }
    if (count(array_unique($fields)) !== count($fields)) {
      throw new ProtectedDraftRefusal('Each requested field must appear once.');
    }
  }

  /**
   * Requires a complete preimage for the exact node and translation.
   */
  private function assertReadableIdentity(array $snapshot, int $nid, string $language): void {
    if (($snapshot['can_read'] ?? NULL) !== TRUE
      || ($snapshot['node_id'] ?? NULL) !== $nid
      || ($snapshot['language'] ?? NULL) !== $language
      || !is_string($snapshot['uuid'] ?? NULL)
      || $snapshot['uuid'] === '') {
      throw new ProtectedDraftRefusal('The exact published preimage is not readable in this language.');
    }
  }

  /**
   * Checks the returned stored revision identity.
   *
   * Requires the exact returned revision, translation and requested stored
   * values.
   */
  private function assertStoredIdentity(array $stored, int $nid, int $revision, string $language, array $fields): void {
    if (($stored['node_id'] ?? NULL) !== $nid
      || ($stored['revision_id'] ?? NULL) !== $revision
      || ($stored['language'] ?? NULL) !== $language
      || !is_array($stored['fields'] ?? NULL)) {
      throw new ProtectedDraftRefusal('The exact stored revision is not readable in this language.');
    }
    $actual = array_keys($stored['fields']);
    sort($actual);
    sort($fields);
    if ($actual !== $fields) {
      throw new ProtectedDraftRefusal('The reader did not return every requested stored field.');
    }
    foreach ($stored['fields'] as $items) {
      if (!is_array($items) || !array_is_list($items)) {
        throw new ProtectedDraftRefusal('Stored fields must preserve their structured item values.');
      }
    }
  }

}
