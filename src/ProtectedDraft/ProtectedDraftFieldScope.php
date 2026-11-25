<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Keeps stored draft content within the requested fields and translation.
 */
final class ProtectedDraftFieldScope {

  /**
   * Rejects a save hook changing any unrequested revisioned content.
   *
   * Every translation must be stored as before, except for the requested
   * fields and what Drupal core itself changes in the same save:
   * - the edited translation: its status, "changed" timestamp and
   *   "revision_translation_affected" flag;
   * - every other translation: exactly the bookkeeping core gives it.
   *   ChangedItem::preSave() moves a translation's "changed" to the request
   *   time when it has changes (ContentEntityBase::hasTranslationChanges()) or
   *   has no timestamp yet, and otherwise keeps it; and
   *   ContentEntityStorageBase::populateAffectedRevisionTranslations() marks
   *   it affected exactly when it has changes. The only change such a
   *   translation may have is a changed value of a requested untranslatable
   *   (shared) field, and only when core counts untranslatable fields for it
   *   ($shared_change_languages: not for a non-default translation of a bundle
   *   whose untranslatable fields affect only the default translation).
   *
   * @param array $before
   *   Language to field name to values, before the save; the affected flag is
   *   the one an untouched translation holds in the new revision (FALSE).
   * @param array $after
   *   The same, as stored; the affected flag as a boolean.
   * @param string $language
   *   The edited translation.
   * @param array $updated_fields
   *   The requested fields.
   * @param array $shared_fields
   *   The requested fields that are untranslatable.
   * @param int|null $request_time
   *   The request time core stamps; NULL keeps every other timestamp fixed.
   * @param array $shared_change_languages
   *   The translations for which core counts a change of an untranslatable
   *   field as a change of the translation.
   */
  public static function assertMatches(array $before, array $after, string $language, array $updated_fields, array $shared_fields = [], ?int $request_time = NULL, array $shared_change_languages = []): void {
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
      $shared_changed = FALSE;
      foreach ($shared_fields as $field) {
        if (!array_key_exists($field, $after[$language])
          || !array_key_exists($field, $stored)
          || $stored[$field] !== $after[$language][$field]) {
          throw new ProtectedDraftRefusal('A shared stored field must have one value across all page translations.');
        }
        $shared_changed = $shared_changed || !self::sameItems($expected[$field] ?? [], $stored[$field]);
        unset($expected[$field], $stored[$field]);
      }
      if ($code === $language) {
        // The edited translation's own bookkeeping: the save moves its
        // timestamp and marks it affected; the mark must be there.
        if (array_key_exists('revision_translation_affected', $expected)
          && ($stored['revision_translation_affected'] ?? NULL) !== TRUE) {
          throw new ProtectedDraftRefusal('The stored revision is not marked as changing the edited translation; nothing was written.');
        }
        foreach (array_merge($updated_fields, ['status', 'changed', 'revision_translation_affected']) as $field) {
          unset($expected[$field], $stored[$field]);
        }
      }
      else {
        // Another translation: exactly what core gives it in this save.
        $affected = $shared_changed && in_array($code, $shared_change_languages, TRUE);
        if (array_key_exists('changed', $expected) && $expected['changed'] !== []) {
          $old = self::timestamp($expected['changed']);
          if ($old !== NULL || $request_time !== NULL) {
            $expected['changed'] = $request_time !== NULL && ($affected || !$old) ? $request_time : $old;
            $stored['changed'] = array_key_exists('changed', $stored) ? (self::timestamp($stored['changed']) ?? $stored['changed']) : NULL;
          }
        }
        if (array_key_exists('revision_translation_affected', $expected)) {
          $expected['revision_translation_affected'] = $affected;
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
   * Tells whether two item lists are equal as FieldItemList::equals() does.
   *
   * Same count, and each item's properties, NULL ones dropped and keys
   * sorted, loosely equal.
   */
  private static function sameItems(mixed $a, mixed $b): bool {
    if ($a === $b) {
      return TRUE;
    }
    if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
      return FALSE;
    }
    $normalise = static function (mixed $item): mixed {
      if (!is_array($item)) {
        return $item;
      }
      $item = array_filter($item, static fn(mixed $value): bool => $value !== NULL);
      ksort($item);
      return $item;
    };
    return array_map($normalise, array_values($a)) == array_map($normalise, array_values($b));
  }

  /**
   * Reads a stored timestamp field value as an integer, or NULL.
   *
   * NULL for an empty value and for anything that is not one item whose only
   * property is a whole number.
   */
  private static function timestamp(mixed $items): ?int {
    if (!is_array($items) || count($items) !== 1 || !isset($items[0]) || !is_array($items[0])
      || array_keys($items[0]) !== ['value']) {
      return NULL;
    }
    $value = $items[0]['value'];
    if (is_int($value)) {
      return $value;
    }
    return is_string($value) && preg_match('/\A-?\d+\z/', $value) ? (int) $value : NULL;
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
   * Refuses a requested number that its field cannot store exactly.
   *
   * Runs on the request's own values, before the save, so a value that the
   * field would round, coerce or truncate is an invalid input with a clear
   * reason instead of a rollback after the fact. Only a numeric property
   * given as a STRING is examined; a PHP int or float is already the value
   * the field would hold. Per type, a string must be a plain decimal numeral
   * (no exponent, space, hex or empty) and then:
   * - integer: no fraction, and within the PHP integer range;
   * - decimal: no more fraction digits than the field's scale and no more
   *   integer digits than precision minus scale;
   * - float (a double when stored): it must survive a round trip through a
   *   double, so a value beyond double precision, or one a double prints in
   *   exponent form (from 1e15), is refused.
   *
   * @param array $items
   *   The requested items, a list of property maps.
   * @param array $types
   *   Property name to typed-data type.
   * @param array $settings
   *   The field storage settings: 'precision' and 'scale' for a decimal.
   */
  public static function assertNumbersExact(array $items, array $types, array $settings = []): void {
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach ($item as $property => $value) {
        $type = $types[$property] ?? NULL;
        if (!is_string($value) || !in_array($type, ['integer', 'decimal', 'float'], TRUE)) {
          continue;
        }
        $canonical = self::canonicalNumeral($value, $type !== 'integer');
        if ($canonical === NULL || !self::numeralFits($canonical, $type, $settings)) {
          throw new ProtectedDraftRefusal('A requested number cannot be stored exactly by its field; nothing was written.');
        }
      }
    }
  }

  /**
   * Tells whether a canonical numeral is held exactly by its field type.
   */
  private static function numeralFits(string $canonical, string $type, array $settings): bool {
    if ($type === 'integer') {
      return $canonical === (string) (int) $canonical;
    }
    if ($type === 'float') {
      $double = json_encode((float) $canonical);
      return is_string($double) && self::canonicalNumeral($double, TRUE) === $canonical;
    }
    $precision = $settings['precision'] ?? NULL;
    $scale = $settings['scale'] ?? NULL;
    if (!is_numeric($precision) || !is_numeric($scale)) {
      return TRUE;
    }
    $parts = explode('.', ltrim($canonical, '-'), 2);
    $integer_digits = $parts[0] === '0' ? 0 : strlen($parts[0]);
    return strlen($parts[1] ?? '') <= (int) $scale && $integer_digits <= (int) $precision - (int) $scale;
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
      if (is_int($value)) {
        $value = (string) $value;
      }
      elseif (is_float($value)) {
        // The shortest text that reads back as the same double, not the
        // 14-digit text of a string cast, so no two doubles read as equal.
        $value = is_finite($value) ? json_encode($value) : [$value];
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
