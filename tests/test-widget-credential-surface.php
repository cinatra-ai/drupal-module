<?php

/**
 * @file
 * Standalone gate: this module composes no browser-facing credential surface.
 *
 * WHY THIS EXISTS RATHER THAN A PHPUNIT TEST. The module's Kernel and
 * Functional tests are real, but neither CI currently runs them: the
 * drupal.org contrib runner is skipped (SKIP_PHPUNIT — the build does not
 * assemble a test-runnable docroot for this project layout) and the GitHub
 * workflow has no PHPUnit job. A protocol change whose whole point is an
 * ABSENCE cannot rest on assertions nothing executes, so the PHP-side absence
 * is ALSO pinned here, in a harness that needs no Drupal bootstrap and that
 * runs on every pull request.
 *
 * WHAT IT PINS (widget bootstrap protocol 2, cinatra#2674):
 *   1. The three retired broker routes and their two controllers are gone —
 *      routes, controller files, and any reference to them from the routing
 *      file.
 *   2. The drupalSettings the module hands the browser carry no broker
 *      endpoint, no CSRF token for one, and no credential key.
 *   3. No shipped PHP/YAML file composes a credential-shaped value, and none
 *      composes a call to the retired instance endpoints.
 *
 * Run: php tests/test-widget-credential-surface.php
 * Exit 0 = every property holds; 1 = at least one failed.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$failures = 0;

/**
 * Records one assertion.
 */
$check = function (bool $ok, string $label) use (&$checks, &$failures): void {
  $checks++;
  if ($ok) {
    echo "  ok   $label\n";
  }
  else {
    echo "  FAIL $label\n";
    $failures++;
  }
};

// ---------------------------------------------------------------------------
// [1] The retired broker routes and controllers are gone.
// ---------------------------------------------------------------------------
echo "\n[1] the widget credential brokers are retired\n";

$routing = (string) file_get_contents($root . '/cinatra.routing.yml');
foreach (['cinatra.token:', 'cinatra.widget_auth_init:', 'cinatra.widget_auth_token:'] as $route) {
  $check(!str_contains($routing, $route), "the retired route $route is not declared");
}
foreach (['TokenController', 'WidgetAuthController'] as $class) {
  $check(!str_contains($routing, $class), "no route points at $class");
  $check(!file_exists($root . '/src/Controller/' . $class . '.php'), "src/Controller/$class.php is deleted");
}
// The negative control: the routing file still declares what the module keeps,
// so "no retired route" cannot pass by the file having been emptied.
foreach (['cinatra.settings_form:', 'cinatra.connect_callback:', 'cinatra.preview:'] as $route) {
  $check(str_contains($routing, $route), "the surviving route $route is still declared");
}

// ---------------------------------------------------------------------------
// [2] The drupalSettings the module hands the browser are an EXACT, closed set.
//
// Not "these banned keys are absent" — that only ever catches the names someone
// thought to ban, and the module writes into this array in TWO places (a
// per-key assignment for the fallback chrome, and the literal array for the
// bundle), so a check that reads one of them proves nothing about the other
// (codex round 2). Every write is collected and the union must equal the
// allowed set exactly: a new key has to be added here deliberately, which is
// where someone would have to notice they were adding a credential surface.
//
// Read from the source rather than a rendered page, because rendering needs a
// booted Drupal. To keep that honest, the scan ALSO refuses any spelling of
// `drupalSettings` it did not itself understand — an alias or a reference would
// otherwise be a way to write a key this parse never sees.
// ---------------------------------------------------------------------------
echo "\n[2] drupalSettings is an exact, closed set of public selectors\n";

// PHP's own tokenizer strips comments, so prose that merely MENTIONS
// drupalSettings (or a commented-out write) neither satisfies nor trips the
// scan. Only executable code is read.
$moduleRaw = (string) file_get_contents($root . '/cinatra.module');
$module = '';
foreach (token_get_all($moduleRaw) as $token) {
  if (is_array($token)) {
    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
      continue;
    }
    $module .= $token[1];
    continue;
  }
  $module .= $token;
}

$allowedKeys = [
  'cinatraUrl',
  'nodeId',
  'nodeBundle',
  'nodeStatus',
  'instanceId',
  'drupalAdminUrl',
];

$writtenKeys = [];

// (a) Per-key writes: …['drupalSettings']['cinatra']['<key>'] = …
preg_match_all(
  "/\\['drupalSettings'\\]\\['cinatra'\\]\\['([A-Za-z0-9_]+)'\\]/",
  $module,
  $perKey,
);
foreach ($perKey[1] as $key) {
  $writtenKeys[$key] = TRUE;
}

// (b) Literal-array writes: …['drupalSettings']['cinatra'] = [ 'key' => …, ];
$arrayWrites = 0;
$offset = 0;
$needle = "['drupalSettings']['cinatra'] = [";
while (($at = strpos($module, $needle, $offset)) !== FALSE) {
  $arrayWrites++;
  $bodyStart = $at + strlen($needle);
  $bodyEnd = strpos($module, '];', $bodyStart);
  $body = $bodyEnd === FALSE ? substr($module, $bodyStart) : substr($module, $bodyStart, $bodyEnd - $bodyStart);
  preg_match_all("/'([A-Za-z0-9_]+)'\\s*=>/", $body, $literalKeys);
  foreach ($literalKeys[1] as $key) {
    $writtenKeys[$key] = TRUE;
  }
  $offset = $bodyEnd === FALSE ? strlen($module) : $bodyEnd;
}

// Every `drupalSettings` mention must be one this scan understood, or a write
// could hide behind a spelling the parse never looked at.
$mentions = substr_count($module, 'drupalSettings');
$understood = count($perKey[0]) + $arrayWrites;
$check(
  $mentions === $understood,
  "every drupalSettings write is one this scan parsed ($understood of $mentions)",
);
$check($writtenKeys !== [], 'at least one drupalSettings key was found');

$written = array_keys($writtenKeys);
sort($written);
$allowed = $allowedKeys;
sort($allowed);
$unexpected = array_values(array_diff($written, $allowed));
$missing = array_values(array_diff($allowed, $written));
$check(
  $unexpected === [],
  'drupalSettings writes no key outside the allowed selector set' . ($unexpected ? ' (unexpected: ' . implode(', ', $unexpected) . ')' : ''),
);
// Negative control: the selectors the frame actually needs are all still there,
// so "no unexpected key" cannot pass by the array having been emptied.
$check(
  $missing === [],
  'drupalSettings still writes every expected selector' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''),
);

// The CSRF token was the only session-varying value in the array; with it gone
// the attachment must no longer force a per-session cache context.
$check(
  !str_contains($module, "\$attachments['#cache']['contexts'][] = 'session';"),
  'the session cache context the CSRF token required is gone',
);

// ---------------------------------------------------------------------------
// [3] No shipped file composes a credential shape or calls a retired endpoint.
//
// The same token-boundary rule the widget applies to its outbound payloads,
// applied to the module's own source: a `cwu_`/`cit_`/`cnx_` literal in shipped
// PHP or YAML would mean this module is minting, matching or forwarding a
// credential again. Tests are exempt (they must be able to NAME the shapes they
// assert are absent), and so is this file.
// ---------------------------------------------------------------------------
echo "\n[3] no credential shape and no retired-endpoint call in shipped source\n";

$credentialShape = '/(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i';
$retiredEndpoint = '#/api/widget-auth/(?:init|token)|/api/agents/[^/\s]+/token#';

$shipped = [];
$walk = function (string $dir) use (&$walk, &$shipped): void {
  foreach (scandir($dir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
      continue;
    }
    $full = $dir . '/' . $entry;
    if (is_dir($full)) {
      // tests/ may name the shapes it asserts are absent; vendor/ is not ours.
      if (in_array($entry, ['tests', 'vendor', 'node_modules'], TRUE)) {
        continue;
      }
      $walk($full);
      continue;
    }
    $ext = pathinfo($full, PATHINFO_EXTENSION);
    if (in_array($ext, ['php', 'module', 'install', 'inc', 'yml', 'yaml'], TRUE)) {
      $shipped[] = $full;
    }
  }
};
$walk($root);
$check($shipped !== [], 'the shipped-source scan found files to scan');

$credentialOffenders = [];
$endpointOffenders = [];
foreach ($shipped as $file) {
  $contents = (string) file_get_contents($file);
  $relative = substr($file, strlen($root) + 1);
  if (preg_match($credentialShape, $contents) === 1) {
    $credentialOffenders[] = $relative;
  }
  if (preg_match($retiredEndpoint, $contents) === 1) {
    $endpointOffenders[] = $relative;
  }
}
$check(
  $credentialOffenders === [],
  'no shipped PHP/YAML composes a credential-shaped value' . ($credentialOffenders ? ' (found in: ' . implode(', ', $credentialOffenders) . ')' : ''),
);
$check(
  $endpointOffenders === [],
  'no shipped PHP/YAML composes a call to a retired instance endpoint' . ($endpointOffenders ? ' (found in: ' . implode(', ', $endpointOffenders) . ')' : ''),
);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
