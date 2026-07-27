<?php

declare(strict_types=1);

namespace Drupal\cinatra\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response shaping for the authenticated preview route ONLY (cinatra#2046).
 *
 * Both listeners are scoped by ROUTE NAME and are no-ops for every other
 * request on the site.
 *
 * 1. A DENIAL IS A 401, NOT A 403 PAGE. The preview route's access check is a
 *    CREDENTIAL check, so "unauthenticated" is the truthful status — and it is
 *    what the WordPress adapter's REST route returns for the identical failure
 *    (wordpress-plugin#94), which keeps one host pipeline speaking to both
 *    CMSes the same way. Just as importantly, Drupal's default denial renders
 *    the site's full themed 403 PAGE; returning a bare, bodyless 401 means an
 *    unsigned caller learns nothing about the site and gets no rendered
 *    content of any kind.
 *
 * 2. NEVER CACHED, NEVER INDEXED. The preview body can contain unpublished
 *    content, so the response carries `no-store, private` and `X-Robots-Tag:
 *    noindex, nofollow` — the same headers the WordPress adapter sets. This is
 *    belt-and-braces over the uncacheable render metadata the controller
 *    already sets: a header a proxy in front of the site will honour, not only
 *    a Drupal-internal cache decision.
 */
final class PreviewResponseSubscriber implements EventSubscriberInterface {

  /**
   * The route this subscriber acts on. Nothing else is ever touched.
   */
  public const ROUTE_NAME = 'cinatra.preview';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Ahead of Drupal's own exception handling (which would otherwise render
      // the themed 403 page for this denial).
      KernelEvents::EXCEPTION => ['onException', 100],
      KernelEvents::RESPONSE => ['onResponse', 0],
    ];
  }

  /**
   * Turns THIS route's access denial into a bare 401.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }
    if (!$this->isPreviewRoute($event)) {
      return;
    }
    $response = new Response('', Response::HTTP_UNAUTHORIZED);
    $this->harden($response);
    $event->setResponse($response);
  }

  /**
   * Marks THIS route's response uncacheable and unindexable.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$this->isPreviewRoute($event)) {
      return;
    }
    $this->harden($event->getResponse());
  }

  /**
   * Applies the no-store / noindex headers.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to harden.
   */
  private function harden(Response $response): void {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
  }

  /**
   * Whether the event belongs to the preview route.
   *
   * TWO checks, because the two events see the request at different stages.
   * On RESPONSE the request has been routed, so the authoritative `_route`
   * attribute is present. On EXCEPTION it may NOT be: Drupal's access-aware
   * router raises the denial from inside `matchRequest()`, i.e. before the
   * router listener writes `_route` onto the request — which is precisely the
   * case this subscriber exists for. There the path is matched instead, with a
   * regex anchored to the WHOLE path so no prefix-alike (`/cinatra/preview/1x`,
   * `/cinatra/preview/1/anything`) can borrow this behaviour. The path belongs
   * to this module, so matching it is not a guess.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent|\Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The kernel event.
   *
   * @return bool
   *   TRUE when this is the preview route.
   */
  private function isPreviewRoute(ExceptionEvent|ResponseEvent $event): bool {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') === self::ROUTE_NAME) {
      return TRUE;
    }
    return self::isPreviewPath($request->getPathInfo());
  }

  /**
   * Whether a path is EXACTLY the preview route's path.
   *
   * Extracted and public so the anchoring is directly provable in a unit test
   * without a kernel.
   *
   * @param string $path
   *   The request path info.
   *
   * @return bool
   *   TRUE for `/cinatra/preview/<digits>` and nothing else.
   */
  public static function isPreviewPath(string $path): bool {
    return (bool) preg_match('#^/cinatra/preview/[0-9]+$#', $path);
  }

}
