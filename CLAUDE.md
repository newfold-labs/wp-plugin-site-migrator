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

Current state: **phases 0–4 are done** (4a–4h). A full migration works end to end, both from
the CLI and through wp-admin: pair, compare, package, download, upload, preview, import, roll
back. What is left is phase 5 (direct site-to-site transfer), phase 6 (WP-CLI as a supported
surface), phase 7 (tests and CI) and phase 8 (hardening and distribution).

**Calibrate your confidence from how 4c–4h were found.** Every one of them came from somebody
using the plugin on a real site, not from review — including two data-integrity bugs (3.18, the
export packaging its own package; 3.19, a 129.8MB `.git` directory) that review had walked past
for days. The happy path is solid and well covered by the round-trip suite. The unknowns are
listed under *Known gaps* at the end of this file, and the way to close them is phase 7, not
more 4x sub-phases.

## Commands

```bash
# PHP
composer lint         # phpcs (Newfold standard)
composer fix          # phpcbf — read its diff, see below

# JS/CSS
npm run generate:css                              # assets/styles/app.css -> src/styles/
npx wp-scripts build ./src/nfd-site-migrator.js   # -> build/
npx wp-scripts lint-js src                        # --fix to autofix
```

**`package.json` hard-codes `yarn` inside its own `build` and `start` scripts**, so on a machine
without yarn `npm run build` dies at the first step instead of falling back. Run the two halves
directly, as above, or install yarn.

`build/` and `src/styles/nfd-site-migrator.css` are **generated and not tracked**. Build after
cloning or the admin page renders an empty div.

`.wp-env.json` exists but wp-env is not provisioned here; the working local setup is two
WordPress installs driven by the CLI (see *Tests*).

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
and cache busting comes from the content hash in `nfd-site-migrator.asset.php`. The old
four-place scheme made `WP_Admin::register_assets()` silently skip enqueueing on a mismatch.

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

**Anything a long request might race must not live inside that array.** It is read once and
written back *whole* on shutdown, so a request that runs for minutes — an export step — persists
a copy of the world as it was when the request started, silently undoing whatever landed in the
meantime. The export's paused flag (`NFD_SM_PAUSED_OPTION`) is standalone for exactly that
reason: it is set while a step is in flight. Same instinct as the on-disk checkpoint.

**Preflight** (`Core/Preflight/`): `Checker` runs the local gates; `SiteProfile::gather()` collects
a site's facts; `Pairing` lets the source fetch the destination's profile live over HTTP, using a
single-use code the user pastes once; `Compatibility` compares two profiles and returns a `Report`.
The `Report` has four statuses, and **`indeterminate` maps to blocking** — a check that could not
run is not a check that passed. There are three checkpoints: at pairing, before packaging, and
again on the destination immediately before the first write, against live facts rather than
whatever the handshake saw days earlier.

`Pairing::fetch_profile()` reaches the destination through **`?rest_route=` first**, `/wp-json/`
only as a fallback: a site on plain permalinks serves only the query form and answers the path
form with a redirect to its home page, which arrives as HTML and reads as "the plugin is not
installed there" (finding 3.17). Same hazard as the import UI's `restEndpoint()`, on the outbound
side. An answer that arrives as JSON is the REST API's own, so it is final and no second URL is
tried.

`Destination` remembers who this source paired with, so backtracking does not demand a new code
from the other site. It stores the destination's **facts, never the verdict** — the comparison is
recomputed on every read, because half of it is this site and this site changes. The code itself
is deliberately not stored.

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

**Neither is anything the site does not need to run.** `PartSpecs::$excluded_names` refuses
`.git`, `.svn`, `.hg`, `.bzr`, `CVS` and `node_modules` **wherever they appear** — matched on the
directory's own name, because a `.git` nine levels down inside a vendored dependency is still a
`.git`, and one was found at 129.8MB (3.19). Core's `upgrade` directories go too. All of it is
listed in the manifest's `skipped_paths`; filter `nfd_sm_excluded_names` to change it.

**The storage directory is excluded from every part that contains it**, computed from where it
actually is. Without this the export packages the package — a 1GB site produced 2.2GB containing
a copy of itself (3.18). The old exclusion named `content-other`, which can never see it, since
`content-other` already excludes all of `uploads`.

**The checkpoint is readable without advancing it.** `Exporter::snapshot()` returns the same
shape a step returns, plus `running` from the lock. A reloaded tab draws the run it is joining
before asking for more work — without it the screen shows zeroes until the first step returns,
which on a large site is half a minute of a page that looks like it lost the export. Pausing is
recorded server-side (`NFD_SM_PAUSED_OPTION`) so a refresh does not silently resume a run
somebody deliberately stopped; reopening a *closed tab* still auto-resumes, which is the promise
the screen makes.

**Pause abandons the in-flight request rather than waiting for it.** A step cannot be interrupted
once it is inside `ZipArchive::close()`, so waiting was the two minutes that made the button look
broken. The step still completes and writes its checkpoint — safe, because the checkpoint only
advances after a successful close — and `Exporter`'s run lock stops a quick Resume starting a
second step on the same archive. Do not "fix" this by making Pause wait.

**Packaging is bound by the first read of each file, not by zip or compression.** Measured: 8,000
small files cost 185s cold, 2.0s warm — ~23ms each. So the per-file path stays thin: the walk
takes size from the iterator's own stat, and readability is settled by the open that has to
happen anyway. Do not add syscalls there; a site can hold a hundred thousand files, and for one
that size the browser is the wrong tool at all — CLI plus the drop-in folder is the answer.

**A zip volume is opened, filled and closed exactly once, and never reopened.** `ZipArchive::close()`
rebuilds the whole archive into a temp file rather than appending, so reopening one rewrites
everything already in it — flushing every 64 files into a 1GB volume made a 1.5GB export cost
hundreds of gigabytes of writing. The 128MB volume limit bounds a single close, which is what
keeps a step inside a shared host's budget; volume size adapts on observed **files per second**,
not bytes, because the cost is per file. Sizes and checksums are taken at close for the same
reason — hashing the whole package in `finalize` is one step that cannot be split.
Already-compressed extensions are stored rather than deflated.

**Import** (`Core/Import/`): `Importer` runs eight stages — precheck, files, database, transform,
users, validate, swap, fixups. **The order is the design.** Files land before the database; the
database loads into `nfdimp_`-prefixed staging tables the live site never reads; and every
remaining way to fail is placed before the swap. The swap itself is one multi-table `RENAME`, which
MySQL executes atomically, moving the live tables to `nfdold_` for rollback. Until that statement
runs, the destination is untouched and the user can simply retry.

`PathMap` maps a package's recorded paths onto this install's layout and is the security boundary
for untrusted archive input. It also **refuses to write the migrator itself**: the running plugin
by both the address the site uses and the real one behind a symlink, this plugin under the name it
ships as (a second copy is two sets of the same classes, a fatal error rather than untidiness),
and an import loader left in `mu-plugins` by a package built from a site that was mid-import. The
export already excludes itself, so ordinarily nothing matches — this is the destination declining
to bet the running importer on a package it did not build. String comparison against a list built
once, because it runs per entry. `UserMerger` is the one table that merges rather than replaces
(plan §9.4). `Swap` also handles rollback and the retention window. `Fixups` repairs what the swap
leaves inconsistent — including re-adding this plugin to `active_plugins`, which the source's list
correctly does not contain.

**Rollback undoes the database exactly and the files only where it honestly can.** `AddedCode`
snapshots the places a package installs into — `plugins`, `mu-plugins`, the recognised drop-ins in
`wp-content`, and every registered theme root, named the way `PartSpecs` names its theme parts —
at precheck, before the first file is written, and takes the difference once the files stage
finishes. Rollback deletes exactly that difference. Anything the destination already had and the
package overwrote stays: its own copy is gone by then, so deleting it would turn an incomplete
rollback into a destructive one. Two of the slots are not cosmetic — a must-use plugin and a
drop-in are loaded from disk with no reference to any option, so before this they went on
*running* after the rollback that was supposed to remove them. The before-snapshot is passed to
the delete as well as the diff, so "never deletes what this site already had" is checked where the
deleting happens. Nothing else a package carries is tracked, `uploads` least of all: a media file
added after the import is indistinguishable from one the package brought, and getting that wrong
destroys work.

`Upload::discard()` deletes a package from the screen that lists them, which is the only place
they are visible — they are whole sites, 2.2GB each on the test install. The path is **matched
against what `discover()` itself reports**, by `realpath()`: an endpoint that deletes the directory
it is handed is an arbitrary-deletion endpoint, and `manage_options` is not reason enough for one
to exist. It also refuses a path holding this site's own import state, and the package of a run
that is not settled. Note `realpath('')` is the *working directory*, not nothing, so an empty
parameter is refused before it is resolved rather than compared after.

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

**Everything the import touches must survive the swap**, and four browser things do not on their
own. The REST *nonce* dies with the users table, so `ImportController` clears that error for a
request bearing a valid import token, at priority **200** — core registers its own check at 100.
The REST *root* dies when the two sites' permalink structures differ, because apiFetch pins
`/wp-json/…` at page render; every import call goes through `?rest_route=` via
`restEndpoint()`/`stableCall()`. The *auth cookie* names the user's login, which the merge may
change, so `Fixups` re-issues it. And the plugin's own *asset URLs* die when its directory is a
symlink: `plugin_dir_url()` resolves one only through `$wp_plugin_paths`, which `wp-settings.php`
populates from `active_plugins` and **never for an mu-plugin** — so a plugin loaded by the import
loader emits `…/wp-content/plugins/Users/you/src/build/app.js`, 404s both assets, and renders a
blank admin page on the screen where the migration is accepted or undone. Do not "simplify" any of
these back to the idiomatic form.

**So there is no plugin-URL constant, and there must not be one.** mu-plugins load at
`wp-settings.php:506` and the active-plugin loop that registers symlinks runs at 583, so anything
computed while this plugin is being included is computed too early — which is why activating the
plugin does not repair a page whose URLs were already baked. `nfd_sm_plugin_url()` answers at the
moment it is asked, and asks `plugins_url()` about the path *under `WP_PLUGIN_DIR`*, which is a
plain prefix strip needing no mapping at all. `WP_Admin::register_assets()` calls it at
`admin_enqueue_scripts`. The loader also registers the mapping before requiring the plugin, so
anything else reaching for `plugin_dir_url()` during an import gets a sane answer.

**The plugin's directory is not its slug.** `nfd_sm_plugin_basename()` answers what `active_plugins`
has to contain, checked against the filesystem and falling back to a search of the plugins
directory for the entry that resolves to this one — `plugin_basename()` alone is wrong exactly when
the plugin is loaded from `mu-plugins`, which is when the import needs it. `Fixups` guessed
`nfd-site-migrator/nfd-site-migrator.php` instead, which activated nothing on a checkout or a
symlinked working copy, and `drop_missing_plugins()` then stripped the entry two lines later as a
plugin whose file is not there. `PartSpecs`'s self-exclusion and `AddedCode`'s ignore list use the
same helper.

**A finished import stays `stage: done` after it is rolled back or kept**, and until this was
handled that record was what stopped the next migration: from the same package `step()` reported
the old run as done and did nothing, from a different one `assert_not_busy()` refused, and
`Resume` sent every visit back to a decision screen with two buttons that could now only fail.
So a *settled* run — rolled back, or confirmed — is forgotten by `step()` before it starts, is not
what `Resume` routes to, and does not decide the Done screen's own click state (which settles from
the server's `rolled_back` / `confirmed`). A finished run that is still **undecided** stays put:
its backup tables are the only copy of this site as it was. `Importer::rollback()` and `confirm()`
say which of the two already happened rather than "nothing to undo".

**Every screen that waits says so** — `components/Loading.js`, used in all nine places that fetch
before they can render. The resume redirect used to `return null`: a blank admin page, on the
first thing anybody sees.

**Nothing on the Download screen blocks on hashing.** `/export/manifest` returns the file list
already on disk; `/export/verify` re-reads every byte and runs **only when asked**. It was never
the check that protects anybody — checksums are taken as each volume closes and the destination
re-verifies before it writes — so it is a button, not a minute per visit. Download-all stays
enabled until a check has *actually failed*: unverified means unknown, not broken.

**Download-all is a browser loop, never a server-side zip.** The package is already compressed,
so re-zipping it costs the whole site's size in disk and time for a file that is no smaller and
cannot be resumed. `utils/download.js` streams each file into a folder the user picks (File
System Access API, subdirectories preserved, so the folder goes straight to the destination's
picker), falling back to sequential browser downloads that report what they *started* rather than
pretending to know when they finish.

Styling is Tailwind, but not through PostCSS in webpack: `yarn generate:css` compiles
`assets/styles/app.css` into `src/styles/nfd-site-migrator.css`, which the JS entry imports.
Component styles are `@apply` classes scoped under `.nfd-sm`.

**The look comes from the export-flow design handoff**, and it lives in two files only:
`tailwind.config.js` holds the tokens by name — `ink`, `canvas`, `hair`, `edge`, `pass`, `warn` —
so `@apply` reads the way the handoff does and a token that moves moves once; `app.css` holds
every component rule. The screens describe structure and were barely touched by the restyle. Two
things are worth knowing before editing either. **`.nfd-sm-note` is a grid, not a flex row**: a
note is often a sentence *plus* a list (the refused paths), and every child belongs in the second
column beside the badge rather than next to its siblings. And **the panel is one card with three
bands** — safety strip, stepper, body — which is why `.nfd-sm-shell` clips its children and
`.nfd-sm-body` carries the padding.

**The fonts are not bundled.** The handoff asks for Public Sans and JetBrains Mono, self-hosted
rather than hot-linked, for WP.org compliance. Both stacks are declared and both currently fall
through to what wp-admin already has, so the layout is right and the faces are not. Dropping the
files in and adding the `@font-face` rules is all that is left.

## Dead code: two kinds, opposite fates

Read plan §11.1 before deleting anything that looks unused. Code can be *abandoned* (delete it)
or *orphaned* — dead only because the import half was stripped when these files were forked from
All-in-One WP Migration.

Phase 4a proved the distinction was worth keeping: `DatabaseUtility::replace_serialized_values()`
looked as dead as the rest and is now the core of `Core/Import/SearchReplace.php`. Still dormant
and still worth keeping: `is_atomic_query()`, `replace_table_collations()`, `repair_table()`,
`DatabaseBase`'s `is_*_query()` predicates.

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

## Tests

`.github/workflows/` runs lint and Cypress. **The round-trip suite is not in the repo** — it is
shell scripts driving two local WordPress installs, and rebuilding it is phase 7's job. Until
then, "verified" in a commit message means somebody ran it by hand.

The one surviving Cypress spec (`checkCompatibility.cy.js`) stubs the REST layer with
`cy.intercept` against URL-encoded `rest_route` paths, backed by `cypress/fixtures/`. `cy.login()`
skips the form when already authenticated. The others covered deleted screens. Plan §12 replaces
this approach entirely: these specs stub the whole backend and cannot catch a single defect in
the analysis.

## Known gaps

Carried forward deliberately. None of these are covered by the round-trip suite.

- No test against a host with a genuinely small `upload_max_filesize`; the chunk size is taken
  from the server but has only ever been exercised against a generous one.
- A multi-gigabyte upload through the browser has never been watched end to end.
- No in-place fallback for a host without `RENAME TABLE` (plan §9.3). Preflight probes for it and
  reports it, so such a host is refused rather than half-migrated.
- The lossy `utf8mb4` → `utf8` branch is coded and never exercised.
- View recreation has been read and not run — the fixture has no views.
- Multisite is blocked at preflight on both sides — not thin coverage, a feature that does not
  exist yet.

## Git Commits
- Keep commit messages under one line, ~50 chars max
- Format: `<type>: <change>` (e.g., `fix: auth token validation`)
- No bullet points, no explanations, no Generated with Claude Code trailer
- Never describe implementation details or trial-and-error
