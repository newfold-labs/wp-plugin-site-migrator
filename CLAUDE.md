# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`nfd-site-migrator`) for moving a WordPress site between hosts. It is
installed on **both** sites: the source exports its content into a package, the destination
imports it. PHP backend under `includes/` (PSR-4 `NewfoldLabs\WP\SiteMigrator\` via Composer)
plus a React SPA under `src/` rendered on a single wp-admin page.

**The plugin is mid-rework.** It began life as the Bluehost Site Migrator, which packaged a site
and handed it to a hosting backend; that backend integration has been removed and the
export/import halves have been rebuilt. Read
`docs/implementation-plan.md` before making changes — it is the authority on what is being
built, in what order, and why. `docs/code-analysis.md` records the defects that motivated it,
and finding IDs (`2.4`, `3.11`, …) are referenced throughout the plan and in commit messages.

Current state: **phases 0–4b are done.** A full migration works end to end, from the CLI and
through wp-admin. What is left is phase 5 (direct site-to-site transfer), phase 6 (WP-CLI as a
supported surface), phase 7 (tests and CI — the verification harness is shell scripts, not
PHPUnit) and phase 8 (hardening and distribution).

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

**`composer fix` rewrites string literals, so read its diff.** Newfold's ruleset includes the
"spell WordPress correctly" sniff, and phpcbf applies it inside strings without knowing which of
them are prose and which are data. It once turned `->get( 'wordpress.version' )` — a dot path into
the site profile — into `'WordPress.version'`, so every lookup returned its default and every
compatibility gate went indeterminate, which the Report correctly treats as blocking. The profile
key is now `wp` so there is nothing left for it to catch, but the general hazard stands: an
auto-fixer that edits strings can change behaviour, and this codebase keys a lot on strings.

## Versioning

The plugin header in `nfd-site-migrator.php` is the source of truth. `package.json` carries the
same number for npm's benefit, but nothing breaks if they drift: **build output is unversioned**
(`build/`, not `build/<version>/`) and cache busting comes from the content hash in
`nfd-site-migrator.asset.php`. This replaced a four-place scheme where a mismatch made
`WP_Admin::register_assets()` silently skip enqueueing.

## Architecture

**Bootstrap** (`nfd-site-migrator.php`): Composer autoload, `constants.php`, then `functions.php`
(procedural helpers, all prefixed `nfd_sm_`, required explicitly). Instantiates `WP_Admin`,
registers `Rest\Routes::register()` and `Cli\Commands::register()`, primes `Utils\Options::fetch()`,
and persists options on `shutdown`. One deactivation hook (`nfd_sm_purge_all`); no activation hooks.

**The execution contract.** Everything in `Core/` that does bulk work exposes
`step( $budget )`: do as much as fits in `$budget` seconds, write a checkpoint, return. A budget
of `0` means no limit, which is how the CLI runs it. The browser loops on the REST endpoint; the
CLI loops in `run()`. This replaced wp-cron, so the request that reports progress is the request
doing the work.

**`Core/` must stay transport-agnostic**: no `WP_CLI`, no `WP_REST_Request`, no superglobals, no
output. Two real consumers exist — `Rest/` and `Cli/` — so that stays true rather than merely
intended.

**Options facade** (`Utils\Options`): all plugin state lives in the single `nfd_site_migrator`
wp_option as an array, read once at bootstrap and written once on shutdown via `maybe_persist()`.
Use `Options::get/set/delete` — never `update_option` for keys inside it. A few packaging status
flags are separate standalone options declared in `constants.php` and listed in
`NFD_SM_OPTIONS_LIST`; `nfd_sm_purge_all()` deletes those plus the storage directory and the
can-migrate transient on deactivation.

**Preflight** (`Core/Preflight/`): `Checker` runs the local gates; `SiteProfile::gather()` collects
a site's facts; `Pairing` lets the source fetch the destination's profile live over HTTP, using a
single-use code the user pastes once; `Compatibility` compares two profiles and returns a `Report`.
The `Report` has four statuses, and **`indeterminate` maps to blocking** — a check that could not
run is not a check that passed. There are three checkpoints: at pairing, before packaging, and
again on the destination immediately before the first write, against live facts rather than
whatever the handshake saw days earlier.

**Package** (`Core/Package/`): `PackageWriter` owns the directory layout and nothing else should
build paths inside a package by hand. `Manifest` is written last, so its presence is what makes a
package complete. `PackageReader::verify()` checks sizes and SHA-256 against it. `Checkpoint` is
on disk, never in an option, because the import replaces the database underneath itself — and it
is written *after* the state it describes is flushed, so a resume can repeat work but never skip
it. Format is specified in `docs/package-format.md`; parts are standard zip, readable with `unzip`.

**Export** (`Core/Export/`): `Exporter` steps through the database dump and then the file parts.
`PartSpecs` is the one place that knows WordPress's directory layout, and derives every prefix from
where a directory actually *is* rather than assuming `wp-content/<name>`. `FileCollector` replaced
six near-identical archivers: everything that differed between them is data on a `PartSpec`.
`ConfigScanner` reads the source's `wp-config.php` with `token_get_all()` — read-only, never
`include`, never a regex.

**Symlinks are never packaged**, and are recorded in the manifest's `skipped_links` rather than
dropped silently. Following one copies content from outside the site into the package.

**Pause abandons the in-flight request rather than waiting for it.** A step cannot be interrupted
once it is inside `ZipArchive::close()`, so waiting was the two minutes that made the button look
broken. The step still completes and writes its checkpoint — safe, because the checkpoint only
advances after a successful close — and `Exporter`'s run lock stops a quick Resume starting a
second step on the same archive. Do not "fix" this by making Pause wait.

**Packaging is bound by the first read of each file, not by zip or compression.** Measured: 8,000
small files cost 185s cold and 2.0s warm, about 23ms each. So the per-file path is kept as thin as
possible — the walk takes the size from the iterator's own stat rather than calling `filesize()`,
and readability is settled by the open that has to happen anyway rather than by a second
`is_readable()`. Do not add syscalls to that path; a site can have a hundred thousand files in it.
For a site of that size the browser is the wrong tool at all, and the CLI plus the drop-in folder
is the answer.

**A zip volume is opened, filled and closed exactly once, and never reopened.** `ZipArchive::close()`
rebuilds the whole archive into a temp file rather than appending, so reopening one to add a few
more files rewrites everything already in it. Flushing every 64 files into a 1GB volume made a
1.5GB export cost hundreds of gigabytes of writing. The volume limit (128MB) is what bounds a
single close, and therefore what keeps a step inside a shared host's budget. Sizes and checksums
are taken at close for the same reason — hashing the whole package in `finalize` is one step that
cannot be split. Already-compressed extensions are stored rather than deflated.

**Import** (`Core/Import/`): `Importer` runs eight stages — precheck, files, database, transform,
users, validate, swap, fixups. **The order is the design.** Files land before the database; the
database loads into `nfdimp_`-prefixed staging tables the live site never reads; and every
remaining way to fail is placed before the swap. The swap itself is one multi-table `RENAME`, which
MySQL executes atomically, moving the live tables to `nfdold_` for rollback. Until that statement
runs, the destination is untouched and the user can simply retry.

`PathMap` maps a package's recorded paths onto this install's layout and is the security boundary
for untrusted archive input. `UserMerger` is the one table that merges rather than replaces
(plan §9.4). `Swap` also handles rollback and the retention window. `Fixups` repairs what the swap
leaves inconsistent — including re-adding this plugin to `active_plugins`, which the source's list
correctly does not contain.

Import state lives in `uploads/nfd-site-migrator/import/`, **not in the package** (plan D15).

**REST API** (`includes/Rest/`, namespace `nfd-site-migrator/v1`): controllers extend
`Rest\Controller` and are listed in `Rest\Routes::register_routes()`. Every route requires
`manage_options` except `/pairing/profile`, which is public by necessity and returns **404, not
401**, for a missing or wrong code — a 401 would make it an oracle for "a WordPress site with this
plugin lives here".

**CLI** (`includes/Cli/`): `wp site-migrator export|verify|import|rollback|cancel|confirm`. A
harness, not the v3 product — but a real second consumer of `Core/` from the day `Core/` existed,
and it is what makes the round-trip test a shell script.

**Frontend** (`src/`): mounts into `#nfd-sm-app`. `routes.js` picks the screen; `utils/useExport.js`
and `utils/useImport.js` drive the step loops. Calls go through `utils/api.js`, which converts
thrown errors into `{ error, failed: true }` rather than rejecting — callers check `response.failed`.

**Everything the import touches must survive the swap**, and three things in a browser do not
survive it on their own. The REST *nonce* dies with the users table, so `ImportController` clears
that specific error for a request bearing a valid import token (at priority **200** — core's own
check is registered at 100). The REST *root* dies if the two sites' permalink structures differ,
because apiFetch pins `/wp-json/…` at page render, so every import call goes through the
`?rest_route=` form via `restEndpoint()`/`stableCall()`. And the *auth cookie* names the user's
login, which the merge is allowed to change, so `Fixups` re-issues it. Do not "simplify" any of
these back to the idiomatic form.

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
