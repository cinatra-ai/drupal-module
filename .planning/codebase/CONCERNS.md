# Codebase Concerns

**Analysis Date:** 2026-06-09

## Tech Debt

**PHPCS is non-gating (continue-on-error: true):**
- Issue: The phpcs job in CI runs with `continue-on-error: true`, meaning Drupal coding standard violations never block a merge.
- Files: `.github/workflows/ci.yml` (line 55)
- Impact: Coding standard drift goes undetected over time; violations accumulate silently.
- Fix approach: Triage the current PHPCS output, fix violations, then remove `continue-on-error: true` to make it a hard gate.

**No PHPStan / static analysis:**
- Issue: The CI comment (`WPD-EXTRACT-03`) explicitly defers PHPStan to a later milestone. There is no static type-checking beyond PHP syntax linting.
- Files: `.github/workflows/ci.yml`
- Impact: Type errors, undefined properties, and bad API calls on `Node`, `Paragraph`, and `File` entities will only surface at runtime.
- Fix approach: Add a `phpstan` job (with `phpstan/phpstan-drupal`) once the module stabilises; start at level 3 and raise incrementally.

**No automated tests (zero test files):**
- Issue: The repository contains no PHPUnit, Kernel, or Functional test files. The `cinatra.info.yml` declares no test namespace.
- Files: entire `src/` tree
- Impact: Regressions in hook implementations, the Drush command, or the settings form will not be caught before deployment.
- Fix approach: Add at minimum a Kernel test for `cinatra_page_attachments()` and a Unit test for the URL-normalisation helpers in `ImportWebsiteCommands`.

**Hard-coded `X-Forwarded-For` header in LLM bridge call:**
- Issue: Every request to `/api/llm-bridge` sends `'X-Forwarded-For' => '172.17.0.1'` unconditionally, regardless of the environment.
- Files: `src/Drush/ImportWebsiteCommands.php` (line 325)
- Impact: In production deployments outside Docker the header is meaningless; if Cinatra uses this header to infer trust level it may apply dev-only permissiveness to production traffic.
- Fix approach: Make the IP configurable via environment variable (e.g. `CINATRA_FORWARDED_FOR`) and omit the header entirely when the variable is not set.

**`drupalAdminUrl` always emitted as empty string:**
- Issue: `cinatra_page_attachments()` always sends `'drupalAdminUrl' => ''` in the JavaScript settings payload.
- Files: `cinatra.module` (line 100)
- Impact: Any Cinatra widget feature that relies on `drupalAdminUrl` to link back to node edit forms will silently break.
- Fix approach: Populate with `\Drupal::urlGenerator()->generateFromRoute('entity.node.edit_form', ['node' => $node_id])` or remove the key from the contract until it is implemented.

**`\Drupal::service('path_alias.manager')` called with no assignment (dead call):**
- Issue: In `importPage()`, line 271, `\Drupal::service('path_alias.manager')` is invoked but the return value is immediately discarded. The alias manager is then correctly obtained from `$this->entityTypeManager`, making the standalone call a no-op that only costs a service lookup.
- Files: `src/Drush/ImportWebsiteCommands.php` (line 271)
- Impact: Minor: wasted service-container lookup per imported page; code is confusing.
- Fix approach: Remove the dead line entirely.

**`$existing` variable shadowed in `importPage()`:**
- Issue: `$existing` is first assigned as a `?Node` from `findExistingNode()` (line 214), then reassigned as a `PathAlias[]` result of `loadByProperties()` (line 273) within the same method scope.
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 214, 273)
- Impact: Confusing; if refactored carelessly the node and alias collections could be mixed up.
- Fix approach: Rename the alias variable to `$existingAliases`.

**`\Drupal::routeMatch()` called twice per request in `cinatra_page_attachments()`:**
- Issue: The route match service is retrieved twice: once via a reference in `_cinatra_widget_applies()` and once directly inside `cinatra_page_attachments()`.
- Files: `cinatra.module` (lines 31, 80)
- Impact: Minor redundant service lookups; also the `$route` variable at line 32 is typed `RouteMatchInterface` but the annotation is on a local variable `$route` while the actual service is assigned with no annotation at line 80.
- Fix approach: Cache the route match in a local variable and pass it to the helper, or call `_cinatra_widget_applies()` to return the route object.

## Known Bugs

**Relative `href` resolution produces incorrect absolute URLs:**
- Symptoms: `toAbsolute()` converts a relative path like `./about` to `{origin}/about`, stripping the `./` prefix with `ltrim($href, './')`. `ltrim` treats its second argument as a character list, not a prefix — it strips any leading `.` or `/` character, meaning a path starting with `...` or `//foo` gets mangled.
- Files: `src/Drush/ImportWebsiteCommands.php` (line 524)
- Trigger: Any relative href beginning with multiple dots or a protocol-relative URL (`//cdn.example.com/img.jpg`).
- Workaround: None currently; images or links from such hrefs are silently dropped.

**Sitemap index recursion has no depth/cycle guard:**
- Symptoms: `fromSitemap()` recursively calls itself for every `<sitemap><loc>` child without tracking visited URLs or limiting recursion depth. A malformed or adversarial sitemap index could produce infinite recursion.
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 171-175)
- Trigger: A sitemap index that references itself or creates a cycle.
- Workaround: The `--limit` flag caps final page count but does not prevent deep recursion before that point.

## Security Considerations

**API key stored in Drupal managed config (database):**
- Risk: The `api_key` value is saved via `ConfigFormBase` into Drupal's active configuration store. Any user or process with access to `drush config:export` or the configuration management UI will see the key in plaintext.
- Files: `src/Form/SettingsForm.php`, `config/install/cinatra.settings.yml`
- Current mitigation: The settings form uses `#type => 'textfield'` (not `password`), so the key is also visible in the browser form and browser history.
- Recommendations: Use `#type => 'password'` on the `api_key` field; add a `$settings` override pattern so operators can inject secrets via `settings.php` without storing them in exported config.

**API key exposed in `drupalSettings` JavaScript object:**
- Risk: `cinatra_page_attachments()` writes `'apiKey' => $api_key` into `drupalSettings.cinatra`, which is serialised into an inline `<script>` tag visible in page source to any authenticated user.
- Files: `cinatra.module` (line 94)
- Current mitigation: The hook only fires for authenticated users (`_cinatra_widget_applies()` checks `isAuthenticated()`), so anonymous visitors cannot see it. However, any authenticated Drupal user (including those with only basic "authenticated user" permissions) receives the key.
- Recommendations: Document the trust boundary clearly; consider scoping widget attachment to users with a specific permission (e.g. `administer site configuration`) rather than all authenticated users.

**No SSRF protection on the LLM bridge URL:**
- Risk: `callLlmBridge()` posts to `$cinatraUrl . '/api/llm-bridge'`. The `cinatraUrl` originates from either an environment variable or Drupal config. If an attacker can write to Drupal config (e.g. via a compromised admin account), they can redirect all LLM bridge calls to an internal service.
- Files: `src/Drush/ImportWebsiteCommands.php` (line 317)
- Current mitigation: Drush commands require shell access; practical risk is low.
- Recommendations: Validate that `$cinatraUrl` resolves to an expected host before making the HTTP call.

**Image download accepts any `Content-Type: image/*` without extension allow-list:**
- Risk: `downloadImage()` accepts any response with a `Content-Type` beginning with `image/`, including `image/svg+xml`. SVG files can contain JavaScript and, if served by Drupal, may execute in a victim's browser.
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 439-441)
- Current mitigation: Files land in `public://imported/` with their original filenames; Drupal's file serving does not add security headers by default.
- Recommendations: Restrict accepted MIME types to `image/jpeg`, `image/png`, `image/webp`, `image/gif`; reject `image/svg+xml` or sanitise SVG before saving.

## Performance Bottlenecks

**`ImportWebsiteCommands` is fully synchronous with no batching:**
- Problem: All pages are imported sequentially in a single PHP process. For a 200-page site this means hundreds of serial HTTP calls (page fetch + LLM bridge, each up to 2 min timeout) blocking the CLI indefinitely.
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 110-124)
- Cause: No Drupal Batch API, no queue, no async HTTP.
- Improvement path: Wrap the per-page loop in `\Drupal\Core\Batch\BatchBuilder` so the browser-based importer can report progress; alternatively push items into a Drupal Queue and process with `drush queue:run`.

**`extractText()` loads entire HTML into `DOMDocument` then serialises it back:**
- Problem: For large HTML documents (e.g. pages with large inline SVG or data URIs) the load → remove-elements → `saveHTML()` → `strip_tags()` pipeline can be slow and memory-intensive.
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 468-491)
- Cause: `DOMDocument::saveHTML()` on a full document re-serialises everything including the noise elements' siblings before they are stripped.
- Improvement path: Use `DOMXPath` to extract only `<main>` or `<article>` content before serialising, reducing the string passed to `strip_tags()`.

**Library cache is cleared synchronously on every settings save:**
- Problem: `\Drupal::service('library.discovery')->clearCachedDefinitions()` in `SettingsForm::submitForm()` flushes the entire library registry, not just the cinatra library.
- Files: `src/Form/SettingsForm.php` (line 70)
- Cause: The Drupal library discovery service has no partial-invalidation API; clearing everything is the only available approach.
- Improvement path: This is a framework limitation; acceptable for a low-frequency settings change. Document that a full library flush occurs so operators are aware of the brief cache-warm cost on the next request.

## Fragile Areas

**`hook_library_info_alter()` relies on placeholder URL string match:**
- Files: `cinatra.module` (lines 152-167)
- Why fragile: The rewrite depends on `$libraries['bundle']['js']['https://placeholder.cinatra.invalid/api/drupal/bundle.js']` existing with exactly that key. If `cinatra.libraries.yml` is edited (typo, trailing slash, etc.) the rewrite silently no-ops and the browser loads the placeholder URL, producing a network error with no admin warning.
- Safe modification: If the placeholder URL in `cinatra.libraries.yml` must change, update the `$placeholder` constant in `cinatra_library_info_alter()` atomically. Add a log warning when the expected key is absent.
- Test coverage: None.

**`buildParagraph()` silently skips unrecognised paragraph types:**
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 349-405)
- Why fragile: The `match` expression returns `NULL` for any type string not in the exhaustive list; unknown types from the LLM are logged at `debug` level only and discarded without a warning.
- Safe modification: Promote the debug log to `warning` so operators notice when the LLM emits types the schema does not recognise (signal of prompt drift or schema version mismatch).
- Test coverage: None.

**`ensureLanguage()` installs Drupal modules at runtime during a Drush command:**
- Files: `src/Drush/ImportWebsiteCommands.php` (lines 539-561)
- Why fragile: Installing `language` and `locale` modules mid-command can trigger cache rebuilds, entity schema updates, and hook invocations that interact unpredictably with an in-progress import. Module installation is also not rolled back on failure.
- Safe modification: Require operators to pre-install language modules; replace the auto-installer with a hard error instructing the user to run `drush en language locale` first.
- Test coverage: None.

## Scaling Limits

**LLM bridge timeout is 120 seconds per page:**
- Current capacity: 20 pages (default `--limit`) × up to 120 s/page = up to 40 minutes of blocking CLI time.
- Limit: PHP `max_execution_time` (typically 30 s on web-initiated processes; unlimited on CLI but at operator discretion).
- Scaling path: Move to a queue-based batch architecture; allow configurable concurrency.

**Sitemap crawl loads all discovered URLs into memory before slicing:**
- Current capacity: Adequate for sites with <10,000 URLs.
- Limit: Very large sitemap indexes (e.g. e-commerce sites) can exhaust PHP memory before the `array_slice($pages, 0, $limit)` guard executes.
- Scaling path: Apply the `$limit` cap inside `fromSitemap()` rather than after full collection.

## Dependencies at Risk

**`drupal/paragraphs` is an undeclared dependency:**
- Risk: `ImportWebsiteCommands` directly instantiates `Drupal\paragraphs\Entity\Paragraph` and calls `Paragraph::create()`. The `paragraphs` module is not listed in `cinatra.info.yml` dependencies.
- Impact: Enabling the module on a site without `paragraphs` installed will not raise a dependency error at enable time; the Drush command will fail with a fatal class-not-found error at runtime.
- Migration plan: Add `drupal:paragraphs` (or `paragraphs:paragraphs`) to the `dependencies` key in `cinatra.info.yml`, or gate the Drush command with a runtime check and a clear error message.

## Missing Critical Features

**No update hooks for future schema changes:**
- Problem: `cinatra.install` only implements `hook_install()`. There are no `hook_update_N()` functions. Any future addition of a new config key will require operators to manually clear config or re-run the settings form; it cannot be automated via `drush updb`.
- Blocks: Reliable schema migrations for existing installations.

**No permission system for the widget beyond "authenticated":**
- Problem: The widget surface (and the API key exposure in `drupalSettings`) is shown to every authenticated user. There is no Drupal permission that restricts widget access to editors or administrators only.
- Blocks: Sites where "authenticated user" includes untrusted members (e.g. a community site) cannot safely enable the module.

## Test Coverage Gaps

**No tests at any level:**
- What's not tested: `cinatra_page_attachments()`, `cinatra_page_bottom()`, `cinatra_library_info_alter()`, `SettingsForm`, `ImportWebsiteCommands` (all methods), `cinatra_install()`.
- Files: `cinatra.module`, `cinatra.install`, `src/Form/SettingsForm.php`, `src/Drush/ImportWebsiteCommands.php`
- Risk: Any regression in the hook logic, the library URL rewrite, settings form save, or Drush command behaviour goes undetected.
- Priority: High — especially for `_cinatra_widget_applies()` (cache-context correctness) and `cinatra_library_info_alter()` (silent failure mode).

---

*Concerns audit: 2026-06-09*
