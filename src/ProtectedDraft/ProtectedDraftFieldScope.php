<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Keeps stored draft content within the requested fields and translation.
 */
final class ProtectedDraftFieldScope {

  /**
   * Rejects a save hook changing any unrequested revisioned content.
   */
  public static function assertMatches(array $before, array $after, string $language, array $updated_fields, array $shared_fields = []): void {
    // The adapter derives this subset from actual field definitions. A caller
    // cannot excuse unrelated content by labelling an unrequested field shared.
    if (array_diff($shared_fields, $updated_fields)) {
      throw new ProtectedDraftRefusal('Shared stored fields must belong to the requested edit scope.');
    }
    $languages = array_keys($before);
    $stored_languages = array_keys($after);
    sort($languages);
    sort($stored_languages);
    if ($languages !== $stored_languages || !isset($before[$language])) {
      throw new ProtectedDraftRefusal('The stored draft changed the page translations outside the edit scope.');
    }
    foreach ($before as $code => $fields) {
      $expected = $fields;
      $stored = $after[$code];
      foreach ($shared_fields as $field) {
        if (!array_key_exists($field, $after[$language])
          || !array_key_exists($field, $stored)
          || $stored[$field] !== $after[$language][$field]) {
          throw new ProtectedDraftRefusal('A shared stored field must have one value across all page translations.');
        }
        unset($expected[$field], $stored[$field]);
      }
      if ($code === $language) {
        foreach (array_merge($updated_fields, ['status']) as $field) {
          unset($expected[$field], $stored[$field]);
        }
      }
      ksort($expected);
      ksort($stored);
      if ($expected !== $stored) {
        throw new ProtectedDraftRefusal('The stored draft changed an unrequested field or another translation.');
      }
    }
  }

}
