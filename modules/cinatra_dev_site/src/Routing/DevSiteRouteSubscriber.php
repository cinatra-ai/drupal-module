<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site\Routing;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points the remote MCP route at the development endpoint while armed.
 */
final class DevSiteRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs the subscriber.
   *
   * @param string $remoteController
   *   The inner controller class.
   */
  public function __construct(
    private readonly string $remoteController,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if (!CinatraDevSite::isArmed()) {
      return;
    }
    $route = $collection->get(CinatraDevSite::ENDPOINT_ROUTE);
    if ($route === NULL) {
      return;
    }
    $controller = ltrim((string) $route->getDefault('_controller'), '\\');
    if ($controller === ltrim($this->remoteController, '\\') . '::handle') {
      $route->setDefault('_controller', CinatraDevSite::ENDPOINT_CONTROLLER);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1024];
    return $events;
  }

}
