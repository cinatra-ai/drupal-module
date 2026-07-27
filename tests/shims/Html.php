<?php

declare(strict_types=1);

namespace Drupal\Component\Utility;

/**
 * Minimal stand-in for Drupal's Html utility (escape() only).
 *
 * Loaded ONLY by tests/test-preview-auth.php, which runs the preview classes
 * without a Drupal install. Core's escape() is the single htmlspecialchars()
 * call reproduced verbatim below, so the escaping the harness asserts is the
 * escaping production performs. Drupal itself never loads this file: the
 * module's autoloader resolves the real class.
 */
class Html {

  /**
   * Escapes text for an HTML attribute or text node.
   *
   * @param string $text
   *   The text to escape.
   *
   * @return string
   *   The escaped text.
   */
  public static function escape($text) {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
