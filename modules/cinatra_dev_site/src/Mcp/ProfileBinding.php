<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Mcp;

use Symfony\Component\HttpFoundation\Request;

/**
 * Binds the one read of the cinatra profile on one request.
 *
 * The endpoint makes one per request and puts it on that request; it is never
 * a service. A second counted read, or a different array, revokes it.
 */
final class ProfileBinding {

  /**
   * The number of counted reads of the cinatra profile.
   */
  private int $reads = 0;

  /**
   * Whether the binding is revoked for good.
   */
  private bool $revoked = FALSE;

  /**
   * The bound profile with its top-level keys sorted, or NULL.
   */
  private ?array $bound = NULL;

  /**
   * Constructs a binding for one request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request the binding belongs to.
   */
  public function __construct(public readonly Request $request) {
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
    if (++$this->reads > 1) {
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
    ksort($profile);
    // The first array after the one read binds; only that array binds again.
    if (!$this->revoked && $this->reads === 1 && ($this->bound ??= $profile) === $profile) {
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
   *   The profile with its top-level keys sorted, or NULL.
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
    $tools = $this->profile()['tools'] ?? NULL;
    return is_array($tools) ? array_values($tools) : [];
  }

}
