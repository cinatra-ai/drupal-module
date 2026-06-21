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

> Embed the Cinatra AI assistant on Drupal node pages so editors can draft, expand, and revise content in a chat panel right next to what they are editing — without leaving the page.

(176 characters — within Project Browser's 200-character limit.)

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

Cinatra™ is the open source AI workspace. This module puts the Cinatra AI
assistant right on your Drupal pages, so content editors can draft, expand, and
revise content in a chat panel next to what they are editing — without leaving
the page they are working on.

The assistant talks to **your** Cinatra instance — the one whose address you
enter in the settings. You choose and control which instance your content goes
to; it is not tied to a fixed outside service.

### What it does for editors

- Adds an AI assistant panel on node pages, node edit forms, and the front page,
  so help is one click away while you write.
- Knows the page you are on, so its suggestions fit the content you are actually
  editing.
- Drafts and rewrites text on request. The editor always reviews what the
  assistant suggests and decides what to keep; when your instance supports it,
  suggested changes can be dropped straight into the form.
- Shows the assistant **only** to the people you choose, using a dedicated
  permission (see below) — not to every logged-in user.

### Getting started

1. Install the module: `composer require drupal/cinatra` (or place it under
   `modules/custom/cinatra/`).
2. Enable it: `drush en cinatra`.
3. Go to **Configuration → Web services → Cinatra**
   (`/admin/config/services/cinatra`), enter your Cinatra instance address, and
   click **Connect with Cinatra**. Approve the connection on the screen that
   appears, and you are set up. (No redirect in your setup? Paste the one-line
   connection code instead, or use the **Manual configuration** section.)
4. On **People → Permissions**, give the **"Use the Cinatra AI assistant"**
   permission to the content editors who should see the assistant.

### Requirements

- Drupal core `^10.3 || ^11`.
- A Cinatra instance you can reach, with the assistant turned on. With an older
  Cinatra instance the panel shows a short "update Cinatra" notice instead of
  the assistant.

### Who can use it

The assistant appears only for users who have the **"Use the Cinatra AI
assistant"** permission. The assistant can read the current page and suggest
content changes, so give this permission to people you trust to edit content.

### Your content

When an editor chats with the assistant, the messages they type and the page
they are on are sent to the Cinatra instance you set up — and nowhere else. That
instance's own privacy terms cover this data; see <https://cinatra.ai>.

### License

GPL-2.0-or-later. The bundled assistant is the Cinatra app frontend under
Apache-2.0, which is compatible with the GPL.

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
  ~2 KB (well under the ~10 KB Project Browser guidance; compressed with
  pngquant). The sanctioned brand **primary (mustard)** colourway: the mustard
  fedora mark (`#c79545`) on a white/paper ground (`#ffffff`).
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

> Why mustard-on-white and not the navy ground: the Cinatra brand rule is
> "mustard on paper or surface; never mustard on the navy ground"
> (`design/assets/logo/variants.json`, `meta.rule`). The mustard fedora reads
> correctly on a white/paper ground, so that is the colourway used here. The PNG
> is rendered with **square corners and no border** — Project Browser applies its
> own mask and renders the tile on its own surface, so we bake in neither a
> corner radius nor a chip. Brand source: `design/assets/logo/variants.json`
> (`colorways.mustard`) and `design/tokens/brand.json`.

## Screenshots — plan + alt text

Drupal.org project pages still support a screenshots/Images field for editorial
screenshots (separate from the deprecated logo-in-Images use). These are not yet
captured because they require a running Drupal + Cinatra instance; capture them
against the live-verify stack at project-creation time and attach to the project
page. Recommended set, in order, with alt text:

1. **`01-connect-settings.png` — the settings form.**
   The **Configuration → Web services → Cinatra** admin form, showing the
   instance-address field and the **Connect with Cinatra** button.
   - *Alt text:* "Cinatra admin settings form in Drupal showing the instance
     address field and a Connect with Cinatra button."
   - *Capture:* `/admin/config/services/cinatra` as an administrator, before
     connecting. Browser viewport 1280×800; crop to the form region.

2. **`02-connect-approve.png` — approving the connection.**
   The screen shown after clicking Connect, where you approve the connection
   between your Drupal site and your Cinatra instance.
   - *Alt text:* "Cinatra screen approving a connection from a Drupal site."
   - *Capture:* the approval page reached during the Connect flow.

3. **`03-assistant-on-edit.png` — the assistant on a node edit form.**
   A node edit form with the Cinatra assistant panel open alongside the body
   field, mid-conversation, the assistant suggesting a content change.
   - *Alt text:* "Drupal node edit form with the Cinatra AI assistant panel open
     beside the body field, showing a suggested content change the editor can
     keep."
   - *Capture:* edit any node as a user who has the "Use the Cinatra AI
     assistant" permission; open the assistant and run one prompt. Hide any real
     instance address.

4. **`04-permission.png` — the permission row.**
   The People → Permissions screen with the **Use the Cinatra AI assistant**
   permission row highlighted, showing the roles it is granted to.
   - *Alt text:* "Drupal permissions page highlighting the Use the Cinatra AI
     assistant permission and the roles it is granted to."
   - *Capture:* `/admin/people/permissions`, filter to the Cinatra permission.

**Screenshot conventions:** PNG; light Drupal admin theme (Claro) for the Drupal
captures; hide real addresses; keep each image's longest edge ≤ 1280px so the
page stays light. Store final screenshots under `.drupalorg/images/` named as
above, and attach them to the project page's Images field at creation time. Like
the logo, they are export-ignored and never ship in the release tarball.
