# Changelog

All notable changes to the Cinatra Drupal module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases are tag-driven: the GitHub release tag is `v<MAJOR>.<MINOR>.<PATCH>`
and the corresponding drupal.org release tag is the bare `<MAJOR>.<MINOR>.<PATCH>`.

## Unreleased
- Two new tools write an unpublished page only if it is unchanged since the read that a person reviewed. `cinatra_write_unpublished` saves the given field values as a new revision, and with `publish: true` publishes the page in the same save. `cinatra_publish_unpublished` publishes the page as it is. Both take the binding from `cinatra_read_protected_revision` read without `revision_id`, which now also reports the translation's `changed` time: `nid`, `language`, `expected_default_revision_id`, `expected_latest_revision_id`, `expected_changed`, `workflow_fingerprint` and `preimage_fingerprint`. They compare it under the same node lock as the protected draft, before they save. They refuse with a fixed reason and write nothing for: a published page (edit it through the protected draft), a page that changed since the read, missing permission (update access on the page; to publish an unmoderated page, the permission to change its published status), a field that does not exist or cannot be edited, and a translation the binding is not for. A moderated page keeps its workflow: a write keeps its current unpublished state through a transition the editor may use, and the publish step uses the editor's transition to the published state; without such a transition they refuse. They answer with the stored field values, the new revision ID and the new `changed` time. On a moderated page the save also needs edit access to the `moderation_state` field, in addition to the transition permission; otherwise it refuses with "Changing the workflow state of this page is not permitted." After the save and before the commit, each requested field's stored value is compared with the reviewed one, so a save hook cannot replace it. Values are normalised only by their property type (integer, decimal, float and boolean by value; text and every other type byte for byte); a difference rolls the save back with "The stored value differs from the reviewed value; nothing was written." Nothing changes on a site until a connector calls them. The development site now exposes both tools.
- The read's `preimage_fingerprint` now names its translation, so a binding read for one language cannot write another, also when the translations share a changed time. This applies to `cinatra_write_protected_draft` too, which also now refuses a draft whose stored value differs from the reviewed one, and a draft when the editor may not edit the `moderation_state` field. A binding read before this release is refused once; read again.
- The node-publish webhook is sent only when the publishing save commits. A publish inside a transaction that rolls back no longer reports a publish.
- `cinatra_read_protected_revision` can now be called without a language when it reads the published preimage. It then reads the page's original (untranslated) language and reports that language in `language`, with the same view and field checks. Cinatra uses it to read a page whose language it does not know yet, on a site whose MCP endpoint serves only the Cinatra tools. An exact revision read still needs its exact language.
- Protected drafts and the development site's page migration now work on MariaDB. The check that the page tables use the transactional InnoDB engine read the engine from `SHOW CREATE TABLE`, which MariaDB prints without it in the SQL mode Drupal uses, so every protected write was refused with "Protected revisions require transactional InnoDB tables." The engine is now read from `information_schema.TABLES`, for exactly the table that Drupal's table name expands to. When the development site's migration fails, its log line now names a fixed reason: the protected draft refusal, or `unexpected_error` for any other failure.
- The development site now reads the mcp_tools server profiles under both names that drupal/mcp_tools uses: `mcp_tools_servers.settings` (1.0.0-beta1 to beta14) and `mcp_tools.servers` (beta15 and later). On a site with beta14 or older it refused to arm with `mcp_config_absent`. A site that stores both records refuses with `mcp_config_ambiguous`.
- The protected draft preimage now reports the page's translation languages, so Cinatra can save a draft of a single-language page whose language it read from the page's path, and refuses when the page has other translations. The list is reported only when the editor may view every translation; otherwise it is left out and Cinatra refuses, so a translation the editor may not view is never named.
- The assistant sign-in now happens inside the Cinatra panel and stays between the editor and Cinatra: this site no longer starts, completes or receives that sign-in, and never holds an editor's Cinatra credential. Requires a Cinatra instance on widget bootstrap protocol 2.
- Removed the three same-origin broker routes the old sign-in needed (`/cinatra/token`, `/cinatra/widget-auth/init`, `/cinatra/widget-auth/token`). Nothing on the site calls them, and a stale page that does gets a plain 404.
- The assistant panel loads only when it is opened, so viewing a node no longer contacts your Cinatra instance at all.
- The assistant no longer appears if your Cinatra instance is on the same web address as this site. Keeping each editor's sign-in private needs the two to be separate addresses; the "Cinatra is unavailable" button stays visible instead.

## 0.1.6
- Declare Drupal 12 support.
- HTTP-layer SSRF guard on outbound broker calls, generic broker error messages, and the webhook secret is now persisted.
- Standard-Webhooks node-publish emitter on the generic host URL.
- Origin header on the session-token mint; ConnectController now extends ServerBase.
- Document the WordPress-parity notification asymmetry.

## 0.1.5
- Mirror the Cinatra widget from its source of truth and add a parity gate that keeps the mirrored copy in sync.
- Fixed stale strings in the module.
- Module publishing to drupal.org is now gated behind a release-approval step.
