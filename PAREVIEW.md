# PAReview / drupal.org publication-readiness — clean run record

This note records a **clean local PAReview-equivalent run** of the Cinatra
Drupal module: the same static-analysis and packaging-hygiene checks a
drupal.org reviewer (and the drupalcode GitLab pipeline) runs, executed against
the local checkout *before* the project exists on drupal.org. Showing up clean
saves a scarce reviewer slot.

It is the explicit pre-flight gate for **issue #18** (run the PAReview-equivalent
battery locally and prove it clean). The *external* steps — creating the
drupalcode.org project, pushing a SemVer release branch/tag, running the official
`pareview.sh` against the drupalcode repo, and filing the
security-advisory-coverage application — are owner-tracked external steps and
stay parked separately. This note does not touch any of those.

## Last clean run

- **Date:** 2026-06-14
- **Commit:** `main` @ 39d16b2 (PR #41 head)
- **Result:** zero findings across the whole battery (see below).

### Toolchain (resolved locally from `composer.json` ranges; this package ships no `composer.lock`)

| Tool | Version resolved locally | Standard / config |
| --- | --- | --- |
| PHP | 8.5.x (module supports `>=8.1`) | — |
| PHP_CodeSniffer | 4.0.1 | `phpcs.xml.dist` → `Drupal` + `DrupalPractice` |
| drupal/coder | 9.0.0 | provides the Drupal / DrupalPractice sniffs |
| PHPStan | 2.2.2 | `phpstan.neon.dist`, level 1 |
| mglaman/phpstan-drupal | 2.0.15 | the `drupal-check` replacement |
| phpstan/phpstan-deprecation-rules | 2.0.4 | Drupal deprecation scan |
| drupal/core (dev) | 11.x | analysis target / stubs |

> drupal.org's pipeline may pin slightly different patch versions; this module
> is clean on the newest PHPCS 4 / coder 9 / PHPStan 2 line, which is the
> strongest available signal.

## What was run (reproduce locally)

```sh
composer install
composer phpcs        # vendor/bin/phpcs  (Drupal + DrupalPractice)
composer phpstan      # vendor/bin/phpstan analyse (phpstan-drupal, deprecations)
composer validate --strict
```

| Check | Command | Result |
| --- | --- | --- |
| Coding standards | `phpcs` (Drupal + DrupalPractice) | **0 errors / 0 warnings** — 26 files, exit 0 |
| Static analysis / deprecations | `phpstan analyse` (level 1, phpstan-drupal) | **No errors**, exit 0 |
| Composer metadata | `composer validate --strict` | **valid** |
| PHP syntax | `php -l` over all `*.php`/`*.module`/`*.install` | **no syntax errors** |
| YAML | parse every `*.yml` | **all parse** |
| `cinatra.info.yml` | no `project`/`version`/`datestamp`; has `core_version_requirement` | **OK** (packager adds those) |
| Config schema | `config/schema/cinatra.schema.yml` types every settings key | **OK** |
| Config install | `config/install/cinatra.settings.yml` decodes to a mapping (`{}`), not null | **OK** |
| PAReview red flags | no `var_dump`/`print_r`/`dpm`/debug calls, no stray `.DS_Store`/backup files, no `t($var)` | **none** |

### Not runnable in this local environment

- **eslint / stylelint** (the GitLab template's `SKIP_ESLINT=0` / `SKIP_STYLELINT=0`
  jobs): no local eslint/stylelint install and no project `package.json`, so the
  DA pipeline's JS/CSS linters were not executed here. Instead, the authored
  `js/cinatra-fallback.js` passed `node --check` and the authored
  `css/cinatra-fallback.css` was inspected by hand; both conform to Drupal
  JS/CSS conventions (`Drupal.behaviors`, `"use strict"`, `Drupal.t()`, `var`
  declarations). `js/cinatra-widget.js` is the vendored Apache-2.0 app bundle:
  it is excluded from PHPCS (`phpcs.xml.dist`) and declared as a GPL-compatible
  third-party library in `cinatra.libraries.yml`. Confirming the bundle's
  treatment under the DA eslint job specifically is part of the parked
  drupalcode.org pipeline run.
- **Official `pareview.sh`** and **gitlab-ci-local**: require the drupalcode.org
  project / the DA pipeline runner; parked with the owner-managed external steps.

## Versioning decision (deliberate)

Today the only tag is the published pre-release **`v0.1.0`**. The module's
`info.yml` deliberately carries **no** `version` key — drupal.org's packager
stamps the version from the release tag, so the source tree must stay
version-less.

**Pinned choice for now:** stay on the current pre-`1.0` line; the latest tag
remains `v0.1.0`. No new tag is created here — tags/releases are owner-approved
only.

**Owner decision: the first drupal.org release publishes on the `0.1.x` line**
(branch `0.1.x`), matching the rest of the project's `0.1.x` line rather than the `1.x` Drupal
convention. No new tag is created here; the actual drupalcode.org release/tag remains an
owner-managed external submission step, parked until v0.1.x ships.
