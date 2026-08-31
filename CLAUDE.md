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

Current state: **all phases are done** (0–3, 4a–4h, then 5, 6, 7, 8). A full migration works end to end, both
from the CLI and through wp-admin: pair, compare, package, then either hand the package over
directly or download and upload it, preview, import, roll back. What is left is the work that follows a first
release rather than precedes it: the gaps listed at the end of this file, and wp.org submission.

**Phase 5 has now run between two real WordPress installs**, after first being driven against a
`php -S` harness. The harness covered the round trip, resume from a truncated part, a damaged part
refetched, a revoked key, an oversized response, a mismatched package clearing the staging
directory, and the step budget splitting a transfer; two bugs came out of it and are fixed. The
real run — a 5.0GB source on Local, 550MB packaged, pulled over Apache by a second install —
covered the same ground through the CLI and through wp-admin, and found three more:

- **A re-export served the *previous* package's manifest for the whole of its run.** The manifest
  is what marks a package complete, so nothing had un-marked it; `Offer` answered 200 with sizes
  and checksums for parts that were being overwritten underneath it. Observed live: a part quoted
  at 1,699,040 bytes was already 6,054,712 bytes of the next package. `PackageWriter::invalidate()`
  now takes the manifest off before a run that is going to rewrite anything, and the endpoint
  answers 409 until `finalize()` puts it back.
- **`/import/pull/state` merged `Source::status()` over `snapshot()`'s `source`**, replacing a URL
  string with an object. React renders an object child as nothing at all, so the destination's pull
  screen was a blank admin page on every load — on the one screen whose promise is that a reloaded
  tab lands back on the running transfer. Note a hash-only navigation does not re-run the bundle,
  so the blank persists until a real reload; that is what makes it look unfixable.
- **The safety strip's second sentence said "Exporting only reads" on every destination screen.**
  It is now a `safetyDetail` prop, set on all five.

Also confirmed on the real run: the 3.18 and 3.19 fixes hold. The same site that once packaged
itself into 2.36GB with a 136MB `.git` pack now produces 550MB with `large: []` and 36 skipped
paths.

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

`npm run build` runs both halves. It used to hard-code `yarn` inside its own `build` and `start`
scripts, so on a machine without yarn it died at the first step; that is fixed, and CI uses npm
throughout. `engines.node` is `>=20`.

`build/` and `src/styles/nfd-site-migrator.css` are **generated and not tracked**. Build after
cloning or the admin page renders an empty div.

**npm, and only npm.** `yarn.lock` was tracked while every script, the build and CI all ran npm —
which ignores it — so installs resolved fresh and the lockfile locked nothing. `package-lock.json`
is the lockfile now and `npm ci` is what CI runs. `@wordpress/env` is gone with the Cypress
workflow that was its only caller; it pulled `@php-wasm/node`, whose native module does not build
on current Node, which is what broke `npm ci`.

**`typescript` is pinned by an override.** `@wordpress/eslint-plugin` depends on it without
constraining it, npm resolves 7.x, and `@typescript-eslint@5` cannot parse that — `lint-js` dies
before reading a line of source. `overrides: { typescript: ^5.9.0 }` holds it down.

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

**The storage directory is protected at its root, and preflight then *checks* that it worked.**
`nfd_sm_protect_directory()` writes `index.php`, `.htaccess` and `web.config` — Apache and IIS.
**nginx reads none of them**, and cannot be configured from inside the document root, so writing
them proves nothing. `Checker::check_storage_reachable()` therefore fetches the silence file over
HTTP and **blocks** when it comes back: a package holds `database.sql`, which is every table and
every password hash, at a guessable path under `uploads`.

Two things about that check. It confirms the *body* is the silence file rather than trusting a
200, because a host with a catch-all landing page would otherwise look exposed when it is not. And
a probe that cannot run **warns rather than blocks**, deliberately breaking the usual
"indeterminate is blocking" rule — many hosts refuse loopback HTTP to themselves, and being unable
to reach yourself is not evidence anyone else can.

Protection used to be written only by `PackageWriter`, so `package/` and `incoming/` had it and
`import/` did not: on a plain Apache host `import/import-checkpoint.json` returned **200**, leaking
absolute server paths, the table prefix, and every plugin and theme the import installed. One
`.htaccess` at the root covers everything beneath it.

**`wp site-migrator export` runs the local gates and refuses when they block.** It did not, so a
site whose package directory was downloadable wrote a full database dump into it and reported
success. The UI gated on this from the start; the CLI did not.

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

**Which is why *Check again* also probes.** Recomputing from remembered facts never touches the
network, so on a source that paired days ago the button could not fail — a destination that had
gone off the air still produced "Ready to migrate". `Pairing::reach()` asks `pairing/profile` with
**no code**, which `PairingController::profile()` short-circuits before `redeem()`, so the probe
costs the destination nothing and does not spend one of the ten attempts protecting a code
somebody is about to type. What it proves is narrow and must not be overstated: a 404 is what that
endpoint gives everyone, so an answer means only that *something* served HTTP there. A transport
error is the real signal. It is reported and does not block — a package can still be downloaded
here and uploaded there by hand; it is the *direct transfer* that needs one site to open a
connection to the other. The screen's error state was the other half of the bug: `recheck()` fell
back to the stored reading on **every** failure, a fallback written for an expired code, and
rendered nothing at all when a call failed.

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

**A fresh export starts on an empty directory, and this is load-bearing.** Nothing an export
writes is incremental across runs, so `Exporter::step()` calls `PackageWriter::reset()` when the
checkpoint shows a run that has written nothing yet. Overwriting by name is not enough, and getting
this wrong produced a package that verified perfectly and then failed on import:

- **`database.sql` was opened `'cb'`** — create, never truncate — and seeked to the resume offset,
  which is right for a resume and wrong for a fresh dump. A shorter new dump left the older one's
  tail in place, so the file held a complete dump, its `-- Dump complete.` trailer, and then the
  middle of the previous one. The join landed three bytes into an `INSERT`, and the import died on
  `ERT INTO`. It now truncates when `0 === $query_offset`.
- **`ZipArchive::CREATE` adds to an archive that already exists.** Every re-exported volume
  inherited the previous run's entries: 296MB of site packaged as 1.3GB, and an import that
  restored 123,700 files from a manifest naming 44,078. Now `CREATE | OVERWRITE`, which is what
  "opened, filled and closed exactly once" already claimed.

**None of this was catchable downstream.** The corruption existed before the package was hashed, so
`verify` passed, and the transfer's per-file checksums passed — both correctly confirming corrupt
bytes. A checksum proves a file arrived intact; it cannot prove it left intact. This is the reason
the export's own output is now the thing that has to be right.

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
(plan §9.4). `Swap` also handles rollback and the backup tables.

**A backup lasts until its import is kept, or until the next migration starts — not 30 days**
(plan D8, revised). The cap used to make a new import *refuse* while a previous backup was inside
its window, so a rule meant to protect one migration blocked the next, and the way out was a button
on a screen the user had already left. `check_previous_backup()` now discards and says so in a
note. The import being run is still fully reversible; what is gone is reaching back past it.

**`confirm()` saves the checkpoint before dropping the tables**, because the drop cannot be undone
and the save can be repeated. The other order was found live: a destination with no backup tables
and a checkpoint still offering to roll back to them, whose `rollback()` then blamed a retention
window that had not expired. `Fixups` repairs what the swap
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

**Search-replace covers both schemes.** Pairs are built from `site_url` and `home_url`, plus the
slash-escaped form that Gutenberg block attributes and JSON meta use, plus **both of those under
the opposite scheme**: a site on `http://` still accumulates `https://` references to its own host,
and a real import left `yith_shippo_webhook_address` pointing at the source because only the http
form was searched for. Only a leading `http://`/`https://` is flipped — never a protocol-relative
`//host` or a bare hostname, which match far more than this site's own address.

**`php.version` is the version of whatever PHP is running the code**, so a CLI export records the
command-line binary and not the SAPI serving the site. One real pair reported a source as 8.5.9
that serves 8.4.18, which made the destination's 8.5.3 look like a downgrade when it was an
upgrade. It cannot be fixed from inside a CLI process, so `php.sapi` travels beside it and
`Compatibility::check_php()` says the number was measured from the command line rather than
asserting what it cannot know. The *blocking* gate reads `php.requires` from plugin headers, not
this, so what was ever at risk is advice rather than safety.

**Transfer** (`Core/Transfer/`): the v2 transport, where the destination fetches the package
itself instead of a person carrying it. `TransferKey` is the source's credential, `Offer` is what
a package will hand over, `Source` is the destination's memory of who it is pulling from, and
`Puller` does the fetching with `step( $budget )` like everything else.

**The plan said `PackageReader` would grow a remote backend. It cannot.** The parts are zip
archives and `ZipArchive` reads a local file, so an import driven straight off HTTP would mean
reimplementing zip. It is also unnecessary: the import already needs the package on disk. So the
pull writes into `Upload::dir()` — the same staging directory a browser upload fills — and
verification, preview, `Importer`, `PathMap` and rollback are untouched. Only the carrying changed.

**Pull, not push**, so the work happens inside the destination's own request: the site doing the
writing is the site reporting the progress, which is the same contract as every other step loop
here. It also means the person authorising the overwrite is standing at the machine being
overwritten. The credentials mirror each other — a destination mints a **pairing code** so a site
cannot be *targeted* by a stranger, a source mints a **transfer key** so a site cannot be *read*
by one — and neither half of a migration can be started from outside.

**The transfer key is long and its hash is fast, which is the opposite of the pairing code.** A
pairing code is twelve characters because a human reads it aloud, so it is stored under
`wp_hash_password()`; there is nothing to guess in 48 characters of `random_bytes()`, and the
check is paid on every one of the hundreds of requests a large pull makes. It expires on
**idleness**, not on a clock — a fixed fifteen minutes would fail every 20GB migration, the one
case this exists for — and it **binds to the first caller's address**, so a key that leaks once a
transfer is under way is already useless. A wrong key gets a 404, not a 401, for the same reason
`/pairing/profile` does.

**Progress is the bytes on disk, not a number anything keeps.** Sizes come from the manifest the
source declared; how far along each file is comes from `filesize()`. There is no checkpoint to
fall out of step with the files, a step never writes a progress record, and a closed tab, a second
tab and a different machine all see the same transfer. `Source` holds only the connection, and
drops the key the moment the last byte lands.

**A step must always make one attempt before the budget can stop it**, and it must hand back the
run lock on the way out. Both of these were bugs. Without the first, a budget already spent by the
time the walk reaches an incomplete file — which a large package's walk over its finished parts
can do on its own — returns having fetched nothing, and the caller loops forever against a number
that never moves. Without the second, every budget-limited step left the lock held and the next
one refused to work. "As much as fits" has a floor of one.

**Every completed file is hashed as it lands**, rather than verifying the whole package in one
request at the end as the upload path does. Same total reading, spread across the transfer, and a
part that arrives wrong is refetched immediately instead of ten gigabytes later. Two refetches per
file, counted in a file beside the staging directory; after that it says what is altering the
bytes rather than trying again forever.

**The source serves only what the manifest names**, which is narrower than the download endpoint's
"anything inside the package directory", and it refuses a response larger than the manifest leaves
room for. `sslverify` is always on and never tied to this site's own scheme (finding 2.5).

**REST API** (`includes/Rest/`, namespace `nfd-site-migrator/v1`): controllers extend
`Rest\Controller` and are listed in `Rest\Routes::register_routes()`. Every route requires
`manage_options` except `/pairing/profile`, which is public by necessity and returns **404, not
401**, for a missing or wrong code — a 401 would make it an oracle for "a WordPress site with this
plugin lives here".

**CLI** (`includes/Cli/`):
`wp site-migrator preflight|export|inspect|verify|import|rollback|cancel|confirm|offer|pull`. A
real second consumer of `Core/` from the day `Core/` existed, which is what makes the round-trip
test a shell script — and, since phase 6, a supported surface rather than a harness.

**The machine contract lives in `Cli\Output`, not scattered through the commands.** `SCHEMA` for
`--format=json`, the `EXIT_*` codes, `progress()` (always stderr, so the stdout contract does not
depend on who is watching), `emit()` (json gets the nested payload, table/csv/yaml get flat rows),
and `confirm()` (which fails rather than asking when `--format` is machine-readable or stdin is not
a TTY). `Commands` has no bare `WP_CLI::error()` left; every failure carries a code.

**Exit `3` needs `--max-time`, and that is not a detail.** `--budget` bounds a *step*, while
`Exporter::run()` and `Importer::run()` loop internally until done — so a budgeted run still only
returns when everything has finished, and "stopped early, run me again" had no way to be
expressed. `Commands::drive()` moves the step loop into the CLI (nothing in `Core/` changed) and
**makes the remaining time the step's own budget**: check the clock only between steps and a
single unbounded step does the whole job before it is ever consulted. Do not "simplify" `drive()`
back to calling `run()`.

**`preflight` and `inspect` wrap what already existed** — `Checker` + `SiteProfile` + `Pairing` +
`Compatibility`, and `PackageReader::inspect()`. `inspect` is the cheap counterpart to `verify`:
the manifest alone, no byte-for-byte re-read. `import` now runs `Importer::preview()` *before* the
confirmation, so a package that was never going to work says so instead of first making somebody
agree to it — and it is the same call the review screen makes, which is what keeps the two
surfaces agreeing on what counts as a blocker.

**Frontend** (`src/`): mounts into `#nfd-sm-app`. `routes.js` picks the screen; `utils/useExport.js`,
`utils/useImport.js` and `utils/usePull.js` drive the step loops. Calls go through `utils/api.js`,
which converts thrown errors into `{ error, failed: true }` rather than rejecting — callers check
`response.failed`.

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

**The last source step is *Deliver*, and two screens share it.** `/send` hands the package over
directly and `/download` is the fallback for a source the destination cannot reach; both render
with `step="deliver"`, because they are two ways through one step of one journey rather than two
steps competing for a place in the stepper. Each links to the other. On the destination `/import`
and `/import/pull` share `step="choose"` the same way.

**A reload during a transfer lands back on it.** `Resume` checks the pull after the import and
before the export, and only an *unfinished* transfer captures the redirect — once every file is
here the staged directory shows up in `Upload::discover()` like any other package, because
`incoming` is a child of the storage path, and `/import` lists it.

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

**`Masthead` names the product, because nothing else does.** wp-admin gives a plugin no title on
its own page: each screen's `<h1>` describes the step, and the sidebar entry is not on the reading
path. It is rendered by `Layout` above the shell, so it is on every screen, and it is deliberately
quieter than the heading beneath it — 15px against 31px, a `<p>` rather than a second heading. The
mark is an inline SVG in the same file rather than an image, for the reason the fonts are bundled
and because only an inline one inherits `currentColor`; the glyph is a package with motion lines
behind it, and the first draft — a container with an arrow leaving through its open side — was
redrawn because that is the standard sign-out icon. The **menu** icon stays `dashicons-migrate`: a
data-URI SVG there does not recolour with the menu's hover and current states, and a mark that
cannot follow them looks broken rather than branded.

**The fonts are bundled, never hot-linked** — a plugin on wp.org may not call a third party to
draw its own admin screen. `assets/fonts/` holds four variable woff2 files (Public Sans and
JetBrains Mono, latin and latin-ext, 98KB in total) with their OFL licences beside them; the
`@font-face` rules are at the top of `app.css`, and webpack emits the files into `build/fonts/`
with hashed names and rewrites the URLs. One axis, `font-display: swap`, and stacks that fall
back to what wp-admin already has. They are **not** an npm dependency — the woff2 files are
committed and nothing in the build reaches for a package; `CREDITS.md` records where they came
from and what refreshing them involves. Note this machine has no yarn, and `npm install` rewrites
the whole of `yarn.lock` into npm registry URLs, so adding a dependency here is not a small act.

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

- Minimum PHP is **7.4** and minimum WordPress **5.8**, declared in three places that must agree:
  the plugin header, phpcs `testVersion`/`minimum_supported_wp_version`, and the hard-coded
  `WP_Forge_Plugin_Check` call in `nfd-site-migrator.php`. The last one is the one that gets
  forgotten — it sat at 5.6/4.7 through the whole rework. Its `req_php_extensions` must list
  `zip`, because every part of a package is a zip archive.
- **The floor rising does not mean the style changed.** `array()` throughout, no typed properties,
  no arrow functions — modern syntax is now permitted, not mandated, and a mechanical rewrite of
  the codebase buys nothing. New code may use it where it earns its place.
- PHPCS uses the `Newfold` standard from `newfold-labs/wp-php-standards`, resolved from the Satis
  repository declared in `composer.json`. It is not on Packagist, so that `repositories` block has
  to stay. `WordPress.WP.AlternativeFunctions` and `WordPress.DB.RestrictedFunctions` are downgraded
  to severity 0 because the packager needs raw file and DB access.
- Procedural helpers go in `functions.php` with the `nfd_sm_` prefix; classes go in `includes/`
  under the PSR-4 namespace.

## Distribution

```bash
composer build:zip     # bin/build-zip.sh  -> dist/nfd-site-migrator.zip
composer verify:zip    # bin/verify-zip.sh -> installs it into a throwaway WordPress, 16 checks
```

**The directory inside the zip is the slug, not the repository name.** The repo is
`wp-plugin-site-migrator`; the slug and text domain are `nfd-site-migrator`. The release workflow
used `${REPO##*/}` and so produced the former — which the plugin survives, because
`nfd_sm_plugin_basename()` exists for exactly that, but which wp.org rejects.

**The build is verified, not trusted.** `build/` is generated and untracked, so a zip made from a
fresh checkout without running the asset build ships a plugin whose admin page is an empty div —
which looks exactly like a working install until somebody opens it. `bin/build-zip.sh` runs the
build and then asserts the bundle, the stylesheet, the asset manifest and the fonts are all in the
staged tree before it zips anything.

**Two things ship for licence reasons and must not be tidied away.** `CREDITS.md` is the
attribution record for the All-in-One WP Migration code, and `.distignore` used to exclude it via a
blanket `*.md`. `assets/fonts` is kept even though webpack emits hashed copies into `build/fonts`,
because the OFL licence texts live beside the sources. `bin/verify-zip.sh` asserts both.

Composer's manifests are copied into the staging directory to run `install --no-dev` and removed
again, so the working tree keeps its dev dependencies — building a zip must not delete the test
suite.

## Licensing

GPL-2.0-or-later, with the licence text in `LICENSE`. `Database/`, `Archiver/`, and
`Utils/DatabaseUtility.php` derive from All-in-One WP Migration (ServMask, Inc.) and carry
attribution headers; `CREDITS.md` records the details. Preserve both when editing those files.

## Tests

```bash
composer test              # phpunit, no database, ~0.2s
composer test:roundtrip    # two real WordPress installs -- see below for what it needs
```

**Two suites, deliberately different in kind.**

`tests/Unit/` runs against `tests/bootstrap.php`, which fakes the small set of WordPress functions
`Core/` actually calls. That is only possible because `Core/` is transport-agnostic, and it is what
keeps the suite fast enough that people run it. It cannot test anything that needs WordPress to
really be there — the REST controllers, the swap, the users merge — and pretending otherwise by
stubbing harder would only test the stubs. `RegressionTest` is named for the defects that happened
rather than the classes they live in, because that is what a reader needs six months later.

`tests/roundtrip.sh` provisions two installs from scratch **at different URLs and different table
prefixes**, then migrates between them: 38 assertions covering the export, a damaged package
refused, the import, serialized options surviving unserialization, Gutenberg attributes with
escaped slashes, uploads by checksum, the users merge against a deliberate account overlap,
rollback, and the same import driven **one process per step**. It needs `wp`, a MySQL server *and
its client on PATH*, and permission to create two databases. On macOS the client is the usual
missing piece: `NFD_MYSQL_DIR` puts one on PATH, and `NFD_DB_HOST` takes `localhost:/path/to.sock`.

**Write the fixture guards.** Four of the round trip's first failures were the test being wrong,
not the plugin: `wp post create` leaves `post_author` at 0 under WP-CLI, `wp option add` given an
already-serialized string double-serializes it, MySQL's `LIKE` eats the backslash in an escaped
URL so the pattern matches the plain one too, and `$?` read after an assignment is the
assignment's. Each would have passed for the wrong reason. The script now asserts its own fixture
is what it thinks before testing anything with it — and needles are bound through `prepare()`
rather than pasted into a `LIKE`.

`tests/playwright/` follows the layout the other plugins in this org use — `specs/`, `helpers/`
with an `index.mjs` barrel, `global-setup.js`, `playwright-projects.json`, and
`playwright.config.mjs` at the root. It is **unstubbed** (plan §12 item 9, which had never
existed): nothing is intercepted, so every assertion goes through the real REST API.

It exists to catch **the blank admin page** — the failure this plugin keeps having, from a wrong
`plugin_dir_url()` under a symlink, a REST payload handing React an object where it wanted a
string, and a resume redirect that returned `null`. All three look identical to a user and none are
visible to a unit test. `wordpress.waitForApp()` asserts a *child* of `#nfd-sm-app`, because the
mount point itself is printed by PHP and proves nothing.

**Two ways to serve the site under test, and running both is what makes the suite honest.** CI
starts `wp-env` as its own workflow step and Playwright connects — a container that fails to come
up should read as an environment failure, not a test failure. `NFD_E2E_SERVER=builtin` instead
provisions with WP-CLI and serves with PHP's own server, which needs no Docker *and* ignores
`.htaccess` exactly as nginx does. `PHP_CLI_SERVER_WORKERS` is set because that server is
single-threaded by default and the admin app fires several REST calls at once.

**The two servers disagree, on purpose, and a test must not.** Under wp-env's Apache the
`.htaccess` the plugin writes works and `check_storage_reachable()` **passes**; under `php -S` it
is ignored and the same check **blocks**. An assertion written against the refusal passed on one
server and failed on the other — a test measuring its environment rather than the code. Assert on
`.nfd-sm-gates`, which renders whenever a report arrived, not on any particular verdict.

**wp-env needs Node ≤ 25 and ports clear of LocalWP.** `.wp-env.json` uses 8888/8889 because
LocalWP occupies 10000+ on a developer machine and `wp-env start` dies on the collision. The
Node ceiling is the `@wordpress/env` note below.

**`@wordpress/env` is an `optionalDependency`, deliberately.** It pulls `@php-wasm/node`, whose
native module ships prebuilt binaries only up to Node 25 — on Node 26+ it cannot install, and as a
hard dependency it would take the whole `npm install` down with it. Optional means npm warns and
carries on. The CI job asserts it is present before using it, so its absence is stated plainly
rather than surfacing later as a connection refused.

**The site under test is built in the system temp directory, not in the repo.** Built inside it,
the plugin directory contains the site that contains the plugin: asset URLs come out recursive, and
the exporter would package a WordPress install into its own fixture.

**A test that cannot fail is not a test.** Both suites have been checked by breaking the code they
cover — an exit-code constant, a manifest key, the escaped-slash replacement pair, and the admin
page's script enqueue — and confirming each goes red, then green again. The escaped-slash case failed to fail the first time,
which is how the block fixture came to exist.

**Four workflows, and exactly one of them publishes anything.** `lint.yml` (phpcs), `tests.yml`
(PHPUnit on 7.4 and 8.3, the round trip, a `package` job that builds the zip and installs it into a
real WordPress, and the browser suite under wp-env), `ai-code-review.yml` (a Newfold reusable), and
`upload-asset-on-release.yml`, which runs **only** on a published release and attaches the zip with
`gh release upload`. Node is 24, which is what GitHub Actions recommends.

Two workflows were deleted rather than repaired. `upload-artifact-on-push.yml` built and uploaded a
zip on every push to master. `cypress.yml` uploaded failure screenshots from
`tests/cypress/screenshots`, a path that never existed — and its one spec drove
`#check-compatibility-button` and `#begin-transfer-button`, neither of which has appeared anywhere
in `src/` since the rework. It was testing a deleted UI through a fully stubbed REST layer.

**`lint.yml` no longer runs `composer fix` before linting** — it did, which meant CI could not fail
on anything phpcbf repairs, while running a fixer that rewrites string literals.

**The release workflow calls `bin/build-zip.sh`.** Four workflows used to build the plugin four
different ways, none of them the way a person does it locally. `actions/upload-release-asset`,
which the release depended on, has been archived by GitHub since 2021; `gh` is on every runner and
needs no third-party action.

## Known gaps

Carried forward deliberately. None of these are covered by the round-trip suite.

- No test against a host with a genuinely small `upload_max_filesize`; the chunk size is taken
  from the server but has only ever been exercised against a generous one.
- A multi-gigabyte upload through the browser has never been watched end to end.
- No in-place fallback for a host without `RENAME TABLE` (plan §9.3). Preflight probes for it and
  reports it, so such a host is refused rather than half-migrated.
- The lossy `utf8mb4` → `utf8` branch is coded and never exercised.
- The `LOOSE_THRESHOLD` (64MB) and `VOLUME_LIMIT` (128MB) have never met real shared hosting. Plan
  D3 shipped the first as proposed and cut the second to an eighth of it, and its validation clause
  is explicitly still open.
- View recreation has been read and not run — the fixture has no views.
- Multisite is blocked at preflight on both sides — not thin coverage, a feature that does not
  exist yet.
- The direct transfer has run between two WordPress installs, but both were on one machine behind
  Local's Apache. Still untested: a host with a proxy in front of it, a TLS certificate that a
  `wp_remote_get` would argue with, and the IP binding against a source reached through more than
  one egress address — which would refuse a legitimate transfer, and whose fix is to issue a new
  key.
- The `utf8mb4` note above still stands; the leftover-output problem that used to sit here was a
  correctness bug, not a disk one, and is now fixed — see *A fresh export starts on an empty
  directory*.
- (`Puller::reconcile()`'s missing guard was fixed — it now makes the same refusal
  `Upload::discard()` does, against the same directory.)
- Nothing has been transferred through a host that buffers or rewrites `Range` responses, which is
  the failure the per-file checksum exists to catch and the one most likely to need a real site to
  find.

## Git Commits
- Keep commit messages under one line, ~50 chars max
- Format: `<type>: <change>` (e.g., `fix: auth token validation`)
- No bullet points, no explanations, no Generated with Claude Code trailer
- Never describe implementation details or trial-and-error
