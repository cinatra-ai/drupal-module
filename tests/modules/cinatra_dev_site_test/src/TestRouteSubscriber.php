<?php

declare(strict_types=1);

namespace Drupal\cinatra_dev_site_test;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Gives the remote route a foreign controller when a State flag asks for it.
 */
final class TestRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(
    private StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $controller = $this->state->get('cinatra_dev_site_test.foreign_controller');
    $route = $collection->get('mcp_tools_remote.handle');
    if (is_string($controller) && $route !== NULL) {
      $route->setDefault('_controller', $controller);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -2048];
    return $events;
  }

}
