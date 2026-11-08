<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

/**
 * A route controller value that is an object whose string is a controller.
 */
final class StringableController implements \Stringable {

  /**
   * Constructs the value.
   *
   * @param string $controller
   *   The controller name its string gives.
   */
  public function __construct(
    private readonly string $controller,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return $this->controller;
  }

}
