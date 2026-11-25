<?php

/**
 * @file
 * Standalone protected-draft checks without a Drupal installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ProtectedDraft/ProtectedDraftRefusal.php';
require_once __DIR__ . '/../src/ProtectedDraft/ProtectedDraftFieldScope.php';

use Drupal\cinatra\ProtectedDraft\ProtectedDraftFieldScope;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;

$before = [
  'en' => [
    'title' => [
      [
        'value' => 'English',
      ],
    ],
    'body' => [
      [
        'value' => 'English body',
        'format' => 'plain_text',
      ],
    ],
  ],
  'fr' => [
    'title' => [
      [
        'value' => 'French',
      ],
    ],
    'body' => [
      [
        'value' => 'French body',
        'format' => 'plain_text',
      ],
    ],
  ],
];
$passed = 0;
$test = function (string $name, array $after, bool $allowed) use ($before, &$passed): void {
  try {
    ProtectedDraftFieldScope::assertMatches($before, $after, 'fr', ['title']);
    if (!$allowed) {
      throw new RuntimeException($name . ' incorrectly accepted');
    }
  }
  catch (ProtectedDraftRefusal $error) {
    if ($allowed) {
      throw $error;
    }
  }
  $passed++;
  echo "PASS $name\n";
};
$after = $before;
$after['fr']['title'] = [['value' => 'Stored normalized title']];
$test('requested field stored normalization', $after, TRUE);
$test('unchanged complete content', $before, TRUE);
$changed = $after;
$changed['fr']['body'][0]['value'] = 'Out of scope';
$test('unrequested field rewrite', $changed, FALSE);
$changed = $after;
$changed['fr']['body'][0]['format'] = 'restricted';
$test('unrequested field format rewrite', $changed, FALSE);
$changed = $after;
$changed['en']['title'][0]['value'] = 'Other translation changed';
$test('other translation rewrite', $changed, FALSE);
$changed = $after;
unset($changed['fr']['body']);
$test('unrequested field disappeared', $changed, FALSE);
$changed = $after;
unset($changed['en']);
$test('translation disappeared', $changed, FALSE);
$changed = $after;
$changed['de'] = $before['en'];
$test('translation added', $changed, FALSE);
$shared_before = $before;
$shared_before['en']['field_shared_note'] = [['value' => 'Shared live note']];
$shared_before['fr']['field_shared_note'] = [['value' => 'Shared live note']];
$shared_test = function (string $name, array $after, array $shared_fields, bool $allowed) use ($shared_before, &$passed): void {
  try {
    ProtectedDraftFieldScope::assertMatches($shared_before, $after, 'en', ['field_shared_note'], $shared_fields);
    if (!$allowed) {
      throw new RuntimeException($name . ' incorrectly accepted');
    }
  }
  catch (ProtectedDraftRefusal $error) {
    if ($allowed) {
      throw $error;
    }
  }
  $passed++;
  echo "PASS $name\n";
};
$shared_after = $shared_before;
$shared_after['en']['field_shared_note'] = [['value' => 'Shared draft note']];
$shared_after['fr']['field_shared_note'] = [['value' => 'Shared draft note']];
$shared_test('requested shared field changes uniformly in all translations', $shared_after, [
  'field_shared_note',
], TRUE);
$changed = $shared_after;
$changed['fr']['field_shared_note'] = [['value' => 'Inconsistent draft note']];
$shared_test('shared field must have one stored value in all translations', $changed, ['field_shared_note'], FALSE);
$changed = $shared_after;
$changed['fr']['title'] = [['value' => 'Unrequested other-language title']];
$shared_test('shared update cannot excuse other-language content', $changed, ['field_shared_note'], FALSE);
$shared_test('unrequested field cannot be declared shared', $shared_after, ['field_shared_note', 'title'], FALSE);
$reviewed_test = function (string $name, array $reviewed, array $stored, bool $allowed) use (&$passed): void {
  try {
    ProtectedDraftFieldScope::assertStoredAsReviewed($reviewed, $stored);
    if (!$allowed) {
      throw new RuntimeException($name . ' incorrectly accepted');
    }
  }
  catch (ProtectedDraftRefusal $error) {
    if ($allowed) {
      throw $error;
    }
    if ($error->getMessage() !== 'The stored value differs from the reviewed value; nothing was written.') {
      throw new RuntimeException($name . ' refused with another reason');
    }
  }
  $passed++;
  echo "PASS $name\n";
};
$reviewed = [
  'title' => [['value' => 'Reviewed title']],
  'body' => [['value' => 'Reviewed body', 'format' => 'plain_text', 'summary' => 'Reviewed summary']],
  'field_count' => [['value' => 3]],
  'field_flag' => [['value' => TRUE]],
  'field_price' => [['value' => 1.5]],
];
$stored = [
  'title' => [['value' => 'Reviewed title']],
  'body' => [['summary' => 'Reviewed summary', 'format' => 'plain_text', 'value' => 'Reviewed body']],
  'field_count' => [['value' => '3']],
  'field_flag' => [['value' => '1']],
  'field_price' => [['value' => '1.50']],
];
$reviewed_test('SQL scalar strings, key order and decimal scale are the same value', $reviewed, $stored, TRUE);
$with_null = $stored;
$with_null['title'][0]['langcode_hint'] = NULL;
$reviewed_test('a stored empty column is the same as an absent one', $reviewed, $with_null, TRUE);
$changed = $stored;
$changed['title'][0]['value'] = 'Replaced by a save hook';
$reviewed_test('a replaced value refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['body'][0]['summary'] = '';
$reviewed_test('a blanked reviewed property refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['body'][0]['format'] = 'full_html';
$reviewed_test('a replaced text format refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['title'][] = ['value' => 'An added item'];
$reviewed_test('an added item refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['title'][0]['extra'] = 'Unreviewed property';
$reviewed_test('an added non-empty property refuses', $reviewed, $changed, FALSE);
$changed = $stored;
unset($changed['field_flag']);
$reviewed_test('a missing reviewed field refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['field_flag'][0]['value'] = '0';
$reviewed_test('a flipped boolean refuses', $reviewed, $changed, FALSE);
$changed = $stored;
$changed['field_count'][0]['value'] = '30';
$reviewed_test('another number refuses', $reviewed, $changed, FALSE);
echo "$passed passed, 0 failed, 0 skipped\n";
