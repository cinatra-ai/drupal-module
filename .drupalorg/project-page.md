<!--
  Drupal.org project-page source of truth for the Cinatra module.

  This file is staged content for the drupal.org project node + Project Browser.
  The drupal.org project is PARKED (not yet created); when it is created, copy
  the sections below into the matching drupal.org fields and promote the logo
  (see "Logo" + ../.gitattributes note). This whole .drupalorg/ directory is
  export-ignored from the release tarball, so none of it ships to end users.

  Brand source: cinatra-ai/design (tokens/brand.json, assets/logo/variants.json).
  Voice/marking rules: design/TRADEMARK.md — the word mark is PENDING, use TM
  (the ™ glyph) never (R); write "open source" unhyphenated.
-->

# Cinatra — Drupal.org project page

## Project short name (machine name)

`cinatra` — so the project URL is `https://www.drupal.org/project/cinatra` and
`composer require drupal/cinatra` will resolve once the drupal.org project and a
release exist. (Matches `cinatra.info.yml` and `composer.json` `drupal/cinatra`.)

## Title

Cinatra

## Short description / "Edit Summary" (Project Browser card, max 200 chars)

> Embed the Cinatra AI assistant on Drupal node pages so editors draft and revise content in context — with permission-gated access and server-side short-lived tokens (no secrets in the browser).

(193 characters — within Project Browser's 200-character limit.)

## Categories (Project Browser — pick up to 3)

1. **Content** (content authoring / editorial workflow)
2. **AI** (AI-assisted editing) — or "Content Display / Editor" if an AI
   category is unavailable at submission time
3. **Third-party Integration** (connects Drupal to a Cinatra instance)

> Confirm the exact category names available in the drupal.org project form at
> creation time; these are the intended buckets.

## Maintenance status

Actively maintained

## Development status

Under active development

## Full project-page description (body)

<!-- Paste as the project node body. The ™ on first use of "Cinatra" reflects the
     pending word-mark status (design/TRADEMARK.md). drupal.org project bodies are
     filtered HTML, NOT Markdown: at paste time, convert the headings/bold/`code`
     below to <h3>/<strong>/<code> (or the body field's WYSIWYG equivalents).
     Keep links absolute. -->

### Cinatra for Drupal

Cinatra™ is the open source AI workspace. This module embeds the Cinatra AI
assistant directly on your Drupal node pages, so content editors can draft,
expand, and revise content in a chat panel that already knows the page they are
working on — without leaving the editorial screen.

The assistant connects to **your** Cinatra instance (self-hosted or
Cinatra-operated) — the URL you configure. It is not a fixed third-party SaaS
endpoint; you choose and control the instance your content is sent to.

### What it does

- Adds an in-context AI assistant panel on node canonical pages, node edit
  forms, and the front page — shown **only** to users who hold the
  "Use the Cinatra AI assistant" permission.
- Reads the current page context (page URL, and on node pages the node ID,
  bundle, and publish status) so suggestions are relevant to what the editor is
  editing.
- Streams the conversation to your Cinatra instance's content-editor agent so
  the assistant can **propose** content edits the editor reviews and uses. When
  the instance advertises the apply-changes capability, proposed field changes
  can be applied to the form; otherwise the assistant still drafts and advises in
  the panel.

### Security model — no secrets in the browser

This is the headline design property, and reviewers should note it:

- The long-lived Cinatra integration key is a **server-side credential only**.
  It is stored in Drupal config, never placed in `drupalSettings`, never sent to
  client JavaScript, and never present in any browser network request.
- The browser receives only **short-lived (5-minute) streaming tokens** that are
  bound to this site's origin and to the assistant stream endpoint. A
  same-origin, CSRF-protected, permission-gated broker route mints them
  server-to-server.
- The assistant widget JavaScript is **bundled locally with the module** (under
  `js/`, Apache-2.0, GPL-compatible). It is **not** fetched as remote code over
  HTTP. Your Cinatra instance is treated purely as a versioned data API.

### One-click "Connect with Cinatra"

Provisioning is an OAuth-style handshake — the admin never copies or pastes a
secret:

1. Enter only your Cinatra instance URL and click **Connect with Cinatra**.
2. Approve the connection on the Cinatra consent screen (PKCE S256 + a
   single-use `state` protect the round-trip).
3. Drupal exchanges the returned short-lived code **server-side** for the
   per-site credential and stores it. The credential never reaches the browser.

A one-line **connection-string** fallback covers environments where the browser
redirect is not viable, and a **Manual configuration** section remains for
advanced / air-gapped setups (URL, API key, instance ID by hand).

### Requirements

- Drupal core `^10.3 || ^11`.
- A Cinatra instance you can reach, with the assistant agent enabled. The
  apiKey-free local flow (server-side token exchange + capabilities negotiation)
  requires a recent Cinatra instance; against an older instance the widget shows
  a one-line "update Cinatra" notice instead of streaming.

### Installation

1. `composer require drupal/cinatra` (or place the module under
   `modules/custom/cinatra/`).
2. `drush en cinatra`.
3. Go to **Configuration → Web services → Cinatra**
   (`/admin/config/services/cinatra`), enter your Cinatra instance URL, and click
   **Connect with Cinatra**.
4. Grant the **"Use the Cinatra AI assistant"** permission to trusted content
   editors (People → Permissions). The assistant loads only for those roles.

### Permissions

- **Use the Cinatra AI assistant** (`use cinatra assistant`, marked *restricted*)
  — loads the assistant and authorizes short-lived streaming-token exchange. The
  assistant can read page context and propose content edits, so grant it only to
  trusted editors.

### Privacy / data handling

When an editor uses the assistant, the chat messages they type and the page
context described above are streamed to the Cinatra instance you configured.
That instance's data-handling and privacy terms govern this data. See
<https://cinatra.ai> for the Cinatra privacy terms. No data is sent to any
endpoint other than the instance you configure.

### License

GPL-2.0-or-later. The bundled assistant widget is the Cinatra app frontend under
Apache-2.0 (GPL-compatible).

## Resources / links (project-page "Resources" links)

- Cinatra website: <https://cinatra.ai>
- Source repository (canonical): <https://github.com/cinatra-ai/drupal-module>
- Issue queue: use the drupal.org project issue queue once the project is created
  (mirror/triage policy with the GitHub repo to be set at creation time).
- License (GPL-2.0-or-later): see `LICENSE` in the repository.
- Trademark policy: the "Cinatra" name and fedora logo are trademarks of the
  project owners; see the brand repo `TRADEMARK.md`. The word mark is pending
  registration — use ™, not ®.

## Logo

The Project Browser project logo is generated from the Cinatra brand and stored
in `.drupalorg/images/`:

- `.drupalorg/images/logo.png` — **512×512 PNG**, no animation, square corners,
  ~1.3 KB (well under the ~10 KB Project Browser guidance; compressed with
  pngquant). The sanctioned brand **app-icon** colourway: the mustard fedora mark
  (`#c79545`) on the full-bleed navy ground (`#15213a`).
- `.drupalorg/images/logo_svg.txt` — the vector master for crisp rendering.

Regenerate with `node .drupalorg/generate-logo.mjs` (see that script's header).

**Promotion at project-creation time** (Project Browser requires the files at the
repo *root* on the default branch):

```
cp .drupalorg/images/logo.png      ./logo.png
cp .drupalorg/images/logo_svg.txt  ./logo_svg.txt
git add logo.png logo_svg.txt && git commit -m "chore: add Project Browser logo"
```

Do not also add a logo to the project page "Images" field — Project Browser uses
the repo-root `logo.png`, and a duplicate in the Images field is deprecated.

> Why the app-icon colourway and not the favicon: the favicon is a mustard fedora
> on a white chip with a rounded corner and a hairline border. Project Browser
> explicitly asks for **no rounded corners baked into the PNG** (it applies its
> own mask) and renders on its own surface, so the white-chip favicon is wrong
> here. The app icon is full-bleed navy with square corners — exactly what
> Project Browser wants. Brand source: `design/assets/logo/variants.json`
> (`applications.appIcon`) and `design/tokens/brand.json`.

## Screenshots — plan + alt text

Drupal.org project pages still support a screenshots/Images field for editorial
screenshots (separate from the deprecated logo-in-Images use). These are not yet
captured because they require a running Drupal + Cinatra instance; capture them
against the live-verify stack at project-creation time and attach to the project
page. Recommended set, in order, with alt text:

1. **`01-connect-settings.png` — the Connect settings form.**
   The **Configuration → Web services → Cinatra** admin form, showing the
   instance-URL field and the **Connect with Cinatra** button, with the
   connection-string fallback and Manual configuration sections collapsed below.
   - *Alt text:* "Cinatra admin settings form in Drupal showing the instance URL
     field and a Connect with Cinatra button, with collapsed connection-string
     and manual-configuration sections."
   - *Capture:* `/admin/config/services/cinatra` as an administrator, before
     connecting. Browser viewport 1280×800; crop to the form region.

2. **`02-consent-handshake.png` — the Cinatra consent screen.**
   The Cinatra-side OAuth-style consent screen reached after clicking Connect,
   where an org admin approves the per-site connection.
   - *Alt text:* "Cinatra consent screen approving a connection from a Drupal
     site, part of the one-click Connect handshake."
   - *Capture:* the redirected consent page during the Connect flow.

3. **`03-assistant-on-edit.png` — the assistant on a node edit form.**
   A node edit form with the Cinatra assistant panel open alongside the body
   field, mid-conversation, the assistant proposing a content edit.
   - *Alt text:* "Drupal node edit form with the Cinatra AI assistant panel open
     beside the body field, showing a suggested content revision the editor can
     apply."
   - *Capture:* edit any node as a user holding "Use the Cinatra AI assistant";
     open the assistant and run one prompt. Redact any real instance host.

4. **`04-permission-gate.png` — the permission row.**
   The People → Permissions screen with the **Use the Cinatra AI assistant**
   permission row highlighted, showing it marked restricted.
   - *Alt text:* "Drupal permissions page highlighting the restricted Use the
     Cinatra AI assistant permission and the roles it is granted to."
   - *Capture:* `/admin/people/permissions`, filter to the Cinatra permission.

**Screenshot conventions:** PNG; light Drupal admin theme (Claro) for the Drupal
captures; redact real hostnames/keys; keep each image's longest edge ≤ 1280px so
the page stays light. Store final screenshots under `.drupalorg/images/` named as
above, and attach them to the project page's Images field at creation time. Like
the logo, they are export-ignored and never ship in the release tarball.
