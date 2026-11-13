<?php

/**
 * @file
 * Standalone protected-draft checks without a Drupal installation.
 */

declare(strict_types=1);

/**
 * Complete configuration binding cases; no Drupal or database is started.
 */

$source = dirname(__DIR__) . '/src/ProtectedDraft/';
require_once $source . 'ProtectedDraftRefusal.php';
require_once $source . 'ProtectedDraftConfiguration.php';

use Drupal\cinatra\ProtectedDraft\ProtectedDraftConfiguration;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;

$raw = [
  'field.field.node.page.body' => [
    'field_name' => 'body',
    'required' => TRUE,
    'settings' => [
      'display_summary' => TRUE,
    ],
  ],
  'field.storage.node.body' => ['type' => 'text_with_summary', 'cardinality' => 1, 'settings' => ['max_length' => 250]],
];
$cases = [];
$changes = [
  'new raw field hidden by stale cache' => static function (array $set): array {
    $set['field.field.node.page.new_field'] = ['field_name' => 'new_field'];
    return $set;
  },
  'deleted raw field still effective' => static function (array $set): array {
    unset($set['field.field.node.page.body']);
    return $set;
  },
  'cardinality changed' => static function (array $set): array {
    $set['field.storage.node.body']['cardinality'] = -1;
    return $set;
  },
  'required constraint changed' => static function (array $set): array {
    $set['field.field.node.page.body']['required'] = FALSE;
    return $set;
  },
  'nested field setting changed' => static function (array $set): array {
    $set['field.storage.node.body']['settings']['max_length'] = 500;
    return $set;
  },
  'typed value changed' => static function (array $set): array {
    $set['field.storage.node.body']['cardinality'] = '1';
    return $set;
  },
];
foreach ($changes as $name => $change) {
  $cases[$name] = static function () use ($raw, $change): void {
    $changed = $change($raw);
    if (ProtectedDraftConfiguration::fingerprint($raw) === ProtectedDraftConfiguration::fingerprint($changed)) {
      throw new RuntimeException('Configuration change was not bound.');
    }
    try {
      ProtectedDraftConfiguration::assertSameSet($raw, $changed);
      throw new RuntimeException('Stale or different effective configuration accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
    }
  };
}
$cases['equal complete sets accept reordered map keys'] = static function () use ($raw): void {
  $reordered = array_reverse($raw, TRUE);
  foreach ($reordered as &$definition) {
    $definition = array_reverse($definition, TRUE);
  }
  ProtectedDraftConfiguration::assertSameSet($raw, $reordered);
};
$cases['effective stale fallback format cannot inherit use permission'] = static function (): void {
  $raw = ['filter.settings' => ['fallback_format' => 'plain_text']];
  $effective = ['filter.settings' => ['fallback_format' => 'restricted_html']];
  try {
    ProtectedDraftConfiguration::assertSameSet($raw, $effective);
    throw new RuntimeException('A stale fallback format was accepted.');
  }
  catch (ProtectedDraftRefusal $e) {
  }
};
$cases['list order remains significant'] = static function (): void {
  $a = ['constraint' => ['allowed_values' => ['a', 'b']]];
  $b = ['constraint' => ['allowed_values' => ['b', 'a']]];
  if (ProtectedDraftConfiguration::fingerprint($a) === ProtectedDraftConfiguration::fingerprint($b)) {
    throw new RuntimeException('Ordered constraint values were normalized away.');
  }
};
// Drupal stores a field storage's last-installed snapshot before
// ConfigEntityBase::preSave() calculates dependencies, so a later request
// reads that snapshot without them. Only schema-relevant properties may bind
// the installed snapshot to the raw configuration.
$raw_storage = [
  'uuid' => '6a1b4c2e-3f5d-4e7a-9b8c-0d1e2f3a4b5c',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['module' => ['node', 'text']],
  '_core' => ['default_config_hash' => 'abc'],
  'id' => 'node.body',
  'field_name' => 'body',
  'entity_type' => 'node',
  'type' => 'text_with_summary',
  'settings' => [],
  'module' => 'text',
  'locked' => FALSE,
  'cardinality' => 1,
  'translatable' => TRUE,
  'indexes' => [],
  'persist_with_no_fields' => TRUE,
  'custom_storage' => FALSE,
];
$raw_storages = ['field.storage.node.body' => $raw_storage];
$installed_storage = $raw_storage;
$installed_storage['dependencies'] = [];
unset($installed_storage['_core']);
$cases['installed snapshot without config dependencies is accepted'] = static function () use ($raw_storages, $installed_storage): void {
  ProtectedDraftConfiguration::assertSameInstalledStorageSet($raw_storages, ['field.storage.node.body' => $installed_storage]);
};
$cases['installed snapshot with other uuid, langcode and status is accepted'] = static function () use ($raw_storages, $installed_storage): void {
  $installed_storage['uuid'] = '00000000-0000-4000-8000-000000000000';
  $installed_storage['langcode'] = 'fr';
  $installed_storage['status'] = FALSE;
  ProtectedDraftConfiguration::assertSameInstalledStorageSet($raw_storages, ['field.storage.node.body' => $installed_storage]);
};
$schema_changes = [
  'installed type differs' => static fn(array $d): array => ['type' => 'text_long'] + $d,
  'installed cardinality differs' => static fn(array $d): array => ['cardinality' => -1] + $d,
  'installed storage setting differs' => static fn(array $d): array => ['settings' => ['max_length' => 64]] + $d,
  'installed indexes differ' => static fn(array $d): array => ['indexes' => ['format' => ['format']]] + $d,
  'installed translatable differs' => static fn(array $d): array => ['translatable' => FALSE] + $d,
  'installed custom storage differs' => static fn(array $d): array => ['custom_storage' => TRUE] + $d,
  'installed persistence differs' => static fn(array $d): array => ['persist_with_no_fields' => FALSE] + $d,
  'installed provider differs' => static fn(array $d): array => ['module' => 'other'] + $d,
  'installed lock differs' => static fn(array $d): array => ['locked' => TRUE] + $d,
  'installed entity type differs' => static fn(array $d): array => ['entity_type' => 'user'] + $d,
  'installed unknown property is still bound' => static fn(array $d): array => $d + ['future_schema_key' => 'x'],
  'installed type is missing' => static function (array $d): array {
    unset($d['type']);
    return $d;
  },
];
foreach ($schema_changes as $name => $change) {
  $cases[$name . ' refuses'] = static function () use ($raw_storages, $installed_storage, $change): void {
    try {
      ProtectedDraftConfiguration::assertSameInstalledStorageSet($raw_storages, ['field.storage.node.body' => $change($installed_storage)]);
      throw new RuntimeException('A schema-relevant installed difference was accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
    }
  };
}
$cases['installed storage on one side only refuses'] = static function () use ($raw_storages, $installed_storage): void {
  foreach ([
    [$raw_storages, []],
    [[], ['field.storage.node.body' => $installed_storage]],
    [$raw_storages, ['field.storage.node.body' => $installed_storage, 'field.storage.node.ghost' => $installed_storage]],
  ] as [$raw, $installed]) {
    try {
      ProtectedDraftConfiguration::assertSameInstalledStorageSet($raw, $installed);
      throw new RuntimeException('An installed storage present on one side only was accepted.');
    }
    catch (ProtectedDraftRefusal $e) {
    }
  }
};
$cases['non-array installed definition refuses'] = static function () use ($raw_storages): void {
  try {
    ProtectedDraftConfiguration::assertSameInstalledStorageSet($raw_storages, ['field.storage.node.body' => 'text_with_summary']);
    throw new RuntimeException('A non-array installed definition was accepted.');
  }
  catch (ProtectedDraftRefusal $e) {
  }
};
$cases['complete set binding still includes dependencies'] = static function () use ($raw_storages, $installed_storage): void {
  try {
    ProtectedDraftConfiguration::assertSameSet($raw_storages, ['field.storage.node.body' => $installed_storage]);
    throw new RuntimeException('The complete binding dropped dependencies.');
  }
  catch (ProtectedDraftRefusal $e) {
  }
};
$failures = 0;
foreach ($cases as $name => $case) {
  try {
    $case();
    echo "PASS: $name\n";
  }
  catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, "FAIL: $name: {$e->getMessage()}\n");
  }
}
echo count($cases) . " cases; $failures failures; 0 skipped\n";
exit($failures ? 1 : 0);
