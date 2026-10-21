<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Symfony\Component\HttpFoundation\Request;

/**
 * Binds the one read of the cinatra profile on one request.
 *
 * One instance is held on the request it was made for. It is never a service
 * and never shared between requests. A second counted read, or a different
 * array, revokes it for the rest of the request.
 */
final class ProfileBinding {

  /**
   * The number of counted reads of the cinatra profile.
   *
   * @var int
   */
  private int $reads = 0;

  /**
   * Whether the binding is revoked for good.
   *
   * @var bool
   */
  private bool $revoked = FALSE;

  /**
   * The bound profile, its top-level keys sorted, or NULL.
   *
   * @var array|null
   */
  private ?array $bound = NULL;

  /**
   * Constructs a binding for one request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request the binding belongs to.
   */
  public function __construct(
    private readonly Request $request,
  ) {
  }

  /**
   * Returns the request the binding belongs to.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  public function request(): Request {
    return $this->request;
  }

  /**
   * Counts one read of the cinatra profile; a second read revokes.
   */
  public function noteRead(): void {
    $this->reads++;
    if ($this->reads > 1) {
      $this->revoke();
    }
  }

  /**
   * Binds the profile that the one counted read produced.
   *
   * @param array $profile
   *   The profile array as the repository answered it.
   *
   * @return bool
   *   TRUE when the binding is validated for exactly this array.
   */
  public function bind(array $profile): bool {
    if ($this->revoked) {
      return FALSE;
    }
    if ($this->reads !== 1) {
      $this->revoke();
      return FALSE;
    }
    ksort($profile);
    if ($this->bound === NULL) {
      $this->bound = $profile;
      return TRUE;
    }
    if ($this->bound === $profile) {
      return TRUE;
    }
    $this->revoke();
    return FALSE;
  }

  /**
   * Revokes the binding for the rest of the request.
   */
  public function revoke(): void {
    $this->revoked = TRUE;
  }

  /**
   * Tells whether the binding is bound and not revoked.
   *
   * @return bool
   *   TRUE when validated.
   */
  public function isValidated(): bool {
    return !$this->revoked && $this->bound !== NULL;
  }

  /**
   * Returns the bound profile while the binding is validated.
   *
   * @return array|null
   *   The profile, or NULL.
   */
  public function profile(): ?array {
    return $this->isValidated() ? $this->bound : NULL;
  }

  /**
   * Returns the bound profile's tool ids while the binding is validated.
   *
   * @return string[]
   *   The tool ids, or [].
   */
  public function tools(): array {
    $profile = $this->profile();
    if ($profile === NULL || !isset($profile['tools']) || !is_array($profile['tools'])) {
      return [];
    }
    return array_values($profile['tools']);
  }

}
