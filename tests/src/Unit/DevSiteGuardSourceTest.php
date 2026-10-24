<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the armed State flag has exactly one reader, the guard.
 *
 * Every file of the module is read token by token. A read of ARMED_KEY
 * outside CinatraDevSite::exposureActive() is a second runtime check that
 * could trust the flag without the marker (MUST-FIX 3). A use this test
 * cannot classify fails it.
 *
 * @group cinatra
 */
final class DevSiteGuardSourceTest extends UnitTestCase {

  /**
   * Only the guard reads the flag; only CinatraDevSite.php names it.
   */
  public function testArmedFlagHasOneReader(): void {
    $module = dirname((string) (new \ReflectionClass(CinatraDevSite::class))->getFileName(), 2);
    $this->assertFileExists($module . '/cinatra_dev_site.info.yml');
    $uses = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if (!preg_match('/\.(php|module|install|inc)$/', $file->getFilename())) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($module) + 1);
      foreach ($this->uses((string) file_get_contents($file->getPathname())) as $use) {
        $uses[] = $relative . ' ' . $use;
      }
    }
    $reads = array_values(array_filter($uses, static fn(string $use): bool => str_ends_with($use, ' get')));
    $this->assertSame(['src/CinatraDevSite.php exposureActive get'], $reads);
    foreach ($uses as $use) {
      $this->assertMatchesRegularExpression('#^src/CinatraDevSite\.php (const|\w+ (get|set|delete))$#', $use);
    }
  }

  /**
   * The scan reports every other read of the flag.
   *
   * Each snippet reads the flag outside the guard. The scan must list it as a
   * use it cannot classify, or as a read by a function that is not the guard.
   *
   * @param string $code
   *   The source the scan reads.
   *
   * @dataProvider otherReads
   */
  public function testScanReportsOtherReads(string $code): void {
    $uses = $this->uses($code);
    $breaches = array_filter($uses, static fn(string $use): bool => str_ends_with($use, ' unknown')
      || (str_ends_with($use, ' get') && $use !== 'exposureActive get'));
    $this->assertNotEmpty($breaches, 'Uses: ' . implode(', ', $uses));
  }

  /**
   * Reads of the flag that are not the guard.
   *
   * A key built by concatenation ('cinatra_dev_site.' . 'armed') is not
   * caught yet, so it is not listed here.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function otherReads(): array {
    return [
      'second reader method' => [
        '<?php final class CinatraDevSite {'
          . ' public const ARMED_KEY = \'cinatra_dev_site.armed\';'
          . ' public static function exposureActive(): bool {'
          . ' return \\Drupal::state()->get(self::ARMED_KEY) === TRUE; }'
          . ' public static function isArmed(): bool {'
          . ' return \\Drupal::state()->get(self::ARMED_KEY) === TRUE; }'
          . ' }',
      ],
      'literal key' => [
        '<?php function probe() { return \\Drupal::state()->get(\'cinatra_dev_site.armed\'); }',
      ],
      'key in a variable' => [
        '<?php function probe() { $k = self::ARMED_KEY; return \\Drupal::state()->get($k); }',
      ],
      'fully qualified class' => [
        '<?php function probe() {'
          . ' return \\Drupal::state()->get(\\Drupal\\cinatra_dev_site\\CinatraDevSite::ARMED_KEY); }',
      ],
    ];
  }

  /**
   * Lists each use of ARMED_KEY or its literal in one file.
   *
   * @param string $code
   *   The file's source.
   *
   * @return string[]
   *   'const' for the definition, '<function> <method>' for a State call
   *   with the key as its first argument, '<function> unknown' otherwise.
   */
  private function uses(string $code): array {
    $tokens = array_values(array_filter(token_get_all($code), static fn($t): bool => !is_array($t) || !in_array($t[0], [
      T_WHITESPACE,
      T_COMMENT,
      T_DOC_COMMENT,
    ], TRUE)));
    $uses = [];
    $function = '';
    foreach ($tokens as $i => $token) {
      $text = is_array($token) ? $token[1] : $token;
      if (is_array($token) && $token[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? NULL) && $tokens[$i + 1][0] === T_STRING) {
        $function = $tokens[$i + 1][1];
      }
      $literal = is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($text, 'cinatra_dev_site.armed');
      $constant = is_array($token) && $token[0] === T_STRING && $text === 'ARMED_KEY';
      if (!$literal && !$constant) {
        continue;
      }
      $at = static fn(int $k): string => is_array($tokens[$k] ?? NULL) ? $tokens[$k][1] : (string) ($tokens[$k] ?? '');
      if ($constant && $at($i - 1) === 'const' && $at($i + 1) === '=') {
        $uses[] = 'const';
        continue;
      }
      if ($literal && $at($i - 1) === '=' && $at($i - 2) === 'ARMED_KEY' && $at($i - 3) === 'const') {
        continue;
      }
      $call = $constant && $at($i - 1) === '::' && in_array($at($i - 2), ['self', 'static', 'CinatraDevSite'], TRUE)
        && $at($i - 3) === '(' && in_array($at($i - 5), ['->', '?->'], TRUE) && in_array($at($i + 1), [')', ','], TRUE);
      $uses[] = $function . ' ' . ($call ? $at($i - 4) : 'unknown');
    }
    return $uses;
  }

}
