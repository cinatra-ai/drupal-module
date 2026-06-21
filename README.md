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
- Provides **one-click "Connect with Cinatra"** provisioning: the admin enters
  only the instance URL, approves a consent screen on Cinatra, and the
  integration credential is fetched server-side automatically — no key is ever
  copied or pasted (a connection-string fallback covers no-redirect
  environments). See [cinatra-ai/cinatra#221](https://github.com/cinatra-ai/cinatra/issues/221).
- Exposes an admin settings form at **Configuration → Web services → Cinatra**
  (`/admin/config/services/cinatra`) with the Connect button, the connection
  fallback, and manual fields (Cinatra URL, API key held server-side only,
  instance ID) for advanced setups.

## Install (end users)

1. Install the module (`composer require drupal/cinatra` once published, or place
   it under `modules/custom/cinatra/`).
2. Enable it: `drush en cinatra`.
3. In Drupal, open **Configuration → Web services → Cinatra**, enter your Cinatra
   instance URL, and click **Connect with Cinatra**. Approve the connection on
   the Cinatra consent screen; the integration credential is provisioned and
   stored on this server automatically. (No browser redirect? Expand the
   connection-string fallback and paste the one-line code from Cinatra. Or use
   **Manual configuration** to paste the URL, API key, and instance ID by hand.)
4. Grant the **“Use the Cinatra AI assistant”** permission to the roles that
   should see the assistant (People → Permissions). The widget no longer loads
   for every authenticated user.

Upgrading from the pre-release `cinatra_widget` module? `hook_install()` performs
a one-shot copy of `cinatra_widget.settings` → `cinatra.settings`.

## Permissions

- **Use the Cinatra AI assistant** (`use cinatra assistant`, marked
  *restricted*): loads the assistant on node pages and authorizes short-lived
  streaming-token exchange. The widget can read the current page context and
  propose content edits, so grant it only to trusted content editors.

## Connecting (provisioning)

The recommended way to provision the credential is **one-click Connect**
(cinatra#221), an OAuth-style handshake:

1. You enter only the instance URL and click **Connect with Cinatra**.
2. Your browser is redirected to a Cinatra consent screen, where an org admin
   approves the connection (PKCE S256 + a single-use `state` protect the
   round-trip).
3. Drupal exchanges the returned short-lived code **server-side** at
   `{cinatra-url}/api/connect/token` for the long-lived per-site credential and
   stores it in `cinatra.settings`. The credential never reaches the browser.

For environments where the browser redirect is not viable, Cinatra can emit a
one-line **connection string** (URL + a one-time install code); paste it under
*No browser redirect?* and Drupal performs the same server-side exchange.

The **Manual configuration** section remains for advanced/air-gapped setups
where you set the URL, API key, and instance ID by hand.

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
  short-lived token. (When the optional `CINATRA_BASE_URL` environment variable
  is set — used for containerized topologies where the configured browser-facing
  URL is not reachable from the server — this one server-side token call uses
  that base instead; nothing the browser receives changes. The value is
  validated as a bare `http(s)://host[:port]` origin: a malformed override —
  wrong scheme, embedded credentials, or a path/query/fragment — is rejected and
  the configured URL is used, so a key-bearing request can never be redirected
  to an unvalidated host.)
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
as a local clone for the dev docker stack.

**Widget source of truth (cinatra#411).** The bundled `js/cinatra-widget.js` is a
**canonical, locally-shipped** widget — NOT a re-vendor of any Cinatra host
route. The host `bundle.js` routes in the cinatra repo
(`src/app/api/{wordpress,drupal}/bundle.js/route.ts`) are the **deprecated,
pre-Option-A** artifact: nothing executes them and they are scheduled for
removal. Do **not** re-vendor from them.

The canonical widget sources are the two vendored copies, kept in lockstep by
review (there is no generator):

- `cinatra-ai/wordpress-plugin/assets/cinatra-widget.js` — **authored first**;
- `cinatra-ai/drupal-module/js/cinatra-widget.js` (this repo) — **hand-mirrored**
  from the WordPress copy (the two differ only in the CMS-config accessor
  `drupalSettings.cinatra` vs `CinatraConfig`, plus library/asset plumbing).

A widget change is authored in the WordPress copy, then mirrored here.
`tools/widget-parity-check.mjs` (run in CI) asserts the security-critical
invariants on this repo's copy and the shared contract-version marker. Changes
reach an installed site only via a **Drupal.org module release** + the site admin
taking the update — there is no live push from a Cinatra instance. See the full
contract in the cinatra repo: `docs/widget-source-of-truth.md`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant widget
(`js/cinatra-widget.js`) is the Cinatra app frontend under Apache-2.0, which is
GPL-compatible.
