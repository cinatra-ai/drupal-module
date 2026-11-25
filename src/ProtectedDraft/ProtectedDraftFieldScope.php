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
   * check compares them, item by item and property by property. A value is
   * normalised only by its property's typed-data TYPE, never by what it
   * looks like:
   * - integer: an integer and its decimal digit string are the same ("3");
   * - decimal and float: a number and its digit string are the same, and
   *   trailing fractional zeros do not count ("1.50" is 1.5);
   * - boolean: TRUE, 1 and "1" are the same, and FALSE, 0 and "0";
   * - every other type (string, text, format, uri, ...): exact; a number
   *   given for it is compared as the string the column stores.
   * A NULL property is the same as an absent one, because Drupal stores an
   * unset property as NULL. An empty map property ([]) is the same as an
   * absent one. '' is a value: Drupal stores '' in a string column, distinct
   * from NULL. A property without a known type is compared exactly.
   *
   * @param array $reviewed
   *   Field name to the reviewed item values.
   * @param array $stored
   *   Field name to the stored item values.
   * @param array $types
   *   Field name to property name to typed-data type.
   */
  public static function assertStoredAsReviewed(array $reviewed, array $stored, array $types): void {
    foreach ($reviewed as $field => $items) {
      $field_types = is_array($types[$field] ?? NULL) ? $types[$field] : [];
      if (!array_key_exists($field, $stored)
        || !is_array($items) || !is_array($stored[$field])
        || self::canonicalItems($items, $field_types) !== self::canonicalItems($stored[$field], $field_types)) {
        throw new ProtectedDraftRefusal('The stored value differs from the reviewed value; nothing was written.');
      }
    }
  }

  /**
   * Reduces a field's items to the comparison form, or NULL when malformed.
   */
  private static function canonicalItems(array $items, array $types): ?array {
    if (!array_is_list($items)) {
      return NULL;
    }
    $result = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        return NULL;
      }
      $canonical = [];
      foreach ($item as $property => $value) {
        $type = is_string($types[$property] ?? NULL) ? $types[$property] : NULL;
        if ($value === NULL || ($type === 'map' && $value === [])) {
          continue;
        }
        $canonical[$property] = self::canonicalValue($value, $type);
      }
      ksort($canonical);
      $result[] = $canonical;
    }
    return $result;
  }

  /**
   * Normalises one property value by its typed-data type only.
   */
  private static function canonicalValue(mixed $value, ?string $type): mixed {
    if ($type === 'boolean') {
      if ($value === TRUE || $value === 1 || $value === '1') {
        return '1';
      }
      if ($value === FALSE || $value === 0 || $value === '0') {
        return '0';
      }
      return [$value];
    }
    if ($type === 'integer') {
      if (is_int($value)) {
        return (string) $value;
      }
      return is_string($value) && preg_match('/\A-?(0|[1-9]\d*)\z/', $value) ? $value : [$value];
    }
    if ($type === 'decimal' || $type === 'float') {
      if (is_int($value) || is_float($value)) {
        $value = (string) $value;
      }
      if (!is_string($value) || !preg_match('/\A-?\d+(\.\d+)?\z/', $value)) {
        return [$value];
      }
      return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
    if ($type === NULL) {
      return [$value];
    }
    if (is_array($value)) {
      return self::sortedMap($value);
    }
    return is_int($value) || is_float($value) ? (string) $value : $value;
  }

  /**
   * Sorts the keys of a map value at every level; lists keep their order.
   */
  private static function sortedMap(array $value): array {
    foreach ($value as $key => $item) {
      if (is_array($item)) {
        $value[$key] = self::sortedMap($item);
      }
    }
    if (!array_is_list($value)) {
      ksort($value);
    }
    return $value;
  }

}
