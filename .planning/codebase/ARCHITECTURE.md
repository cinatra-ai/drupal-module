<!-- refreshed: 2026-06-09 -->
# Architecture

**Analysis Date:** 2026-06-09

## System Overview

```text
┌──────────────────────────────────────────────────────────────┐
│                    Drupal Page Request                        │
│        (authenticated user on node / front page)             │
└───────────────────────┬──────────────────────────────────────┘
                        │
                        ▼
┌──────────────────────────────────────────────────────────────┐
│            cinatra.module — Hook Layer                        │
│  hook_page_attachments()   hook_page_bottom()                │
│  hook_library_info_alter()                                    │
│  `cinatra.module`                                             │
└──────┬──────────────────────────┬────────────────────────────┘
       │                          │
       ▼                          ▼
┌─────────────────┐   ┌──────────────────────────────────────┐
│  Library Layer  │   │          DOM Mount Point              │
│  cinatra/bundle │   │  #cinatra-root  +  fallback chrome   │
│  cinatra/fallback│  │  `cinatra.module` (hook_page_bottom) │
│ `cinatra.libraries.yml`                                     │
└────────┬────────┘   └──────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────────┐
│         Remote Cinatra Instance (external)                   │
│   GET  {cinatra_url}/api/drupal/bundle.js   (widget IIFE)   │
│   POST {cinatra_url}/api/llm-bridge         (Drush import)  │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│            Admin / Configuration Layer                        │
│  SettingsForm  →  cinatra.settings config object             │
│  `src/Form/SettingsForm.php`                                  │
│  Route: /admin/config/services/cinatra                        │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│            Drush Command Layer (optional)                     │
│  cinatra:import-website (alias: ciw)                         │
│  `src/Drush/ImportWebsiteCommands.php`                        │
│  Crawl → LLM bridge → Node + Paragraph entities              │
└──────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Hook layer | Attach libraries, inject DOM mount point, rewrite bundle URL at runtime | `cinatra.module` |
| SettingsForm | Admin UI: save `cinatra_url`, `api_key`, `instance_id` to config | `src/Form/SettingsForm.php` |
| ImportWebsiteCommands | Drush command: crawl external site, call LLM bridge, create Drupal nodes+paragraphs | `src/Drush/ImportWebsiteCommands.php` |
| Library definitions | Declare `cinatra/bundle` (remote JS) and `cinatra/fallback` (local CSS+JS) | `cinatra.libraries.yml` |
| Fallback chrome | Floating button + error card rendered when bundle cannot connect | `js/cinatra-fallback.js`, `css/cinatra-fallback.css` |
| Install hook | Migrate legacy config from `cinatra_widget.settings` on first install | `cinatra.install` |
| Config schema | Runtime config object with empty defaults (deliberately blank) | `config/install/cinatra.settings.yml` |

## Pattern Overview

**Overall:** Drupal hook-driven module with a thin PHP layer and a heavy remote JavaScript widget.

**Key Characteristics:**
- No custom Drupal services or plugins — all behaviour wired through standard Drupal hooks in `cinatra.module`.
- Widget is a remote IIFE (`bundle.js`) loaded dynamically from the operator-configured Cinatra URL; the module only supplies the DOM mount point and `drupalSettings` context.
- A local fallback library (`cinatra/fallback`) is always attached when `cinatra_url` is set so the widget surface degrades gracefully instead of going silently missing.
- The Drush command is an optional batch tool that bridges the Drupal PHP process directly to the Cinatra LLM API to import/migrate web content into Drupal nodes.

## Layers

**Hook Layer:**
- Purpose: Entry point for every page render — decides whether the widget applies, attaches libraries, and injects the HTML mount point.
- Location: `cinatra.module`
- Contains: `_cinatra_widget_applies()`, `cinatra_page_attachments()`, `cinatra_page_bottom()`, `cinatra_library_info_alter()`
- Depends on: `cinatra.settings` config, `drupal:node`, `drupal:user`, `path.matcher` service
- Used by: Drupal core render pipeline (called automatically)

**Admin Form Layer:**
- Purpose: Provides the settings UI at `/admin/config/services/cinatra`.
- Location: `src/Form/SettingsForm.php`
- Contains: `SettingsForm` (extends `ConfigFormBase`) — fields: `cinatra_url`, `api_key`, `instance_id`
- Depends on: `cinatra.settings` config object, `library.discovery` service (cache clear on save)
- Used by: Site administrators via the Drupal admin interface

**Library / Frontend Layer:**
- Purpose: Declares the two Drupal libraries consumed by the hook layer.
- Location: `cinatra.libraries.yml`, `js/cinatra-fallback.js`, `css/cinatra-fallback.css`
- Contains: `cinatra/bundle` (external remote JS, URL rewritten at runtime), `cinatra/fallback` (local static assets)
- Depends on: `core/drupalSettings`
- Used by: Hook layer via `#attached['library']`

**Drush Command Layer:**
- Purpose: CLI batch importer — crawls a public website, sends page text to the Cinatra LLM bridge, maps the structured JSON response to Drupal `landing_page` nodes with typed paragraph fields.
- Location: `src/Drush/ImportWebsiteCommands.php`
- Contains: `ImportWebsiteCommands` (extends `DrushCommands`) — discovery (`discoverPages`, `fromSitemap`, `fromCrawl`), per-page import (`importPage`, `callLlmBridge`, `buildParagraph`), image download (`downloadImage`), HTML helpers
- Depends on: `config.factory`, `entity_type.manager`, `http_client` (Guzzle), `file_system` Drupal services; optionally `CINATRA_BASE_URL` env var for container-to-container routing
- Used by: Operators via `drush cinatra:import-website <url>`

## Data Flow

### Widget Render Path (per page request)

1. Drupal invokes `cinatra_page_attachments()` (`cinatra.module:43`) — checks auth + route.
2. Reads `cinatra.settings` config for `cinatra_url` and `api_key`.
3. Attaches `cinatra/fallback` library and populates `drupalSettings.cinatra.cinatraUrl` always (when URL set).
4. If `api_key` is set, also attaches `cinatra/bundle` with full `drupalSettings.cinatra` context (nodeId, nodeBundle, nodeStatus, contractVersion, instanceId).
5. `cinatra_library_info_alter()` (`cinatra.module:152`) rewrites the placeholder bundle URL to `{cinatra_url}/api/drupal/bundle.js` at library load time.
6. `cinatra_page_bottom()` (`cinatra.module:112`) injects `#cinatra-root` div + fallback button/error card HTML at the bottom of the page.
7. Browser loads `bundle.js` from Cinatra origin; IIFE mounts Shadow DOM on `#cinatra-root`, hides fallback button.

### Drush Import Path

1. Operator runs `drush cinatra:import-website <url> [--lang=de] [--limit=N]`.
2. `ImportWebsiteCommands::importWebsite()` resolves Cinatra URL (prefers `CINATRA_BASE_URL` env, falls back to stored config).
3. `discoverPages()` tries `sitemap.xml` → `sitemap_index.xml` → HTML crawl to build URL list.
4. For each URL: `importPage()` fetches HTML, `extractText()` strips noise and caps at 12 000 chars.
5. `callLlmBridge()` POSTs to `{cinatraUrl}/api/llm-bridge` with a structured prompt; receives JSON `{title, sections[]}`.
6. `buildParagraph()` maps each section type to a Drupal `Paragraph` entity using a `match` field map; `downloadImage()` fetches remote images into `public://imported/`.
7. Creates or updates a `landing_page` Node with `field_sections` pointing to the saved paragraphs; sets a URL alias matching the source path.

**State Management:**
- All persistent state lives in Drupal config (`cinatra.settings`) and standard Drupal entities (Node, Paragraph, File).
- No custom database tables. No module-level PHP singletons.

## Key Abstractions

**`CINATRA_CONTRACT_VERSION`:**
- Purpose: Version string sent in `drupalSettings` so the remote widget can reject incompatible Drupal module versions.
- Examples: `cinatra.module:18` (`const CINATRA_CONTRACT_VERSION = 'v1'`)
- Pattern: Module-level PHP constant; checked by the remote bundle JS, not by PHP.

**`cinatra/fallback` library:**
- Purpose: Always-present UI chrome (floating button + error card) that degrades gracefully when the remote bundle is unreachable or unconfigured.
- Examples: `cinatra.libraries.yml:24`, `js/cinatra-fallback.js`, `css/cinatra-fallback.css`
- Pattern: Attached unconditionally when `cinatra_url` is set (even if `api_key` is missing), hides itself once the real bundle mounts.

**LLM Paragraph Schema:**
- Purpose: Defines the set of structured Drupal paragraph types the LLM bridge can produce: `hero_section`, `feature_card`, `feature_cards_section`, `benefit_item`, `benefits_section`, `cloud_features_section`, `stats_section`, `contact_section`, `downloads_section`, `text_section`.
- Examples: `src/Drush/ImportWebsiteCommands.php:352` (`buildParagraph` match)
- Pattern: System prompt enforces JSON schema; `buildParagraph` maps type strings to Drupal field names via `match`.

## Entry Points

**`cinatra_page_attachments()`:**
- Location: `cinatra.module:43`
- Triggers: Every Drupal page render (hook invoked by core)
- Responsibilities: Guard check, config read, library attachment, drupalSettings population

**`cinatra_page_bottom()`:**
- Location: `cinatra.module:112`
- Triggers: Every Drupal page render, after attachments
- Responsibilities: Injects `#cinatra-root` div and fallback button/error HTML

**`cinatra_library_info_alter()`:**
- Location: `cinatra.module:152`
- Triggers: Drupal library discovery (cached; cleared on settings save)
- Responsibilities: Rewrites placeholder bundle URL to configured Cinatra origin

**`SettingsForm`:**
- Location: `src/Form/SettingsForm.php`
- Triggers: Admin visits `/admin/config/services/cinatra`
- Responsibilities: Display and save `cinatra_url`, `api_key`, `instance_id`; clear library cache on save

**`ImportWebsiteCommands::importWebsite()`:**
- Location: `src/Drush/ImportWebsiteCommands.php:62`
- Triggers: `drush cinatra:import-website <url>` (alias `ciw`)
- Responsibilities: Full crawl → LLM → entity creation pipeline

## Architectural Constraints

- **Threading:** Single-threaded PHP; the Drush import is synchronous and sequential per page.
- **Global state:** `\Drupal::config()`, `\Drupal::routeMatch()`, `\Drupal::currentUser()` static calls are used throughout `cinatra.module` (standard Drupal procedural hook pattern). `ImportWebsiteCommands` avoids statics — uses injected services.
- **Circular imports:** None detected.
- **Drupal version:** Requires Drupal `^10.3 || ^11` and the `drupal:node` + `drupal:user` core modules.
- **Paragraphs dependency:** `ImportWebsiteCommands` depends on the contributed `paragraphs` module (`Drupal\paragraphs\Entity\Paragraph`) but this is not declared in `cinatra.info.yml` — callers must ensure it is installed.
- **Cache:** Library definitions are cached by Drupal; `SettingsForm::submitForm()` explicitly calls `library.discovery->clearCachedDefinitions()` to pick up the new bundle URL.

## Anti-Patterns

### Static service calls in procedural hooks

**What happens:** `cinatra.module` uses `\Drupal::config()`, `\Drupal::routeMatch()`, `\Drupal::currentUser()`, `\Drupal::service()` directly throughout hook implementations.
**Why it's wrong:** Harder to unit test; tight coupling to global container.
**Do this instead:** This is idiomatic for Drupal procedural hooks — acceptable here. New OOP code (Forms, Commands) correctly uses injected services.

### Missing `paragraphs` module dependency

**What happens:** `ImportWebsiteCommands` uses `Drupal\paragraphs\Entity\Paragraph` without declaring `drupal:paragraphs` in `cinatra.info.yml`.
**Why it's wrong:** Module will fail with a class-not-found fatal if paragraphs is not installed, with no Drupal-level warning.
**Do this instead:** Add `- drupal:paragraphs` (or the contributed module machine name) to the `dependencies` key in `cinatra.info.yml`.

## Error Handling

**Strategy:** Fail-soft for the widget (fallback chrome shown); throw `\RuntimeException` for Drush import per-page errors (caught at the loop level, logged as warnings, import continues).

**Patterns:**
- Widget: empty `cinatra_url` or `api_key` causes early return in hooks — no error surfaced to end user, fallback chrome explains misconfiguration when clicked.
- Drush: `\Throwable` caught per page in `importWebsite()` loop; page is skipped with a warning log. Fatal configuration errors (no URL) cause early return from the command.
- HTTP errors in `ImportWebsiteCommands`: Guzzle `RequestException` → `\RuntimeException` rethrown with context. Image download failures are silently ignored (debug-level log).

## Cross-Cutting Concerns

**Logging:** Drush commands use `$this->logger()` (PSR-3 compatible, provided by `DrushCommands`). Module hooks use no logging.
**Validation:** `cinatra_url` field uses Drupal `#type: url` (HTML5 validation + server-side). No additional input sanitisation beyond Drupal defaults.
**Authentication:** Widget applies only to authenticated users (`\Drupal::currentUser()->isAuthenticated()`). Admin form requires `administer site configuration` permission (declared in `cinatra.routing.yml`). API key passed to browser via `drupalSettings` — stored in Drupal active config (not environment).

---

*Architecture analysis: 2026-06-09*
