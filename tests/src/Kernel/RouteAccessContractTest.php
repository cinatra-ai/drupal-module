<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Routing\Route;

/**
 * Every route this module registers carries a real access check.
 *
 * Routing is a file, not code: `cinatra.routing.yml` is data that only becomes
 * behavior once Drupal's route builder has compiled it, so nothing below can
 * be reached by a unit test. Two of the properties asserted here are security
 * boundaries rather than conventions:
 *
 * - No route may ship open. A missing `requirements:` block does not fail
 *   loudly at build time; it produces a route that is simply reachable by
 *   everyone, which is why the check enumerates the compiled router instead of
 *   naming routes one at a time.
 * - The preview route takes a RAW `{nid}`, never an upcast `{node}`. Drupal's
 *   parameter converter runs BEFORE access checking, so an upcast parameter
 *   would load an unpublished node for an unsigned caller. With a raw integer
 *   the access check decides while the node is still just a number in a path.
 *
 * @group cinatra
 */
final class RouteAccessContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cinatra', 'user', 'system'];

  /**
   * Requirement keys that constitute a real access decision.
   *
   * `_access` is deliberately absent: `_access: 'TRUE'` is the "open to
   * everyone" marker, not an access check.
   */
  private const ACCESS_REQUIREMENTS = [
    '_permission',
    '_custom_access',
    '_entity_access',
    '_role',
    '_user_is_logged_in',
  ];

  /**
   * The routes this module owns, keyed by name.
   *
   * @return array<string, \Symfony\Component\Routing\Route>
   *   Every compiled route whose name belongs to this module.
   */
  private function moduleRoutes(): array {
    $routes = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if (str_starts_with((string) $name, 'cinatra.')) {
        $routes[$name] = $route;
      }
    }
    return $routes;
  }

  /**
   * The module's routing file compiles to the routes it declares.
   *
   * The list is exhaustive on purpose: a route added without an entry here is
   * a route nobody reviewed against the rest of this class.
   */
  public function testCompiledRouteSetIsExactlyTheDeclaredOne(): void {
    // Sorted: the router hands routes back in storage order, and that order is
    // not part of the contract under test.
    $names = array_keys($this->moduleRoutes());
    sort($names);
    $this->assertSame(
      ['cinatra.connect_callback', 'cinatra.preview', 'cinatra.settings_form'],
      $names,
      'The compiled router must hold exactly the module routes under review.',
    );
  }

  /**
   * No route of this module is reachable without an access decision.
   */
  public function testEveryRouteHasAnAccessRequirement(): void {
    foreach ($this->moduleRoutes() as $name => $route) {
      $requirements = array_keys($route->getRequirements());
      $this->assertNotEmpty(
        array_intersect(self::ACCESS_REQUIREMENTS, $requirements),
        sprintf('Route "%s" has no access requirement.', $name),
      );
      $this->assertNotSame(
        'TRUE',
        $route->getRequirement('_access'),
        sprintf('Route "%s" is open to everyone.', $name),
      );
    }
  }

  /**
   * The settings form and the connect return leg are admin-only.
   */
  public function testAdminRoutesRequireTheSiteConfigurationPermission(): void {
    $routes = $this->moduleRoutes();
    foreach (['cinatra.settings_form', 'cinatra.connect_callback'] as $name) {
      $this->assertSame(
        'administer site configuration',
        $routes[$name]->getRequirement('_permission'),
        sprintf('Route "%s" must stay admin-only.', $name),
      );
    }
  }

  /**
   * The connect return leg is a GET that is never cached.
   *
   * It carries a single-use `state`, so a cached response would replay one.
   */
  public function testConnectCallbackIsAnUncachedGet(): void {
    $route = $this->moduleRoutes()['cinatra.connect_callback'];
    $this->assertSame(['GET'], $route->getMethods());
    $this->assertSame('TRUE', $route->getOption('no_cache'));
  }

  /**
   * The preview route decides access BEFORE the node is loaded.
   *
   * The raw `{nid}` (with an integer requirement) plus a custom access check
   * and NO parameter upcasting is the whole deny-before-load property.
   */
  public function testPreviewRouteDeniesBeforeItLoads(): void {
    $route = $this->moduleRoutes()['cinatra.preview'];
    $this->assertSame('/cinatra/preview/{nid}', $route->getPath());
    $this->assertSame(['GET'], $route->getMethods());
    $this->assertSame('\Drupal\cinatra\Access\PreviewAccessCheck::access', $route->getRequirement('_custom_access'));
    $this->assertSame('\d+', $route->getRequirement('nid'));
    $this->assertSame('TRUE', $route->getOption('no_cache'));
    // No upcasting: nothing may turn {nid} into a loaded entity, and no
    // {node} parameter may appear in the path.
    $this->assertNull($route->getOption('parameters'), 'The preview route must not upcast its parameter.');
    $this->assertStringNotContainsString('{node}', $route->getPath());
    // The credential is a server-to-server signature, not a user session, so
    // a Drupal permission on this route would be the wrong gate entirely.
    $this->assertNull($route->getRequirement('_permission'));
    $this->assertNull($route->getRequirement('_entity_access'));
  }

  /**
   * The negative control: the assertions above can fail.
   *
   * Every check in this class reads `getRequirement()` / `getOption()`, which
   * return NULL for anything absent — so a typo in a key name would make the
   * suite vacuously green. This runs the same predicates against a route that
   * is deliberately open and upcasting, and requires them to REJECT it.
   */
  public function testTheContractRejectsAnOpenUpcastingRoute(): void {
    $open = new Route(
      '/cinatra/preview/{node}',
      ['_controller' => '\Drupal\cinatra\Controller\PreviewController::preview'],
      ['_access' => 'TRUE'],
      ['parameters' => ['node' => ['type' => 'entity:node']]],
    );

    $this->assertEmpty(array_intersect(self::ACCESS_REQUIREMENTS, array_keys($open->getRequirements())));
    $this->assertSame('TRUE', $open->getRequirement('_access'));
    $this->assertNotNull($open->getOption('parameters'));
    $this->assertStringContainsString('{node}', $open->getPath());
  }

}
