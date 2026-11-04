<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_probe;

/**
 * A registry stand-in whose constructor matches the upstream registries.
 */
final class ProbeRegistry {

  /**
   * Constructs the registry.
   *
   * @param iterable $providers
   *   The items it lists.
   */
  public function __construct(private readonly iterable $providers = []) {
  }

  /**
   * Lists the items.
   *
   * @return array
   *   The items, in order.
   */
  public function items(): array {
    $items = [];
    foreach ($this->providers as $item) {
      $items[] = $item;
    }
    return $items;
  }

}
