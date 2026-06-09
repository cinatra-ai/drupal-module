# Codebase Structure

**Analysis Date:** 2026-06-09

## Directory Layout

```
drupal-module/
├── .github/
│   ├── CODEOWNERS              # Code ownership rules
│   └── workflows/
│       └── ci.yml              # CI pipeline definition
├── .planning/
│   └── codebase/               # GSD codebase map documents (this file)
├── config/
│   └── install/
│       └── cinatra.settings.yml  # Config schema installed with module (intentionally empty values)
├── css/
│   └── cinatra-fallback.css    # Fallback chrome styles (floating button + error card)
├── js/
│   └── cinatra-fallback.js     # Fallback chrome behaviour (shows error, hides on bundle mount)
├── src/
│   ├── Drush/
│   │   └── ImportWebsiteCommands.php  # Drush command: cinatra:import-website
│   └── Form/
│       └── SettingsForm.php    # Admin settings form at /admin/config/services/cinatra
├── cinatra.info.yml            # Module metadata, Drupal version requirement, dependencies
├── cinatra.install             # hook_install() — legacy config migration from cinatra_widget
├── cinatra.libraries.yml       # Library definitions: cinatra/bundle, cinatra/fallback
├── cinatra.module              # All hook implementations
├── cinatra.routing.yml         # Admin route: cinatra.settings_form
└── LICENSE                    # Module license
```

## Directory Purposes

**`src/Form/`:**
- Purpose: OOP Drupal Form plugins (PSR-4 under `Drupal\cinatra\Form`)
- Contains: `SettingsForm.php` — the only admin form
- Key files: `src/Form/SettingsForm.php`

**`src/Drush/`:**
- Purpose: OOP Drush command classes (PSR-4 under `Drupal\cinatra\Drush`)
- Contains: `ImportWebsiteCommands.php` — the `cinatra:import-website` Drush command
- Key files: `src/Drush/ImportWebsiteCommands.php`

**`config/install/`:**
- Purpose: Default configuration installed when the module is enabled
- Contains: `cinatra.settings.yml` — intentionally empty (values set at runtime via admin form)
- Key files: `config/install/cinatra.settings.yml`

**`css/` and `js/`:**
- Purpose: Static frontend assets for the local fallback library only; the real widget bundle is fetched remotely
- Contains: `cinatra-fallback.css`, `cinatra-fallback.js`

**`.github/workflows/`:**
- Purpose: CI automation
- Key files: `.github/workflows/ci.yml`

## Key File Locations

**Entry Points:**
- `cinatra.module`: All Drupal hook implementations — widget attachment, DOM injection, library URL rewrite
- `cinatra.routing.yml`: Admin route declaration (`/admin/config/services/cinatra`)

**Configuration:**
- `cinatra.info.yml`: Module descriptor — name, description, Drupal core version requirement (`^10.3 || ^11`), dependencies (`drupal:node`, `drupal:user`)
- `cinatra.libraries.yml`: Drupal library declarations for `cinatra/bundle` and `cinatra/fallback`
- `config/install/cinatra.settings.yml`: Config schema (empty defaults; values populated at runtime)

**Core Logic:**
- `cinatra.module`: `_cinatra_widget_applies()`, `cinatra_page_attachments()`, `cinatra_page_bottom()`, `cinatra_library_info_alter()`
- `src/Form/SettingsForm.php`: Settings persistence and library cache invalidation
- `src/Drush/ImportWebsiteCommands.php`: Web crawl, LLM bridge call, Node/Paragraph entity creation

**Install / Upgrade:**
- `cinatra.install`: `cinatra_install()` — one-time migration from legacy `cinatra_widget.settings` config

## Naming Conventions

**Files:**
- Module hook files: `{machine_name}.{hook_type}` — e.g., `cinatra.module`, `cinatra.install`, `cinatra.routing.yml`, `cinatra.libraries.yml`
- Config files: `{machine_name}.{config_object}.yml` — e.g., `cinatra.settings.yml`
- OOP classes: PascalCase matching the class name — e.g., `SettingsForm.php`, `ImportWebsiteCommands.php`
- Static assets: kebab-case with module prefix — e.g., `cinatra-fallback.css`, `cinatra-fallback.js`

**Directories:**
- PSR-4 source: `src/` with subdirectories matching the namespace segment (`Form/`, `Drush/`)
- Drupal config: `config/install/` for install-time defaults

**PHP Namespaces:**
- `Drupal\cinatra\Form` → `src/Form/`
- `Drupal\cinatra\Drush` → `src/Drush/`

**Hook functions:**
- Follow Drupal convention: `{module_name}_{hook_name}()` — e.g., `cinatra_page_attachments()`, `cinatra_library_info_alter()`
- Private helpers prefixed with `_`: `_cinatra_widget_applies()`

**Config object:**
- `cinatra.settings` (single config object for all module settings)

## Where to Add New Code

**New admin form or settings page:**
- Implementation: `src/Form/` (new `*Form.php` extending `ConfigFormBase` or `FormBase`)
- Route: add to `cinatra.routing.yml`

**New Drush command:**
- Implementation: `src/Drush/` (new `*Commands.php` extending `DrushCommands`)

**New hook implementation:**
- Implementation: `cinatra.module` — add the hook function following existing pattern
- If it's a schema/install hook: `cinatra.install`

**New library (CSS/JS):**
- Static assets: `css/` or `js/`
- Library declaration: `cinatra.libraries.yml`

**New default configuration:**
- Config schema YAML: `config/install/cinatra.{config_name}.yml`

**New OOP service or plugin:**
- Source: `src/{PluginType}/` — create the subdirectory following PSR-4
- Register in `cinatra.services.yml` if a Drupal service (file does not yet exist — create it at the root alongside `cinatra.module`)

## Special Directories

**`.planning/codebase/`:**
- Purpose: GSD codebase map documents consumed by `/gsd-plan-phase` and `/gsd-execute-phase`
- Generated: Yes (by `/gsd-map-codebase`)
- Committed: Yes (project planning artefacts)

**`config/install/`:**
- Purpose: Drupal configuration exported and imported via CMI; installed automatically on `drush en cinatra`
- Generated: No (hand-authored)
- Committed: Yes

---

*Structure analysis: 2026-06-09*
