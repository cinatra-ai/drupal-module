# External Integrations

**Analysis Date:** 2026-06-09

## APIs & External Services

**Cinatra AI Platform (primary integration):**
- Cinatra SaaS/self-hosted instance — the central external service this module connects to
  - Widget bundle: `{cinatra_url}/api/drupal/bundle.js` loaded as an external JS library (see `cinatra.libraries.yml`, rewritten at runtime in `hook_library_info_alter` in `cinatra.module`)
  - LLM bridge endpoint: `POST {cinatra_url}/api/llm-bridge` — called server-side by `src/Drush/ImportWebsiteCommands.php` to structure crawled page content via an LLM
  - Auth for widget: `Authorization: Bearer <api_key>` passed via `drupalSettings.cinatra.apiKey` to the frontend bundle
  - Auth for LLM bridge: No bearer token; Docker bridge IP `172.17.0.1` is sent as `X-Forwarded-For` for loopback trust in dev environments
  - Contract version: `v1` (constant `CINATRA_CONTRACT_VERSION` in `cinatra.module`) — Cinatra rejects unknown versions with an admin-visible error
  - Config: `cinatra_url`, `api_key`, `instance_id` in Drupal config `cinatra.settings`

**Target websites (for bulk import):**
- Any public website URL passed to `drush cinatra:import-website`
  - Crawled via Guzzle HTTP client in `src/Drush/ImportWebsiteCommands.php`
  - Sitemap discovery: `GET {origin}/sitemap.xml` and `GET {origin}/sitemap_index.xml`
  - Fallback: HTML crawl following `<a href>` links within the base URL prefix
  - Image download: any `https://` image URL found in LLM-structured content, saved to `public://imported/`

## Data Storage

**Databases:**
- Drupal database (type determined by host Drupal installation — typically MySQL/MariaDB or PostgreSQL)
  - Connection: managed by Drupal core; no module-specific DB connection
  - Client: Drupal Entity API (`EntityTypeManagerInterface`) for node, paragraph, file, and path alias storage

**File Storage:**
- Drupal public file system (`public://imported/`) — images downloaded during `cinatra:import-website` are written here via `FileSystemInterface`

**Caching:**
- Drupal's built-in cache system — `hook_page_attachments` sets cache contexts (`user.roles:authenticated`, `route`) and tags (`config:cinatra.settings`) to prevent leaking authenticated config into anonymous caches; library cache cleared on settings save via `library.discovery->clearCachedDefinitions()`

## Authentication & Identity

**Auth Provider:**
- Drupal's native authentication — the widget only activates for authenticated Drupal users (`\Drupal::currentUser()->isAuthenticated()` check in `cinatra.module`)
- Cinatra API key — Bearer token passed to the Cinatra frontend bundle via `drupalSettings`; generated in Cinatra at `/settings/connectors/drupal-widget`
- Admin form protected by `_permission: 'administer site configuration'` (see `cinatra.routing.yml`)

## Monitoring & Observability

**Error Tracking:**
- Not detected — no third-party error tracking SDK

**Logs:**
- Drush logger (`$this->logger()`) used in `ImportWebsiteCommands` for notice/warning/error/success/debug output during import runs
- Drupal's standard watchdog/logging system inherited via Drupal core hooks

## CI/CD & Deployment

**Hosting:**
- Any Drupal-compatible PHP host (no platform-specific deployment config detected)

**CI Pipeline:**
- GitHub Actions (`.github/workflows/ci.yml`) — three jobs:
  1. `php-lint`: PHP syntax check on all `.php`/`.module`/`.install` files using PHP 8.3
  2. `yaml-lint`: YAML parse validation using Python `yaml` on all `.yml`/`.yaml` files
  3. `phpcs` (non-gating, `continue-on-error: true`): Drupal + DrupalPractice coding standards via `drupal/coder`

## Webhooks & Callbacks

**Incoming:**
- None detected

**Outgoing:**
- `POST {cinatra_url}/api/llm-bridge` — server-side call from `ImportWebsiteCommands::callLlmBridge()` sending crawled page text for LLM structuring; payload includes `system`, `user`, and `agent_id: "drupal-importer"` fields; 120-second timeout

## Environment Configuration

**Required env vars:**
- `CINATRA_BASE_URL` (optional) — server-side base URL override for the Cinatra instance, used by `ImportWebsiteCommands` when Drupal runs inside Docker and the stored `cinatra_url` (browser-reachable) differs from the container-reachable address (e.g., `http://host.docker.internal:3000`)

**Secrets location:**
- `api_key` stored in Drupal's active config (`cinatra.settings`) — managed via the admin UI, not committed to code or version-controlled config (`config/install/cinatra.settings.yml` is intentionally empty)

---

*Integration audit: 2026-06-09*
