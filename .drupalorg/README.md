# `.drupalorg/` — Drupal.org project-page source material

This directory holds everything needed to create and dress the module's
**drupal.org project page + Project Browser listing**. The drupal.org project is
currently **parked** (not yet created); this material is staged so it is ready to
paste/promote the moment the project is created.

Nothing here ships to end users: the whole directory is `export-ignore`d in
`../.gitattributes`, so `git archive` (the drupal.org packager) excludes it from
the release tarball.

## Contents

| Path | What it is |
|---|---|
| `project-page.md` | The project-page copy: short summary (≤200 chars), full description body, categories, maintenance/dev status, resources/links, logo spec, and the screenshot plan + alt text. |
| `generate-logo.mjs` | Deterministic generator for the project logo, derived from the Cinatra brand (`cinatra-ai/design`). Reproduces the sanctioned app-icon colourway. |
| `images/logo.png` | The Project Browser project logo — 512×512 PNG, square corners, ~1.3 KB. |
| `images/logo_svg.txt` | Vector master of the logo for crisp rendering (`logo_svg.txt` is the name Project Browser reads). |
| `images/0*-*.png` | Project-page screenshots — **to be captured** against a live Drupal + Cinatra stack at project-creation time (see the plan in `project-page.md`). |

## At project-creation time

1. Create the project at `https://www.drupal.org/project/cinatra` (machine name
   `cinatra`, matching `cinatra.info.yml` / `composer.json`).
2. Paste the fields from `project-page.md` into the project node.
3. Promote the logo to the repo root on the default branch (Project Browser
   requires it there):

   ```sh
   cp .drupalorg/images/logo.png      ./logo.png
   cp .drupalorg/images/logo_svg.txt  ./logo_svg.txt
   git add logo.png logo_svg.txt && git commit -m "chore: add Project Browser logo"
   ```

   > Note: the repo-root `logo.png` / `logo_svg.txt` should NOT be
   > `export-ignore`d — Project Browser reads them on the default branch, and
   > shipping them is harmless. Only this staging directory is export-ignored.

4. Capture the screenshots per the plan and attach them to the project page's
   Images field. Do **not** put the logo in the Images field (deprecated).

## Brand provenance

Logo colours and geometry come from `cinatra-ai/design`:
- `tokens/brand.json` — `color.mustard` (`#c79545`), `color.navy` (`#15213a`).
- `assets/logo/variants.json` — `applications.appIcon` (mustard fedora on navy).
- `scripts/generate-assets.mjs` — `appIconSvg()` (identical geometry/transform).

Trademark/voice rules (`design/TRADEMARK.md`): the "Cinatra" word mark is
**pending** registration — use **™**, never **®**; write "open source"
unhyphenated.
