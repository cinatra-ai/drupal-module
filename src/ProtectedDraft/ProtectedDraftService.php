<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Protects published pages and returns actual stored draft field values.
 *
 * It also writes or publishes an unpublished page only while the page is
 * unchanged since the read that a person reviewed.
 */
final class ProtectedDraftService {

  /**
   * The binding keys that both unpublished write steps require.
   */
  private const UNPUBLISHED_BINDING = [
    'nid',
    'language',
    'expected_default_revision_id',
    'expected_latest_revision_id',
    'expected_changed',
    'workflow_fingerprint',
    'preimage_fingerprint',
  ];

  /**
   * The refusal of an incomplete or malformed unpublished write request.
   */
  private const UNPUBLISHED_MALFORMED = 'A complete language-bound revision, changed time, workflow and content binding is required.';

  public function __construct(private readonly ProtectedDraftStorageInterface $storage) {
  }

  /**
   * Reads the protected preimage needed to bind a subsequent edit.
   *
   * A NULL language binds the untranslated (original) language of the stored
   * default revision. The result reports the bound language in 'language'.
   */
  public function prepare(int $nid, ?string $language, array $fields): array {
    $this->validateIdentity($nid, $language, $fields);
    return $this->storage->withLockedNode($nid, function () use ($nid, $language, $fields): array {
      if ($language === NULL) {
        $language = $this->storage->originalLanguage($nid);
        if ($language === NULL) {
          throw new ProtectedDraftRefusal('The exact page translation is not available.');
        }
        $this->validateIdentity($nid, $language, $fields);
      }
      $snapshot = $this->storage->readDefault($nid, $language, $fields);
      $this->assertReadableIdentity($snapshot, $nid, $language);
      $allowed = [
        'node_id', 'uuid', 'language', 'default_revision_id',
        'latest_revision_id', 'is_default_revision', 'is_published', 'changed',
        'moderated', 'canonical_moderation_field', 'workflow_fingerprint', 'preimage_fingerprint',
        'draft_states', 'allowed_draft_states', 'fields',
        'available_fields', 'translation_languages',
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
      ProtectedDraftFieldScope::assertStoredAsReviewed($request['updates'], $stored['fields']);
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
   * Writes field values to an unpublished page only while it is unchanged.
   *
   * The new revision becomes the default revision of the unpublished page.
   * With 'publish' TRUE the same save also publishes it, so no other save can
   * come between the reviewed write and the publication. A moderated page
   * moves only through a workflow transition the editor may use.
   */
  public function writeUnpublished(array $request): array {
    $allowed = array_merge(self::UNPUBLISHED_BINDING, ['updates', 'publish']);
    if (array_diff(array_keys($request), $allowed)
      || !$this->validUnpublishedBinding($request)
      || !is_array($request['updates'] ?? NULL)
      || !$request['updates']
      || array_is_list($request['updates'])
      || (array_key_exists('publish', $request) && !is_bool($request['publish']))) {
      throw new ProtectedDraftRefusal(self::UNPUBLISHED_MALFORMED);
    }
    return $this->saveUnpublished($request, $request['updates'], $request['publish'] ?? FALSE);
  }

  /**
   * Publishes an unpublished page as it is, only while it is unchanged.
   */
  public function publishUnpublished(array $request): array {
    if (array_diff(array_keys($request), self::UNPUBLISHED_BINDING)
      || !$this->validUnpublishedBinding($request)) {
      throw new ProtectedDraftRefusal(self::UNPUBLISHED_MALFORMED);
    }
    return $this->saveUnpublished($request, [], TRUE);
  }

  /**
   * Whether the request carries a complete, well-formed binding.
   */
  private function validUnpublishedBinding(array $request): bool {
    return is_int($request['nid'] ?? NULL)
      && is_string($request['language'] ?? NULL)
      && is_int($request['expected_default_revision_id'] ?? NULL)
      && $request['expected_default_revision_id'] >= 1
      && is_int($request['expected_latest_revision_id'] ?? NULL)
      && $request['expected_latest_revision_id'] >= 1
      && is_int($request['expected_changed'] ?? NULL)
      && $request['expected_changed'] >= 0
      && is_string($request['workflow_fingerprint'] ?? NULL)
      && preg_match('/\A[a-f0-9]{64}\z/', $request['workflow_fingerprint']) === 1
      && is_string($request['preimage_fingerprint'] ?? NULL)
      && preg_match('/\A[a-f0-9]{64}\z/', $request['preimage_fingerprint']) === 1;
  }

  /**
   * Compares the binding under the node lock, then saves one default revision.
   *
   * Every check runs before the save. A failed check after the save throws,
   * which rolls the root transaction back, so a refusal never writes.
   */
  private function saveUnpublished(array $request, array $updates, bool $publish): array {
    $nid = $request['nid'];
    $language = $request['language'];
    $fields = array_keys($updates);
    $this->validateIdentity($nid, $language, $fields, $publish && !$updates);

    return $this->storage->withLockedNode($nid, function () use ($request, $nid, $language, $fields, $updates, $publish): array {
      $before = $this->storage->readDefault($nid, $language, $fields);
      $this->assertReadableIdentity($before, $nid, $language);
      if (($before['is_default_revision'] ?? NULL) !== TRUE
        || ($before['is_published'] ?? NULL) !== FALSE
        || !is_array($before['published_languages'] ?? NULL)
        || $before['published_languages'] !== []) {
        throw new ProtectedDraftRefusal('This page is published; edit it through the protected draft instead.');
      }
      if (!is_bool($before['moderated'] ?? NULL)) {
        throw new ProtectedDraftRefusal('The page workflow could not be read.');
      }
      if ($before['moderated'] && ($before['canonical_moderation_field'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('The moderation field is ambiguous or maps to a custom field.');
      }
      if (($before['latest_revision_id'] ?? NULL) !== ($before['default_revision_id'] ?? NULL)) {
        throw new ProtectedDraftRefusal('A newer revision than the current one exists; review it first.');
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
        throw new ProtectedDraftRefusal('The page values changed; read them again before editing.');
      }
      if (($before['changed'] ?? NULL) !== $request['expected_changed']) {
        throw new ProtectedDraftRefusal('The page was saved after it was read; read it again before editing.');
      }
      if (($before['can_update'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('Editing this page is not permitted.');
      }
      // A moderated page is published by a workflow transition, which
      // unpublishedTargetState() checks against the editor's permissions.
      if ($publish && !$before['moderated'] && ($before['can_publish'] ?? NULL) !== TRUE) {
        throw new ProtectedDraftRefusal('Publishing this page is not permitted.');
      }
      foreach ($fields as $field) {
        if (($before['field_access'][$field] ?? NULL) !== TRUE) {
          throw new ProtectedDraftRefusal('A requested field cannot be edited in this translation.');
        }
      }
      $state = $before['moderated'] ? $this->unpublishedTargetState($before, $publish) : NULL;

      $revision_id = $this->storage->saveUnpublished($nid, $language, $updates, $publish, $state);
      if ($revision_id < 1 || $revision_id === $before['default_revision_id']) {
        throw new ProtectedDraftRefusal('A new default revision was not stored.');
      }
      // The new revision is the default one, so the default read returns its
      // stored values with the same view and field checks as the first read.
      $after = $this->storage->readDefault($nid, $language, $fields);
      $this->assertReadableIdentity($after, $nid, $language);
      if (($after['uuid'] ?? NULL) !== ($before['uuid'] ?? NULL)
        || ($after['default_revision_id'] ?? NULL) !== $revision_id
        || ($after['latest_revision_id'] ?? NULL) !== $revision_id
        || ($after['is_default_revision'] ?? NULL) !== TRUE
        || ($after['is_published'] ?? NULL) !== $publish
        || ($after['moderated'] ?? NULL) !== $before['moderated']
        || ($state !== NULL && ($after['moderation_state'] ?? NULL) !== $state)
        || ($after['workflow_fingerprint'] ?? NULL) !== $before['workflow_fingerprint']
        || !is_int($after['changed'] ?? NULL)) {
        throw new ProtectedDraftRefusal('The stored result is not the requested revision; the write was rolled back.');
      }
      $stored = $after['fields'] ?? NULL;
      if (!is_array($stored)) {
        throw new ProtectedDraftRefusal('The reader did not return every requested stored field.');
      }
      $actual = array_keys($stored);
      $expected = $fields;
      sort($actual);
      sort($expected);
      if ($actual !== $expected) {
        throw new ProtectedDraftRefusal('The reader did not return every requested stored field.');
      }
      foreach ($stored as $items) {
        if (!is_array($items) || !array_is_list($items)) {
          throw new ProtectedDraftRefusal('Stored fields must preserve their structured item values.');
        }
      }
      // What is stored, and with publish what goes live, must be what was
      // reviewed; a difference rolls the save back.
      ProtectedDraftFieldScope::assertStoredAsReviewed($updates, $stored);
      return [
        'node_id' => $nid,
        'uuid' => $after['uuid'],
        'language' => $language,
        'revision_id' => $revision_id,
        'is_default_revision' => TRUE,
        'is_published' => $publish,
        'changed' => $after['changed'],
        'fields' => $stored,
      ];
    });
  }

  /**
   * Chooses the workflow state of a moderated unpublished write.
   *
   * Without publish the page keeps its current state, which must be an
   * unpublished state that a transition the editor may use leads back to (in
   * the core editorial workflow: Draft to Draft). With publish the target is
   * the one published default-revision state that a transition the editor may
   * use leads to from the current state. Anything else is refused before the
   * save.
   */
  private function unpublishedTargetState(array $before, bool $publish): string {
    $states = $before['draft_states'] ?? NULL;
    $allowed = $before['allowed_draft_states'] ?? NULL;
    $current = $before['moderation_state'] ?? NULL;
    if (!is_array($states) || !is_array($allowed) || !is_string($current) || !is_array($states[$current] ?? NULL)) {
      throw new ProtectedDraftRefusal('The page workflow could not be read.');
    }
    if (!$publish) {
      if (($states[$current]['published'] ?? NULL) !== FALSE || !in_array($current, $allowed, TRUE)) {
        throw new ProtectedDraftRefusal('The page workflow does not allow this change.');
      }
      return $current;
    }
    $targets = [];
    foreach ($allowed as $id) {
      if (is_string($id)
        && ($states[$id]['published'] ?? NULL) === TRUE
        && ($states[$id]['default_revision'] ?? NULL) === TRUE) {
        $targets[] = $id;
      }
    }
    $targets = array_values(array_unique($targets));
    if (count($targets) !== 1) {
      throw new ProtectedDraftRefusal('The page workflow does not allow publishing it.');
    }
    return $targets[0];
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
   *
   * A NULL language is accepted only by prepare(), which resolves it to the
   * original language and validates the result again. No field names are
   * accepted only by publishUnpublished(), which changes no field value.
   */
  private function validateIdentity(int $nid, ?string $language, array $fields, bool $allow_no_fields = FALSE): void {
    if ($nid < 1
      || ($language !== NULL && ($language === '' || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $language)))
      || (!$fields && !$allow_no_fields) || !array_is_list($fields)) {
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
