<?php

declare(strict_types=1);

/**
 * Native contract tests. No Drupal site, database or transport is started.
 */

$source = dirname(__DIR__) . '/src/ProtectedDraft/';
foreach (['ProtectedDraftRefusal', 'ProtectedDraftStorageInterface', 'ProtectedDraftService'] as $class) {
  $file = $source . $class . '.php';
  if (!is_file($file)) {
    fwrite(STDERR, "RED: protected draft operation is absent: $class\n");
    exit(1);
  }
  require_once $file;
}

use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftService;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftStorageInterface;

final class MemoryProtectedDraftStorage implements ProtectedDraftStorageInterface {

  public array $snapshot;
  public array $stored;
  public array $after;
  public int $saves = 0;
  public bool $committed = FALSE;
  public bool $rolledBack = FALSE;
  public bool $locked = FALSE;
  public array $saveArguments = [];

  public function __construct() {
    $this->snapshot = [
      'node_id' => 19, 'uuid' => 'page-19', 'language' => 'de',
      'default_revision_id' => 40, 'latest_revision_id' => 40,
      'is_default_revision' => TRUE, 'is_published' => TRUE,
      'moderated' => TRUE, 'canonical_moderation_field' => TRUE,
      'workflow_fingerprint' => str_repeat('a', 64),
      'preimage_fingerprint' => str_repeat('c', 64),
      'can_read' => TRUE, 'can_update' => TRUE,
      'draft_states' => ['draft' => ['published' => FALSE, 'default_revision' => FALSE]],
      'allowed_draft_states' => ['draft'],
      'field_access' => ['title' => TRUE, 'body' => TRUE],
      'fields' => ['title' => [['value' => 'Live']], 'body' => [['value' => 'Live body', 'format' => 'basic_html', 'summary' => 'Live summary']]],
      'published_values' => ['de' => ['title' => [['value' => 'Live']], 'body' => [['value' => 'Live body', 'format' => 'basic_html', 'summary' => 'Live summary']]]],
    ];
    $this->after = $this->snapshot;
    $this->stored = [
      'node_id' => 19, 'uuid' => 'page-19', 'language' => 'de',
      'revision_id' => 41, 'default_revision_id' => 40,
      'is_default_revision' => FALSE, 'is_published' => FALSE,
      'moderation_state' => 'draft',
      'fields' => ['title' => [['value' => 'Stored draft']], 'body' => [['value' => '<p>Stored</p>', 'format' => 'basic_html', 'summary' => 'Stored summary']]],
    ];
  }

  public function withLockedNode(int $nid, callable $operation): array {
    $this->locked = TRUE;
    try {
      $result = $operation();
      $this->committed = TRUE;
      return $result;
    }
    catch (\Throwable $e) {
      $this->rolledBack = TRUE;
      throw $e;
    }
    finally {
      $this->locked = FALSE;
    }
  }

  public function readDefault(int $nid, string $language, array $fields): array {
    if (!$this->locked) {
      throw new \LogicException('Read outside transaction');
    }
    return $this->saves ? $this->after : $this->snapshot;
  }

  public function saveDraft(int $nid, string $language, string $state, array $updates): int {
    if (!$this->locked) {
      throw new \LogicException('Save outside transaction');
    }
    $this->saves++;
    $this->saveArguments = [$nid, $language, $state, $updates];
    return 41;
  }

  public function readRevision(int $nid, int $revision, string $language, array $fields): array {
    if (!$this->locked) {
      throw new \LogicException('Revision read outside transaction');
    }
    return $this->stored;
  }

}

function request(): array {
  return [
    'nid' => 19, 'language' => 'de', 'draft_state' => 'draft',
    'expected_default_revision_id' => 40, 'expected_latest_revision_id' => 40,
    'workflow_fingerprint' => str_repeat('a', 64),
    'preimage_fingerprint' => str_repeat('c', 64),
    'updates' => ['title' => [['value' => 'Requested']], 'body' => [['value' => '<p>Requested</p>', 'format' => 'basic_html', 'summary' => 'Requested summary']]],
  ];
}

function requireTrue(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$cases = [];
$refusals = [
  'custom moderation alias' => ['canonical_moderation_field', FALSE],
  'missing draft state' => ['draft_states', []],
  'default-like draft state' => ['draft_states', ['draft' => ['published' => FALSE, 'default_revision' => TRUE]]],
  'published draft state' => ['draft_states', ['draft' => ['published' => TRUE, 'default_revision' => FALSE]]],
  'pending draft' => ['latest_revision_id', 41],
  'unmoderated node' => ['moderated', FALSE],
  'denied preimage read' => ['can_read', FALSE],
  'denied edit' => ['can_update', FALSE],
  'translation not bound' => ['language', 'en'],
  'workflow changed' => ['workflow_fingerprint', str_repeat('b', 64)],
  'in-place preimage changed' => ['preimage_fingerprint', str_repeat('d', 64)],
  'default revision changed' => ['default_revision_id', 42],
  'not a published preimage' => ['is_published', FALSE],
  'not a default preimage' => ['is_default_revision', FALSE],
  'wrong node preimage' => ['node_id', 20],
  'transition denied' => ['allowed_draft_states', []],
  'field denied' => ['field_access', ['title' => TRUE, 'body' => FALSE]],
];
foreach ($refusals as $name => [$key, $value]) {
  $cases['refuses before save: ' . $name] = static function () use ($key, $value): void {
    $store = new MemoryProtectedDraftStorage();
    $store->snapshot[$key] = $value;
    $input = request();
    if ($key === 'latest_revision_id') {
      // Isolate the pending guard even when the client saw that latest ID.
      $input['expected_latest_revision_id'] = $value;
    }
    elseif ($key === 'default_revision_id') {
      $store->snapshot['latest_revision_id'] = $value;
    }
    try {
      (new ProtectedDraftService($store))->write($input);
      throw new \RuntimeException('Unsafe write accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      requireTrue($store->saves === 0, 'Refusal happened after a save');
      requireTrue(!$store->committed && $store->rolledBack, 'Refusal committed');
    }
  };
}
foreach (['language' => '', 'workflow_fingerprint' => 'a', 'updates' => [], 'expected_latest_revision_id' => 0] as $key => $value) {
  $cases['invalid request: ' . $key] = static function () use ($key, $value): void {
    $store = new MemoryProtectedDraftStorage();
    $input = request();
    $input[$key] = $value;
    try {
      (new ProtectedDraftService($store))->write($input);
      throw new \RuntimeException('Malformed request accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      requireTrue($store->saves === 0, 'Malformed request saved');
    }
  };
}
$cases['returns exact stored structured field values, not requested values'] = static function (): void {
  $store = new MemoryProtectedDraftStorage();
  $result = (new ProtectedDraftService($store))->write(request());
  requireTrue($result['fields'] === $store->stored['fields'], 'Returned request or flattened values');
  requireTrue($result['fields']['body'][0]['summary'] === 'Stored summary', 'Lost summary');
  requireTrue($store->saveArguments === [19, 'de', 'draft', request()['updates']], 'Changed save identity');
  requireTrue($store->saves === 1 && $store->committed, 'Draft did not commit once');
};
$corruptions = [
  'foreign stored node' => ['node_id', 20],
  'foreign stored UUID' => ['uuid', 'foreign'],
  'wrong stored language' => ['language', 'en'],
  'wrong revision ID' => ['revision_id', 99],
  'default revision was saved' => ['is_default_revision', TRUE],
  'published revision was saved' => ['is_published', TRUE],
  'wrong stored moderation state' => ['moderation_state', 'published'],
  'missing actual stored field' => ['fields', ['title' => [['value' => 'Only title']]]],
];
foreach ($corruptions as $name => [$key, $value]) {
  $cases['rolls back stored mismatch: ' . $name] = static function () use ($key, $value): void {
    $store = new MemoryProtectedDraftStorage();
    $store->stored[$key] = $value;
    try {
      (new ProtectedDraftService($store))->write(request());
      throw new \RuntimeException('Corrupt stored revision accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      requireTrue($store->saves === 1 && $store->rolledBack && !$store->committed, 'Stored mismatch did not roll back');
    }
  };
}
$cases['rolls back a changed published value'] = static function (): void {
  $store = new MemoryProtectedDraftStorage();
  $store->after['published_values']['de']['title'][0]['value'] = 'Went live';
  try {
    (new ProtectedDraftService($store))->write(request());
    throw new \RuntimeException('Published change accepted');
  }
  catch (ProtectedDraftRefusal $e) {
    requireTrue($store->rolledBack && !$store->committed, 'Live change did not roll back');
  }
};
$cases['rolls back a moved default revision'] = static function (): void {
  $store = new MemoryProtectedDraftStorage();
  $store->after['default_revision_id'] = 41;
  try {
    (new ProtectedDraftService($store))->write(request());
    throw new \RuntimeException('Default moved');
  }
  catch (ProtectedDraftRefusal $e) {
    requireTrue($store->rolledBack && !$store->committed, 'Default move did not roll back');
  }
};
$cases['read-back remains exact and language bound'] = static function (): void {
  $store = new MemoryProtectedDraftStorage();
  $result = (new ProtectedDraftService($store))->read(19, 41, 'de', ['title', 'body']);
  requireTrue($result === $store->stored, 'Reader substituted another representation');
  requireTrue($store->saves === 0, 'Reader saved');
};
$cases['preimage exposes only requested readable fields and no internal snapshot'] = static function (): void {
  $store = new MemoryProtectedDraftStorage();
  $store->snapshot['published_values']['en']['secret'] = [['value' => 'Must not be exported']];
  $result = (new ProtectedDraftService($store))->prepare(19, 'de', ['title']);
  requireTrue(array_keys($result['fields']) === ['title'], 'Exported unrequested field');
  requireTrue(!isset($result['published_values']), 'Exported internal snapshot');
};

$failed = 0;
foreach ($cases as $name => $test) {
  try {
    $test();
    echo "PASS $name\n";
  }
  catch (\Throwable $e) {
    $failed++;
    fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n");
  }
}
echo count($cases) . ' cases; ' . $failed . " failures; 0 skips (memory port, not SQL isolation proof)\n";
exit($failed ? 1 : 0);
