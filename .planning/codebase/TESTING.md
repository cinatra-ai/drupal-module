# Testing Patterns

**Analysis Date:** 2026-06-09

## Test Framework

**Runner:**
- No PHP unit test framework detected (no PHPUnit config, no `tests/` directory, no `*.test` or `*Test.php` files)
- CI uses two hard-blocking lint jobs in `.github/workflows/ci.yml` as the primary quality gate

**Assertion Library:**
- Not applicable — no automated assertion-based tests exist

**Run Commands:**
```bash
# PHP syntax lint (hard-blocking CI gate)
find . -path ./vendor -prune -o \( -name '*.php' -o -name '*.module' -o -name '*.install' \) -print | while read -r f; do php -l "$f"; done

# YAML validity (hard-blocking CI gate)
python3 -c "import glob,sys,yaml; [list(yaml.safe_load_all(open(f))) for f in glob.glob('**/*.yml', recursive=True)]"

# PHPCS (informational, non-blocking)
phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,inc,yml cinatra
```

## Test File Organization

**Location:**
- No test files present in the repository

**Naming:**
- Not applicable

**Structure:**
- Not applicable

## Test Structure

**Suite Organization:**
- Not applicable — no test suites exist

**Patterns:**
- Not applicable

## Mocking

**Framework:** Not applicable

**Patterns:**
- Not applicable

**What to Mock:**
- Not applicable

**What NOT to Mock:**
- Not applicable

## Fixtures and Factories

**Test Data:**
- Not applicable

**Location:**
- Not applicable

## Coverage

**Requirements:** None enforced — no coverage tooling configured

**View Coverage:**
```bash
# Not configured
```

## Test Types

**Unit Tests:**
- Not present. The codebase is a Drupal module; standard approach would be PHPUnit with `Drupal\Tests\UnitTestCase` for `_cinatra_widget_applies()` logic and `ImportWebsiteCommands` helpers like `normalizeBase()`, `toAbsolute()`, `extractText()`

**Integration Tests:**
- Not present. Standard approach would be `Drupal\Tests\KernelTestBase` for `SettingsForm`, `cinatra_library_info_alter()`, and `cinatra_install()` migration logic

**E2E Tests:**
- Not present. Standard approach would be `Drupal\Tests\BrowserTestBase` for the admin settings form at `/admin/config/services/cinatra`

## CI Quality Gates

The CI pipeline (`.github/workflows/ci.yml`) provides three jobs as a substitute for automated tests:

**php-lint (blocking):**
- Runs `php -l` on every `.php`, `.module`, and `.install` file
- Catches syntax errors before merge
- PHP 8.3 runtime

**yaml-lint (blocking):**
- Validates every `.yml` and `.yaml` file parses via Python `yaml.safe_load_all`
- Catches malformed YAML in config, info, libraries, and routing files

**phpcs (informational, non-blocking):**
- Runs PHPCS with `Drupal` and `DrupalPractice` standards
- `continue-on-error: true` — failures do not block PRs
- Intent is triage-first: per CI comment, gating is planned once initial findings are addressed

## Common Patterns

**Async Testing:**
- Not applicable

**Error Testing:**
- Not applicable

## Gap Summary

The module has no automated tests. Key areas that lack coverage and carry the highest regression risk:

- `_cinatra_widget_applies()` — authentication + route matching logic (`cinatra.module`)
- `cinatra_page_attachments()` — conditional library attachment, cache context setting, `drupalSettings` payload (`cinatra.module`)
- `cinatra_library_info_alter()` — URL rewriting of the placeholder bundle URL (`cinatra.module`)
- `cinatra_install()` — legacy config migration from `cinatra_widget.settings` to `cinatra.settings` (`cinatra.install`)
- `ImportWebsiteCommands::toAbsolute()` — URL normalization edge cases (`src/Drush/ImportWebsiteCommands.php`)
- `ImportWebsiteCommands::extractText()` — HTML cleaning and truncation (`src/Drush/ImportWebsiteCommands.php`)
- `ImportWebsiteCommands::buildParagraph()` — `match` dispatch over all paragraph types (`src/Drush/ImportWebsiteCommands.php`)

---

*Testing analysis: 2026-06-09*
