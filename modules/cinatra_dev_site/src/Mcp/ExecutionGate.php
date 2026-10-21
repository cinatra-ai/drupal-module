<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * Lets the remote controller switch accounts only for a validated binding.
 *
 * Built per request over the real account switcher. It is never a service.
 */
final class ExecutionGate implements AccountSwitcherInterface {

  /**
   * Constructs the gate.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The real account switcher.
   * @param \Drupal\cinatra_dev_site\Mcp\ProfileBinding $binding
   *   The request's binding.
   */
  public function __construct(
    private readonly AccountSwitcherInterface $switcher,
    private readonly ProfileBinding $binding,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function switchTo(AccountInterface $account) {
    if (!CinatraDevSite::isArmed() || !$this->binding->isValidated()) {
      throw new ExposureDeniedException(CinatraDevSite::DENY_TEXT);
    }
    $this->switcher->switchTo($account);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function switchBack() {
    $this->switcher->switchBack();
    return $this;
  }

}
