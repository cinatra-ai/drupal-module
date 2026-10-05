<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Revision operations inside one transaction against ordinary Drupal writers.
 */
interface ProtectedDraftStorageInterface {

  /**
   * Locks revision/configuration storage and commits only a verified result.
   *
   * No operation may save before its preconditions pass. An exception must roll
   * back the root transaction, including an unexpected stored-result mismatch.
   */
  public function withLockedNode(int $nid, callable $operation): array;

  /**
   * Reads an authorized, language-bound default preimage and safety metadata.
   *
   * The internal published_values snapshot must never be exported to a caller:
   * only the requested fields have passed the caller's field-read checks.
   */
  public function readDefault(int $nid, string $language, array $fields): array;

  /**
   * Saves one validated non-default draft of the same node and translation.
   */
  public function saveDraft(int $nid, string $language, string $state, array $updates): int;

  /**
   * Reads stored field values from this exact authorized revision/translation.
   */
  public function readRevision(int $nid, int $revision, string $language, array $fields): array;

}
