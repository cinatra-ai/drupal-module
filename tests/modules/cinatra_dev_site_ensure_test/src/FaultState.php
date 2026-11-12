<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\State\State;

/**
 * Core's State; the delete of the armed flag throws on request.
 *
 * For the step 'disarm' the delete throws before anything is deleted.
 */
final class FaultState extends State {

  /**
   * {@inheritdoc}
   */
  public function delete($key) {
    if ($key === CinatraDevSite::ARMED_KEY) {
      EnsureFault::fail('disarm');
    }
    parent::delete($key);
  }

}
