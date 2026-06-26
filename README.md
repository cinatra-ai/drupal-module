# Cinatra for Drupal

Puts the [Cinatra](https://cinatra.ai) AI assistant right on your Drupal pages,
so content editors can draft, expand, and revise content in a chat panel next to
what they are editing — without leaving the page they are working on.

The assistant talks to **your** Cinatra instance — the one whose address you
enter in the settings. You choose and control which instance your content goes
to; it is not tied to a fixed outside service.

## Documentation

The full documentation hub for this integration — overview, quick start,
day-to-day usage, settings & permissions, troubleshooting, and advanced
reference — lives at **https://docs.cinatra.ai/integrations/drupal/**. The
source for those pages is this repository's [`docs/`](docs/) directory, published
to docs.cinatra.ai on each release.

## Works with

- Drupal `^10.3 || ^11`
- A running Cinatra instance

## Capabilities

- Adds an AI assistant panel on node pages, node edit forms, and the front page,
  so help is one click away while you write.
- Knows the page you are on, so its suggestions fit the content you are actually
  editing.
- Drafts and rewrites text on request. The editor always reviews what the
  assistant suggests and decides what to keep; when your instance supports it,
  suggested changes can be dropped straight into the form.
- Shows the assistant **only** to the people you choose, using a dedicated
  Drupal permission — not to every logged-in user.
- One-click **Connect with Cinatra**: enter your instance address and approve a
  consent screen; the integration credential is provisioned and stored
  automatically — no key is copied or pasted.

## Getting started

1. Install the module:

   ```sh
   composer require drupal/cinatra
   ```

   Alternatively, place the module directory under `modules/custom/cinatra/`.

2. Enable it:

   ```sh
   drush en cinatra
   ```

3. In Drupal, open **Configuration → Web services → Cinatra**
   (`/admin/config/services/cinatra`), enter your Cinatra instance address, and
   click **Connect with Cinatra**. Approve the connection on the screen that
   appears, and you are set up.

   If your environment does not support a browser redirect, paste the one-line
   connection string instead (expand "No browser redirect?"), or fill in the
   **Manual configuration** fields directly.

4. On **People → Permissions**, grant the **"Use the Cinatra AI assistant"**
   permission to the roles whose members should see the assistant.

Upgrading from the pre-release `cinatra_widget` module? Enabling this module
copies your old `cinatra_widget` settings over automatically.

## Who can use it

The assistant appears only for users who have the **"Use the Cinatra AI
assistant"** permission. The widget can read the current page and propose
content edits, so grant this permission to people you trust to edit content.

## Requirements

- Drupal core `^10.3 || ^11`
- PHP `>=8.1`
- A Cinatra instance you can reach, with the assistant turned on. With an older
  Cinatra instance the panel shows a short "update Cinatra" notice instead of
  the assistant.

## Your content

When an editor chats with the assistant, the messages they type and the page
they are on are sent to the Cinatra instance you set up — and nowhere else. That
instance's own privacy terms apply; see <https://cinatra.ai>.

## Development

### Prerequisites

- PHP `>=8.1`
- [Composer](https://getcomposer.org/) for dependency management

### Install dev dependencies

```sh
composer install
```

### Static analysis and coding standards

```sh
# Drupal coding standards (Drupal + DrupalPractice sniffs)
composer phpcs

# Auto-fix fixable coding-standards issues
composer phpcbf

# Static analysis (phpstan-drupal, deprecation rules)
composer phpstan
```

The PHPCS configuration lives in `phpcs.xml.dist` and the PHPStan configuration
in `phpstan.neon.dist`.

### Tests

Unit and functional tests live under `tests/`. To run them you need a Drupal
test environment (the standard Drupal core test runner or DDEV/Lando):

```sh
# Example using the Drupal test runner from a Drupal root
php core/scripts/run-tests.sh --module cinatra
```

### JS and CSS

The module ships two authored files — `js/cinatra-fallback.js` and
`css/cinatra-fallback.css` — which are the fallback chrome that shows while the
assistant widget loads or when the instance is unreachable. These conform to
Drupal JS/CSS conventions (`Drupal.behaviors`, `"use strict"`, `Drupal.t()`).

`js/cinatra-widget.js` is the vendored assistant widget bundle (Apache-2.0) and
is excluded from PHPCS; see `cinatra.libraries.yml` for the library declaration.

### Contributing

Please open a pull request against the `main` branch. Run `composer phpcs` and
`composer phpstan` before submitting; the CI pipeline runs the same checks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant widget is the
Cinatra app frontend under Apache-2.0, which is compatible with the GPL.
