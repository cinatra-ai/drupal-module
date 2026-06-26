---
slug: drupal
title: Cinatra for Drupal quick start
description: Install the module, connect it to your Cinatra instance, and grant access — start to finish.
navOrder: 2
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.2"
sourceRepo: https://github.com/cinatra-ai/drupal-module
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/drupal
---

# Cinatra for Drupal quick start

This page takes you all the way from nothing to a working assistant. You do not
need to leave this page to finish setup.

## Before you start

You need:

- A Drupal site running Drupal `^10.3 || ^11`, with administrator access.
- A running Cinatra instance and its address (for example
  `https://your-team.cinatra.ai`). The assistant talks to this instance.

## 1. Install the module

Install with Composer (recommended):

```sh
composer require drupal/cinatra
```

Alternatively, place the module directory under `modules/custom/cinatra/` in your
Drupal site.

## 2. Enable the module

Enable it with Drush:

```sh
drush en cinatra
```

Or enable **Cinatra** from **Extend** (`/admin/modules`) in the Drupal admin UI.

## 3. Connect to your Cinatra instance

1. In Drupal, open **Configuration → Web services → Cinatra**
   (`/admin/config/services/cinatra`).
2. Enter your Cinatra instance address.
3. Click **Connect with Cinatra**.
4. Approve the connection on the consent screen that appears. The integration
   credential is provisioned and stored for you automatically — you never copy
   or paste a key.

If your environment does not support a browser redirect, expand **No browser
redirect?** and paste the one-line connection string instead, or fill in the
**Manual configuration** fields directly.

## 4. Grant access to the right roles

By default no one sees the assistant. On **People → Permissions**
(`/admin/people/permissions`), grant the **"Use the Cinatra AI assistant"**
permission to the roles whose members should see it, then save.

## 5. Confirm it works

Open any node page or node edit form as a user in one of those roles. The Cinatra
assistant panel appears next to your content. Type a request — for example, "draft
an intro paragraph" — and the assistant responds in context. You are set up.

> [!NOTE]
> Upgrading from the pre-release `cinatra_widget` module? Enabling this module
> copies your old `cinatra_widget` settings over automatically, so the steps
> above will already be partly done.

## Where to go next

- [Use it](./use-it.md) — what you do with the assistant day to day.
- [Settings & permissions](./settings-and-permissions.md) — configuration and the
  trust model.
- [Troubleshooting](./troubleshooting.md) — if a step above did not work.
