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
 * stubbed connection. Its information_schema answer matches names without
 * regard to case, as the server's dictionary collation does.
 *
 * @group cinatra
 */
final class ProtectedDraftInnoDbCheckTest extends UnitTestCase {

  /**
   * SHOW CREATE TABLE as MariaDB 11.4 prints it in the ANSI sql_mode.
   */
  private const ANSI_CREATE = "CREATE TABLE \"node\" (\n  \"nid\" int(10) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (\"nid\")\n)";

  /**
   * The current database of the stubbed connection.
   */
  private const DB = 'app';

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
    $this->check('node', [[self::DB, 'node', 'InnoDB']]);
    $this->assertSame(['node'], $this->engineReads());
  }

  /**
   * The engine name is compared without regard to case.
   */
  public function testEngineNameCaseIsIgnored(): void {
    $this->check('node', [[self::DB, 'node', 'innodb']]);
    $this->assertSame(['node'], $this->engineReads());
  }

  /**
   * The engine is read for the prefixed table name.
   */
  public function testPrefixedTableNameIsRead(): void {
    $this->check('node_revision', [[self::DB, 'cms_node_revision', 'InnoDB']], 'cms_');
    $this->assertSame(['cms_node_revision'], $this->engineReads());
  }

  /**
   * The name is the one Drupal expands the table placeholder to.
   *
   * A connection whose default prefix differs from the expansion of the
   * table must be read at the expansion: there the table is MyISAM.
   */
  public function testExpandedTableNameIsRead(): void {
    $this->assertRefused(fn() => $this->check('node_revision', [
      [self::DB, 'd_node_revision', 'InnoDB'],
      [self::DB, 'n_node_revision', 'MyISAM'],
    ], 'd_', '"n_node_revision"'));
    $this->assertSame(['n_node_revision'], $this->engineReads());
  }

  /**
   * A schema-qualified expansion is read in that schema.
   */
  public function testSchemaQualifiedExpansionIsReadInItsSchema(): void {
    $this->check('node', [['shared', 'node', 'InnoDB']], 'shared.', '"shared"."node"');
    $this->assertSame(['node'], $this->engineReads());
    $this->assertSame(['shared'], $this->schemaReads());
  }

  /**
   * An expansion that is not a plain or schema-qualified name refuses.
   */
  public function testUnsupportedExpansionRefuses(): void {
    try {
      $this->check('node', [[self::DB, 'node', 'InnoDB']], '', '"a"."b"."node"');
      $this->fail('The expansion was accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
      $this->assertSame('The table mapping is not supported.', $e->getMessage());
    }
    $this->assertSame([], $this->engineReads());
  }

  /**
   * An InnoDB case twin does not validate the MyISAM target table.
   */
  public function testCaseTwinTableDoesNotPass(): void {
    $this->assertRefused(fn() => $this->check('node_revision', [
      [self::DB, 'Node_Revision', 'InnoDB'],
      [self::DB, 'node_revision', 'MyISAM'],
    ]));
  }

  /**
   * A case twin alone is not the target table.
   */
  public function testOnlyCaseTwinListedRefuses(): void {
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'NODE', 'InnoDB']]));
  }

  /**
   * A table of a case-twin schema is not the target table.
   */
  public function testCaseTwinSchemaDoesNotPass(): void {
    $this->assertRefused(fn() => $this->check('node', [['APP', 'node', 'InnoDB']]));
  }

  /**
   * Without a current database the check refuses.
   */
  public function testNoCurrentDatabaseRefuses(): void {
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'node', 'InnoDB']], '', NULL, self::ANSI_CREATE, NULL));
  }

  /**
   * SHOW CREATE TABLE still opens the target table before the engine read.
   */
  public function testShowCreateTableOpensTheTableFirst(): void {
    $this->check('node', [[self::DB, 'node', 'InnoDB']]);
    $this->assertCount(2, $this->queries);
    $this->assertSame('SHOW CREATE TABLE {node}', $this->queries[0][0]);
    $this->assertStringContainsStringIgnoringCase('information_schema', $this->queries[1][0]);
  }

  /**
   * A MyISAM table refuses.
   */
  public function testMyIsamTableRefuses(): void {
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'node', 'MyISAM']]));
  }

  /**
   * A NULL or empty engine refuses.
   */
  public function testMissingEngineRefuses(): void {
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'node', NULL]]));
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'node', '']]));
  }

  /**
   * A MyISAM table refuses even when SHOW CREATE TABLE names InnoDB.
   */
  public function testInformationSchemaEngineDecides(): void {
    $this->assertRefused(fn() => $this->check('node', [[self::DB, 'node', 'MyISAM']], '', NULL, self::ANSI_CREATE . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
  }

  /**
   * A table that information_schema does not list refuses.
   */
  public function testUnlistedTableRefuses(): void {
    $this->assertRefused(fn() => $this->check('node', []));
  }

  /**
   * A table name outside the plain identifier set refuses before any query.
   */
  public function testUnsupportedTableNameRefuses(): void {
    try {
      $this->check('node; DROP', [[self::DB, 'node', 'InnoDB']]);
      $this->fail('The table name was accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
      $this->assertSame('The table mapping is not supported.', $e->getMessage());
    }
    $this->assertSame([], $this->queries);
  }

  /**
   * Asserts that a call refuses with the InnoDB refusal.
   *
   * @param \Closure $call
   *   The call.
   */
  private function assertRefused(\Closure $call): void {
    try {
      $call();
    }
    catch (ProtectedDraftRefusal $e) {
      $this->assertSame('Protected revisions require transactional InnoDB tables.', $e->getMessage());
      return;
    }
    $this->fail('The check passed.');
  }

  /**
   * Runs the private InnoDB check against a stubbed MySQL connection.
   *
   * @param string $table
   *   The unprefixed table name.
   * @param array $catalog
   *   The tables of the server: [schema, name, engine] each.
   * @param string $prefix
   *   The connection's default table prefix.
   * @param string|null $expansion
   *   What the table placeholder expands to, or NULL for the quoted prefixed
   *   name.
   * @param string $create
   *   The CREATE TABLE text that SHOW CREATE TABLE prints.
   * @param string|null $current
   *   The current database, or NULL for none.
   */
  private function check(string $table, array $catalog, string $prefix = '', ?string $expansion = NULL, string $create = self::ANSI_CREATE, ?string $current = self::DB): void {
    $this->queries = [];
    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn('mysql');
    $database->method('getPrefix')->willReturn($prefix);
    $database->method('prefixTables')->willReturnCallback(static fn(string $sql): string => str_replace('{' . $table . '}', $expansion ?? '"' . $prefix . $table . '"', $sql));
    $database->method('query')->willReturnCallback(function (string $query, array $args = []) use ($catalog, $create, $table, $prefix, $current): StatementInterface {
      $this->queries[] = [$query, $args];
      $statement = $this->createMock(StatementInterface::class);
      if (stripos($query, 'information_schema') !== FALSE) {
        // The dictionary compares names without regard to case.
        $schema = $args[':schema'] ?? $current;
        $rows = [];
        foreach ($catalog as [$row_schema, $row_name, $row_engine]) {
          if ($schema !== NULL && strcasecmp($row_schema, $schema) === 0 && strcasecmp($row_name, (string) ($args[':name'] ?? '')) === 0) {
            $rows[] = [
              'schema_name' => $row_schema,
              'table_name' => $row_name,
              'table_engine' => $row_engine,
              'current_db' => $current,
            ];
          }
        }
        // The rows are read one at a time: fetchAll() takes a PDO mode on
        // Drupal 10 and a FetchAs enum on Drupal 11.4, so the check must not
        // call it.
        $statement->expects($this->never())->method('fetchAll');
        $statement->method('fetchAssoc')->willReturnCallback(static function () use (&$rows): array|false {
          return array_shift($rows) ?? FALSE;
        });
        $statement->method('fetchField')->willReturn($rows === [] ? FALSE : $rows[0]['table_engine']);
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
   * @return array<int, mixed>
   *   The :name argument of each information_schema query.
   */
  private function engineReads(): array {
    $reads = [];
    foreach ($this->queries as [$query, $args]) {
      if (stripos($query, 'information_schema') !== FALSE) {
        $reads[] = $args[':name'] ?? NULL;
      }
    }
    return $reads;
  }

  /**
   * Lists the schema names that the information_schema reads were bound to.
   *
   * @return array<int, mixed>
   *   The :schema argument of each information_schema query.
   */
  private function schemaReads(): array {
    $reads = [];
    foreach ($this->queries as [$query, $args]) {
      if (stripos($query, 'information_schema') !== FALSE) {
        $reads[] = $args[':schema'] ?? NULL;
      }
    }
    return $reads;
  }

}
