# Coding Conventions

**Analysis Date:** 2026-06-09

## Naming Patterns

**Files:**
- PHP class files use PascalCase matching the class name: `SettingsForm.php`, `ImportWebsiteCommands.php`
- Drupal hook files use snake_case with the module machine name prefix: `cinatra.module`, `cinatra.install`
- Configuration and metadata files use the Drupal dot-notation convention: `cinatra.info.yml`, `cinatra.libraries.yml`, `cinatra.routing.yml`
- CSS and JS assets use kebab-case: `cinatra-fallback.css`, `cinatra-fallback.js`

**Functions:**
- Drupal hook implementations follow the `{module}_{hook_name}` convention: `cinatra_page_attachments()`, `cinatra_page_bottom()`, `cinatra_library_info_alter()`
- Private helpers use a leading underscore prefix: `_cinatra_widget_applies()`
- Class methods use camelCase: `importWebsite()`, `discoverPages()`, `callLlmBridge()`, `buildParagraph()`, `downloadImage()`

**Variables:**
- Local variables use camelCase: `$cinatraUrl`, `$pageUrl`, `$cleanText`, `$fieldMap`
- Loop variables use short descriptive names: `$pageUrl`, `$section`, `$para`, `$node`

**Constants:**
- Module-level PHP constants use SCREAMING_SNAKE_CASE with a module prefix: `CINATRA_CONTRACT_VERSION`
- Class-level private constants also use SCREAMING_SNAKE_CASE: `private const UA`

**Classes:**
- Namespaced under `Drupal\cinatra\{SubNamespace}` following PSR-4: `Drupal\cinatra\Form\SettingsForm`, `Drupal\cinatra\Drush\ImportWebsiteCommands`

**Drupal Config Keys:**
- Snake_case: `cinatra_url`, `api_key`, `instance_id`

**Drupal Field Names:**
- Prefixed by paragraph type abbreviation: `field_hero_headline`, `field_card_title`, `field_con_name`, `field_dl_title`

## Code Style

**Formatting:**
- PHP files begin with `declare(strict_types=1);` — enforced on every PHP file
- `@file` docblock present in procedural files (`cinatra.module`, `cinatra.install`)
- Opening brace on same line for control structures; separate line for class/method bodies (Drupal coding standard)
- Trailing commas used in multi-line arrays and function argument lists

**Linting:**
- PHPCS with Drupal + DrupalPractice standards (`--extensions=php,module,install,inc,yml`)
- Run in CI as `continue-on-error: true` (informational, not blocking)
- PHP syntax lint (`php -l`) is a hard-blocking CI check
- YAML validity is validated via Python `yaml.safe_load_all` in CI — hard-blocking

## Import Organization

**Order (PHP files):**
1. Drupal core classes (`Drupal\Core\*`)
2. Drupal module-specific classes (`Drupal\file\*`, `Drupal\node\*`, `Drupal\paragraphs\*`)
3. Drush/framework classes (`Drush\Attributes`, `Drush\Commands\DrushCommands`)
4. Third-party library classes (`GuzzleHttp\*`)
5. Symfony classes (`Symfony\Component\DependencyInjection\ContainerInterface`)

**Path Aliases:**
- None — standard PSR-4 autoloading via Drupal's class loader

## Error Handling

**Patterns:**
- `catch (\Throwable)` with empty catch body used for optional/non-fatal HTTP calls (e.g., sitemap fetch in `fromSitemap()`)
- `catch (\Throwable $e)` with `throw new \RuntimeException(...)` re-wrapping for errors that must propagate upward (e.g., `callLlmBridge()`, `importPage()`)
- Top-level loop wraps per-page import in `catch (\Throwable $e)` and logs a warning, then continues — never aborts the whole batch
- Image download failures caught with `\Throwable` and logged at `debug` level, returning `NULL`
- No custom exception classes — uses `\RuntimeException` for all thrown errors
- Early-return guard clauses used throughout rather than deep nesting (e.g., `_cinatra_widget_applies()`, `cinatra_page_attachments()`)

## Logging

**Framework:** Drush `$this->logger()` (PSR-3 compatible) inside `ImportWebsiteCommands`; no logging in hook functions

**Patterns:**
- `$this->logger()->notice()` for progress/informational messages with placeholders: `['url' => $url]`
- `$this->logger()->warning()` for skippable errors
- `$this->logger()->error()` for fatal configuration/setup failures that cause early return
- `$this->logger()->success()` for final completion summary
- `$this->logger()->debug()` for low-level diagnostic info (image failures, unknown paragraph types)
- Placeholder syntax uses Drupal/PSR-3 style: `"message {key}"` with array `['key' => $value]`

## Comments

**When to Comment:**
- Block comments (`/** */`) on all classes and methods following Drupal PHPDoc conventions
- `{@inheritdoc}` for overridden methods
- Inline comments explain non-obvious decisions (e.g., why `CINATRA_BASE_URL` env var takes precedence, why `saveHTML()` is used on the full document)
- `@return` type hints included in docblocks when the return type is an array: `/** @return string[] */`
- `/** @var Type $var */` inline annotations used to help static analysis: `/** @var RouteMatchInterface $route */`, `/** @var NodeInterface|NULL $node */`

**JSDoc/TSDoc:**
- Not applicable — no TypeScript or JavaScript files with function-level documentation detected

## Function Design

**Size:** Methods tend toward single responsibility; longer methods (`importPage`, `importWebsite`) are divided internally by section-separator comments (`// ----`)

**Parameters:** Drush command methods use `array $options` with named defaults following Drush conventions; private helpers receive typed scalar or object parameters

**Return Values:**
- Methods returning nullable objects typed as `?ClassName` (e.g., `?Node`, `?File`, `?Paragraph`)
- Helper methods returning `string[]` have `@return string[]` docblock
- `importPage()` returns a string sentinel (`'skipped'` or `'imported'`) rather than a boolean, for clarity in logging

## Module Design

**Exports:**
- Procedural hooks defined globally in `cinatra.module` and `cinatra.install`
- OOP logic encapsulated in `src/` with proper namespace hierarchy

**Barrel Files:**
- Not applicable — Drupal module does not use barrel/index files; class discovery is via PSR-4 autoloading declared in `cinatra.info.yml`

**Config:**
- All configuration kept in `config/install/cinatra.settings.yml` (intentionally empty, to preserve runtime values on config import — see inline comment in the file)
- Runtime config mutated only via `SettingsForm` at `src/Form/SettingsForm.php`

---

*Convention analysis: 2026-06-09*
