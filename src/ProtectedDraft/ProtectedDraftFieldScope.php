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
      if ($shared_fields) {
        // Core itself marks every translation as affected, and moves its
        // timestamp, when an untranslatable field changes: such a write
        // legitimately touches all translations' bookkeeping.
        unset($expected['changed'], $stored['changed']);
        unset($expected['revision_translation_affected'], $stored['revision_translation_affected']);
      }
      if ($code === $language) {
        // The edited translation's own bookkeeping: the save moves its
        // timestamp and marks it affected. No other translation's may move.
        foreach (array_merge($updated_fields, ['status', 'changed', 'revision_translation_affected']) as $field) {
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
   * - integer, decimal and float: a number and its digit string are the same,
   *   through one exact string form (see canonicalNumeral()), so "03" is "3",
   *   "01.50", "1.50", "1.5" and "+1.5" are the same, and "-0" is "0". A
   *   float is a PHP double when stored: only a value that reads back as a
   *   plain numeral compares equal; one in exponent form, or beyond double
   *   precision, differs from the reviewed digits and refuses (never accepts);
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
    if ($type === 'integer' || $type === 'decimal' || $type === 'float') {
      if (is_int($value) || is_float($value)) {
        $value = (string) $value;
      }
      $canonical = is_string($value) ? self::canonicalNumeral($value, $type !== 'integer') : NULL;
      return $canonical ?? [$value];
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
   * Reduces a plain decimal numeral to its one exact form, or NULL.
   *
   * Strings only, with no float cast, so a value beyond float precision keeps
   * every digit. The form: no sign for zero or a plus sign, a minus sign only
   * for a non-zero value, the integer part without leading zeroes (one digit
   * at least), the fraction without trailing zeroes, and no point when the
   * fraction is empty. "01.50", "1.5" and "+1.50" are the same; "-0", "+0",
   * "0.0" and ".0" are "0". The accepted spellings are those of Drupal's own
   * decimal validation: a sign, digits, and an optional point, with a bare
   * ".5" and a bare "5." both valid. An integer takes no point. Anything else
   * (an exponent, whitespace, a hex or empty string, a second point) is NOT a
   * plain numeral and returns NULL, so the caller compares the raw value and
   * nothing unequal can become equal.
   */
  private static function canonicalNumeral(string $value, bool $allow_fraction): ?string {
    $pattern = $allow_fraction ? '/\A([+-]?)(\d*)(?:\.(\d*))?\z/' : '/\A([+-]?)(\d+)\z/';
    if (!preg_match($pattern, $value, $parts)) {
      return NULL;
    }
    // PCRE leaves out a trailing group that did not take part in the match.
    $fraction = rtrim($parts[3] ?? '', '0');
    if ($parts[2] === '' && ($parts[3] ?? '') === '') {
      return NULL;
    }
    $integer = ltrim($parts[2], '0');
    if ($integer === '') {
      $integer = '0';
    }
    $sign = $parts[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';
    return $sign . $integer . ($fraction === '' ? '' : '.' . $fraction);
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
