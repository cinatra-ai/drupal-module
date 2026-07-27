<?php

declare(strict_types=1);

namespace Symfony\Component\EventDispatcher;

/**
 * Minimal stand-in for Symfony's event-subscriber contract.
 *
 * Only the INTERFACE has to exist for the preview response subscriber to be
 * declared; every other Symfony type that class names appears in a method
 * signature, which PHP resolves lazily and the harness never triggers. Loaded
 * only by tests/test-preview-auth.php; Drupal never loads this file.
 */
interface EventSubscriberInterface {

  /**
   * Returns the subscribed events.
   *
   * @return array
   *   The event map.
   */
  public static function getSubscribedEvents();

}
