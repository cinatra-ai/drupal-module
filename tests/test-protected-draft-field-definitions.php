<?php

declare(strict_types=1);

/** Native adapter API cases with doubles, not Drupal Kernel or SQL proof. */

namespace Drupal\node {
  interface NodeInterface {}
}

namespace {
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftStorageInterface.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftRefusal.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/ProtectedDraftConfiguration.php';
  require_once dirname(__DIR__) . '/src/ProtectedDraft/DrupalProtectedDraftStorage.php';

  use Drupal\cinatra\ProtectedDraft\DrupalProtectedDraftStorage;

  final class NativeFieldStorage {
    public function __construct(public bool $revisionable = TRUE) {}
    public function isRevisionable(): bool { return $this->revisionable; }
    public function getCardinality(): int { return 1; }
    public function getConstraints(): array { return []; }
  }

  // Configurable definitions intentionally have NO isRevisionable() method:
  // FieldDefinitionInterface supplies getFieldStorageDefinition() instead.
  class NativeConfigurableField {
    public function __construct(
      protected NativeFieldStorage $storage,
      public bool $translatable = TRUE,
      public bool $computed = FALSE,
      public bool $readOnly = FALSE,
    ) {}
    public function getFieldStorageDefinition(): object { return $this->storage; }
    public function isTranslatable(): bool { return $this->translatable; }
    public function isComputed(): bool { return $this->computed; }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function getType(): string { return 'string'; }
    public function getSettings(): array { return []; }
    public function isRequired(): bool { return FALSE; }
    public function getConstraints(): array { return []; }
    public function getItemDefinition(): object { return new NativeFieldStorage(); }
    public function getDefaultValueLiteral(): array { return []; }
  }

  // Base definitions serve as their own storage definition in Drupal.
  final class NativeBaseField extends NativeConfigurableField {
    public function getFieldStorageDefinition(): object { return $this; }
    public function isRevisionable(): bool { return $this->storage->isRevisionable(); }
    public function getCardinality(): int { return 1; }
  }

  final class NativeFieldItems {
    public function __construct(private array $values) {}
    public function getValue(): array { return $this->values; }
  }

  final class NativeFieldNode implements Drupal\node\NodeInterface {
    public array $translations = [];
    public function __construct(private array $definitions, private array $values, private string $language = 'en') {}
    public function hasField(string $name): bool { return isset($this->definitions[$name]); }
    public function getFieldDefinition(string $name): object { return $this->definitions[$name]; }
    public function getFieldDefinitions(): array { return $this->definitions; }
    public function isDefaultTranslation(): bool { return $this->language === 'en'; }
    public function getTranslationLanguages(): array { return array_fill_keys(array_keys($this->translations), NULL); }
    public function getTranslation(string $language): self { return $this->translations[$language]; }
    public function get(string $name): NativeFieldItems { return new NativeFieldItems($this->values[$name]); }
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
    ['configurable translated field on default language', new NativeConfigurableField(new NativeFieldStorage()), 'en', TRUE],
    ['configurable translated field on French', new NativeConfigurableField(new NativeFieldStorage()), 'fr', TRUE],
    ['configurable shared field on default language', new NativeConfigurableField(new NativeFieldStorage(), FALSE), 'en', TRUE],
    ['configurable shared field on French refuses', new NativeConfigurableField(new NativeFieldStorage(), FALSE), 'fr', FALSE],
    ['nonrevisionable storage refuses', new NativeConfigurableField(new NativeFieldStorage(FALSE)), 'en', FALSE],
    ['computed field refuses', new NativeConfigurableField(new NativeFieldStorage(), TRUE, TRUE), 'en', FALSE],
    ['readonly field refuses', new NativeConfigurableField(new NativeFieldStorage(), TRUE, FALSE, TRUE), 'en', FALSE],
    ['base field using itself as storage remains editable', new NativeBaseField(new NativeFieldStorage()), 'en', TRUE],
  ];
  foreach ($cases as [$name, $definition, $language, $allowed]) {
    $node = new NativeFieldNode(['body' => $definition], ['body' => [['value' => 'Stored value']]], $language);
    $assert($name, $editable->invoke($adapter, $node, 'body'), $allowed);
    $passed++;
    echo "PASS $name\n";
  }

  $definitions = [
    'body' => new NativeConfigurableField(new NativeFieldStorage()),
    'field_shared_note' => new NativeConfigurableField(new NativeFieldStorage(), FALSE),
    'unrevisioned' => new NativeConfigurableField(new NativeFieldStorage(FALSE)),
    'computed' => new NativeConfigurableField(new NativeFieldStorage(), TRUE, TRUE),
    'changed' => new NativeBaseField(new NativeFieldStorage()),
  ];
  $en = new NativeFieldNode($definitions, ['body' => [['value' => 'English']], 'field_shared_note' => [['value' => 'Shared']]]);
  $fr = new NativeFieldNode($definitions, ['body' => [['value' => 'French']], 'field_shared_note' => [['value' => 'Shared']]], 'fr');
  $en->translations = $fr->translations = ['en' => $en, 'fr' => $fr];
  $actual = $content->invoke($adapter, $en);
  $expected = ['en' => ['body' => [['value' => 'English']], 'field_shared_note' => [['value' => 'Shared']]], 'fr' => ['body' => [['value' => 'French']], 'field_shared_note' => [['value' => 'Shared']]]];
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
  echo "$passed passed, 0 failed, 0 skipped\n";
}
