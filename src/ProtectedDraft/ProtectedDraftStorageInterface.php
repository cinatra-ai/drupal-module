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
   * Returns the untranslated (original) language of the stored default.
   *
   * It is called only inside withLockedNode(). It returns NULL when the page
   * does not exist or has no original language. It makes no access decision:
   * the caller then reads that language through readDefault(), which runs the
   * view and field checks of an exact-language read.
   */
  public function originalLanguage(int $nid): ?string;

  /**
   * Saves one validated non-default draft of the same node and translation.
   */
  public function saveDraft(int $nid, string $language, string $state, array $updates): int;

  /**
   * Reads stored field values from this exact authorized revision/translation.
   */
  public function readRevision(int $nid, int $revision, string $language, array $fields): array;

}
