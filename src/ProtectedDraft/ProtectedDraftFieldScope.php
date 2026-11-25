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

  /**
   * Requires each requested field to be stored exactly as it was reviewed.
   *
   * The scope check above deliberately ignores the requested fields, so a
   * save hook could replace a reviewed value with another valid one. This
   * check compares them. Both sides are reduced to the same form first:
   * scalars become strings (SQL returns "3" and "1" for 3 and TRUE), a decimal
   * loses trailing zeros ("1.50" is 1.5), keys are sorted, and NULL, '' and
   * empty-array properties are dropped (an empty column is the same as an
   * absent one). None of these carries content, so a replaced, blanked or
   * added value, item or property still differs.
   *
   * @param array $reviewed
   *   Field name to the reviewed item values.
   * @param array $stored
   *   Field name to the stored item values.
   */
  public static function assertStoredAsReviewed(array $reviewed, array $stored): void {
    foreach ($reviewed as $field => $items) {
      if (!array_key_exists($field, $stored)
        || !is_array($items) || !is_array($stored[$field])
        || self::canonical($items) !== self::canonical($stored[$field])) {
        throw new ProtectedDraftRefusal('The stored value differs from the reviewed value; nothing was written.');
      }
    }
  }

  /**
   * Reduces a field value to the comparison form of assertStoredAsReviewed().
   */
  private static function canonical(mixed $value): mixed {
    if (is_array($value)) {
      $list = array_is_list($value);
      $result = [];
      foreach ($value as $key => $item) {
        $item = self::canonical($item);
        if ($item === NULL || $item === '' || $item === []) {
          continue;
        }
        $result[$key] = $item;
      }
      if ($list) {
        return array_values($result);
      }
      ksort($result);
      return $result;
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
      $value = (string) $value;
    }
    if (is_string($value) && preg_match('/\A-?\d+\.\d+\z/', $value)) {
      $value = rtrim(rtrim($value, '0'), '.');
    }
    return $value;
  }

}
