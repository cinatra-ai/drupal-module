<?php

/**
 * @file
 * Standalone protected-draft service checks without a Drupal installation.
 */

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch


declare(strict_types=1);

/**
 * Native contract tests. No Drupal site, database or transport is started.
 */

$source = dirname(__DIR__) . '/src/ProtectedDraft/';
$classes = [
  'ProtectedDraftRefusal',
  'ProtectedDraftFieldScope',
  'ProtectedDraftStorageInterface',
  'ProtectedDraftService',
];
foreach ($classes as $class) {
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

/**
 * Provides the native cinatra memory protected draft storage dependency double.
 */
class CinatraMemoryProtectedDraftStorageBase implements ProtectedDraftStorageInterface {

  /**
   * Native fixture snapshot.
   *
   * @var array
   */
  public array $snapshot;
  /**
   * Native fixture stored.
   *
   * @var array
   */
  public array $stored;
  /**
   * Native fixture after.
   *
   * @var array
   */
  public array $after;
  /**
   * Native fixture saves.
   *
   * @var int
   */
  public int $saves = 0;
  /**
   * Native fixture committed.
   *
   * @var bool
   */
  public bool $committed = FALSE;
  /**
   * Native fixture rolled back.
   *
   * @var bool
   */
  public bool $rolledBack = FALSE;
  /**
   * Native fixture locked.
   *
   * @var bool
   */
  public bool $locked = FALSE;
  /**
   * Native fixture save arguments.
   *
   * @var array
   */
  public array $saveArguments = [];
  /**
   * Native fixture original (untranslated) language of the page.
   *
   * @var string|null
   */
  public ?string $original = 'de';
  /**
   * Native fixture language of each default read.
   *
   * @var array
   */
  public array $readLanguages = [];

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
      'fields' => [
        'title' => [
          [
            'value' => 'Live',
          ],
        ],
        'body' => [
          [
            'value' => 'Live body',
            'format' => 'basic_html',
            'summary' => 'Live summary',
          ],
        ],
      ],
      'published_values' => [
        'de' => [
          'title' => [
            [
              'value' => 'Live',
            ],
          ],
          'body' => [
            [
              'value' => 'Live body',
              'format' => 'basic_html',
              'summary' => 'Live summary',
            ],
          ],
        ],
      ],
    ];
    $this->after = $this->snapshot;
    $this->stored = [
      'node_id' => 19, 'uuid' => 'page-19', 'language' => 'de',
      'revision_id' => 41, 'default_revision_id' => 40,
      'is_default_revision' => FALSE, 'is_published' => FALSE,
      'moderation_state' => 'draft',
      'fields' => [
        'title' => [
          [
            'value' => 'Requested',
          ],
        ],
        'body' => [
          [
            'summary' => 'Requested summary',
            'format' => 'basic_html',
            'value' => '<p>Requested</p>',
          ],
        ],
      ],
    ];
  }

  /**
   * Exercises with locked node.
   */
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

  /**
   * Exercises read default.
   */
  public function readDefault(int $nid, string $language, array $fields): array {
    if (!$this->locked) {
      throw new \LogicException('Read outside transaction');
    }
    $this->readLanguages[] = $language;
    return $this->saves ? $this->after : $this->snapshot;
  }

  /**
   * Exercises original language.
   */
  public function originalLanguage(int $nid): ?string {
    if (!$this->locked) {
      throw new \LogicException('Original language read outside transaction');
    }
    return $this->original;
  }

  /**
   * Exercises save draft.
   */
  public function saveDraft(int $nid, string $language, string $state, array $updates): int {
    if (!$this->locked) {
      throw new \LogicException('Save outside transaction');
    }
    $this->saves++;
    $this->saveArguments = [$nid, $language, $state, $updates];
    return 41;
  }

  /**
   * Exercises save unpublished.
   */
  public function saveUnpublished(int $nid, string $language, array $updates, bool $publish, ?string $state): int {
    if (!$this->locked) {
      throw new \LogicException('Unpublished save outside transaction');
    }
    $this->saves++;
    $this->saveArguments = [$nid, $language, $updates, $publish, $state];
    return 41;
  }

  /**
   * Exercises read revision.
   */
  public function readRevision(int $nid, int $revision, string $language, array $fields): array {
    if (!$this->locked) {
      throw new \LogicException('Revision read outside transaction');
    }
    return $this->stored;
  }

}

/**
 * Provides the default memory storage double.
 */
final class CinatraMemoryProtectedDraftStorage extends CinatraMemoryProtectedDraftStorageBase {
}

/**
 * Builds a complete request from the protected preimage and proposed updates.
 */
function request(): array {
  return [
    'nid' => 19, 'language' => 'de', 'draft_state' => 'draft',
    'expected_default_revision_id' => 40, 'expected_latest_revision_id' => 40,
    'workflow_fingerprint' => str_repeat('a', 64),
    'preimage_fingerprint' => str_repeat('c', 64),
    'updates' => [
      'title' => [
        [
          'value' => 'Requested',
        ],
      ],
      'body' => [
        [
          'value' => '<p>Requested</p>',
          'format' => 'basic_html',
          'summary' => 'Requested summary',
        ],
      ],
    ],
  ];
}

/**
 * Exercises require true.
 */
function require_true(bool $condition, string $message): void {
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
    $store = new CinatraMemoryProtectedDraftStorage();
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
      require_true($store->saves === 0, 'Refusal happened after a save');
      require_true(!$store->committed && $store->rolledBack, 'Refusal committed');
    }
  };
}
foreach ([
  'language' => '',
  'workflow_fingerprint' => 'a',
  'updates' => [],
  'expected_latest_revision_id' => 0,
] as $key => $value) {
  $cases['invalid request: ' . $key] = static function () use ($key, $value): void {
    $store = new CinatraMemoryProtectedDraftStorage();
    $input = request();
    $input[$key] = $value;
    try {
      (new ProtectedDraftService($store))->write($input);
      throw new \RuntimeException('Malformed request accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      require_true($store->saves === 0, 'Malformed request saved');
    }
  };
}
$cases['returns the exact stored structured field values'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $result = (new ProtectedDraftService($store))->write(request());
  require_true($result['fields'] === $store->stored['fields'], 'Returned request or flattened values');
  require_true($result['fields']['body'][0]['summary'] === 'Requested summary', 'Lost summary');
  require_true($store->saveArguments === [19, 'de', 'draft', request()['updates']], 'Changed save identity');
  require_true($store->saves === 1 && $store->committed, 'Draft did not commit once');
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
    $store = new CinatraMemoryProtectedDraftStorage();
    $store->stored[$key] = $value;
    try {
      (new ProtectedDraftService($store))->write(request());
      throw new \RuntimeException('Corrupt stored revision accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      require_true($store->saves === 1 && $store->rolledBack && !$store->committed, 'Stored mismatch did not roll back');
    }
  };
}
$cases['rolls back a changed published value'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->after['published_values']['de']['title'][0]['value'] = 'Went live';
  try {
    (new ProtectedDraftService($store))->write(request());
    throw new \RuntimeException('Published change accepted');
  }
  catch (ProtectedDraftRefusal $e) {
    require_true($store->rolledBack && !$store->committed, 'Live change did not roll back');
  }
};
$cases['rolls back a moved default revision'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->after['default_revision_id'] = 41;
  try {
    (new ProtectedDraftService($store))->write(request());
    throw new \RuntimeException('Default moved');
  }
  catch (ProtectedDraftRefusal $e) {
    require_true($store->rolledBack && !$store->committed, 'Default move did not roll back');
  }
};
$cases['read-back remains exact and language bound'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $result = (new ProtectedDraftService($store))->read(19, 41, 'de', ['title', 'body']);
  require_true($result === $store->stored, 'Reader substituted another representation');
  require_true($store->saves === 0, 'Reader saved');
};
$cases['preimage exposes only requested readable fields and no internal snapshot'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->snapshot['published_values']['en']['secret'] = [['value' => 'Must not be exported']];
  $result = (new ProtectedDraftService($store))->prepare(19, 'de', ['title']);
  require_true(array_keys($result['fields']) === ['title'], 'Exported unrequested field');
  require_true(!isset($result['published_values']), 'Exported internal snapshot');
};
$cases['preimage reports the node translation languages'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->snapshot['translation_languages'] = ['de', 'fr'];
  $result = (new ProtectedDraftService($store))->prepare(19, 'de', ['title']);
  require_true(($result['translation_languages'] ?? NULL) === ['de', 'fr'], 'Dropped the translation languages');
};
$cases['preimage with an omitted language reads the original translation and reports it'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $result = (new ProtectedDraftService($store))->prepare(19, NULL, ['title']);
  require_true($store->readLanguages === ['de'], 'Did not read the original translation');
  require_true(($result['language'] ?? NULL) === 'de', 'Did not report the resolved language');
  require_true(array_keys($result['fields']) === ['title'], 'Exported unrequested field');
};
$cases['preimage with an omitted language refuses a page without an original language'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->original = NULL;
  try {
    (new ProtectedDraftService($store))->prepare(19, NULL, ['title']);
    throw new \RuntimeException('Read without a resolved language');
  }
  catch (ProtectedDraftRefusal $e) {
    require_true($store->readLanguages === [], 'Read a preimage without a language');
  }
};
$cases['preimage with an omitted language keeps the exact identity check'] = static function (): void {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->snapshot['language'] = 'en';
  try {
    (new ProtectedDraftService($store))->prepare(19, NULL, ['title']);
    throw new \RuntimeException('Accepted a foreign translation');
  }
  catch (ProtectedDraftRefusal $e) {
    require_true($store->readLanguages === ['de'], 'Did not bind the resolved language');
  }
};

/**
 * Turns the fixture into an unpublished, unmoderated page and its result.
 */
function unpublished_store(bool $publish = FALSE): CinatraMemoryProtectedDraftStorage {
  $store = new CinatraMemoryProtectedDraftStorage();
  $store->snapshot = array_merge($store->snapshot, [
    'is_published' => FALSE,
    'published_languages' => [],
    'moderated' => FALSE,
    'moderation_state' => NULL,
    'changed' => 1000,
    'can_publish' => TRUE,
    'can_edit_moderation_state' => TRUE,
  ]);
  $store->after = array_merge($store->snapshot, [
    'default_revision_id' => 41,
    'latest_revision_id' => 41,
    'is_published' => $publish,
    'changed' => 2000,
    'fields' => [
      'title' => [['value' => 'Requested']],
      'body' => [['summary' => 'Requested summary', 'format' => 'basic_html', 'value' => '<p>Requested</p>']],
    ],
  ]);
  return $store;
}

/**
 * Builds a complete unpublished write request from the read binding.
 */
function unpublished_request(): array {
  $request = request();
  unset($request['draft_state']);
  $request['expected_changed'] = 1000;
  return $request;
}

/**
 * Builds a complete publish request from the read binding.
 */
function publish_request(): array {
  $request = unpublished_request();
  unset($request['updates']);
  return $request;
}

/**
 * Requires a refusal that wrote nothing and committed nothing.
 */
function require_refused_unsaved(CinatraMemoryProtectedDraftStorage $store, callable $operation, string $reason): void {
  try {
    $operation();
  }
  catch (ProtectedDraftRefusal $e) {
    require_true(str_contains($e->getMessage(), $reason), 'Another refusal than "' . $reason . '": ' . $e->getMessage());
    require_true($store->saves === 0, 'Refusal happened after a save');
    require_true(!$store->committed, 'Refusal committed');
    require_true(!str_contains($e->getMessage(), 'Requested') && !str_contains($e->getMessage(), 'Live'), 'Refusal echoed content');
    return;
  }
  throw new \RuntimeException('Unsafe unpublished write accepted');
}

$cases['unpublished write saves once and returns stored values, revision and changed time'] = static function (): void {
  $store = unpublished_store();
  $result = (new ProtectedDraftService($store))->writeUnpublished(unpublished_request());
  require_true($store->saveArguments === [19, 'de', request()['updates'], FALSE, NULL], 'Changed save identity');
  require_true($store->saves === 1 && $store->committed, 'Write did not commit once');
  require_true($result['revision_id'] === 41 && $result['changed'] === 2000, 'Missing new revision or changed time');
  require_true($result['fields'] === $store->after['fields'], 'Returned request instead of stored values');
  require_true($result['is_published'] === FALSE && $result['is_default_revision'] === TRUE, 'Wrong stored status');
  require_true(!isset($result['published_values']) && !isset($result['published_languages']), 'Exported internal snapshot');
};
$cases['unpublished write with publish publishes in the same save'] = static function (): void {
  $store = unpublished_store(TRUE);
  $request = unpublished_request();
  $request['publish'] = TRUE;
  $result = (new ProtectedDraftService($store))->writeUnpublished($request);
  require_true($store->saves === 1 && $store->saveArguments[3] === TRUE, 'Publish was not part of the one save');
  require_true($result['is_published'] === TRUE, 'Not published');
};
$cases['publish step publishes as it is, with no field value'] = static function (): void {
  $store = unpublished_store(TRUE);
  $store->after['fields'] = [];
  $result = (new ProtectedDraftService($store))->publishUnpublished(publish_request());
  require_true($store->saveArguments === [19, 'de', [], TRUE, NULL], 'Publish changed field values');
  require_true($result['is_published'] === TRUE && $result['fields'] === [], 'Publish result is wrong');
};
$cases['moderated unpublished write keeps its draft state through an allowed transition'] = static function (): void {
  $store = unpublished_store();
  $store->snapshot['moderated'] = TRUE;
  $store->snapshot['moderation_state'] = 'draft';
  $store->after['moderated'] = TRUE;
  $store->after['moderation_state'] = 'draft';
  (new ProtectedDraftService($store))->writeUnpublished(unpublished_request());
  require_true($store->saveArguments[4] === 'draft', 'Did not keep the draft state');
};
$cases['moderated publish uses the one allowed published default state'] = static function (): void {
  $store = unpublished_store(TRUE);
  $states = [
    'draft' => ['published' => FALSE, 'default_revision' => FALSE],
    'published' => ['published' => TRUE, 'default_revision' => TRUE],
  ];
  foreach (['snapshot', 'after'] as $key) {
    $store->{$key}['moderated'] = TRUE;
    $store->{$key}['draft_states'] = $states;
    $store->{$key}['allowed_draft_states'] = ['draft', 'published'];
  }
  $store->snapshot['moderation_state'] = 'draft';
  // The transition permission, not the status field, publishes it.
  $store->snapshot['can_publish'] = FALSE;
  $store->after['moderation_state'] = 'published';
  $store->after['fields'] = [];
  (new ProtectedDraftService($store))->publishUnpublished(publish_request());
  require_true($store->saveArguments[4] === 'published', 'Did not publish through the workflow');
};
$unpublished_refusals = [
  'published page' => [['is_published' => TRUE], 'This page is published'],
  'another published translation' => [['published_languages' => ['fr']], 'This page is published'],
  'pending revision' => [['latest_revision_id' => 42], 'A newer revision'],
  'default revision changed' => [['default_revision_id' => 39, 'latest_revision_id' => 39], 'The page revision changed'],
  'workflow changed' => [['workflow_fingerprint' => str_repeat('b', 64)], 'The workflow configuration changed'],
  'values changed' => [['preimage_fingerprint' => str_repeat('d', 64)], 'The page values changed'],
  'changed time moved' => [['changed' => 1001], 'The page was saved after it was read'],
  'edit denied' => [['can_update' => FALSE], 'Editing this page is not permitted'],
  'field denied' => [['field_access' => ['title' => TRUE, 'body' => FALSE]], 'A requested field cannot be edited'],
  'translation not bound' => [['language' => 'en'], 'not readable in this language'],
  'read denied' => [['can_read' => FALSE], 'not readable in this language'],
  'moderated without a transition back to its state' => [
    [
      'moderated' => TRUE,
      'moderation_state' => 'draft',
      'allowed_draft_states' => [],
    ],
    'does not allow this change',
  ],
  'moderated without edit access to its workflow state' => [
    [
      'moderated' => TRUE,
      'moderation_state' => 'draft',
      'can_edit_moderation_state' => FALSE,
    ],
    'Changing the workflow state of this page is not permitted',
  ],
  'moderated with an ambiguous field' => [
    [
      'moderated' => TRUE,
      'canonical_moderation_field' => FALSE,
    ],
    'moderation field is ambiguous',
  ],
];
foreach ($unpublished_refusals as $name => [$changes, $reason]) {
  $cases['unpublished write refuses before save: ' . $name] = static function () use ($changes, $reason): void {
    $store = unpublished_store();
    $store->snapshot = array_merge($store->snapshot, $changes);
    require_refused_unsaved($store, static fn() => (new ProtectedDraftService($store))->writeUnpublished(unpublished_request()), $reason);
  };
  if ($name === 'field denied') {
    // The publish step writes no field.
    continue;
  }
  $cases['publish step refuses before save: ' . $name] = static function () use ($changes, $reason): void {
    $store = unpublished_store(TRUE);
    $store->snapshot = array_merge($store->snapshot, $changes);
    if (($changes['moderated'] ?? FALSE) === TRUE && $reason === 'does not allow this change') {
      $reason = 'does not allow publishing it';
    }
    require_refused_unsaved($store, static fn() => (new ProtectedDraftService($store))->publishUnpublished(publish_request()), $reason);
  };
}
$cases['publish refuses without the publish permission'] = static function (): void {
  $store = unpublished_store(TRUE);
  $store->snapshot['can_publish'] = FALSE;
  require_refused_unsaved($store, static fn() => (new ProtectedDraftService($store))->publishUnpublished(publish_request()), 'Publishing this page is not permitted');
  $request = unpublished_request();
  $request['publish'] = TRUE;
  require_refused_unsaved($store, static fn() => (new ProtectedDraftService($store))->writeUnpublished($request), 'Publishing this page is not permitted');
};
// The one stored-as-reviewed comparison is the storage's, of Drupal's own
// normalised value. A clear asks for empty, Drupal stores empty: the service
// must not compare the raw request again.
foreach (['an empty list' => [], 'an item with an empty value' => [['value' => '']]] as $form => $clear) {
  $cases['unpublished write accepts a clear sent as ' . $form] = static function () use ($clear): void {
    $store = unpublished_store();
    $request = unpublished_request();
    $request['updates'] = ['title' => [['value' => 'Requested']], 'body' => $clear];
    $store->after['fields'] = ['title' => [['value' => 'Requested']], 'body' => []];
    $result = (new ProtectedDraftService($store))->writeUnpublished($request);
    require_true($store->committed && $result['fields']['body'] === [], 'A normalised clear was refused');
    require_true($store->saveArguments[2] === $request['updates'], 'The clear was not passed to the storage as sent');
  };
  $cases['protected draft accepts a clear sent as ' . $form] = static function () use ($clear): void {
    $store = new CinatraMemoryProtectedDraftStorage();
    $request = request();
    $request['updates']['body'] = $clear;
    $store->stored['fields']['body'] = [];
    $result = (new ProtectedDraftService($store))->write($request);
    require_true($store->committed && $result['fields']['body'] === [], 'A normalised clear was refused');
  };
}
$cases['a storage refusal of a replaced value rolls back with nothing committed'] = static function (): void {
  $store = new class() extends CinatraMemoryProtectedDraftStorageBase {

    /**
     * Refuses like the storage comparison after a save hook replaced a value.
     */
    public function saveUnpublished(int $nid, string $language, array $updates, bool $publish, ?string $state): int {
      parent::saveUnpublished($nid, $language, $updates, $publish, $state);
      throw new ProtectedDraftRefusal('The stored value differs from the reviewed value; nothing was written.');
    }

  };
  $fixture = unpublished_store(TRUE);
  $store->snapshot = $fixture->snapshot;
  $store->after = $fixture->after;
  $request = unpublished_request();
  $request['publish'] = TRUE;
  try {
    (new ProtectedDraftService($store))->writeUnpublished($request);
    throw new \RuntimeException('A refused replacement was committed');
  }
  catch (ProtectedDraftRefusal $e) {
    require_true(str_contains($e->getMessage(), 'stored value differs'), 'Another refusal: ' . $e->getMessage());
    require_true($store->rolledBack && !$store->committed, 'A replaced value committed');
  }
};
$cases['write without publish needs no publish permission'] = static function (): void {
  $store = unpublished_store();
  $store->snapshot['can_publish'] = FALSE;
  (new ProtectedDraftService($store))->writeUnpublished(unpublished_request());
  require_true($store->saves === 1, 'Write was refused for a missing publish permission');
};
foreach ([
  'no changed time' => static fn(array $r): array => array_diff_key($r, ['expected_changed' => TRUE]),
  'string changed time' => static fn(array $r): array => ['expected_changed' => '1000'] + $r,
  'draft state key' => static fn(array $r): array => $r + ['draft_state' => 'draft'],
  'list of updates' => static fn(array $r): array => ['updates' => [[['value' => 'x']]]] + $r,
  'empty updates' => static fn(array $r): array => ['updates' => []] + $r,
  'string publish' => static fn(array $r): array => $r + ['publish' => 'yes'],
  'unknown field name' => static fn(array $r): array => ['updates' => ['Not A Field' => [['value' => 'x']]]] + $r,
  'zero revision' => static fn(array $r): array => ['expected_default_revision_id' => 0] + $r,
] as $name => $change) {
  $cases['malformed unpublished write: ' . $name] = static function () use ($change): void {
    $store = unpublished_store();
    try {
      (new ProtectedDraftService($store))->writeUnpublished($change(unpublished_request()));
      throw new \RuntimeException('Malformed request accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      require_true($store->saves === 0 && !$store->locked, 'Malformed request saved');
    }
  };
}
$cases['malformed publish: updates or publish keys are refused'] = static function (): void {
  foreach (['updates' => request()['updates'], 'publish' => TRUE] as $key => $value) {
    $store = unpublished_store(TRUE);
    try {
      (new ProtectedDraftService($store))->publishUnpublished(publish_request() + [$key => $value]);
      throw new \RuntimeException('Publish accepted ' . $key);
    }
    catch (ProtectedDraftRefusal $e) {
      require_true($store->saves === 0, 'Malformed publish saved');
    }
  }
};
$unpublished_corruptions = [
  'default did not move' => ['default_revision_id', 40],
  'publish status differs' => ['is_published', TRUE],
  'workflow changed in the save' => ['workflow_fingerprint', str_repeat('e', 64)],
  'missing stored field' => ['fields', ['title' => [['value' => 'Only title']]]],
  'no changed time' => ['changed', NULL],
  'foreign UUID' => ['uuid', 'foreign'],
];
foreach ($unpublished_corruptions as $name => [$key, $value]) {
  $cases['unpublished write rolls back stored mismatch: ' . $name] = static function () use ($key, $value): void {
    $store = unpublished_store(TRUE);
    $store->after[$key] = $value;
    $store->after['is_published'] = $key === 'is_published' ? FALSE : TRUE;
    $request = unpublished_request();
    // With publish, a refused stored value must never be committed as live.
    $request['publish'] = TRUE;
    try {
      (new ProtectedDraftService($store))->writeUnpublished($request);
      throw new \RuntimeException('Corrupt stored revision accepted');
    }
    catch (ProtectedDraftRefusal $e) {
      require_true($store->saves === 1 && $store->rolledBack && !$store->committed, 'Stored mismatch did not roll back');
    }
  };
}
$cases['preimage reports the changed time'] = static function (): void {
  $store = unpublished_store();
  $result = (new ProtectedDraftService($store))->prepare(19, 'de', ['title']);
  require_true(($result['changed'] ?? NULL) === 1000, 'Did not report the changed time');
  require_true(!isset($result['published_languages']) && !isset($result['can_publish']) && !isset($result['moderation_state']), 'Exported internal read keys');
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
