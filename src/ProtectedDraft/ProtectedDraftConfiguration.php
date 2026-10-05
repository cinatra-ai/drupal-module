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
