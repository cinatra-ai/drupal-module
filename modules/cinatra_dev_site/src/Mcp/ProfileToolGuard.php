<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The cinatra profile's permission callback: binds the profile read.
 *
 * The per-request state lives on the request's binding, never here.
 */
final class ProfileToolGuard {

  /**
   * Constructs the guard.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(private readonly RequestStack $requestStack) {
  }

  /**
   * Checks and binds the profile for the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request the repository checks.
   * @param array $profile
   *   The profile array the repository read.
   *
   * @return bool
   *   TRUE only while the exposure is active, for the current request, its
   *   own binding and the exact profile; on FALSE, and on a failure such as
   *   an unreadable State, the binding is revoked.
   */
  public function check(?Request $request, array $profile): bool {
    $candidate = $request?->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE);
    $binding = $candidate instanceof ProfileBinding && $candidate->request() === $request ? $candidate : NULL;
    $allowed = FALSE;
    try {
      $allowed = CinatraDevSite::exposureActive()
        && $binding !== NULL
        && $request === $this->requestStack->getCurrentRequest()
        && CinatraDevSite::validProfile($profile)
        && $binding->bind($profile);
    }
    finally {
      if (!$allowed) {
        $binding?->revoke();
      }
    }
    return $allowed;
  }

}
