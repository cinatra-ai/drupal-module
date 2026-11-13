<?php

declare(strict_types=1);

namespace Drupal\cinatra\ProtectedDraft;

/**
 * Binds complete configuration sets, including additions and removals.
 */
final class ProtectedDraftConfiguration {

  /**
   * Rejects any difference between locked and effective configuration sets.
   */
  public static function assertSameSet(array $raw, array $effective): void {
    if (!hash_equals(self::fingerprint($raw), self::fingerprint($effective))) {
      throw new ProtectedDraftRefusal('The effective definitions differ from the locked configuration; refresh the site configuration before editing.');
    }
  }

  /**
   * Field storage properties that never reach the installed storage schema.
   *
   * Drupal's field storage listener stores the last-installed definition
   * before ConfigEntityBase::preSave() calculates dependencies, so a later
   * request reads that snapshot back without them. These keys are config
   * entity metadata; none of them changes a table, a column or an index.
   * Every other property, including one unknown today, stays bound.
   */
  private const NON_SCHEMA_STORAGE_PROPERTIES = ['_core', 'dependencies', 'langcode', 'status', 'uuid'];

  /**
   * Properties every installed field storage definition must carry.
   */
  private const REQUIRED_STORAGE_PROPERTIES = ['entity_type', 'field_name', 'id', 'type'];

  /**
   * Rejects a schema-relevant difference of installed field storage sets.
   *
   * Compares the raw field.storage.* configuration with the storage handler's
   * last-installed definitions. Names on one side only, and any difference in
   * a property outside NON_SCHEMA_STORAGE_PROPERTIES, refuse.
   */
  public static function assertSameInstalledStorageSet(array $raw, array $installed): void {
    self::assertSameSet(self::installedStorageProjection($raw), self::installedStorageProjection($installed));
  }

  /**
   * Drops the non-schema metadata of each field storage definition.
   */
  private static function installedStorageProjection(array $set): array {
    $result = [];
    foreach ($set as $name => $definition) {
      if (!is_array($definition)) {
        throw new ProtectedDraftRefusal('The effective definitions differ from the locked configuration; refresh the site configuration before editing.');
      }
      $definition = array_diff_key($definition, array_flip(self::NON_SCHEMA_STORAGE_PROPERTIES));
      foreach (self::REQUIRED_STORAGE_PROPERTIES as $property) {
        if (!isset($definition[$property]) || !is_string($definition[$property]) || $definition[$property] === '') {
          throw new ProtectedDraftRefusal('The effective definitions differ from the locked configuration; refresh the site configuration before editing.');
        }
      }
      $result[$name] = $definition;
    }
    return $result;
  }

  /**
   * Creates a deterministic token for a complete configuration value.
   */
  public static function fingerprint(array $value): string {
    self::sortRecursively($value);
    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
  }

  /**
   * Sorts map keys recursively while preserving list order.
   */
  private static function sortRecursively(array &$value): void {
    if (!array_is_list($value)) {
      ksort($value);
    }
    foreach ($value as &$child) {
      if (is_array($child)) {
        self::sortRecursively($child);
      }
    }
  }

}
