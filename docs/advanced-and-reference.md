---
slug: drupal
title: Cinatra for Drupal advanced and reference
description: Deeper material and canonical links out to Cinatra Guides and References.
navOrder: 6
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.2"
sourceRepo: https://github.com/cinatra-ai/drupal-module
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/drupal
---

# Cinatra for Drupal advanced and reference

This page collects deeper material and links out to the canonical Cinatra
documentation. Cross-cutting platform material is not duplicated here — follow
the links.

## Installation alternatives

The [quick start](./quick-start.md) uses Composer. You can also place the module
directory under `modules/custom/cinatra/` and enable it from **Extend**
(`/admin/modules`) or with `drush en cinatra`.

## Connecting without a browser redirect

If your environment cannot use the guided redirect flow, expand **No browser
redirect?** on the settings page to paste a one-line connection string, or use
**Manual configuration** to enter the connection details directly. See
[Settings & permissions](./settings-and-permissions.md) for the full list of
settings.

## Migrating from `cinatra_widget`

Enabling this module copies settings from the pre-release `cinatra_widget`
module automatically, while that module's configuration is still present. See the
migration entry in [Troubleshooting](./troubleshooting.md) if the copy did not
run as expected.

## Host compatibility

This integration supports Drupal `^10.3 || ^11` and a running Cinatra instance.

## Canonical references

- **Source repository and releases** — the module source and release history live
  at [cinatra-ai/drupal-module](https://github.com/cinatra-ai/drupal-module). It
  is also published on
  [drupal.org/project/cinatra](https://www.drupal.org/project/cinatra).
- **Marketplace listing** —
  [Cinatra Marketplace](https://marketplace.cinatra.ai/extensions/drupal).
- **Install & manage any marketplace extension** — the shared install, permission,
  trust, update, and removal flow:
  [Install & manage any marketplace extension](/integrations/install-and-manage-marketplace-extensions/).
- **Cinatra Guides** — cross-cutting platform material, including how permissions
  are administered: [Guides](/guides/).
- **Cinatra References** — platform reference material: [References](/references/).
- **Support** — [Get help](https://docs.cinatra.ai/resources/support/).
