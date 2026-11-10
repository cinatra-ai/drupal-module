<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Routing;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points the remote MCP route at the development endpoint while active.
 *
 * Only the route's controller changes, and only from the stock controller
 * as a string, with no or one leading backslash; any other controller, one
 * that is not a string included, is left alone. The router is built with
 * the answer of the exposure guard at that moment.
 */
final class DevSiteRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs the subscriber.
   *
   * @param string $remoteController
   *   The stock controller class of the remote route. Its leading
   *   backslashes are stripped before the stock controller is built.
   */
  public function __construct(private readonly string $remoteController) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if (!CinatraDevSite::exposureActive()) {
      return;
    }
    $route = $collection->get(CinatraDevSite::ENDPOINT_ROUTE);
    $controller = $route?->getDefault('_controller');
    $stock = ltrim($this->remoteController, '\\') . '::handle';
    if ($route !== NULL && is_string($controller) && in_array($controller, [$stock, '\\' . $stock], TRUE)) {
      $route->setDefault('_controller', CinatraDevSite::ENDPOINT_CONTROLLER);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Late, after the default route subscribers have built the route.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -1024]];
  }

}
