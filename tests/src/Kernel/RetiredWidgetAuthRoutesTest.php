<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The retired widget credential-broker routes are gone from the router.
 *
 * Until the widget bootstrap moved to protocol 2 (cinatra#2674) this module
 * exposed three same-origin POST routes for the assistant: `/cinatra/token`
 * minted a short-lived site token, and `/cinatra/widget-auth/{init,token}`
 * relayed the per-user PKCE handshake. Each presented the long-lived
 * integration key server-to-server and handed a bearer back to the browser.
 *
 * The instance's `/api/widget-auth/{init,token}` now answer 410 Gone, and the
 * frame runs the ceremony itself. REMOVING the routes rather than leaving them
 * to relay into a 410 is what makes the retirement quiet on this side: a stale
 * cached page that still POSTs to one gets a plain 404 from Drupal, no
 * key-bearing request leaves the site, and nothing is logged as an upstream
 * failure. There is no compatibility arm, because the point of the change is
 * that this site can no longer obtain a bearer at all.
 *
 * @group cinatra
 */
final class RetiredWidgetAuthRoutesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user', 'system'];

  /**
   * The route names retired with the credential-bearing bootstrap.
   */
  private const RETIRED_ROUTES = [
    'cinatra.token',
    'cinatra.widget_auth_init',
    'cinatra.widget_auth_token',
  ];

  /**
   * The routes this module still owns — the negative control.
   */
  private const SURVIVING_ROUTES = [
    'cinatra.settings_form',
    'cinatra.connect_callback',
    'cinatra.preview',
  ];

  /**
   * None of the retired broker routes is registered.
   */
  public function testRetiredRoutesAreNotRegistered(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (self::RETIRED_ROUTES as $name) {
      try {
        $provider->getRouteByName($name);
        $this->fail(sprintf('Route "%s" is still registered; the widget credential broker is retired.', $name));
      }
      catch (RouteNotFoundException) {
        // Expected: the route does not exist.
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * The module's remaining routes are still registered.
   *
   * Without this, "no retired route exists" would also pass if the module's
   * routing file had failed to load at all.
   */
  public function testSurvivingRoutesAreStillRegistered(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (self::SURVIVING_ROUTES as $name) {
      $this->assertNotNull($provider->getRouteByName($name), sprintf('Route "%s" must still exist.', $name));
    }
  }

  /**
   * No routing entry points at a retired controller class.
   *
   * A route could be renamed and still reach the deleted broker; this pins the
   * controllers themselves as gone.
   */
  public function testNoRoutingEntryReferencesRetiredControllers(): void {
    $routing = file_get_contents(__DIR__ . '/../../../cinatra.routing.yml');
    $this->assertIsString($routing);
    $this->assertStringNotContainsString('TokenController', $routing);
    $this->assertStringNotContainsString('WidgetAuthController', $routing);
    $this->assertFileDoesNotExist(__DIR__ . '/../../../src/Controller/TokenController.php');
    $this->assertFileDoesNotExist(__DIR__ . '/../../../src/Controller/WidgetAuthController.php');
  }

}
