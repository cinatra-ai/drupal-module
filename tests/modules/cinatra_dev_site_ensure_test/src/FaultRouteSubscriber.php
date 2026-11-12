<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_ensure_test;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Throws at a router rebuild while the exposure is active, on request.
 *
 * For the step 'foreign route' it gives the remote route a controller that is
 * neither the stock one nor the endpoint, and for the step 'no route' it
 * removes the route, at every rebuild; for 'no route once' it removes the
 * route at the next rebuild only. For 'stringable route' and 'object route'
 * it gives the route a controller that is not a string: a StringableController
 * of the controller set so far, or a plain object.
 */
final class FaultRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if (EnsureFault::$step === 'foreign route') {
      $collection->get(CinatraDevSite::ENDPOINT_ROUTE)?->setDefault('_controller', '\Drupal\cinatra_dev_site_ensure_test\Elsewhere::handle');
    }
    $route = $collection->get(CinatraDevSite::ENDPOINT_ROUTE);
    if (EnsureFault::$step === 'stringable route' && $route !== NULL) {
      $route->setDefault('_controller', new StringableController((string) $route->getDefault('_controller')));
    }
    if (EnsureFault::$step === 'object route' && $route !== NULL) {
      $route->setDefault('_controller', new \stdClass());
    }
    if (EnsureFault::$step === 'no route' || EnsureFault::$step === 'no route once') {
      $collection->remove(CinatraDevSite::ENDPOINT_ROUTE);
    }
    if (EnsureFault::$step === 'no route once') {
      EnsureFault::$step = NULL;
    }
    EnsureFault::fail('router', CinatraDevSite::exposureActive());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the submodule's own subscriber, as the last word on the route.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -2048]];
  }

}
