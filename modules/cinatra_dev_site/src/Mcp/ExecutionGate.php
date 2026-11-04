<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * Lets the remote controller switch accounts only for a validated binding.
 *
 * The endpoint builds one per request over the site's account switcher. It
 * is never a service. It reverts only the switches it made.
 */
final class ExecutionGate implements AccountSwitcherInterface {

  /**
   * The number of switches this gate made and did not revert yet.
   */
  private int $depth = 0;

  /**
   * Constructs the gate.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The site's account switcher.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileBinding $binding
   *   The request's binding.
   */
  public function __construct(private readonly AccountSwitcherInterface $switcher, private readonly ProfileBinding $binding) {
  }

  /**
   * {@inheritdoc}
   */
  public function switchTo(AccountInterface $account) {
    if (!CinatraDevSite::exposureActive() || !$this->binding->isValidated()) {
      throw new ExposureDeniedException(CinatraDevSite::DENY_TEXT);
    }
    $this->switcher->switchTo($account);
    $this->depth++;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function switchBack() {
    if ($this->depth === 0) {
      throw new \LogicException('The gate has no switch to revert.');
    }
    $this->depth--;
    $this->switcher->switchBack();
    return $this;
  }

}
