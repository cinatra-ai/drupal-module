<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

use Drupal\Core\Lock\DatabaseLockBackend;

/**
 * The site's database locks, with faults at the ensure lock on request.
 *
 * For the step 'lock release' it releases the lock through core's backend
 * and then throws. Core's own release() forgets the lock before its delete,
 * so a delete that throws there can leave the semaphore row until it
 * expires; that case is not driven here. For the steps 'nested at acquire'
 * and 'nested at release' it calls the ensure service again (EnsureFault::
 * nest()) right after it took or released the ensure lock, once.
 */
final class FaultLock extends DatabaseLockBackend {

  /**
   * The name DevSiteEnsure::LOCK.
   *
   * Written out: every release of a lock calls this class, also in the test
   * setup of a tree without DevSiteEnsure.
   */
  private const ENSURE_LOCK = 'cinatra_dev_site.ensure';

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0) {
    $acquired = parent::acquire($name, $timeout);
    if ($acquired && $name === self::ENSURE_LOCK && EnsureFault::$step === 'nested at acquire') {
      EnsureFault::$step = NULL;
      EnsureFault::nest();
    }
    return $acquired;
  }

  /**
   * {@inheritdoc}
   */
  public function release($name) {
    parent::release($name);
    if ($name === self::ENSURE_LOCK) {
      EnsureFault::fail('lock release');
      if (EnsureFault::$step === 'nested at release') {
        EnsureFault::$step = NULL;
        EnsureFault::nest();
      }
    }
  }

}
