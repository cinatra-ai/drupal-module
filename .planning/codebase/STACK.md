# Technology Stack

**Analysis Date:** 2026-06-09

## Languages

**Primary:**
- PHP 8.3 - All module logic (`cinatra.module`, `src/Form/SettingsForm.php`, `src/Drush/ImportWebsiteCommands.php`, `cinatra.install`)

**Secondary:**
- JavaScript - Client-side fallback widget (`js/cinatra-fallback.js`)
- CSS - Fallback widget styles (`css/cinatra-fallback.css`)
- YAML - Drupal module metadata, routing, library definitions, config (`cinatra.info.yml`, `cinatra.routing.yml`, `cinatra.libraries.yml`, `config/install/cinatra.settings.yml`)

## Runtime

**Environment:**
- PHP 8.3 (CI enforces 8.3 via `shivammathur/setup-php@v2`)
- Drupal core 10.3 or 11 (declared in `cinatra.info.yml`: `core_version_requirement: ^10.3 || ^11`)

**Package Manager:**
- Composer (no `composer.json` present in this repo; end users install via `composer require drupal/cinatra` against their Drupal site's composer project)

## Frameworks

**Core:**
- Drupal 10.3 / 11 - CMS framework providing hooks, routing, config, entity, and library APIs
- Drush - CLI framework for the `cinatra:import-website` Drush command (`src/Drush/ImportWebsiteCommands.php`)

**Testing:**
- Not applicable — no test files or test framework configuration present

**Build/Dev:**
- GitHub Actions CI (`/.github/workflows/ci.yml`) — runs PHP lint, YAML lint, and PHPCS (Drupal coding standards)

## Key Dependencies

**Critical:**
- `drupal:node` - Required Drupal module (declared in `cinatra.info.yml`); widget only activates on node routes
- `drupal:user` - Required Drupal module; widget activates for authenticated users only
- `core/drupalSettings` - Drupal JS settings system; carries `cinatra.*` config to the browser bundle
- `GuzzleHttp\ClientInterface` - HTTP client used in `ImportWebsiteCommands` for crawling target websites and calling the Cinatra LLM bridge

**Infrastructure:**
- `drupal/paragraphs` - Entity type used by `ImportWebsiteCommands` to build structured content sections (imported via `Drupal\paragraphs\Entity\Paragraph`)
- `Drupal\file\Entity\File` - Used by `ImportWebsiteCommands` to persist downloaded images into the Drupal public file system
- `drupal/coder` - Dev/CI dependency (not runtime); enforces Drupal + DrupalPractice PHPCS standards in CI

## Configuration

**Environment:**
- Settings stored in Drupal config system as `cinatra.settings` (managed via `config/install/cinatra.settings.yml`)
- Three runtime config keys: `cinatra_url`, `api_key`, `instance_id` — set via the admin form at `/admin/config/services/cinatra` (`src/Form/SettingsForm.php`)
- `CINATRA_BASE_URL` PHP environment variable — optional server-side override used by `ImportWebsiteCommands` so Docker container networking can reach Cinatra independently of the browser-visible URL

**Build:**
- `.github/workflows/ci.yml` — CI pipeline (PHP lint, YAML lint, PHPCS)
- No build step or asset compilation; JS/CSS are static files committed directly

## Platform Requirements

**Development:**
- PHP 8.3+
- Drupal 10.3 or 11 site with the `node` and `user` core modules enabled
- Drush (for `cinatra:import-website` command)
- `drupal/paragraphs` module (for import command paragraph creation)

**Production:**
- Any PHP 8.3+ Drupal 10.3/11 hosting environment
- Outbound HTTP access from the Drupal server to the Cinatra instance URL (for LLM bridge calls in `ImportWebsiteCommands`)
- Browser-accessible Cinatra instance URL serving `/api/drupal/bundle.js`

---

*Stack analysis: 2026-06-09*
