<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\cinatra_dev_site\DevSiteEnsure;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * The step at which a test asks ensure() to fail, and what the doubles saw.
 *
 * Static on purpose: a State value would itself be a write that the tests of
 * ensure() compare.
 */
final class EnsureFault {

  /**
   * The step that throws, or NULL.
   *
   * Throwing steps: 'plan', 'router', 'census', 'log', 'unbind', 'disarm'
   * (the delete of the armed flag, before it is deleted) and 'lock release'
   * (after the lock of ensure() is released). The values 'foreign route',
   * 'no route' and 'no route once' do not throw: the route subscriber then
   * gives the remote route a controller that is neither stock nor endpoint,
   * or removes it (at the next rebuild only, for 'no route once'). At the
   * info line of a verified run, 'lapse' expires the lock of ensure() and
   * takes it with another backend, 'nested' calls ensure() and release() of
   * the same service, and 'stale release' unbinds the pages through the
   * binder as a release elsewhere whose lock lapsed would; each happens
   * once. The lock test class calls ensure() and release() at the steps
   * 'nested at acquire' and 'nested at release'; the workflow update hook
   * arms the site at the step 'arm at unbind', as a run elsewhere would,
   * once, and throws at the step 'unbind'. The values 'stringable route'
   * and 'object route' give the remote route a controller that is not a
   * string: an object whose string is the stored controller, or a plain
   * object.
   *
   * @var string|null
   */
  public static ?string $step = NULL;

  /**
   * The stored armed flag when the fault threw, as a kill there would leave.
   *
   * @var mixed
   */
  public static mixed $flagAtFault = NULL;

  /**
   * The profile reads of requests that carry a binding.
   *
   * @var int
   */
  public static int $bindingReads = 0;

  /**
   * The log lines of the cinatra_dev_site channel, as level and message.
   *
   * @var string[]
   */
  public static array $lines = [];

  /**
   * What the nested ensure() and release() of the step 'nested' returned.
   *
   * @var array|null
   */
  public static ?array $nested = NULL;

  /**
   * The other lock backend of the step 'lapse'.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface|null
   */
  public static ?LockBackendInterface $other = NULL;

  /**
   * Whether the other backend of the step 'lapse' took the lock.
   *
   * @var bool|null
   */
  public static ?bool $otherHeld = NULL;

  /**
   * What the binder's release() of the step 'stale release' returned.
   *
   * @var string|null
   */
  public static ?string $staleRelease = NULL;

  /**
   * Resets the doubles.
   */
  public static function reset(): void {
    self::$step = NULL;
    self::$flagAtFault = NULL;
    self::$bindingReads = 0;
    self::$lines = [];
    self::$nested = NULL;
    self::$other = NULL;
    self::$otherHeld = NULL;
    self::$staleRelease = NULL;
  }

  /**
   * Expires the lock of ensure() and takes it with another backend.
   *
   * Core's database backend lets a second backend take an expired lock.
   */
  public static function lapse(): void {
    $database = \Drupal::database();
    $database->update('semaphore')->fields(['expire' => 1.0])->condition('name', DevSiteEnsure::LOCK)->execute();
    self::$other = new DatabaseLockBackend($database);
    self::$otherHeld = self::$other->acquire(DevSiteEnsure::LOCK, 60.0);
  }

  /**
   * Calls ensure() and release() of the ensure service and keeps the results.
   */
  public static function nest(): void {
    $ensure = \Drupal::service('cinatra_dev_site.ensure');
    self::$nested = ['ensure' => $ensure->ensure(), 'release' => $ensure->release(FALSE)];
  }

  /**
   * Removes the page binding as a release whose lock lapsed would.
   *
   * It calls the binder directly: that release took the lock before it
   * lapsed, so it does not ask for it again.
   */
  public static function staleRelease(): void {
    self::$staleRelease = \Drupal::service('cinatra_dev_site.editorial_binder')->release(FALSE);
  }

  /**
   * Throws when the test asked for this step and the condition holds.
   *
   * @param string $step
   *   The step.
   * @param bool $when
   *   The condition.
   */
  public static function fail(string $step, bool $when = TRUE): void {
    if (self::$step === $step && $when) {
      self::$flagAtFault = \Drupal::state()->get(CinatraDevSite::ARMED_KEY);
      throw new \RuntimeException('ensure test failure: ' . $step);
    }
  }

}
