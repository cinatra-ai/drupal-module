<?php

// Multiple dependency doubles deliberately share this standalone harness.
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch


declare(strict_types=1);

/**
 * Native adapter API cases with doubles, not Drupal Kernel or SQL proof.
 */

namespace Drupal\node {

  /**
   * Provides the native node interface dependency double.
   */
  interface NodeInterface {}
}

namespace Drupal\Core\Config {

  /**
   * Provides the configuration factory identity for a native adapter check.
   */
  interface ConfigFactoryInterface {}
}

namespace Drupal\Core\Entity {

  /**
   * Provides the entity manager identity for a native adapter check.
   */
  interface EntityTypeManagerInterface {}
}

namespace {
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftStorageInterface.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftRefusal.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftConfiguration.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/DrupalProtectedDraftStorage.php';

  use Drupal\cinatra\ProtectedDraft\DrupalProtectedDraftStorage;
  use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\node\NodeInterface;

  /**
   * Provides only the global service registry used by the identity assertion.
   *
   * This class is a native double; it boots no Drupal container or site.
   */
  final class CinatraNativeSiteServices {

    /**
     * Global configuration factory identity supplied by the native test.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    public static ConfigFactoryInterface $configFactory;

    /**
     * Global entity manager identity supplied by the native test.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    public static EntityTypeManagerInterface $entityTypeManager;

    /**
     * Returns the global configuration factory from the native fixture.
     */
    public static function service(string $id): ConfigFactoryInterface {
      if ($id !== 'config.factory') {
        throw new RuntimeException('The native registry exposes only config.factory.');
      }
      return self::$configFactory;
    }

    /**
     * Returns the global entity manager from the native fixture.
     */
    public static function entityTypeManager(): EntityTypeManagerInterface {
      return self::$entityTypeManager;
    }

  }

  class_alias(CinatraNativeSiteServices::class, 'Drupal');

  /**
   * Supplies native PDO error metadata without opening a database.
   */
  final class CinatraNativeSqliteError extends PDOException {

    public function __construct(?array $error_info, string $sqlstate = 'HY000') {
      parent::__construct('Native SQLite protocol double.');
      $this->errorInfo = $error_info;
      $this->code = $sqlstate;
    }

  }

  /**
   * Provides the native cinatra native field storage dependency double.
   */
  final class CinatraNativeFieldStorage {

    public function __construct(public bool $revisionable = TRUE) {}

    /**
     * Reports is revisionable.
     */
    public function isRevisionable(): bool {
      return $this->revisionable;
    }

    /**
     * Reports get cardinality.
     */
    public function getCardinality(): int {
      return 1;
    }

    /**
     * Reports get constraints.
     */
    public function getConstraints(): array {
      return [];
    }

  }

  // Configurable definitions intentionally have NO isRevisionable() method:
  /**
   * FieldDefinitionInterface supplies getFieldStorageDefinition() instead.
   */
  class CinatraNativeConfigurableField {

    public function __construct(
      protected CinatraNativeFieldStorage $storage,
      public bool $translatable = TRUE,
      public bool $computed = FALSE,
      public bool $readOnly = FALSE,
    ) {}

    /**
     * Reports get field storage definition.
     */
    public function getFieldStorageDefinition(): object {
      return $this->storage;
    }

    /**
     * Reports is translatable.
     */
    public function isTranslatable(): bool {
      return $this->translatable;
    }

    /**
     * Reports is computed.
     */
    public function isComputed(): bool {
      return $this->computed;
    }

    /**
     * Reports is read only.
     */
    public function isReadOnly(): bool {
      return $this->readOnly;
    }

    /**
     * Reports get type.
     */
    public function getType(): string {
      return 'string';
    }

    /**
     * Reports get settings.
     */
    public function getSettings(): array {
      return [];
    }

    /**
     * Reports is required.
     */
    public function isRequired(): bool {
      return FALSE;
    }

    /**
     * Reports get constraints.
     */
    public function getConstraints(): array {
      return [];
    }

    /**
     * Reports get item definition.
     */
    public function getItemDefinition(): object {
      return new CinatraNativeFieldStorage();
    }

    /**
     * Reports get default value literal.
     */
    public function getDefaultValueLiteral(): array {
      return [];
    }

  }

  /**
   * Base definitions serve as their own storage definition in Drupal.
*/
  final class CinatraNativeBaseField extends CinatraNativeConfigurableField {

    /**
     * Reports get field storage definition.
     */
    public function getFieldStorageDefinition(): object {
      return $this;
    }

    /**
     * Reports is revisionable.
     */
    public function isRevisionable(): bool {
      return $this->storage->isRevisionable();
    }

    /**
     * Reports get cardinality.
     */
    public function getCardinality(): int {
      return 1;
    }

  }

  /**
   * Provides the native cinatra native field items dependency double.
   */
  final class CinatraNativeFieldItems {

    public function __construct(private array $values) {}

    /**
     * Reports get value.
     */
    public function getValue(): array {
      return $this->values;
    }

  }

  /**
   * Provides the native cinatra native field node dependency double.
   */
  final class CinatraNativeFieldNode implements NodeInterface {
    /**
     * Language-keyed native node translation doubles.
     *
     * @var array
     */
    public array $translations = [];

    public function __construct(private array $definitions, private array $values, private string $language = 'en') {}

    /**
     * Reports has field.
     */
    public function hasField(string $name): bool {
      return isset($this->definitions[$name]);
    }

    /**
     * Reports get field definition.
     */
    public function getFieldDefinition(string $name): object {
      return $this->definitions[$name];
    }

    /**
     * Reports get field definitions.
     */
    public function getFieldDefinitions(): array {
      return $this->definitions;
    }

    /**
     * Reports is default translation.
     */
    public function isDefaultTranslation(): bool {
      return $this->language === 'en';
    }

    /**
     * Reports get translation languages.
     */
    public function getTranslationLanguages(): array {
      return array_fill_keys(array_keys($this->translations), NULL);
    }

    /**
     * Reports get translation.
     */
    public function getTranslation(string $language): self {
      return $this->translations[$language];
    }

    /**
     * Reports get.
     */
    public function get(string $name): CinatraNativeFieldItems {
      return new CinatraNativeFieldItems($this->values[$name]);
    }

  }

  // Only these pure adapter methods execute; no connection, container, account,
  // storage handler, configuration backend or public read/write is initialized.
  $adapter = (new ReflectionClass(DrupalProtectedDraftStorage::class))->newInstanceWithoutConstructor();
  $editable = new ReflectionMethod($adapter, 'editableField');
  $content = new ReflectionMethod($adapter, 'draftContent');
  $fingerprint = new ReflectionMethod($adapter, 'workflowFingerprint');
  $passed = 0;
  $assert = function (string $name, bool $actual, bool $expected): void {
    if ($actual !== $expected) {
      throw new RuntimeException($name . ' has incorrect eligibility');
    }
  };
  $cases = [
    [
      'configurable translated field on default language',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage()),
      'en',
      TRUE,
    ],
    [
      'configurable translated field on French',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage()),
      'fr',
      TRUE,
    ],
    [
      'configurable shared field on default language',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), FALSE),
      'en',
      TRUE,
    ],
    [
      'configurable shared field on French refuses',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), FALSE),
      'fr',
      FALSE,
    ],
    [
      'nonrevisionable storage refuses',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(FALSE)),
      'en',
      FALSE,
    ],
    [
      'computed field refuses',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), TRUE, TRUE),
      'en',
      FALSE,
    ],
    [
      'readonly field refuses',
      new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), TRUE, FALSE, TRUE),
      'en',
      FALSE,
    ],
    [
      'base field using itself as storage remains editable',
      new CinatraNativeBaseField(new CinatraNativeFieldStorage()),
      'en',
      TRUE,
    ],
  ];
  foreach ($cases as [$name, $definition, $language, $allowed]) {
    $node = new CinatraNativeFieldNode(['body' => $definition], ['body' => [['value' => 'Stored value']]], $language);
    $assert($name, $editable->invoke($adapter, $node, 'body'), $allowed);
    $passed++;
    echo "PASS $name\n";
  }

  $definitions = [
    'body' => new CinatraNativeConfigurableField(new CinatraNativeFieldStorage()),
    'field_shared_note' => new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), FALSE),
    'unrevisioned' => new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(FALSE)),
    'computed' => new CinatraNativeConfigurableField(new CinatraNativeFieldStorage(), TRUE, TRUE),
    'changed' => new CinatraNativeBaseField(new CinatraNativeFieldStorage()),
  ];
  $en = new CinatraNativeFieldNode($definitions, [
    'body' => [
      [
        'value' => 'English',
      ],
    ],
    'field_shared_note' => [
      [
        'value' => 'Shared',
      ],
    ],
  ]);
  $fr = new CinatraNativeFieldNode($definitions, [
    'body' => [
      [
        'value' => 'French',
      ],
    ],
    'field_shared_note' => [
      [
        'value' => 'Shared',
      ],
    ],
  ], 'fr');
  $en->translations = $fr->translations = ['en' => $en, 'fr' => $fr];
  $actual = $content->invoke($adapter, $en);
  $expected = [
    'en' => [
      'body' => [
        [
          'value' => 'English',
        ],
      ],
      'field_shared_note' => [
        [
          'value' => 'Shared',
        ],
      ],
    ],
    'fr' => [
      'body' => [
        [
          'value' => 'French',
        ],
      ],
      'field_shared_note' => [
        [
          'value' => 'Shared',
        ],
      ],
    ],
  ];
  if ($actual !== $expected) {
    throw new RuntimeException('Stored scope must use storage revisionability and preserve both translation values.');
  }
  $passed++;
  echo "PASS configurable stored content scope across actual supplied translations\n";

  $revisionable = $fingerprint->invoke($adapter, ['body' => $definitions['body']]);
  $definitions['body']->getFieldStorageDefinition()->revisionable = FALSE;
  $unrevisioned = $fingerprint->invoke($adapter, ['body' => $definitions['body']]);
  if ($revisionable === $unrevisioned || !preg_match('/^[0-9a-f]{64}$/', $revisionable)) {
    throw new RuntimeException('The binding must include the actual storage revisionability.');
  }
  $passed++;
  echo "PASS configuration binding observes changed field storage revisionability\n";

  $factory = new class implements ConfigFactoryInterface {};
  $manager = new class implements EntityTypeManagerInterface {};
  CinatraNativeSiteServices::$configFactory = $factory;
  CinatraNativeSiteServices::$entityTypeManager = $manager;
  $site_handlers = new ReflectionMethod(DrupalProtectedDraftStorage::class, 'assertSiteHandlers');
  $site_handlers->invoke(NULL, $factory, $manager);
  $passed++;
  echo "PASS matching global handlers preserve the actual site identity\n";
  foreach ([
    'alternate injected configuration factory' => [clone $factory, $manager],
    'alternate injected entity manager' => [$factory, clone $manager],
  ] as $name => [$injected_factory, $injected_manager]) {
    try {
      $site_handlers->invoke(NULL, $injected_factory, $injected_manager);
      throw new RuntimeException('An alternate handler bypassed the global identity guard.');
    }
    catch (ProtectedDraftRefusal $expected) {
      if (!str_contains($expected->getMessage(), 'actual access checks consume')) {
        throw new RuntimeException('An unrelated refusal satisfied the identity check.');
      }
    }
    $passed++;
    echo "PASS $name refuses\n";
  }
  // These callbacks model native exec outcomes only. The actual Core client,
  // transaction ownership and SQL execution still require the Kernel suite.
  $probe = new ReflectionMethod(DrupalProtectedDraftStorage::class, 'probeLegacySqliteTransaction');
  $nested = ['HY000', 1, 'cannot start a transaction within a transaction'];
  $probe_cases = [
    ['exact native nesting error means active', new CinatraNativeSqliteError($nested), 0, TRUE, ['BEGIN']],
    ['wrong exception SQLSTATE refuses', new CinatraNativeSqliteError($nested, '42000'), 0, 'REFUSE', ['BEGIN']],
    [
      'wrong error-info SQLSTATE refuses',
      new CinatraNativeSqliteError(['42000', 1, $nested[2]]),
      0, 'REFUSE', ['BEGIN'],
    ],
    [
      'busy database does not prove an owned root',
      new CinatraNativeSqliteError(['HY000', 5, 'database is locked']),
      0, 'REFUSE', ['BEGIN'],
    ],
    [
      'unrelated SQLite error refuses',
      new CinatraNativeSqliteError(['HY000', 1, 'no such table']),
      0, 'REFUSE', ['BEGIN'],
    ],
    ['missing native error metadata refuses', new CinatraNativeSqliteError(NULL), 0, 'REFUSE', ['BEGIN']],
    [
      'message-only metadata impostor refuses',
      new CinatraNativeSqliteError(['HY000', '1', $nested[2]]),
      0, 'REFUSE', ['BEGIN'],
    ],
    ['zero-row BEGIN is cleaned and reports inactive', 0, 0, FALSE, ['BEGIN', 'ROLLBACK']],
    ['successful BEGIN is never adopted as the old root', 1, 0, FALSE, ['BEGIN', 'ROLLBACK']],
    ['failed BEGIN return refuses', FALSE, 0, 'REFUSE', ['BEGIN']],
    ['non-native BEGIN error refuses', new RuntimeException('Unknown client error.'), 0, 'REFUSE', ['BEGIN']],
    ['failed cleanup return refuses', 0, FALSE, 'REFUSE', ['BEGIN', 'ROLLBACK']],
    ['cleanup exception refuses', 0, new RuntimeException('Cleanup failed.'), 'REFUSE', ['BEGIN', 'ROLLBACK']],
    ['invalid BEGIN result refuses', TRUE, 0, 'REFUSE', ['BEGIN']],
    ['invalid cleanup result refuses', 0, TRUE, 'REFUSE', ['BEGIN', 'ROLLBACK']],
  ];
  foreach ($probe_cases as [$name, $begin_outcome, $rollback_outcome, $expected, $expected_calls]) {
    $calls = [];
    $begin = static function () use (&$calls, $begin_outcome) {
      $calls[] = 'BEGIN';
      if ($begin_outcome instanceof Throwable) {
        throw $begin_outcome;
      }
      return $begin_outcome;
    };
    $rollback = static function () use (&$calls, $rollback_outcome) {
      $calls[] = 'ROLLBACK';
      if ($rollback_outcome instanceof Throwable) {
        throw $rollback_outcome;
      }
      return $rollback_outcome;
    };
    try {
      $actual = $probe->invoke(NULL, $begin, $rollback);
      $assert($name, $actual === $expected, TRUE);
    }
    catch (ProtectedDraftRefusal $refusal) {
      if ($expected !== 'REFUSE' || !str_contains($refusal->getMessage(), 'SQLite transaction')) {
        throw new RuntimeException('An unrelated refusal satisfied the native transaction boundary.', 0, $refusal);
      }
    }
    $assert($name . ' invokes only its owned cleanup', $calls === $expected_calls, TRUE);
    $passed++;
    echo "PASS $name\n";
  }
  echo "$passed passed, 0 failed, 0 skipped\n";
}
