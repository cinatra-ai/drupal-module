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
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {
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
   *   TRUE only for an armed site, the current request, its own binding, an
   *   exact profile and a successful bind.
   */
  public function check(?Request $request, array $profile): bool {
    $binding = NULL;
    if ($request !== NULL) {
      $candidate = $request->attributes->get(CinatraDevSite::BINDING_ATTRIBUTE);
      if ($candidate instanceof ProfileBinding && $candidate->request() === $request) {
        $binding = $candidate;
      }
    }
    $allowed = CinatraDevSite::isArmed()
      && $request !== NULL
      && $request === $this->requestStack->getCurrentRequest()
      && $binding !== NULL
      && CinatraDevSite::validProfile($profile)
      && $binding->bind($profile);
    if (!$allowed && $binding !== NULL) {
      $binding->revoke();
    }
    return $allowed;
  }

}
