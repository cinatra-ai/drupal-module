<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra_dev_site\CinatraDevSite;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the armed State flag has exactly one reader, the guard.
 *
 * Every file of the module with the extension .php, .module, .install or
 * .inc is read token by token. A read of ARMED_KEY outside
 * CinatraDevSite::exposureActive() is a second runtime check that could
 * trust the flag without the marker (MUST-FIX 3). A use this test cannot
 * classify fails it.
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
      if (!self::scans($file->getFilename())) {
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
   * Only arm() sets the flag and only disarm() deletes it.
   *
   * The method ensure() calls arm() as its last write (MUST-FIX 5); a second
   * writer could set the flag before the verify step.
   */
  public function testArmedFlagHasOneWriterAndOneRemover(): void {
    $uses = $this->moduleUses();
    $this->assertSame(['src/CinatraDevSite.php arm set'], array_values(array_filter($uses, static fn(string $use): bool => str_ends_with($use, ' set'))));
    $this->assertSame(['src/CinatraDevSite.php disarm delete'], array_values(array_filter($uses, static fn(string $use): bool => str_ends_with($use, ' delete'))));
  }

  /**
   * Only DevSiteEnsure.php enters the verifying scope.
   *
   * The scope makes exposureActive() TRUE for this process without the flag.
   * Every static call of a method named verifying in the module's files is
   * listed with its file.
   */
  public function testVerifyingScopeHasOneCaller(): void {
    $callers = [];
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    foreach ($this->moduleFiles() as $relative => $code) {
      $tokens = array_values(array_filter(token_get_all($code), static fn($t): bool => !is_array($t) || !in_array($t[0], $skip, TRUE)));
      foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], 'verifying') === 0
          && is_array($tokens[$i - 1] ?? NULL) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
          $callers[] = $relative;
        }
      }
    }
    $this->assertSame(['src/DevSiteEnsure.php'], array_values(array_unique($callers)));
  }

  /**
   * The scan reads a file only when its name ends in one of four extensions.
   *
   * The extensions are .php, .module, .install and .inc. A name that has a
   * line feed after one of them does not end in it.
   *
   * @param string $name
   *   The file name.
   * @param bool $scanned
   *   Whether the scan reads the file.
   *
   * @dataProvider fileNames
   */
  public function testScanReadsFourExtensions(string $name, bool $scanned): void {
    $this->assertSame($scanned, self::scans($name));
  }

  /**
   * File names and whether the scan reads the file.
   *
   * @return array<string, array{string, bool}>
   *   The cases.
   */
  public static function fileNames(): array {
    return [
      '.php' => ['probe.php', TRUE],
      '.module' => ['probe.module', TRUE],
      '.install' => ['probe.install', TRUE],
      '.inc' => ['probe.inc', TRUE],
      '.theme' => ['probe.theme', FALSE],
      '.yml' => ['probe.yml', FALSE],
      '.php inside the name' => ['probe.php.txt', FALSE],
      '.php and a line feed' => ["probe.php\n", FALSE],
    ];
  }

  /**
   * The scan reports each read of the flag that otherReads() lists.
   *
   * Each snippet reads the flag outside the guard. The scan must list it as a
   * use it cannot classify, or as a read by a function that is not the guard.
   * otherReads() also names reads the scan misses.
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
   * The scan lists exactly the uses that each of these rows expects.
   *
   * It lists no use for the strings of the rows 'step names', 'another key
   * of the module' and 'a status value', which stand alone (no "." joins
   * them to something that is not a literal). It lists the definition built
   * by concatenation once, as the definition. The run of the row 'armed and
   * a line feed' is joined to a variable, but its value is "armed" followed
   * by a line feed, not "armed", so the scan lists no use for it.
   *
   * @param string $code
   *   The source the scan reads.
   * @param string[] $expected
   *   The uses the scan must list.
   *
   * @dataProvider otherStrings
   */
  public function testScanSkipsOtherStrings(string $code, array $expected): void {
    $this->assertSame($expected, $this->uses($code));
  }

  /**
   * Strings that are not a use of the flag, and the definition.
   *
   * @return array<string, array{string, string[]}>
   *   The cases.
   */
  public static function otherStrings(): array {
    return [
      'step names' => [
        '<?php function run() { $steps[] = \'armed\'; $steps[] = \'disarmed\'; return $steps; }',
        [],
      ],
      'another key of the module' => [
        '<?php function probe() { return \\Drupal::state()->get(\'cinatra_dev_site.\' . \'owned_binding\'); }',
        [],
      ],
      'a status value' => [
        '<?php function probe() { return [\'status\' => \'armed\', \'lock\' => \'cinatra_dev_site.ensure\']; }',
        [],
      ],
      'the definition built by concatenation' => [
        '<?php final class CinatraDevSite { public const ARMED_KEY = \'cinatra_dev_site.\' . \'armed\'; }',
        ['const'],
      ],
      'armed and a line feed' => [
        '<?php function probe($prefix) { return \\Drupal::state()->get($prefix . "armed\\n"); }',
        [],
      ],
    ];
  }

  /**
   * The scan labels each use as the return value of uses() describes.
   *
   * @param string $code
   *   The source the scan reads.
   * @param string[] $expected
   *   The uses the scan must list.
   *
   * @dataProvider labelledUses
   */
  public function testScanLabelsEachUse(string $code, array $expected): void {
    $this->assertSame($expected, $this->uses($code));
  }

  /**
   * Uses of the flag and the label of each.
   *
   * @return array<string, array{string, string[]}>
   *   The cases.
   */
  public static function labelledUses(): array {
    return [
      'the guard' => [
        '<?php final class CinatraDevSite { public static function exposureActive(): bool {'
          . ' return \\Drupal::state()->get(self::ARMED_KEY) === TRUE; } }',
        ['exposureActive get'],
      ],
      'a call whose receiver is not State' => [
        '<?php function probe($foreign) { return $foreign->get(self::ARMED_KEY); }',
        ['probe get'],
      ],
      'a State call with the literal key' => [
        '<?php function probe() { return \\Drupal::state()->get(\'cinatra_dev_site.armed\'); }',
        ['probe unknown'],
      ],
      'a fully qualified name' => [
        '<?php function probe() {'
          . ' return \\Drupal::state()->get(\\Drupal\\cinatra_dev_site\\CinatraDevSite::ARMED_KEY); }',
        ['probe unknown'],
      ],
      'static, "?->" and a second argument' => [
        '<?php function probe($state) { return $state?->get(static::ARMED_KEY, FALSE); }',
        ['probe get'],
      ],
      'the unqualified class name' => [
        '<?php function probe($state) { $state->set(CinatraDevSite::ARMED_KEY, TRUE); }',
        ['probe set'],
      ],
      'a use outside any function' => [
        '<?php \\Drupal::state()->delete(self::ARMED_KEY);',
        [' delete'],
      ],
      'a variable method name' => [
        '<?php function probe($state, $m) { return $state->$m(self::ARMED_KEY); }',
        ['probe $m'],
      ],
      'the name as a later argument' => [
        '<?php function probe($state) { return $state->get(\'flag\', self::ARMED_KEY); }',
        ['probe unknown'],
      ],
      'an operator after the name' => [
        '<?php function probe($state) { return $state->get(self::ARMED_KEY . \'.x\'); }',
        ['probe unknown'],
      ],
      'the key inside a longer literal' => [
        '<?php function probe() { return \'prefix cinatra_dev_site.armed suffix\'; }',
        ['probe unknown'],
      ],
      'a typed definition' => [
        '<?php final class Probe { public const string ARMED_KEY = \'cinatra_dev_site.armed\'; }',
        [' unknown', ' unknown'],
      ],
      'a grouped definition' => [
        '<?php final class Probe { const OTHER = \'other\', ARMED_KEY = \'cinatra_dev_site.armed\'; }',
        [' unknown', ' unknown'],
      ],
      'the keyword function in upper case' => [
        '<?php FUNCTION probe($state) { return $state->get(self::ARMED_KEY); }',
        ['probe get'],
      ],
      'the keyword const in upper case' => [
        '<?php final class Probe { public CONST ARMED_KEY = \'cinatra_dev_site.armed\'; }',
        [' unknown', ' unknown'],
      ],
      'self in upper case' => [
        '<?php function probe($state) { return $state->get(SELF::ARMED_KEY); }',
        ['probe unknown'],
      ],
    ];
  }

  /**
   * The scan misses each read that the comment of otherReads() names.
   *
   * These rows pin the stated limits of the scan. When the scan learns one
   * of these forms, its row moves to otherReads(). The rows read the key:
   * PHP leaves the line feed before the closing identifier of a heredoc or
   * a nowdoc out of its value, it reads \u{2e} in a double-quoted string
   * as ".", and the row with a variable reads the key when $x is empty.
   *
   * @param string $code
   *   The source the scan reads.
   *
   * @dataProvider missedReads
   */
  public function testScanMissesTheNamedReads(string $code): void {
    $this->assertSame([], $this->uses($code));
  }

  /**
   * Reads of the flag that the scan misses.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function missedReads(): array {
    return [
      'two partial constants' => [
        '<?php final class Probe { private const PREFIX = \'cinatra_dev_site.\'; private const SUFFIX = \'armed\';'
          . ' public function probe() { return \\Drupal::state()->get(self::PREFIX . self::SUFFIX); } }',
      ],
      'a key completed by .=' => [
        '<?php function probe() { $k = \'cinatra_dev_site.\'; $k .= \'armed\'; return \\Drupal::state()->get($k); }',
      ],
      'a key built by sprintf()' => [
        '<?php function probe() { return \\Drupal::state()->get(sprintf(\'%s.%s\', \'cinatra_dev_site\', \'armed\')); }',
      ],
      'a join in parentheses' => [
        '<?php function probe() { return \\Drupal::state()->get((\'cinatra_dev_site.\' . \'ar\') . \'med\'); }',
      ],
      'a double-quoted string with a variable' => [
        '<?php function probe($x) { return \\Drupal::state()->get("cinatra_dev_site.armed{$x}"); }',
      ],
      'a heredoc' => [
        "<?php function probe() { return \\Drupal::state()->get(<<<KEY\ncinatra_dev_site.armed\nKEY); }",
      ],
      'a nowdoc' => [
        "<?php function probe() { return \\Drupal::state()->get(<<<'KEY'\ncinatra_dev_site.armed\nKEY); }",
      ],
      'an escape that stripcslashes() does not read' => [
        '<?php function probe() { return \\Drupal::state()->get("cinatra_dev_site\\u{2e}armed"); }',
      ],
    ];
  }

  /**
   * Reads of the flag that are not the guard.
   *
   * The scan reports each use of the name ARMED_KEY. It also reads each
   * quoted literal without a variable in it, with or without the prefix b
   * or B, and it reads a run of such literals joined by "." as one value.
   * It reads a single-quoted literal as PHP does, and a double-quoted one
   * with stripcslashes(), which reads the escapes of C: it does not read
   * \e or \u{...}, it drops the backslash of an escape that PHP keeps as
   * written, and it reads \a and \b, which PHP keeps as written. A run
   * whose value contains the key counts as the literal key. A run that is
   * also joined by "." to something that is not a literal counts when its
   * value is "armed", ends in ".armed" or ends in "cinatra_dev_site.". The
   * scan reports nothing else, so it misses a read whose key is spelled in
   * none of these ways, and no such read is listed here. Examples:
   * self::PREFIX . self::SUFFIX where the two constants are defined as the
   * single literals 'cinatra_dev_site.' and 'armed'; the statement
   * $k = 'cinatra_dev_site.' followed by $k .= 'armed'; the call
   * sprintf('%s.%s', 'cinatra_dev_site', 'armed'); the join in parentheses
   * ('cinatra_dev_site.' . 'ar') . 'med'; a key that lies whole inside a
   * double-quoted string with a variable in it, a heredoc or a nowdoc; and
   * the key spelled with \u{2e} for the dot. missedReads() pins each
   * example. The scan is a CI guard against an accidental second reader,
   * not a runtime gate.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function otherReads(): array {
    return [
      'concatenated key' => [
        '<?php function probe() { return \\Drupal::state()->get(\'cinatra_dev_site.\' . \'armed\'); }',
      ],
      'key in four pieces' => [
        '<?php function probe() { return \\Drupal::state()->get(\'cinatra_\' . \'dev_site.\' . \'arm\' . \'ed\'); }',
      ],
      'double-quoted pieces' => [
        '<?php function probe() { return \\Drupal::state()->get("cinatra_dev_site." . "armed"); }',
      ],
      'prefix constant and suffix' => [
        '<?php final class Probe { private const PREFIX = \'cinatra_dev_site.\';'
          . ' public function probe() { return \\Drupal::state()->get(self::PREFIX . \'armed\'); } }',
      ],
      'prefix variable and suffix' => [
        '<?php function probe($prefix) { return \\Drupal::state()->get($prefix . \'.armed\'); }',
      ],
      'prefix and suffix constant' => [
        '<?php final class Probe { private const SUFFIX = \'armed\';'
          . ' public function probe() { return \\Drupal::state()->get(\'cinatra_dev_site.\' . self::SUFFIX); } }',
      ],
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
      'binary-prefixed pieces' => [
        '<?php function probe() { return \\Drupal::state()->get(b\'cinatra_dev_site.\' . B"armed"); }',
      ],
    ];
  }

  /**
   * Reads every scanned file of the module.
   *
   * @return array<string, string>
   *   The code, keyed by the path relative to the module folder.
   */
  private function moduleFiles(): array {
    $module = dirname((string) (new \ReflectionClass(CinatraDevSite::class))->getFileName(), 2);
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (self::scans($file->getFilename())) {
        $files[substr($file->getPathname(), strlen($module) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Lists every use of the flag in the module, each with its file.
   *
   * @return string[]
   *   The uses, as "<file> <function> <get|set|delete|unknown>" or
   *   "<file> const".
   */
  private function moduleUses(): array {
    $uses = [];
    foreach ($this->moduleFiles() as $relative => $code) {
      foreach ($this->uses($code) as $use) {
        $uses[] = $relative . ' ' . $use;
      }
    }
    return $uses;
  }

  /**
   * Tells whether the scan reads a file of this name.
   *
   * @param string $name
   *   The file name.
   *
   * @return bool
   *   TRUE when the name ends in .php, .module, .install or .inc.
   */
  private static function scans(string $name): bool {
    return preg_match('/\.(php|module|install|inc)\z/', $name) === 1;
  }

  /**
   * Lists each use of ARMED_KEY, or of a run of literals that spells it.
   *
   * A run right after the three tokens "const", "ARMED_KEY" and "=" is not
   * listed on its own; that definition is listed once, as 'const'.
   *
   * @param string $code
   *   The file's source.
   *
   * @return string[]
   *   'const' for the name ARMED_KEY when the token right before it is
   *   "const" and the token right after it is "=" (a typed or a grouped
   *   definition is not such a use); '<function> <method>' when
   *   self::ARMED_KEY, static::ARMED_KEY or the unqualified
   *   CinatraDevSite::ARMED_KEY is the first argument of a method call
   *   and is followed by ")" or ",": "->" or "?->", then one token whose
   *   text is <method>, then "("; '<function> unknown' for every other
   *   use, a run of literals that spells the key included. The receiver
   *   of the method call is not checked. <function> is the last name that
   *   directly follows the keyword "function" before the use, or empty.
   *   The keyword "function" is matched by its token type, in any case;
   *   every other token named here is compared by its text, case
   *   included, so CONST or SELF does not count as "const" or "self".
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
      $literal = $this->spellsTheKey($tokens, $i);
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

  /**
   * Tells whether a string literal starts a run that spells the key.
   *
   * A run is the literal and the literals joined to it by ".", read as one
   * value. It spells the key when the value contains the key. When the run
   * is also joined by "." to something that is not a literal, it spells the
   * key too when the value is "armed", ends in ".armed", or ends in
   * "cinatra_dev_site.". A literal inside a run is not read on its own.
   * A literal may carry the prefix b or B. A single-quoted literal is read
   * as PHP reads it, a double-quoted one with stripcslashes().
   *
   * @param array $tokens
   *   The tokens, without whitespace and comments.
   * @param int $i
   *   The index of the token.
   *
   * @return bool
   *   TRUE when the token starts such a run.
   */
  private function spellsTheKey(array $tokens, int $i): bool {
    $string = static fn(int $k): bool => is_array($tokens[$k] ?? NULL) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING;
    $dot = static fn(int $k): bool => ($tokens[$k] ?? NULL) === '.';
    if (!$string($i) || ($dot($i - 1) && $string($i - 2))) {
      return FALSE;
    }
    $value = '';
    $k = $i - 2;
    do {
      $k += 2;
      $text = ltrim($tokens[$k][1], 'bB');
      $inner = substr($text, 1, -1);
      $value .= $text[0] === '"' ? stripcslashes($inner) : str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    } while ($dot($k + 1) && $string($k + 2));
    if (str_contains($value, 'cinatra_dev_site.armed')) {
      return TRUE;
    }
    $joined = $dot($i - 1) || $dot($k + 1);
    return $joined && preg_match('/(^armed|\.armed|cinatra_dev_site\.)\z/', $value) === 1;
  }

}
