<?php

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
  'field.field.node.page.body' => ['field_name' => 'body', 'required' => TRUE, 'settings' => ['display_summary' => TRUE]],
  'field.storage.node.body' => ['type' => 'text_with_summary', 'cardinality' => 1, 'settings' => ['max_length' => 250]],
];
$cases = [];
$changes = [
  'new raw field hidden by stale cache' => static function (array $set): array {
    $set['field.field.node.page.new_field'] = ['field_name' => 'new_field']; return $set;
  },
  'deleted raw field still effective' => static function (array $set): array {
    unset($set['field.field.node.page.body']); return $set;
  },
  'cardinality changed' => static function (array $set): array {
    $set['field.storage.node.body']['cardinality'] = -1; return $set;
  },
  'required constraint changed' => static function (array $set): array {
    $set['field.field.node.page.body']['required'] = FALSE; return $set;
  },
  'nested field setting changed' => static function (array $set): array {
    $set['field.storage.node.body']['settings']['max_length'] = 500; return $set;
  },
  'typed value changed' => static function (array $set): array {
    $set['field.storage.node.body']['cardinality'] = '1'; return $set;
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
    catch (ProtectedDraftRefusal $e) {}
  };
}
$cases['equal complete sets accept reordered map keys'] = static function () use ($raw): void {
  $reordered = array_reverse($raw, TRUE);
  foreach ($reordered as &$definition) { $definition = array_reverse($definition, TRUE); }
  ProtectedDraftConfiguration::assertSameSet($raw, $reordered);
};
$cases['effective stale fallback format cannot inherit use permission'] = static function (): void {
  $raw = ['filter.settings' => ['fallback_format' => 'plain_text']];
  $effective = ['filter.settings' => ['fallback_format' => 'restricted_html']];
  try {
    ProtectedDraftConfiguration::assertSameSet($raw, $effective);
    throw new RuntimeException('A stale fallback format was accepted.');
  }
  catch (ProtectedDraftRefusal $e) {}
};
$cases['list order remains significant'] = static function (): void {
  $a = ['constraint' => ['allowed_values' => ['a', 'b']]];
  $b = ['constraint' => ['allowed_values' => ['b', 'a']]];
  if (ProtectedDraftConfiguration::fingerprint($a) === ProtectedDraftConfiguration::fingerprint($b)) {
    throw new RuntimeException('Ordered constraint values were normalized away.');
  }
};
$failures = 0;
foreach ($cases as $name => $case) {
  try { $case(); echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; fwrite(STDERR, "FAIL: $name: {$e->getMessage()}\n"); }
}
echo count($cases) . " cases; $failures failures; 0 skipped\n";
exit($failures ? 1 : 0);
