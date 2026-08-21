# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`bluehost-site-migrator`) that packages a WordPress site into chunked zip archives and hands them off to Bluehost's "Can We Migrate" (CWM) API. PHP backend under `includes/` (PSR-4 `BluehostSiteMigrator\` via Composer) plus a React SPA under `src/` rendered on a single wp-admin page.

## Commands

```bash
# JS/CSS
yarn build            # generate:css + wp-scripts build -> build/<package.json version>/
yarn start            # same, in watch mode
yarn watch            # watch only the Tailwind CSS generation
yarn generate:css     # assets/styles/app.css -> src/styles/bh-site-migrator.css (must run before webpack)
yarn lint:js          # eslint (wp-scripts) over ./src
yarn lint:js:fix
yarn format           # prettier via wp-scripts

# PHP
composer lint         # phpcs (Newfold standard)
composer fix          # phpcbf

# Local WordPress (http://localhost:10004, admin/password)
npx wp-env start
npx wp-env stop

# E2E tests (require wp-env running with the plugin built)
yarn test                                         # cypress run, all specs
yarn cypress run --spec cypress/e2e/checkRender.cy.js   # single spec
yarn cypress open                                 # interactive
```

CI runs `composer lint` on PHP changes and the Cypress suite on PRs. `.husky/pre-commit` runs `lint-staged` (no config committed, so it is effectively a no-op unless one is added).

## Version bumping — four places must agree

`build/` output is versioned: webpack writes to `build/${package.json version}/`, while PHP enqueues from `BH_SITE_MIGRATOR_PLUGIN_BUILD_DIR` = `build/BH_SITE_MIGRATOR_VERSION`. If they diverge, `WP_Admin::register_assets()` silently skips enqueueing and the admin page renders an empty div. Keep in sync:

1. `package.json` → `version`
2. `constants.php` → `BH_SITE_MIGRATOR_VERSION`
3. `bluehost-site-migrator.php` → plugin header `Version:`
4. `readme.txt` → `Stable tag:`

Built assets under `build/` are committed to the repo.

## Architecture

**Bootstrap** (`bluehost-site-migrator.php`): loads Composer autoload, `constants.php`, then `functions.php` (procedural helpers, all prefixed `nfd_bhsm_` — not autoloaded, required explicitly). Instantiates `WP_Admin`, `RestApi\RestApi`, primes `Utils\Options::fetch()`, registers `MigrationChecks\Checker::register()`, and persists options on `shutdown`.

**Options facade** (`Utils\Options`): all plugin state lives in the single `bluehost_site_migrator` wp_option as an array, read once at bootstrap and written once on shutdown via `maybe_persist()`. Use `Options::get/set/delete` for that state — never `update_option` for keys inside it. A handful of values (migration ID, auth token, regions, packaging status flags, geo data) are *separate* standalone options declared in `constants.php` and listed in `BH_SITE_MIGRATOR_OPTIONS_LIST`; `nfd_bhsm_purge_all()` deletes those plus the storage directory and the can-migrate transient on deactivation.

**Compatibility check** (`MigrationChecks\Checker`): a filter chain on `bluehost_site_migrator_can_migrate`. Each check both contributes a boolean and records a diagnostic in `Checker::$results` (returned to the UI). `can_we_migrate_api()` POSTs the manifest to `{BH_SITE_MIGRATOR_API_BASEURL}/manifestScan` and, on success, stores `migrationId`, `x-auth-token`, and region URLs, caching the verdict in a one-hour transient. To add a check, add a filter in `register()` and set a `self::$results` key.

**Manifest** (`Manifest/`): `Manifest extends Registry` collects site facts (WP, plugins, themes) into an array cached under the `manifest` option key; it's the payload for the CWM feasibility scan.

**Packaging pipeline** (`MigrationManager\MigrationTasks` + `Packager/`): work is queued as `newfold-labs/wp-module-tasks` `Task` objects whose `task_execute` is a `'Class::method'` string. Ordering is by descending `task_priority` (database dump 20 → root archive 6); `MigrationTasks::__construct()` rewrites `wp-config.php` to force `DISABLE_WP_CRON` to false, because the queue runs on wp-cron.

Each packager is static and **resumable**: `prepare()` builds a CSV list file of everything to archive, `execute()` walks it while tracking byte offsets (`archive_bytes_offset`, `file_bytes_offset`, list-file offset, processed size) stashed in Options under `<type>_task_params`, and bails after ~10s (`nfd_bhsm_completed_timeout` filter) so the next cron tick resumes. On completion it calls `PackagerBase::persist_archive_path()`, which records hash/size/url into the `packaged_files` option map. Add a new archiver by extending `PackagerBase`, following the prepare/execute offset pattern, and queuing it in `MigrationTasks::queue_tasks()` **and** listing its task name in `Utils\Common::get_packaging_task_names()` (used for cancel and failure reporting).

Archives are written by `Archiver\Compressor extends Archiver`, a custom incremental format with a fixed header block (name/size/mtime/prefix), not `ZipArchive` — this is what makes mid-file resume possible. Files land in `wp-content/uploads/bluehost-site-migrator/` (`nfd_bhsm_storage_path()`), with hashed filenames from `nfd_bhsm_get_hashed_file_path()`.

**Progress** (`Utils\Status`): `set_status( message, progress, stage )` writes a single option polled by the UI; `set_packaging_success( bool )` sets the terminal success/failed flags.

**REST API** (`includes/RestApi/`, namespace `bluehost-site-migrator/v1`): controllers are registered by listing the class name in `RestApi::register_routes()`. Every route uses a `check_permission()` requiring `manage_options`.
- `migration-check` (POST run checks, GET `/compatible`, GET `/step`)
- `migration-tasks` (POST queue, GET `/status`, POST `/cancel`, POST `/send-files`, POST `/report-errors`)
- `migration-data` (GET migration ID, regions, country code)

`/migration-check/step` is the state machine the SPA drives off of, returning `compatible` / `checked` / `transfer_queued` / `packaged_success` / `packaged_failed`.

**Frontend** (`src/`): mounts into `#bh-sm-app` rendered by `WP_Admin::render_page()`. `components/Migration.js` fetches `/step` and picks the screen (CompatibilityCheck → BeginTransfer → TransferStatus → TransferSuccess, with `/error` and `/incompatible` as HashRouter routes in `routes.js`). All calls go through `utils/api.js` (`apiFetch` wrappers) wrapped in `utils/apiCall.js`, which converts thrown errors into `{ error, failed: true }` rather than rejecting — callers check `response.failed`. Geolocation comes from an external `hiive.cloud` worker before the compatibility POST. Polling uses `useInterval` in `utils/hooks.js`.

Styling is Tailwind, but not through PostCSS in webpack: `yarn generate:css` compiles `assets/styles/app.css` into `src/styles/bh-site-migrator.css`, which the JS entry imports. Component styles are `@apply` classes scoped under `.bh-sm` in `app.css`; Tailwind scans `./src/**/*.{html,jsx,js}`.

## Conventions

- Minimum PHP is 5.6 per the plugin header (phpcs `testVersion` is `7.0-`); avoid modern syntax — no short arrays are used, `array()` throughout, no typed properties or arrow functions.
- PHPCS uses the `Newfold` standard from `newfold-labs/wp-php-standards`; `WordPress.WP.AlternativeFunctions` and `WordPress.DB.RestrictedFunctions` are downgraded to severity 0 because the packager needs raw file and DB access.
- Procedural helpers go in `functions.php` with the `nfd_bhsm_` prefix; classes go in `includes/` under the PSR-4 namespace.
- Text domain is `bluehost-site-migrator` (note: `WP_Admin` menu strings use `bluehost_site_migrator` — a pre-existing inconsistency).
- Composer pulls `newfold-labs/*` packages from the Satis repo at https://newfold-labs.github.io/satis/.

## Cypress notes

Specs stub the REST layer with `cy.intercept` against URL-encoded `rest_route` paths, e.g. `` `**${ encodeURIComponent( '/bluehost-site-migrator/v1/migration-check/step' ) }**` ``, backed by fixtures in `cypress/fixtures/`. `cy.login()` (`cypress/support/commands.js`) is cookie-aware and skips the login form when already authenticated. Assertions target stable element IDs (`#check-compatibility-button`, `#begin-transfer-button`, `#transfer-status-heading`, `#copy-transfer-key-button`) — keep those IDs when editing components.
