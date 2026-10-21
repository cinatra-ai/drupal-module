<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;

/**
 * Reads the development-site submodule's manifest files and its source.
 *
 * @group cinatra
 */
final class DevSiteManifestTest extends KernelTestBase {

  /**
   * The repository root.
   *
   * @return string
   *   The absolute path.
   */
  private static function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The submodule folder.
   *
   * @return string
   *   The absolute path.
   */
  private static function module(): string {
    return self::root() . '/modules/cinatra_dev_site';
  }

  /**
   * Reads a file of the submodule.
   *
   * @param string $relative
   *   The path inside the submodule.
   *
   * @return string
   *   The contents.
   */
  private function read(string $relative): string {
    $path = self::module() . '/' . $relative;
    $this->assertFileExists($path);
    return (string) file_get_contents($path);
  }

  /**
   * Returns every source file of the submodule, keyed by relative path.
   *
   * @return array
   *   The contents keyed by path.
   */
  private function sources(): array {
    $files = [];
    $this->assertDirectoryExists(self::module());
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::module(), \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $relative = substr($file->getPathname(), strlen(self::module()) + 1);
        $files[$relative] = (string) file_get_contents($file->getPathname());
      }
    }
    return $files;
  }

  /**
   * The info file declares a hidden development module.
   */
  public function testInfoFile(): void {
    $info = Yaml::decode($this->read('cinatra_dev_site.info.yml'));
    $this->assertSame('Cinatra development site', $info['name']);
    $this->assertSame('module', $info['type']);
    $this->assertTrue($info['hidden']);
    $this->assertSame('Development', $info['package']);
    $this->assertSame('^10.5 || ^11', $info['core_version_requirement']);
    $this->assertSame('8.3', (string) $info['php']);
    $this->assertSame([
      'cinatra:cinatra',
      'drupal:node',
      'drupal:workflows',
      'drupal:content_moderation',
      'tool:tool',
      'mcp_tools:mcp_tools',
      'mcp_tools:mcp_tools_remote',
    ], $info['dependencies']);
  }

  /**
   * No config folders; the submodule is export-ignored.
   */
  public function testNoConfigAndExportIgnored(): void {
    $this->assertDirectoryExists(self::module());
    $this->assertDirectoryDoesNotExist(self::module() . '/config');
    $this->assertDirectoryDoesNotExist(self::module() . '/config/install');
    $this->assertDirectoryDoesNotExist(self::module() . '/config/optional');
    $attributes = (string) file_get_contents(self::root() . '/.gitattributes');
    $this->assertMatchesRegularExpression('#^/modules/cinatra_dev_site\s+export-ignore\s*$#m', $attributes);
  }

  /**
   * The services file wires the road and replaces no service.
   */
  public function testServices(): void {
    $raw = $this->read('cinatra_dev_site.services.yml');
    $this->assertStringNotContainsString('decorates', $raw);
    $services = Yaml::decode($raw);
    $this->assertSame('Drupal\mcp_tools_remote\Controller\McpToolsRemoteController', $services['parameters']['cinatra_dev_site.remote_controller']);
    $defined = $services['services'];
    foreach (['plugin.manager.tool', 'cinatra_dev_site.tool_manager', 'account_switcher'] as $forbidden) {
      $this->assertArrayNotHasKey($forbidden, $defined);
    }
    foreach ($defined as $definition) {
      $class = (string) ($definition['class'] ?? '');
      foreach (['ProfileBinding', 'ExecutionGate', 'ScopedToolManager', 'ScopedToolContainer'] as $plain) {
        $this->assertStringNotContainsString($plain, $class);
      }
    }

    $ensure = $defined['cinatra_dev_site.ensure'];
    $this->assertSame('Drupal\cinatra_dev_site\CinatraDevSite', $ensure['class']);
    $this->assertSame([
      '@config.factory',
      '@entity_type.manager',
      '@database',
      '@state',
      '@lock',
      '@logger.factory',
      '@module_handler',
      '@?plugin.manager.tool',
      '@cinatra.protected_draft_storage',
      '@router.builder',
      '@router.route_provider',
      '@request_stack',
      '@?mcp_tools.server_config_repository',
      '%cinatra_dev_site.remote_controller%',
      '@cinatra_dev_site.profile_guard',
      '@account_switcher',
    ], $ensure['arguments']);

    $guard = $defined['cinatra_dev_site.profile_guard'];
    $this->assertSame('Drupal\cinatra_dev_site\Mcp\ProfileToolGuard', $guard['class']);
    $this->assertSame(['@request_stack'], $guard['arguments']);
    $this->assertArrayNotHasKey('tags', $guard);

    $routes = $defined['cinatra_dev_site.route_subscriber'];
    $this->assertSame('Drupal\cinatra_dev_site\Routing\DevSiteRouteSubscriber', $routes['class']);
    $this->assertSame(['%cinatra_dev_site.remote_controller%'], $routes['arguments']);
    $this->assertSame([['name' => 'event_subscriber']], $routes['tags']);

    $override = $defined['cinatra_dev_site.config_override'];
    $this->assertSame('Drupal\cinatra_dev_site\Config\DevSiteConfigOverride', $override['class']);
    $this->assertSame(['@state'], $override['arguments']);
    $this->assertSame([['name' => 'config.factory.override', 'priority' => 5]], $override['tags']);

    $export = $defined['cinatra_dev_site.export_transform'];
    $this->assertSame('Drupal\cinatra_dev_site\EventSubscriber\DevSiteExportTransform', $export['class']);
    $this->assertSame(['@state'], $export['arguments']);
    $this->assertSame([['name' => 'event_subscriber']], $export['tags']);
  }

  /**
   * The route subscriber runs after the default route subscribers.
   */
  public function testRouteSubscriberPriority(): void {
    $source = $this->read('src/Routing/DevSiteRouteSubscriber.php');
    $this->assertStringContainsString('extends RouteSubscriberBase', $source);
    $this->assertMatchesRegularExpression('/RoutingEvents::ALTER\]\s*=\s*\[\'onAlterRoutes\',\s*-1024\]/', $source);
  }

  /**
   * Source scans of the submodule.
   */
  public function testSourceScans(): void {
    $sources = $this->sources();
    $this->assertNotEmpty($sources);
    $all = implode("\n", $sources);
    $this->assertStringNotContainsString("getEditable('mcp_tools", $all);
    $this->assertDoesNotMatchRegularExpression('/->set\(\s*[\'"]include_all_tools/', $all);
    $this->assertDoesNotMatchRegularExpression('/\[[\'"]include_all_tools[\'"]\]\s*=[^=]/', $all);
    $this->assertDoesNotMatchRegularExpression('/->set\(\s*[\'"](type_settings\.)?default_moderation_state/', $all);
    $this->assertDoesNotMatchRegularExpression('/\[[\'"]default_moderation_state[\'"]\]\s*=[^=]/', $all);
    $this->assertStringNotContainsString('setProvider(', $all);
    $this->assertStringNotContainsString('_tool_info_alter', $all);
    $this->assertStringNotContainsString('decorates:', $all);
    $this->assertStringNotContainsString('startTransaction(', $all);

    $this->assertArrayHasKey('cinatra_dev_site.install', $sources);
    $this->assertStringNotContainsString('ensure(', $sources['cinatra_dev_site.install']);
    $this->assertStringContainsString('withLockedNode(', $sources['src/CinatraDevSite.php']);
    $this->assertStringContainsString('function cinatra_dev_site_workflow_presave(', $sources['cinatra_dev_site.module']);

    $endpoint = $sources['src/Controller/DevSiteRemoteEndpoint.php'];
    $this->assertStringNotContainsString('getServer(', $endpoint);
    $this->assertStringNotContainsString('getDefinitions(', $endpoint);
    $this->assertStringNotContainsString('config.factory', $endpoint);

    $readers = [];
    foreach ($sources as $relative => $source) {
      if (str_starts_with($relative, 'src/') && str_contains($source, 'getServer(')) {
        $readers[] = $relative;
      }
    }
    $this->assertSame(['src/CinatraDevSite.php'], $readers);
  }

}
