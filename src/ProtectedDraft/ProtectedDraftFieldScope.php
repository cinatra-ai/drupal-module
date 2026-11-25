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
   * field, the PHP binding or the database column would round, coerce,
   * truncate or print differently is an invalid input with a clear reason
   * instead of a rollback after the fact. Every value of a numeric column is
   * examined, whatever PHP type it arrives as. A string must be a plain
   * decimal numeral (a sign, digits and a point; no exponent, space, hex or
   * empty string); a PHP float is read as its shortest round-trip numeral
   * (1.24 is "1.24", never 1.2399999999999999911); a boolean, or any other
   * type, is refused. Then, per column:
   * - integer: a PHP int or an integer numeral (a float is refused: it cannot
   *   tell 3.0 from a larger integer that lost digits), within the range of
   *   the column type (size and unsigned from the field schema; the PHP
   *   integer range when no column is known);
   * - decimal: no more fraction digits than the scale, no more integer digits
   *   than precision minus scale, and the value survives the field's own
   *   round() to a PHP float and the float's conversion to text for the
   *   database unchanged;
   * - float: the column is single precision on MySQL and PostgreSQL (FLOAT,
   *   real), so the value must have at most 6 significant digits, the digit
   *   count a single-precision value always reads back as, and lie between
   *   0.0001 and 999999 in size (or be zero), where every database prints it
   *   without an exponent.
   * A true/false property takes only TRUE, FALSE, 1, 0, "1" or "0".
   *
   * @param array $items
   *   The requested items, a list of property maps.
   * @param array $types
   *   Property name to typed-data type.
   * @param array $settings
   *   The field storage settings: 'precision' and 'scale' for a decimal.
   * @param array $columns
   *   The field schema columns, keyed by property name: their 'type', 'size'
   *   and 'unsigned' give the integer range.
   */
  public static function assertNumbersExact(array $items, array $types, array $settings = [], array $columns = []): void {
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach ($item as $property => $value) {
        $type = $types[$property] ?? NULL;
        if ($value === NULL) {
          continue;
        }
        if ($type === 'boolean') {
          if (!in_array($value, [TRUE, FALSE, 1, 0, '1', '0'], TRUE)) {
            throw new ProtectedDraftRefusal('A requested true/false value must be true, false, 1 or 0; nothing was written.');
          }
          continue;
        }
        $column = is_array($columns[$property] ?? NULL) ? $columns[$property] : [];
        $kind = match ($column['type'] ?? NULL) {
          'int' => 'integer',
          'numeric' => 'decimal',
          'float' => 'float',
          default => in_array($type, ['integer', 'decimal', 'float'], TRUE) ? $type : NULL,
        };
        if ($kind === NULL) {
          continue;
        }
        $canonical = self::requestedNumeral($value, $kind);
        if ($canonical === NULL || !self::numeralFits($canonical, $kind, $settings + $column, is_float($value))) {
          throw new ProtectedDraftRefusal('A requested number cannot be stored exactly by its field; nothing was written.');
        }
      }
    }
  }

  /**
   * Reads a requested numeric value as a canonical numeral, or NULL.
   */
  private static function requestedNumeral(mixed $value, string $kind): ?string {
    if (is_int($value)) {
      return (string) $value;
    }
    if (is_float($value)) {
      return $kind === 'integer' ? NULL : self::floatNumeral($value);
    }
    return is_string($value) ? self::canonicalNumeral($value, $kind !== 'integer') : NULL;
  }

  /**
   * Tells whether a canonical numeral is held exactly by its column.
   */
  private static function numeralFits(string $canonical, string $kind, array $settings, bool $native_float): bool {
    if ($kind === 'integer') {
      if ($canonical !== (string) (int) $canonical) {
        return FALSE;
      }
      [$minimum, $maximum] = self::integerRange($settings);
      return (int) $canonical >= $minimum && (int) $canonical <= $maximum;
    }
    if ($kind === 'float') {
      $parts = explode('.', ltrim($canonical, '-'), 2);
      $digits = ltrim($parts[0] . ($parts[1] ?? ''), '0');
      if ($digits === '') {
        return TRUE;
      }
      $leading_zeroes = $parts[0] === '0' ? strlen($parts[1] ?? '') - strlen(ltrim($parts[1] ?? '', '0')) : 0;
      $fits = strlen(rtrim($digits, '0')) <= 6 && strlen($parts[0]) <= 6 && $leading_zeroes <= 3;
      // A PHP float goes to the database as its PHP text form.
      return $fits && (!$native_float || self::textNumeral((string) (float) $canonical) === $canonical);
    }
    $precision = $settings['precision'] ?? NULL;
    $scale = $settings['scale'] ?? NULL;
    if (!is_numeric($precision) || !is_numeric($scale)) {
      return TRUE;
    }
    $parts = explode('.', ltrim($canonical, '-'), 2);
    $integer_digits = $parts[0] === '0' ? 0 : strlen($parts[0]);
    // DecimalItem::preSave() rounds to a PHP float, which goes to the
    // database as its PHP text form; that text must be the same number.
    return strlen($parts[1] ?? '') <= (int) $scale && $integer_digits <= (int) $precision - (int) $scale
      && self::textNumeral((string) round((float) $canonical, (int) $scale)) === $canonical;
  }

  /**
   * Returns the value range of an integer column, by its schema.
   *
   * @return int[]
   *   The lowest and the highest value.
   */
  private static function integerRange(array $column): array {
    $bits = match ($column['size'] ?? NULL) {
      'tiny' => 8,
      'small' => 16,
      'medium' => 24,
      'big' => 64,
      'normal' => 32,
      default => isset($column['type']) ? 32 : 64,
    };
    if ($bits === 64) {
      return !empty($column['unsigned']) ? [0, PHP_INT_MAX] : [PHP_INT_MIN, PHP_INT_MAX];
    }
    return !empty($column['unsigned']) ? [0, 2 ** $bits - 1] : [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1];
  }

  /**
   * Returns the shortest numeral that reads back as the same float, or NULL.
   *
   * Independent of the serialize_precision setting: it tries 1 to 17
   * significant digits and keeps the first that reads back exactly.
   */
  private static function floatNumeral(float $value): ?string {
    if (!is_finite($value)) {
      return NULL;
    }
    for ($digits = 1; $digits <= 17; $digits++) {
      $text = sprintf('%.' . ($digits - 1) . 'e', $value);
      if ((float) $text === $value) {
        break;
      }
    }
    return self::textNumeral($text);
  }

  /**
   * Reads a numeral that may use an exponent as a canonical numeral, or NULL.
   */
  private static function textNumeral(string $text): ?string {
    if (!preg_match('/\A([+-]?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)\z/', $text, $parts)) {
      return self::canonicalNumeral($text, TRUE);
    }
    $exponent = (int) $parts[4];
    if (abs($exponent) > 400) {
      return NULL;
    }
    $digits = $parts[2] . ($parts[3] ?? '');
    $point = strlen($parts[2]) + $exponent;
    if ($point <= 0) {
      $plain = '0.' . str_repeat('0', -$point) . $digits;
    }
    elseif ($point >= strlen($digits)) {
      $plain = $digits . str_repeat('0', $point - strlen($digits));
    }
    else {
      $plain = substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
    return self::canonicalNumeral($parts[1] . $plain, TRUE);
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
        $numeral = $type === 'integer' ? NULL : self::floatNumeral($value);
        return $numeral ?? [$value];
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
