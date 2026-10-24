<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Composer\Autoload\ClassLoader;
use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the install requirements as core checks a module not yet installed.
 *
 * Core's install check loads the .install file and the requirements class
 * before the module's namespace is registered. A child process with only
 * Composer's autoloader stands for that state (MUST-FIX 1).
 *
 * @group cinatra
 */
final class DevSiteInstallBootstrapTest extends UnitTestCase {

  /**
   * The refusal both forms return without the marker.
   */
  private const REFUSAL = [
    'cinatra_dev_site_marker' => [
      'title' => 'Cinatra development site',
      'description' => 'This module installs only on a site with CINATRA_DEV_SITE=1.',
      'severity' => 2,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CINATRA_DEV_SITE');
    parent::tearDown();
  }

  /**
   * The install check loads, and refuses unless the marker is exactly "1".
   *
   * @param string|null $marker
   *   The marker value, or NULL for no marker.
   * @param bool $refused
   *   Whether the install must be refused.
   *
   * @dataProvider markerCases
   */
  public function testInstallCheckWithoutTheModuleNamespace(?string $marker, bool $refused): void {
    $result = $this->probe('install', $marker);
    $this->assertSame($refused ? self::REFUSAL : [], $result['hook']);
    if ($result['class'] === NULL) {
      // Core before 11.2 has no class form; its check uses the hook only.
      $this->assertFalse(interface_exists('Drupal\Core\Extension\InstallRequirementsInterface'));
    }
    else {
      $expected = self::REFUSAL;
      $expected['cinatra_dev_site_marker']['severity'] = 'Error';
      $this->assertSame($refused ? $expected : [], $result['class']);
    }
    // The guard of the running module reads the marker the same way.
    putenv($marker === NULL ? 'CINATRA_DEV_SITE' : 'CINATRA_DEV_SITE=' . $marker);
    $this->assertSame(!$refused, CinatraDevSite::markerPresent());
  }

  /**
   * Marker values and whether the install is refused.
   *
   * @return array<string, array{string|null, bool}>
   *   The cases.
   */
  public static function markerCases(): array {
    return [
      'no marker' => [NULL, TRUE],
      'marker 1' => ['1', FALSE],
      'empty' => ['', TRUE],
      'zero' => ['0', TRUE],
      'true' => ['true', TRUE],
      'TRUE' => ['TRUE', TRUE],
      'yes' => ['yes', TRUE],
      'leading zero' => ['01', TRUE],
      'leading space' => [' 1', TRUE],
      'trailing space' => ['1 ', TRUE],
    ];
  }

  /**
   * Phases other than "install" return nothing, marker or not.
   */
  public function testOtherPhasesReturnNothing(): void {
    foreach (['runtime', 'update', 'Install', ''] as $phase) {
      $this->assertSame([], $this->probe($phase, NULL)['hook'], $phase);
    }
  }

  /**
   * Runs the install-check probe in a child process.
   *
   * @param string $phase
   *   The phase passed to the hook.
   * @param string|null $marker
   *   The marker value, or NULL for none.
   *
   * @return array
   *   The decoded 'premise', 'hook' and 'class' results.
   */
  private function probe(string $phase, ?string $marker): array {
    $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
    $module = dirname(__DIR__, 3) . '/modules/cinatra_dev_site';
    $script = dirname(__DIR__, 2) . '/fixtures/dev-site-install-probe.php';
    $this->assertFileExists($autoload);
    $this->assertFileExists($module . '/cinatra_dev_site.install');
    $env = $marker === NULL ? [] : ['CINATRA_DEV_SITE' => $marker];
    $process = proc_open([PHP_BINARY, $script, $autoload, $module, $phase], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, NULL, $env);
    $this->assertIsResource($process);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $this->assertSame(0, proc_close($process), 'The install check failed to load: ' . $err);
    $result = json_decode($out, TRUE);
    $this->assertIsArray($result);
    // The premise: before either form loaded, no class of the module could be
    // autoloaded in the child process.
    $this->assertSame([
      'Drupal\cinatra_dev_site\CinatraDevSite' => FALSE,
      'Drupal\cinatra_dev_site\Controller\DevSiteRemoteEndpoint' => FALSE,
      'Drupal\cinatra_dev_site\Install\Requirements\CinatraDevSiteRequirements' => FALSE,
      'Drupal\cinatra_dev_site\Mcp\AllowListContainer' => FALSE,
      'Drupal\cinatra_dev_site\Mcp\ExposureDeniedException' => FALSE,
    ], $result['premise'] ?? NULL);
    return $result;
  }

}
