<?php

declare(strict_types=1);

namespace Drupal\Tests\cinatra\Unit;

use Drupal\cinatra\ProtectedDraft\DrupalProtectedDraftStorage;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the InnoDB check of the protected draft storage on MySQL/MariaDB.
 *
 * Drupal runs MySQL and MariaDB connections in the ANSI sql_mode. In that
 * mode MariaDB 11.4 prints SHOW CREATE TABLE without its ENGINE= option, so
 * a check that reads the engine from that text refuses an InnoDB table. The
 * engine is read from information_schema.TABLES instead. The Kernel tests
 * run on SQLite and never reach this check, so it is tested here against a
 * stubbed connection that answers both queries.
 *
 * @group cinatra
 */
final class ProtectedDraftInnoDbCheckTest extends UnitTestCase {

  /**
   * SHOW CREATE TABLE as MariaDB 11.4 prints it in the ANSI sql_mode.
   */
  private const ANSI_CREATE = "CREATE TABLE \"node\" (\n  \"nid\" int(10) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (\"nid\")\n)";

  /**
   * The queries the stubbed connection received, with their arguments.
   *
   * @var array<int, array{string, array}>
   */
  private array $queries = [];

  /**
   * An InnoDB table passes although SHOW CREATE TABLE names no engine.
   */
  public function testInnoDbTablePassesWithoutEngineInCreateTable(): void {
    $this->check('node', 'InnoDB');
    $this->assertSame([['node']], $this->engineReads());
  }

  /**
   * The engine name is compared without regard to case.
   */
  public function testEngineNameCaseIsIgnored(): void {
    $this->check('node', 'innodb');
    $this->assertSame([['node']], $this->engineReads());
  }

  /**
   * The engine is read for the prefixed table name.
   */
  public function testPrefixedTableNameIsRead(): void {
    $this->check('node_revision', 'InnoDB', 'cms_');
    $this->assertSame([['cms_node_revision']], $this->engineReads());
  }

  /**
   * A MyISAM table refuses.
   */
  public function testMyIsamTableRefuses(): void {
    $this->expectException(ProtectedDraftRefusal::class);
    $this->expectExceptionMessage('Protected revisions require transactional InnoDB tables.');
    $this->check('node', 'MyISAM');
  }

  /**
   * A MyISAM table refuses even when SHOW CREATE TABLE names InnoDB.
   */
  public function testInformationSchemaEngineDecides(): void {
    $this->expectException(ProtectedDraftRefusal::class);
    $this->expectExceptionMessage('Protected revisions require transactional InnoDB tables.');
    $this->check('node', 'MyISAM', '', self::ANSI_CREATE . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  }

  /**
   * A table that information_schema does not list refuses.
   */
  public function testUnlistedTableRefuses(): void {
    $this->expectException(ProtectedDraftRefusal::class);
    $this->expectExceptionMessage('Protected revisions require transactional InnoDB tables.');
    $this->check('node', FALSE);
  }

  /**
   * A table name outside the plain identifier set refuses before any query.
   */
  public function testUnsupportedTableNameRefuses(): void {
    try {
      $this->check('node; DROP', 'InnoDB');
      $this->fail('The table name was accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
      $this->assertSame('The table mapping is not supported.', $e->getMessage());
    }
    $this->assertSame([], $this->queries);
  }

  /**
   * Runs the private InnoDB check against a stubbed MySQL connection.
   *
   * @param string $table
   *   The unprefixed table name.
   * @param string|false $engine
   *   The ENGINE that information_schema.TABLES reports, or FALSE for none.
   * @param string $prefix
   *   The connection's table prefix.
   * @param string $create
   *   The CREATE TABLE text that SHOW CREATE TABLE prints.
   */
  private function check(string $table, string|false $engine, string $prefix = '', string $create = self::ANSI_CREATE): void {
    $this->queries = [];
    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn('mysql');
    $database->method('getPrefix')->willReturn($prefix);
    $database->method('query')->willReturnCallback(function (string $query, array $args = []) use ($engine, $create, $table, $prefix): StatementInterface {
      $this->queries[] = [$query, $args];
      $statement = $this->createMock(StatementInterface::class);
      if (stripos($query, 'information_schema') !== FALSE) {
        $statement->method('fetchField')->willReturn($engine);
        $statement->method('fetchAssoc')->willReturn($engine === FALSE ? FALSE : ['ENGINE' => $engine]);
      }
      elseif (stripos($query, 'SHOW CREATE TABLE') !== FALSE) {
        $statement->method('fetchAssoc')->willReturn(['Table' => $prefix . $table, 'Create Table' => $create]);
      }
      return $statement;
    });
    $storage = new DrupalProtectedDraftStorage(
      $database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(StorageInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(EntityTypeBundleInfoInterface::class),
      $this->createMock(TimeInterface::class),
    );
    (new \ReflectionMethod(DrupalProtectedDraftStorage::class, 'assertInnoDb'))->invoke($storage, $table);
  }

  /**
   * Lists the table names that the information_schema reads were bound to.
   *
   * @return array<int, array<int, mixed>>
   *   One list of bound argument values per information_schema query.
   */
  private function engineReads(): array {
    $reads = [];
    foreach ($this->queries as [$query, $args]) {
      if (stripos($query, 'information_schema') !== FALSE) {
        $reads[] = array_values($args);
      }
    }
    return $reads;
  }

}
