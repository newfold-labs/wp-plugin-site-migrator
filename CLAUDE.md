# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`nfd-site-migrator`) for moving a WordPress site between hosts. It is
installed on **both** sites: the source exports its content into a package, the destination
imports it. PHP backend under `includes/` (PSR-4 `NewfoldLabs\WP\SiteMigrator\` via Composer)
plus a React SPA under `src/` rendered on a single wp-admin page.

**The plugin is mid-rework and does not currently perform a migration.** It began life as the
Bluehost Site Migrator, which packaged a site and handed it to a hosting backend; that backend
integration has been removed and the export/import halves are being rebuilt. Read
`docs/implementation-plan.md` before making changes — it is the authority on what is being
built, in what order, and why. `docs/code-analysis.md` records the defects that motivated it,
and finding IDs (`2.4`, `3.11`, …) are referenced throughout the plan and in commit messages.

Current state: phases 0 (deletion) and 1 (rename) are done. What survives is the compatibility
check, the database dumper, the file archivers, and the manifest.

## Commands

```bash
# JS/CSS
yarn build            # generate:css + wp-scripts build -> build/
yarn start            # same, in watch mode
yarn generate:css     # assets/styles/app.css -> src/styles/nfd-site-migrator.css (runs before webpack)
yarn lint:js
yarn format

# PHP
composer lint         # phpcs (Newfold standard)
composer fix          # phpcbf

# Local WordPress (http://localhost:10004, admin/password)
npx wp-env start
npx wp-env stop

# E2E (requires wp-env running with the plugin built)
yarn test
yarn cypress open
```

`build/` and `src/styles/nfd-site-migrator.css` are **generated and not tracked**. Run
`yarn build` after cloning or the admin page renders an empty div.

## Naming

Settled in decision D1 of the implementation plan. Everything is consistent; keep it that way.

| Thing | Value |
|---|---|
| Namespace | `NewfoldLabs\WP\SiteMigrator\` → `includes/` |
| Function prefix | `nfd_sm_` (procedural helpers in `functions.php`, not autoloaded) |
| Constants | `NFD_SM_*` in `constants.php` |
| Slug, text domain, REST namespace | `nfd-site-migrator` |
| Single option key | `nfd_site_migrator` |
| CSS scope / mount point | `.nfd-sm` / `#nfd-sm-app` |

**When renaming anything, drive it with text search, not an IDE refactor.** The namespace
appears inside string literals (REST controller registration, and formerly task executors);
a symbol-aware rename misses those and the failure is a runtime fatal, not a compile error.

## Versioning

The plugin header in `nfd-site-migrator.php` is the source of truth. `package.json` carries the
same number for npm's benefit, but nothing breaks if they drift: **build output is unversioned**
(`build/`, not `build/<version>/`) and cache busting comes from the content hash in
`nfd-site-migrator.asset.php`. This replaced a four-place scheme where a mismatch made
`WP_Admin::register_assets()` silently skip enqueueing.

## Architecture

**Bootstrap** (`nfd-site-migrator.php`): Composer autoload, `constants.php`, then `functions.php`
(procedural helpers, all prefixed `nfd_sm_`, required explicitly). Instantiates `WP_Admin` and
`RestApi\RestApi`, primes `Utils\Options::fetch()`, registers `MigrationChecks\Checker::register()`,
and persists options on `shutdown`. One deactivation hook (`nfd_sm_purge_all`); no activation hooks.

**Options facade** (`Utils\Options`): all plugin state lives in the single `nfd_site_migrator`
wp_option as an array, read once at bootstrap and written once on shutdown via `maybe_persist()`.
Use `Options::get/set/delete` — never `update_option` for keys inside it. A few packaging status
flags are separate standalone options declared in `constants.php` and listed in
`NFD_SM_OPTIONS_LIST`; `nfd_sm_purge_all()` deletes those plus the storage directory and the
can-migrate transient on deactivation.

**Compatibility check** (`MigrationChecks\Checker`): a filter chain on `nfd_site_migrator_can_migrate`.
Each check contributes a boolean and records a diagnostic in `Checker::$results`. Four local checks
remain — disk-space functions, content-directory writability, multisite. This is scheduled to be
replaced in phase 3 by `Preflight\Checker` returning a structured `Report` that fails closed.

**Manifest** (`Manifest/`): collects site facts into an array cached under the `manifest` option key.
Currently has **no callers** — its caller was the removed hosting-backend scan. It is kept
deliberately (plan §11.1) as the site-facts source for the phase 3 preflight report.

**Packaging** (`Packager/`): six static archivers, each resumable — `prepare()` builds a CSV list
file, `execute()` walks it tracking byte offsets stashed in Options under `<type>_task_params`, and
bails after ~10s so the next invocation resumes. Nothing currently drives them: the wp-cron task
queue that did was removed in phase 0. Phase 2 collapses all six into one `FileCollector`.

Archives are written by `Archiver\Compressor extends Archiver`, a custom incremental format with a
fixed header block — not `ZipArchive`. Files land in `wp-content/uploads/nfd-site-migrator/`
(`nfd_sm_storage_path()`). Phase 2 replaces this with standard zip.

**REST API** (`includes/RestApi/`, namespace `nfd-site-migrator/v1`): controllers are registered by
listing the class name in `RestApi::register_routes()`. Every route requires `manage_options`.
Only `migration-check` remains (POST run checks, GET `/compatible`, GET `/step`).

**Frontend** (`src/`): mounts into `#nfd-sm-app`. `components/Migration.js` fetches `/step` and picks
the screen. Calls go through `utils/api.js` wrapped in `utils/apiCall.js`, which converts thrown
errors into `{ error, failed: true }` rather than rejecting — callers check `response.failed`.
The transfer screens were removed with their endpoints; phases 3 and 4b rebuild the UI.

Styling is Tailwind, but not through PostCSS in webpack: `yarn generate:css` compiles
`assets/styles/app.css` into `src/styles/nfd-site-migrator.css`, which the JS entry imports.
Component styles are `@apply` classes scoped under `.nfd-sm`.

## Dead code: two kinds, opposite fates

Read plan §11.1 before deleting anything that looks unused.

- **Abandoned** — delete. Unused encryption parameters on `Compressor::add_file()`, path helpers
  duplicated between `Archiver` and `functions.php`, the computed `$progress` variable.
- **Orphaned — keep every line.** `DatabaseBase`'s `is_*_query()` predicates, `is_atomic_query()`,
  `replace_table_collations()`, `repair_table()`, and `DatabaseUtility::replace_serialized_values()`
  are dead because the import half was stripped when the file was forked from All-in-One WP
  Migration. Phase 4a revives all of it. Deleting it would throw away the most valuable code here.

Do not drive deletions from an IDE's "unused symbol" report — dispatch happens through string
literals that no symbol graph follows.

## Conventions

- Minimum PHP is 5.6 per the plugin header (phpcs `testVersion` is `7.0-`); avoid modern syntax —
  `array()` throughout, no typed properties, no arrow functions. Phase 8 raises the floor to 7.4.
- PHPCS uses the `Newfold` standard from `newfold-labs/wp-php-standards`, resolved from the Satis
  repository declared in `composer.json`. It is not on Packagist, so that `repositories` block has
  to stay. `WordPress.WP.AlternativeFunctions` and `WordPress.DB.RestrictedFunctions` are downgraded
  to severity 0 because the packager needs raw file and DB access.
- Procedural helpers go in `functions.php` with the `nfd_sm_` prefix; classes go in `includes/`
  under the PSR-4 namespace.

## Licensing

GPL-2.0-or-later, with the licence text in `LICENSE`. `Database/`, `Archiver/`, and
`Utils/DatabaseUtility.php` derive from All-in-One WP Migration (ServMask, Inc.) and carry
attribution headers; `CREDITS.md` records the details. Preserve both when editing those files.

## Cypress notes

Specs stub the REST layer with `cy.intercept` against URL-encoded `rest_route` paths, e.g.
`` `**${ encodeURIComponent( '/nfd-site-migrator/v1/migration-check/step' ) }**` ``, backed by
fixtures in `cypress/fixtures/`. `cy.login()` is cookie-aware and skips the form when already
authenticated. Only `checkCompatibility.cy.js` remains — the others covered deleted screens. The
plan replaces this whole approach (§12): these specs stub the entire backend and cannot catch a
single defect in the analysis.
