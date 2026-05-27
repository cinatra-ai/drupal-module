# Cinatra — Drupal module

Embeds the [Cinatra](https://cinatra.ai) AI assistant on Drupal node pages so
editors can draft and revise content with an in-context chat assistant. The
module talks to your Cinatra instance over HTTP only — the assistant bundle is
loaded over HTTP (Apache-2.0) and is not statically linked into this GPL module
(`gpl-compatible: true`).

## What it does

- Attaches the assistant bundle (`{your-cinatra-url}/api/drupal/bundle.js`) on
  node canonical view, node edit forms, and the front page, for authenticated
  users only.
- Injects a `#cinatra-root` mount point via `hook_page_bottom()`.
- Exposes an admin settings form at **Configuration → Web services → Cinatra**
  (`/admin/config/services/cinatra`) for the Cinatra URL, API key, and instance ID.
- Ships a `drush cinatra:import-website` command for bulk content import.

## Install (end users)

1. Install the module (`composer require drupal/cinatra` once published, or place
   it under `modules/custom/cinatra/`).
2. Enable it: `drush en cinatra`.
3. In Cinatra, open `/settings/connectors/drupal-widget` and generate an API key.
4. In Drupal, open **Configuration → Web services → Cinatra** and paste the
   Cinatra URL, API key, and instance ID. Save.

Upgrading from the pre-release `cinatra_widget` module? `hook_install()` performs
a one-shot copy of `cinatra_widget.settings` → `cinatra.settings`.

## Plugin ↔ core contract

The module sends `contractVersion: "v1"` in its bootstrap. Cinatra validates it
and rejects unknown versions with an admin-visible error. The contract schemas
live in the cinatra repo under `contracts/wp-drupal-assistant/`.

## Development

This repo is the source of truth for the module. Cinatra developers consume it
as a local clone for the dev docker stack. See the cinatra repo:
`docs/developer/wp-drupal-plugin-development.md` for the multi-repo workflow,
the contract-version bump checklist, and dirty-tree recovery.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
