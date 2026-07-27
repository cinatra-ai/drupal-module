<?php

declare(strict_types=1);

namespace Drupal\cinatra\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\cinatra\PreviewAuth;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Route access for the host-facing authenticated preview (cinatra#2046).
 *
 * DENY BEFORE LOAD is the load-bearing property. The route declares a RAW
 * `{nid}` integer parameter — deliberately NOT an upcast `{node}` — so Drupal's
 * parameter converter never loads (and never access-checks, and never logs) an
 * unpublished node before this check has run. An unsigned or forged request is
 * refused while the node is still just a number in a path.
 *
 * The result is ALWAYS uncacheable (`setCacheMaxAge(0)`): the decision depends
 * on request headers and a one-shot replay consume, so a cached "allowed" would
 * be a replay oracle.
 *
 * REPLAY. A verified signature additionally consumes its `webhook-id` exactly
 * once inside the freshness window. Getting that right needs BOTH halves, and a
 * convergence round rejected each half on its own:
 *
 *  - ATOMICITY. Core's expirable key-value `setWithExpireIfNotExists()` is a
 *    `has()` followed by a `set()`, so two simultaneous replays can both
 *    observe absence and both be served. The check-and-set therefore runs
 *    inside a lock named after the message id — an INSERT whose primary key
 *    picks a single winner. Contention on that name means a concurrent
 *    request is already consuming THIS id — exactly the replay case — so it
 *    is denied (fail closed).
 *  - NO UNBOUNDED STATE. Holding a never-released lock as the nonce would be
 *    atomic but would leak one `semaphore` row per preview forever: core has no
 *    lock garbage collection, and an expired row is only cleared when the SAME
 *    name is retried — which never happens for a one-shot id. So the lock is
 *    a short critical section, always released in a `finally`, and the
 *    DURABLE record is the expirable key-value entry, which core's cron does
 *    collect.
 *
 * The host mints a fresh id per attempt, so a legitimate retry is never a
 * replay.
 */
final class PreviewAccessCheck implements ContainerInjectionInterface {

  /**
   * The expirable key-value collection consumed webhook ids live in.
   */
  private const REPLAY_COLLECTION = 'cinatra.preview_seen';

  /**
   * Lock-name prefix for the consume critical section.
   */
  private const REPLAY_LOCK_PREFIX = 'cinatra_preview_seen_';

  /**
   * Seconds to hold the consume critical section.
   *
   * Bounds one local read+write, never a network call, and the lock is
   * released explicitly either way.
   */
  private const REPLAY_LOCK_SECONDS = 5.0;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('keyvalue.expirable'),
      $container->get('lock'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Checks a signed host preview request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (carries the Standard-Webhooks headers).
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match (carries the raw `nid` path parameter).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed only for a fresh, correctly-signed, id-bound, unconsumed request.
   */
  public function access(Request $request, RouteMatchInterface $route_match): AccessResultInterface {
    $denied = AccessResult::forbidden('The Cinatra preview request is not signed by the connected host.')
      ->setCacheMaxAge(0);

    $raw = $route_match->getRawParameter('nid');
    $nid = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    if ($nid <= 0) {
      return $denied;
    }

    // No connect-provisioned secret means the site is not host-connected, so
    // there is no preview credential and nothing can authorise this request.
    $secret = (string) $this->configFactory->get('cinatra.settings')->get('webhook_secret');
    if ($secret === '') {
      return $denied;
    }

    $messageId = (string) $request->headers->get('webhook-id', '');
    $verified = PreviewAuth::verify(
      $secret,
      $messageId,
      (string) $request->headers->get('webhook-timestamp', ''),
      (string) $request->headers->get('webhook-signature', ''),
      $nid,
      $this->time->getRequestTime(),
    );
    if (!$verified) {
      return $denied;
    }

    // SINGLE-USE. Both the lock name and the stored key are a HASH of the
    // message id, so no host-supplied bytes become a storage key verbatim.
    $hashed = hash('sha256', $messageId);
    if (!$this->lock->acquire(self::REPLAY_LOCK_PREFIX . $hashed, self::REPLAY_LOCK_SECONDS)) {
      // Someone else is consuming THIS id right now — the replay case.
      return $denied;
    }
    try {
      $consumed = $this->keyValueExpirable
        ->get(self::REPLAY_COLLECTION)
        ->setWithExpireIfNotExists($hashed, 1, PreviewAuth::TIMESTAMP_TOLERANCE_SECONDS);
    }
    finally {
      $this->lock->release(self::REPLAY_LOCK_PREFIX . $hashed);
    }
    if (!$consumed) {
      return $denied;
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

}
