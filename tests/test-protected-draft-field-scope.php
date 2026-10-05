<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ProtectedDraft/ProtectedDraftRefusal.php';
require_once __DIR__ . '/../src/ProtectedDraft/ProtectedDraftFieldScope.php';

use Drupal\cinatra\ProtectedDraft\ProtectedDraftFieldScope;
use Drupal\cinatra\ProtectedDraft\ProtectedDraftRefusal;

$before = ['en' => ['title' => [['value' => 'English']], 'body' => [['value' => 'English body', 'format' => 'plain_text']]], 'fr' => ['title' => [['value' => 'French']], 'body' => [['value' => 'French body', 'format' => 'plain_text']]]];
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
$shared_test('requested shared field changes uniformly in all translations', $shared_after, ['field_shared_note'], TRUE);
$changed = $shared_after;
$changed['fr']['field_shared_note'] = [['value' => 'Inconsistent draft note']];
$shared_test('shared field must have one stored value in all translations', $changed, ['field_shared_note'], FALSE);
$changed = $shared_after;
$changed['fr']['title'] = [['value' => 'Unrequested other-language title']];
$shared_test('shared update cannot excuse other-language content', $changed, ['field_shared_note'], FALSE);
$shared_test('unrequested field cannot be declared shared', $shared_after, ['field_shared_note', 'title'], FALSE);
echo "$passed passed, 0 failed, 0 skipped\n";
