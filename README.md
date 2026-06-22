# Cinatra — Drupal module

Puts the [Cinatra](https://cinatra.ai) AI assistant right on your Drupal pages,
so content editors can draft, expand, and revise content in a chat panel next to
what they are editing — without leaving the page they are working on.

The assistant talks to **your** Cinatra instance — the one whose address you
enter in the settings. You choose and control which instance your content goes
to; it is not tied to a fixed outside service.

## What does the assistant help me do?

It's an AI assistant built right into the editor. It helps you draft, rewrite,
shorten, retitle, and improve content and answer questions while you work.
Because it runs through your own Cinatra instance, it isn't a generic writing
tool — it works through a Cinatra agent on your instance, so it can draw on the
tools, data, and knowledge you've connected there and bring that capability
straight into your CMS. It knows the page you are on, so its suggestions
fit the content you are actually editing.

## Do I need a Cinatra account?

You need access to a running Cinatra instance. Cinatra is an open source AI
platform that you or your organisation host and connect the assistant to — learn
more and get the source at <https://www.cinatra.ai>. Once your instance is
running, open the Cinatra settings, enter the instance's web address, and
connect.

## What it does for editors

- Adds an AI assistant panel on node pages, node edit forms, and the front page,
  so help is one click away while you write.
- Knows the page you are on, so its suggestions fit the content you are actually
  editing.
- Drafts and rewrites text on request. The editor always reviews what the
  assistant suggests and decides what to keep; when your instance supports it,
  suggested changes can be dropped straight into the form.
- Shows the assistant **only** to the people you choose, using a dedicated
  permission (see below) — not to every logged-in user.

## Getting started

1. Install the module (`composer require drupal/cinatra` once published, or place
   it under `modules/custom/cinatra/`).
2. Enable it: `drush en cinatra`.
3. In Drupal, open **Configuration → Web services → Cinatra**, enter your Cinatra
   instance address, and click **Connect with Cinatra**. Approve the connection
   on the screen that appears, and you are set up. (No redirect in your setup?
   Paste the one-line connection code instead, or use the **Manual
   configuration** section.)
4. On **People → Permissions**, give the **"Use the Cinatra AI assistant"**
   permission to the content editors who should see the assistant.

Upgrading from the pre-release `cinatra_widget` module? Enabling this module
copies your old `cinatra_widget` settings over automatically.

## Who can use it

The assistant appears only for users who have the **"Use the Cinatra AI
assistant"** permission. The assistant can read the current page and suggest
content changes, so give this permission to people you trust to edit content.

## Requirements

- Drupal core `^10.3 || ^11`.
- A Cinatra instance you can reach, with the assistant turned on. With an older
  Cinatra instance the panel shows a short "update Cinatra" notice instead of the
  assistant.

## Your content

When an editor chats with the assistant, the messages they type and the page they
are on are sent to the Cinatra instance you set up — and nowhere else. That
instance's own privacy terms cover this data; see <https://cinatra.ai>.

## Development

This repo is the source of truth for the module. Cinatra developers consume it as
a local clone for the dev docker stack. See the cinatra repo's
`docs/developer/wp-drupal-plugin-development.md` for the multi-repo workflow.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant is the Cinatra
app frontend under Apache-2.0, which is compatible with the GPL.
