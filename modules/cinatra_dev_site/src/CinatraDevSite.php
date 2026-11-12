<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site;

/**
 * The development marker and the one exposure guard.
 *
 * Every path that serves the exposure asks exposureActive() before any work.
 * It is the only reader of the armed State flag, and it also requires the
 * development marker of the running process: a database restored on a site
 * without the marker serves nothing. Only arm() sets the flag, and only
 * DevSiteEnsure calls arm() and verifying().
 */
final class CinatraDevSite {

  /**
   * The environment variable that marks a development site; only "1" counts.
   */
  public const MARKER_ENV = 'CINATRA_DEV_SITE';

  /**
   * The State key that arms the exposure; only TRUE counts.
   */
  public const ARMED_KEY = 'cinatra_dev_site.armed';

  /**
   * The fixed answer to a remote request that the exposure refuses.
   */
  public const DENY_TEXT = 'cinatra_dev_site: the remote request was refused.';

  /**
   * The two tools the cinatra profile lists and calls.
   */
  public const EXPOSED_TOOLS = [
    'cinatra_read_protected_revision',
    'cinatra_write_protected_draft',
  ];

  /**
   * The profile id.
   */
  public const PROFILE_ID = 'cinatra';

  /**
   * The profile's permission callback: the profile guard service.
   */
  public const GUARD_CALLBACK = 'cinatra_dev_site.profile_guard:check';

  /**
   * The remote MCP route that the route subscriber may switch.
   */
  public const ENDPOINT_ROUTE = 'mcp_tools_remote.handle';

  /**
   * The controller that the route subscriber sets while the exposure is active.
   */
  public const ENDPOINT_CONTROLLER = '\Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint::handle';

  /**
   * The request attribute that carries the request's profile binding.
   */
  public const BINDING_ATTRIBUTE = '_cinatra_dev_site_binding';

  /**
   * The cinatra profile: tools only, no resources, no prompts.
   */
  public const PROFILE = [
    'name' => 'Cinatra development site',
    'version' => '1.0.0',
    'pagination_limit' => 50,
    'include_all_tools' => FALSE,
    'gateway_mode' => FALSE,
    'enable_resources' => FALSE,
    'enable_prompts' => FALSE,
    'component_public_only' => TRUE,
    'transports' => ['http'],
    'enabled' => TRUE,
    'scopes' => ['read', 'write'],
    'permission_callback' => self::GUARD_CALLBACK,
    'id' => self::PROFILE_ID,
    'tools' => self::EXPOSED_TOOLS,
  ];

  /**
   * Whether this process runs the verify step of ensure(); never stored.
   *
   * @var bool
   */
  private static bool $verifying = FALSE;

  /**
   * Whether this process may serve the exposure.
   *
   * @return bool
   *   TRUE only when the marker is "1" and either this process is inside
   *   verifying() or the State flag is exactly TRUE. The marker is read
   *   first, so without it State is not read.
   */
  public static function exposureActive(): bool {
    return self::markerPresent() && (self::$verifying || \Drupal::state()->get(self::ARMED_KEY) === TRUE);
  }

  /**
   * Runs a callable while this process alone counts as exposed.
   *
   * It writes nothing: the scope is a static value of this process, and it
   * ends when the callable returns or throws. Without the marker the scope
   * changes nothing, because exposureActive() reads the marker first.
   *
   * @param \Closure $run
   *   The verify step.
   *
   * @return mixed
   *   What the callable returns.
   */
  public static function verifying(\Closure $run): mixed {
    self::$verifying = TRUE;
    try {
      return $run();
    }
    finally {
      self::$verifying = FALSE;
    }
  }

  /**
   * Whether the running process carries the development marker.
   *
   * @return bool
   *   TRUE only when CINATRA_DEV_SITE is exactly "1".
   */
  public static function markerPresent(): bool {
    return getenv(self::MARKER_ENV) === '1';
  }

  /**
   * Whether a profile array is exactly the cinatra profile.
   *
   * @param mixed $profile
   *   The profile read from the repository.
   *
   * @return bool
   *   TRUE only for an exact match of PROFILE, key order aside.
   */
  public static function validProfile(mixed $profile): bool {
    if (!is_array($profile)) {
      return FALSE;
    }
    $expected = self::PROFILE;
    ksort($expected);
    ksort($profile);
    return $profile === $expected;
  }

  /**
   * Sets the armed State flag; ensure() calls it as its last write.
   */
  public static function arm(): void {
    \Drupal::state()->set(self::ARMED_KEY, TRUE);
  }

  /**
   * Removes the armed State flag.
   */
  public static function disarm(): void {
    \Drupal::state()->delete(self::ARMED_KEY);
  }

}
