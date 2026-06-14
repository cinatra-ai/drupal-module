# Cinatra — Drupal module

Embeds the [Cinatra](https://cinatra.ai) AI assistant on Drupal node pages so
editors can draft and revise content with an in-context chat assistant. The
assistant widget is shipped **locally** with this module (`js/cinatra-widget.js`,
Apache-2.0, GPL-compatible) — it is no longer fetched as remote code over HTTP.
Your Cinatra instance is treated purely as a versioned data API.

## What it does

- Attaches the local assistant widget on node canonical view, node edit forms,
  and the front page, **only for users with the “Use the Cinatra AI assistant”
  permission**.
- Injects a `#cinatra-root` mount point via `hook_page_bottom()`.
- Mints **short-lived streaming tokens** server-side: the browser never holds
  the long-lived integration key. A same-origin broker route (`/cinatra/token`,
  CSRF-protected, permission-gated) exchanges the stored key for a short-lived,
  origin/audience/scope-bound token at your Cinatra instance, and the widget
  streams to the instance with that token.
- Negotiates capabilities and contract version with the instance at boot
  (`GET {cinatra-url}/api/agents/drupal-content-editor/capabilities`), falling
  back gracefully against older instances.
- Exposes an admin settings form at **Configuration → Web services → Cinatra**
  (`/admin/config/services/cinatra`) for the Cinatra URL, API key (held
  server-side only), and instance ID.
- Ships a `drush cinatra:import-website` command for bulk content import.

## Install (end users)

1. Install the module (`composer require drupal/cinatra` once published, or place
   it under `modules/custom/cinatra/`).
2. Enable it: `drush en cinatra`.
3. In Cinatra, open `/settings/connectors/drupal-widget` and generate an API key.
4. In Drupal, open **Configuration → Web services → Cinatra** and paste the
   Cinatra URL, API key, and instance ID. Save.
5. Grant the **“Use the Cinatra AI assistant”** permission to the roles that
   should see the assistant (People → Permissions). The widget no longer loads
   for every authenticated user.

Upgrading from the pre-release `cinatra_widget` module? `hook_install()` performs
a one-shot copy of `cinatra_widget.settings` → `cinatra.settings`.

## Permissions

- **Use the Cinatra AI assistant** (`use cinatra assistant`, marked
  *restricted*): loads the assistant on node pages and authorizes short-lived
  streaming-token exchange. The widget can read the current page context and
  propose content edits, so grant it only to trusted content editors.

## Credential model

The long-lived Cinatra integration key is a **server-side credential**. It is
stored in `cinatra.settings.api_key`, rendered in the settings form as a
password field (never echoed back), and used **only** server-to-server by the
`/cinatra/token` broker route. It is never placed into `drupalSettings`, never
sent to client JavaScript, and never present in a browser network request. The
browser receives only short-lived (5-minute) tokens bound to this site’s origin
and to the assistant stream endpoint.

## External services

This module connects your Drupal site to **your Cinatra instance** — the URL you
configure at `/admin/config/services/cinatra` (for example a self-hosted Cinatra
or a Cinatra-operated instance). It is not a fixed third-party endpoint; it is
the instance you choose.

When an editor uses the assistant on a page, the following is sent to your
configured Cinatra instance:

- **From the browser (after authorization):** the chat messages the editor
  types, and page context (the current page URL, and on node pages the node ID,
  bundle, and publish status), streamed to
  `{cinatra-url}/api/agents/drupal-content-editor/stream`.
- **Server-to-server (from Drupal, never the browser):** your long-lived API key
  is sent only to `{cinatra-url}/api/agents/drupal-content-editor/token` to
  exchange it for a short-lived token. The browser then streams using that
  short-lived token.
- **At boot (no auth):** the widget reads static capability metadata from
  `{cinatra-url}/api/agents/drupal-content-editor/capabilities`.

The assistant widget JavaScript is bundled with this module and runs locally; it
is **not** loaded as remote code. Your Cinatra instance’s data handling and
privacy terms govern the data sent to it; see <https://cinatra.ai> for the
Cinatra privacy terms.

## Plugin ↔ core contract

The module sends `contractVersion: "v2"` in its bootstrap and token exchange.
Cinatra validates it and rejects unknown versions with an admin-visible error;
the bundled widget negotiates the highest mutually-supported version and falls
back to `v1` against instances that predate the local/token-exchange flow. The
contract schemas live in the cinatra repo under `contracts/wp-drupal-assistant/`.

> **Note:** the apiKey-free local flow (token exchange + capabilities endpoint)
> requires the matching instance-side change (cinatra-ai/cinatra#220). Against an
> instance that does not yet support token exchange, the widget surfaces a
> one-line “update Cinatra” notice instead of streaming.

## Development

This repo is the source of truth for the module. Cinatra developers consume it
as a local clone for the dev docker stack. The bundled `js/cinatra-widget.js` is
vendored from the cinatra repo’s `src/app/api/drupal/bundle.js/route.ts` (the
canonical IIFE) and adapted to the token-exchange flow; re-vendor it from that
route when the upstream bundle changes. See the cinatra repo:
`docs/developer/wp-drupal-plugin-development.md` for the multi-repo workflow,
the contract-version bump checklist, and dirty-tree recovery.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant widget
(`js/cinatra-widget.js`) is the Cinatra app frontend under Apache-2.0, which is
GPL-compatible.
