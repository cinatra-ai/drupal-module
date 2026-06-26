---
slug: drupal
title: Cinatra for Drupal troubleshooting
description: Diagnose and fix common Cinatra for Drupal issues, with symptoms, causes, fixes, and escalation.
navOrder: 5
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.2"
sourceRepo: https://github.com/cinatra-ai/drupal-module
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/drupal
---

# Cinatra for Drupal troubleshooting

Each problem below lists what you see, why it happens, how to fix it, how to
confirm the cause and verify the fix, and where to go if the fix does not work.

## The assistant panel does not appear

- **Symptom:** You open a node page or edit form and there is no Cinatra
  assistant panel.
- **Cause:** Your user's role does not have the **"Use the Cinatra AI
  assistant"** permission, or the module is not enabled.
- **Fix:** Confirm the module is enabled (**Extend**, `/admin/modules`). Then on
  **People → Permissions** (`/admin/people/permissions`) grant **"Use the
  Cinatra AI assistant"** to your role and save.
- **Diagnostics:** Check the permission as the affected user's role, and verify
  the module appears as enabled. Clear caches (`drush cr`) if you just changed
  the configuration.
- **Escalation:** If the permission is granted and the panel still does not
  appear, [contact support](https://docs.cinatra.ai/resources/support/).

## "Connect with Cinatra" does not complete

- **Symptom:** Clicking **Connect with Cinatra** does not finish, or returns to
  the settings form without a connection.
- **Cause:** The instance address is wrong or unreachable, or your environment
  blocks the browser redirect used by the guided flow.
- **Fix:** Re-check the Cinatra instance address at **Configuration → Web
  services → Cinatra** (`/admin/config/services/cinatra`). If your environment
  does not support a browser redirect, expand **No browser redirect?** and paste
  the one-line connection string, or use **Manual configuration**.
- **Diagnostics:** Confirm the instance address loads in a browser from the
  network where Drupal runs. Check the Drupal log
  (`/admin/reports/dblog`) for connection errors.
- **Escalation:** If the address is correct and reachable but connecting still
  fails, [contact support](https://docs.cinatra.ai/resources/support/).

## The assistant loads but cannot reach the instance

- **Symptom:** The panel appears, but requests fail or time out.
- **Cause:** The stored connection has expired or been revoked, or the Cinatra
  instance is unreachable from the server.
- **Fix:** Reconnect from **Configuration → Web services → Cinatra**
  (`/admin/config/services/cinatra`) with **Connect with Cinatra** to
  re-provision the credential.
- **Diagnostics:** Check the Drupal log (`/admin/reports/dblog`) for request
  errors, and confirm the instance is up and reachable.
- **Escalation:** If reconnecting does not restore service,
  [contact support](https://docs.cinatra.ai/resources/support/).

## Old `cinatra_widget` settings did not carry over

- **Symptom:** After enabling this module you expected your previous
  `cinatra_widget` configuration but the settings look empty.
- **Cause:** The automatic copy runs when this module is enabled; if
  `cinatra_widget` was already removed, there is nothing to copy.
- **Fix:** Re-enter the instance address and reconnect using the
  [quick start](./quick-start.md). The one-time copy only applies while the old
  module's configuration is still present.
- **Diagnostics:** Check whether `cinatra_widget` configuration still exists in
  your site before relying on the automatic copy.
- **Escalation:** [Contact support](https://docs.cinatra.ai/resources/support/)
  if you need help migrating older configuration.

## Still stuck?

See [Advanced & reference](./advanced-and-reference.md) for deeper material, or
[contact support](https://docs.cinatra.ai/resources/support/).
