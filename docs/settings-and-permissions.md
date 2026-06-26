---
slug: drupal
title: Cinatra for Drupal settings and permissions
description: Configure the connection, control who sees the assistant, and understand the trust model.
navOrder: 4
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.2"
sourceRepo: https://github.com/cinatra-ai/drupal-module
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/drupal
---

# Cinatra for Drupal settings and permissions

## Settings

All module settings live at **Configuration → Web services → Cinatra**
(`/admin/config/services/cinatra`).

- **Cinatra instance address.** The address of the Cinatra instance the assistant
  talks to. You choose and control this — the module is not tied to a fixed
  outside service.
- **Connect with Cinatra.** The one-click connection flow: enter the instance
  address, click **Connect with Cinatra**, and approve the consent screen. The
  integration credential is provisioned and stored for you automatically; no key
  is copied or pasted.
- **No browser redirect?** For environments without a browser redirect, expand
  this option and paste the one-line connection string instead.
- **Manual configuration.** If you prefer, fill in the connection fields directly
  rather than using the guided flow.

> [!NOTE]
> Upgrading from the pre-release `cinatra_widget` module? Enabling this module
> copies your old `cinatra_widget` settings over automatically.

## Permissions

The module ships a dedicated Drupal permission so the assistant is shown **only**
to the people you choose — not to every logged-in user.

- **"Use the Cinatra AI assistant"** — controls who can see and use the assistant
  panel. Grant it on **People → Permissions**
  (`/admin/people/permissions`) to the roles whose members should have the
  assistant.

Configuring the module itself (entering the instance address, connecting) uses
Drupal's standard administrative permissions for configuration; grant module
administration only to trusted administrators.

## Trust model

What the integration can access, and how that access is granted and governed:

- **Your instance, your choice.** The assistant only ever talks to the Cinatra
  instance whose address you enter. Your content goes to the instance you
  control.
- **Credential, not a copied key.** Connecting provisions an integration
  credential through a consent flow and stores it for you. You do not paste a
  secret into Drupal, and the credential is scoped to this integration.
- **Page context, on demand.** The assistant works from the context of the page
  the editor is on so its suggestions are relevant. It is exposed only to the
  roles you grant the dedicated permission to.
- **Editor decides.** The assistant suggests; it never changes your content on
  its own. The editor reviews every suggestion and decides what to keep.
- **Governed by Cinatra's permission model.** On the Cinatra side, what the
  integration may do is governed by the permissions and access model of your
  instance, the same as any other integration.

For how Cinatra administers permissions across the platform, see the canonical
[Guides](/guides/) chapter. For how any marketplace extension is installed,
permissioned, and trusted, see
[Install & manage any marketplace extension](/integrations/install-and-manage-marketplace-extensions/).

## Host compatibility

This integration supports Drupal `^10.3 || ^11` and a running Cinatra instance.
