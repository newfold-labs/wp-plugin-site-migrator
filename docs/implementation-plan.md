# Implementation Plan — Site Migrator rework

**Status:** Draft for review · **Revised:** 2026-08-22 (v3) · **Baseline:** `87e9a6e`, branch `rework`

Companion to [`code-analysis.md`](./code-analysis.md). That document says what is wrong; this
one says what to build instead, in what order, and what "done" means for each step.

> **Revision note.** v1 of this plan was CLI-first with the UI as a secondary adapter. That is
> now inverted: the browser UI is the primary interface for v1, and WP-CLI moves to a later
> release. The architecture is unchanged by this — a transport-agnostic core with thin
> adapters is exactly what makes the reordering cheap — but the phase order, sizing, and risk
> profile all change substantially.

**Reading of the brief.** Two stages, in this order:

1. **v1** — the plugin is installed on **both** sites. The user exports on the source,
   **downloads** the package, **uploads** it on the destination, and imports. Entirely manual
   file handling, no network link between the two sites.
2. **v2** — direct site-to-site transfer, so the user never touches a file. This is the
   Migrate Guru-like experience.

WP-CLI comes after both. If stage 1 was meant to be "install the plugin on both sites and
transfer directly," say so — it collapses v1 and v2 and changes [§9](#9-phased-delivery)
significantly.

---

## Contents

1. [Release shape](#1-release-shape)
2. [Goal and constraints](#2-goal-and-constraints)
3. [Migrate Guru parity](#3-migrate-guru-parity)
4. [The execution model](#4-the-execution-model)
5. [Target architecture](#5-target-architecture)
6. [The package format](#6-the-package-format)
7. [Moving the package between sites](#7-moving-the-package-between-sites)
8. [Surviving the database swap](#8-surviving-the-database-swap)
9. [Phased delivery](#9-phased-delivery)
10. [What gets deleted](#10-what-gets-deleted)
11. [Testing strategy](#11-testing-strategy)
12. [Risk register](#12-risk-register)
13. [Open decisions](#13-open-decisions-for-review)

---

## 1. Release shape

| Release | Interface | Transfer | Consumer |
|---|---|---|---|
| **v1** | wp-admin UI on both sites | Manual download → upload | Any WordPress user |
| **v2** | wp-admin UI on both sites | Direct pull, source → destination | Any WordPress user |
| **v3** | WP-CLI | Local package or direct pull | Hosting migration tooling |

Each is independently shippable. v3 is cheap **only if** the core stays transport-agnostic
from day one — that constraint is the whole reason for [§5](#5-target-architecture), and it
must not be traded away under v1 delivery pressure.

---

## 2. Goal and constraints

**Goal.** A WordPress plugin that exports a site to a documented, portable package and imports
that package into another WordPress install, usable by a non-technical person through wp-admin
and by automated tooling through WP-CLI, with no dependency on any host's backend service.

| Constraint | Consequence |
|---|---|
| Plugin will be renamed | Full de-brand pass; option/table names change, so no upgrade path from 1.0.x |
| Not published to wp.org for now | No SVN deploy, no `readme.txt` discipline. Distribution is a zip |
| Installed on **both** sites | Both halves ship in one plugin. The destination is a WordPress install, not a bare server |
| Bluehost CWM is out | The plugin owns export *and* import. No remote service performs the restore |
| Humans on shared hosting | Timeouts, `upload_max_filesize`, and memory limits are all live constraints. This drives nearly every design decision below |

**Non-goals for v1:** multisite, incremental/delta sync, scheduled backups, migrating to a
bare server with no WordPress installed.

---

## 3. Migrate Guru parity

Worth being precise about what is and is not reachable, because one of Migrate Guru's headline
properties is architectural and we are deliberately giving it up.

| Migrate Guru property | Reachable? | Notes |
|---|---|---|
| Free, no account for basic use | **Yes** | No backend service at all |
| Serialized-data-safe search/replace | **Yes** | `replace_serialized_values()` already exists, currently dead |
| Progress reporting in wp-admin | **Yes** | Real byte-based progress, not the current hardcoded bands |
| Source site stays up during migration | **Yes** | Export is read-only against the source |
| No manual file handling | **v2 only** | v1 is explicitly download/upload |
| Very large sites (100GB+) | **Partially** | See below |
| **Does not consume source server resources** | **No** | See below |
| Multisite | **Not in v1** | Significant scope; preflight should report it clearly |

**The gap that cannot be closed without infrastructure.** Migrate Guru's core trick is that
BlogVault's servers do the copying — the source only streams bytes out. With no intermediary,
packaging happens *on the source server*, under its memory limit, disk quota, and CPU share.
On constrained shared hosting a very large site will be slower and more failure-prone than
Migrate Guru, and no amount of client-side cleverness changes that.

Two things narrow the gap without a cloud:

- In **v2**, the destination does the expensive half — it pulls, unpacks, and imports. The
  source only packages and serves bytes.
- Also in v2, packaging can be skipped entirely by streaming files individually rather than
  archiving first (see [D5](#13-open-decisions-for-review)). That removes the source's need for
  ~1x extra disk space, which is one of the most common hard failures on shared hosting.

We should not market a "won't touch your server resources" claim we cannot honour.

---

## 4. The execution model

Everything difficult in the current codebase exists to survive a web request timing out. The
existing answer — a wp-cron task queue — is where the worst defects live (findings 2.1, 2.3,
2.7). Resumability is a genuine requirement for a browser-driven UI; **wp-cron was never the
way to get it.**

Replace it with **browser-driven stepping**:

```
POST /export/step   →  { progress, done, warnings, next }
POST /import/step   →  { progress, done, warnings, next }
```

Each call performs a bounded slice of work (~15s) and returns. The SPA calls it in a
sequential loop. The browser is the scheduler.

Why this is strictly better than the queue:

- **No cron.** wp-cron only fires on page loads; today the SPA's own polling is what keeps
  packaging alive, so closing the tab silently stops it.
- **No locking problem.** One request at a time, serialised by the client. The check-then-set
  race in finding 2.7(c) cannot occur.
- **No head-of-line blocking**, no stuck-task table, no `wp-module-tasks` dependency — and with
  it, no Newfold Satis repository requirement.
- **Progress is the response body**, not an `update_option` side channel written once per file
  (finding 3.4).
- **The same core serves the CLI later.** `step( $budget )` called in a loop with no budget is
  simply `run()`.

```php
// Core, transport-agnostic
$state = Checkpoint::load( $dir );
$done  = $exporter->step( $state, $budget );   // web: one call per request
                                               // CLI: loop until $done, $budget = 0
```

The existing SPA is already close to this shape — `TransferStatus.js` polls every 5s via
`useInterval`. The change is that the poll *performs* work instead of reading a status option,
and the interval becomes a sequential loop (overlapping requests must be impossible).

**Client-side requirements**, easy to get wrong:

- Sequential, never overlapping. `useInterval` fires on a timer regardless of whether the
  previous call returned — that must be replaced.
- Exponential backoff and a bounded retry on 5xx / network blips.
- A visible, honest "do not close this tab" state, plus resume-on-reopen from the checkpoint.
- A hard stop after N consecutive failures with a diagnosable error, not an infinite spinner.

---

## 5. Target architecture

One transport-agnostic core, thin adapters. **Nothing in `Core/` may reference `WP_CLI`,
`WP_REST_Request`, `$_POST`, or emit output.** This is what makes v3 cheap.

```
includes/
  Core/
    Package/
      PackageWriter.php     creates parts, writes manifest, checksums
      PackageReader.php     opens + validates a package, streams parts out
      Manifest.php          schema, versioning, read/write
      PartSpec.php          value object: name, source root, filters, priority
      Checkpoint.php        resumable state, on disk, never in the database
    Export/
      Exporter.php          step() orchestration: manifest -> db -> file parts
      DatabaseExporter.php  wraps Database/ (kept)
      FileCollector.php     one implementation driven by PartSpec (replaces 6 archivers)
    Import/
      Importer.php          step() orchestration: validate -> files -> db -> fixups
      DatabaseImporter.php  NEW. streams SQL, uses the existing dead is_*_query() predicates
      FileRestorer.php      NEW. unpack with path-traversal guards
      SearchReplace.php     NEW. wraps replace_serialized_values()
      Fixups.php            NEW. prefix reconciliation, siteurl/home, permalinks, dropins
    Preflight/
      Checker.php           local-only checks (was MigrationChecks/)
      Report.php            structured result; no boolean smuggled through a filter chain
    Progress/
      ProgressReporter.php  interface: start/advance/finish/warn
  Rest/                     v1 primary adapter — thin
  Admin/                    wp-admin page + SPA mount
  Cli/                      v3 adapter — thin
  Database/                 KEPT largely as-is
  Manifest/                 KEPT, folded under Core/Preflight as the site-facts source
```

**What is worth keeping**

- **`Database/DatabaseBase.php` (1533 lines).** The most valuable asset in the repo. A fork of
  All-in-One WP Migration's dumper that correctly handles views, collation downgrades, table
  prefixes, `max_allowed_packet`, and base64-encoded page-builder payloads (Visual Composer,
  Oxygen, Avada, BeTheme, OptimizePress). `mysqldump` and `wp db export` do **not** rewrite
  builder payloads. Its `export()` already takes `$query_offset` / `$table_index` /
  `$table_offset` by reference and genuinely resumes — unlike `Compressor::add_file()`
  (finding 2.2).
- **Its import-side scaffolding is already present and dead.** `is_drop_table_query()`,
  `is_create_table_query()`, `is_insert_into_query()`, `is_start_transaction_query()`,
  `is_commit_query()`, `is_transient_query()`, `is_cache_query()`, `repair_table()` — all
  reachable only from `is_atomic_query()`, which nothing calls. These are precisely the
  classifiers an importer needs; the import half was stripped from the fork and left its
  skeleton behind.
- **`Manifest/` (509 lines).** Clean; already collects the site facts a preflight report needs.
- **`Utils/DatabaseUtility::replace_serialized_values()` (147 lines).** Serialized-safe
  search/replace, called today only by its own recursion.

---

## 6. The package format

The format is the product. Documented in `docs/package-format.md` as a phase 2 deliverable.

```
site-package/
  manifest.json            schema_version, source facts, part index, checksums
  database.sql             (or database.NNN.sql when split)
  parts/
    plugins.zip
    themes.zip
    uploads.001.zip
    uploads.002.zip
    mu-plugins.zip
    dropins.zip
    content-other.zip      everything else under wp-content (languages/, custom dirs)
    root-extras.zip        non-core top-level files only (see below)
  large/                   files above the size threshold, stored loose
    wp-content/uploads/2024/03/video.mp4
  checkpoint.json          transient; present only mid-run, deleted on success
```

**Scope: content only. WordPress core is never packaged.**

The destination is always an existing WordPress install, so shipping `wp-admin/`,
`wp-includes/`, and the core root PHP files is pure waste — they are byte-identical for a given
version. The current code already behaves this way (`RootArchiver` never descends into
directories, and no archiver touches core), so this is a codification rather than a change.

Two consequences:

- **`wp-config.php` is never packaged, ever.** Not by allowlist, not by exclusion rule — the
  root part is a strict allowlist of known-safe filenames, so credentials and salts have no
  path into a package at all. This closes finding 2.4 structurally rather than by filtering.
- **The destination's WordPress version must be >= the source's** (see
  [§7](#7-moving-the-package-between-sites) preflight). Because core is not carried, importing a
  newer site's database onto older core leaves a `db_version` ahead of the running core, which
  WordPress will not reconcile downward. Preflight gates on this and tells the user to update
  core first.

`root-extras.zip` is a **strict allowlist** of non-core top-level files — `.htaccess`,
`robots.txt`, `ads.txt`, `favicon.ico`, search-engine verification files. Typically a few
kilobytes. It exists because losing custom `.htaccess` rules (redirects, security, cache
config) silently breaks sites, and those rules are not recoverable from the database.
Environment-specific content is **captured and reported, not blindly applied** — see
[§8.6](#86-manual-follow-ups).

`content-other.zip` closes a real gap in the current code: today only `plugins`, `themes`,
`uploads`, `mu-plugins`, and `dropins` are archived, so anything else under `wp-content` is
silently dropped — including **`wp-content/languages/`**, where custom and manually installed
translations live. The new `PartSpec` model walks all of `wp-content` with an exclusion list
(caches, other plugins' backup directories, our own storage directory, `upgrade/`) rather than
an allowlist of five hardcoded subdirectories.

**Rules**

- Each part is a **standard zip** (`ZipArchive`, ZIP64), openable with `unzip`. No custom
  container. Resumable at **file** granularity: open, append until the budget expires, close,
  repeat on the next step.
- **Large files are stored loose, not zipped.** A single 3GB video cannot be added to a zip
  incrementally, so on a host with a hard 30s limit it would fail forever, retrying
  identically — exactly the failure the original custom container existed to avoid. Files over
  a threshold (~64MB, filterable) are instead copied byte-by-byte with an offset, which is
  trivially resumable and needs no reassembly on import.
- Parts are split at a size cap (`--split`, default **1GB for v1** — smaller than would
  otherwise be sensible, because in v1 a human downloads these through a browser).
- `manifest.json` carries an explicit `schema_version` and is a published contract.
- SHA-256 per part in the manifest; verified before import.
- **Nothing secret ships.** Finding 2.4 was a blocklist comparing full paths against
  basenames, so it matched nothing and shipped DB credentials and auth salts. The replacement
  is a strict allowlist, and the destination always keeps its own `wp-config.php`.
- **`WP_CONTENT_DIR` is recorded in the manifest** relative to `ABSPATH`. Either side may have
  moved it; the importer maps source layout to destination layout rather than assuming
  `wp-content/`.
- **The package is a faithful snapshot.** No URL rewriting at export time — the destination is
  often unknown when packaging, and one package should restore to many destinations.
  Search-replace is an import-time step.

---

## 7. Moving the package between sites

This is v1's defining problem and the current code has nothing for it.

### Download (source side)

The storage directory today gets an `index.php` silence file but **no `.htaccess` deny**, and
filenames come from `uniqid()` — guessable, and the basis of finding 2.4. Do not serve parts as
static files.

- Stream through an **authenticated endpoint** (`manage_options` + nonce), with `Range` support
  so an interrupted download resumes rather than restarting.
- Storage directory hardened: `index.php`, `.htaccess`/`web.config` deny, and filenames from
  `random_bytes()`.
- The UI lists parts with sizes and checksums, tracks which have been downloaded, and offers a
  "download all" that proceeds sequentially.

Multi-part manual download is a mediocre experience for a 20GB site. It is acceptable for v1
precisely because v2 removes it.

### Upload (destination side)

`upload_max_filesize` and `post_max_size` are commonly 8–128MB on shared hosting, so a 1GB part
**cannot** be uploaded in a single POST. **Chunked upload is mandatory for v1, not an
enhancement.**

- JS slices each part with `Blob.slice()` and POSTs chunks (~5MB, adaptive to the detected
  server limit) to an append endpoint.
- Per-chunk retry, resume after a dropped connection, SHA-256 verification once assembled.
- Preflight on the destination detects and displays the real limits before the user starts.

**Escape hatch, and it should ship in v1.** Let the user place package files into a known
directory over SFTP or a host file manager; the destination UI scans it and offers what it
finds. This is what UpdraftPlus and All-in-One WP Migration do, it costs very little, and it is
the difference between "works" and "impossible" for users with genuinely large sites.

### Disk space

Export needs roughly 1x the site size free; import needs the package plus the extracted result,
so ~2x. Running out mid-run is one of the most common real-world failures. Preflight must check
free space on both sides and refuse clearly rather than failing deep into a run.

---

## 8. Surviving the database swap

The hardest problem in the import half, and the one most likely to sink v1 if it is not
designed in from the start.

### 8.1 The problem

**The importer runs inside WordPress, on the database it is replacing.** The moment the source
database lands, three things change underneath the running process:

1. **The user table is replaced.** The current session's cookie no longer authenticates —
   `manage_options` checks on subsequent step requests fail, and the import stalls half-done.
2. **`active_plugins` is replaced** with the source's list, which does not include this plugin
   (it correctly excludes itself from its own archive). On the next request the importer is not
   loaded at all.
3. **Our own options are gone**, including all state about the in-progress import.

Duplicator avoids this entirely by running its installer as a standalone PHP script outside
WordPress. That is not available to us — "plugin installed on both sites" is a fixed constraint
— so the mitigations below are the price of the chosen model.

### 8.2 Replace, not merge

**Decision: import is a full replace. There is no row-level merge, for the users table or any
other.**

The instinct to merge rather than overwrite is understandable, but it is unsound for
WordPress. Tables are joined by **integer primary keys that are not globally unique**. Source
user `ID 1` is Alice; destination user `ID 1` is Bob. Merging offers two options and both are
wrong:

- **Collapse them** (same ID = same entity) — Alice's content is silently reattributed to Bob,
  and their capabilities merge with them. Two different people, one account.
- **Renumber one** — every foreign key referencing it must then be rewritten. Not only the
  obvious columns (`posts.post_author`, `usermeta.user_id`, `comments.user_id`,
  `term_relationships.object_id`, `posts.post_parent`, `_thumbnail_id`,
  `_menu_item_object_id`) but **every ID embedded inside serialized arrays and JSON blobs** in
  postmeta and options. Elementor, Divi, and WPBakery store post and attachment IDs inside JSON
  strings; ACF stores post references in meta; WooCommerce line items reference product IDs.

No schema anywhere declares "this integer is an ID," so they cannot be enumerated, so they
cannot all be rewritten. The resulting failures are **silent and delayed** — a wrong featured
image, a menu item pointing at the wrong page — surfacing weeks later with no trail back to the
migration.

This is why no migration tool performs row-level merge: not All-in-One WP Migration,
Duplicator, UpdraftPlus, Migrate Guru, or WP Migrate. WP Staging Pro does push staging → live,
but only because staging is a clone of live and the IDs are therefore already aligned.
**Merge is sound only when both databases share a common ancestor.** A general migration has
none.

A partial "skip the users table" option is *not* a safer middle ground — it produces posts
whose `post_author` points at users that no longer exist. WordPress degrades quietly (blank
author names) rather than erroring, which makes it worse, not better. Skipping users is only
coherent alongside an authorship remap; see the content-only mode in
[D7](#13-open-decisions-for-review).

**If a genuine content merge is ever needed**, the sound mechanism is WXR export/import through
WordPress's own importer, which re-creates content via WP APIs so WordPress assigns new IDs and
the importer maintains a remap table. It is slow and lossy — no options, no settings, no plugin
configuration, some meta dropped, media re-downloaded — and it is a *content* tool, not a
migration tool. The dividing line: **SQL-level means replace; API-level means merge is possible
but lossy.** Whoever assigns the IDs decides which you get.

### 8.3 Atomic swap

Rather than importing over the live tables, import beside them and switch in one step.

1. Import the source database into tables under a **temporary prefix** (`wpimp_`).
2. Verify — row counts per table, and that every expected table arrived.
3. Swap with a single multi-table rename, which MySQL executes **atomically**:

```sql
RENAME TABLE
  wp_posts      TO wpold_posts,   wpimp_posts      TO wp_posts,
  wp_options    TO wpold_options, wpimp_options    TO wp_options,
  /* … every table … */ ;
```

4. Retain `wpold_` for a configurable period. Rollback is a second rename.

What this buys:

- **There is never a half-imported database.** If the import dies partway, nothing has been
  touched and the live site is untouched — the user simply retries.
- **The inconsistent window collapses from minutes to an instant**, which is what makes 8.1's
  problems tractable rather than merely mitigated.
- **Rollback is trivial**, and is the honest answer to "what if the migration goes wrong."

Constraints, which preflight must probe rather than assume:

- Needs roughly **2x the database size** in free space.
- Needs `ALTER`, `DROP`, `CREATE`, and `INSERT` privileges. Some managed hosts restrict
  `RENAME TABLE` or cap table counts. Probe with a scratch table
  (`CREATE` → `RENAME` → `DROP`) during preflight; do not infer from the grant table.
- **Views must be recreated after the swap**, since their definitions reference table names by
  their original identifiers. `DatabaseBase` already understands views on export.
- Foreign-key constraints referencing renamed tables need care. WordPress core declares none,
  but plugins occasionally do.

**Fallback when the probe fails:** in-place import preceded by a full SQL backup written into
the package directory, so rollback is a restore rather than a rename. Slower, with a real
inconsistent window — but it works, and preflight tells the user which mode they are getting
*before* they commit.

### 8.4 Preserving destination access

Full replace means the destination's user accounts are gone, which is normally correct — but it
should not mean the person running the migration is locked out of their own site.

**Preserve the destination administrator, on by default.**

1. Before the swap, capture the acting user's `wp_users` row and all their `wp_usermeta` rows.
2. After the swap, re-insert them with a **fresh auto-increment ID**.
3. Grant `administrator` via the capabilities meta key, which is **prefix-dependent**
   (`{prefix}capabilities`) — it must be written using the destination's *final* prefix, not the
   source's.

Nothing is renumbered and no foreign key is rewritten, because this **adds** a row rather than
remapping one. That is precisely what makes it safe where merge is not.

Collision handling: if the preserved username or email already exists among the source's users,
suffix the username and warn clearly. Do not silently overwrite the source account.

One useful side effect: because the destination's `wp-config.php` is preserved (so its salts
are unchanged), and we re-insert the same username, the same password hash, and the
`session_tokens` meta, the existing login cookie may well remain valid. **Do not rely on it** —
the UI should assume re-login is required and say so.

### 8.5 The rest of the survival kit

All of these must be in place before the first line of `DatabaseImporter`:

- **Checkpoint lives on disk, never in the database.** Required for resumability; here it is
  required for correctness.
- **Import-window authentication is file-based.** At import start, mint a token, store it in
  the protected storage directory, and hand it to the client. Step endpoints accept that token
  *instead of* relying solely on the cookie session, so authority survives the users table
  changing. Single-purpose, time-limited, deleted on completion. This is still needed even with
  8.4, because there is a window between the swap and the admin re-injection in which no valid
  session exists.
- **A temporary mu-plugin keeps the importer loaded**, written at import start and removed at
  the end. This is the answer to problem 2 in 8.1.
- **Ordering: files first, database last**, so the destructive step is also the last step.
- **The destination's `wp-config.php` is never overwritten.** Its DB credentials must survive;
  we import content, not configuration.
- **Table prefix reconciliation.** Source and destination prefixes routinely differ.
  `DatabaseBase` handles prefixes on export; import needs the mirror.
- **Post-import messaging.** The user logs in with the *source* site's credentials, or with the
  preserved destination admin from 8.4. State this before the import starts and again on the
  completion screen — it is a classic support burden.

### 8.6 Manual follow-ups

Some things cannot be migrated safely because they describe the *environment*, not the site.
Copying them can actively break the destination — a source `.htaccess` applied to an nginx host
does nothing, and applied to a differently-configured Apache host can 500 it.

For these, **capture and report** rather than copy. The manifest records them, and the
completion screen presents a short "manual steps" list:

- **Custom `.htaccess` rules** beyond WordPress's own permalink block. The block itself is
  regenerated by the permalink flush in `Fixups`; anything else is shown for review.
- **Custom `wp-config.php` constants** — `WP_MEMORY_LIMIT`, `WP_CACHE`, `WP_DEBUG`, custom
  paths, and plugin defines such as license keys. Never copied, always listed. Values that
  look like secrets are named but not printed.
- **PHP version and extension deltas** between source and destination, since a source plugin
  may depend on an extension the destination lacks.
- **Server software difference** (Apache vs nginx vs LiteSpeed), which decides whether the
  `.htaccess` advice is even applicable.

This list is cheap to produce, and it converts the most common category of post-migration
support ticket into something the user can see and act on immediately.

### 8.7 Summary of the decision

| | |
|---|---|
| **Default** | Full replace — the only sound SQL-level operation |
| **Plus** | Preserve the destination admin account (default on) |
| **Plus** | Atomic swap, with `wpold_` tables retained for rollback |
| **Not** | Row-level merge, for users or anything else |
| **Fallback** | In-place import with a pre-import SQL backup, when `RENAME TABLE` is unavailable |
| **File scope** | Content only — WordPress core is never packaged ([§6](#6-the-package-format)) |
| **Rollback window** | `wpold_` retained until the user confirms success, hard cap **30 days** |
| **Out of scope** | True content merge — that is a WXR-based feature, not this pipeline |

**This area still needs a spike before phase 4 is estimated with confidence** — specifically
the `RENAME TABLE` probe across real shared hosts, and the behaviour of the preserved session
cookie across the swap.

---

## 9. Phased delivery

Sizes are relative (S/M/L/XL), not calendar estimates.

### Phase 0 — Rename, de-brand, repo hygiene · **M**

Mechanical, but touches everything, so it goes first — doing it later means redoing every
reference written in between.

- Namespace, constants, function prefix, text domain, option names, main plugin file, REST
  namespace, CSS/JS prefixes. Target names pending [D1](#13-open-decisions-for-review).
- Delete wp.org machinery: `.wporg/`, `readme.txt`, `svn-deploy-*.yml`.
- Collapse the four-place version scheme to **one** source of truth — read the version from the
  plugin header at build time so `build/` can never diverge again.
- `composer.json`: name, description, autoload prefix; drop `newfold-labs/wp-module-tasks` and
  the Satis `repositories` block.

**Exit:** activates cleanly under the new name; `composer lint` passes; no `bluehost|bh_sm|bhsm|BH_SITE_MIGRATOR` outside `docs/`.

### Phase 1 — Stop the bleeding · **S**

Pure deletion; independently valuable. After this the plugin can no longer break a site.

- Delete `MigrationManager/MigrationTasks.php` including the `wp-config.php` rewrite (**2.1**).
- Delete the CWM client, `migrationId` / auth-token / region options, `send-files`,
  `report-errors` (**2.4, 2.5**).
- Drop `wp-module-tasks` (**2.7**).
- Remove the `admin_init` redirect hijack (**3.10**) and `set_time_limit()` in a getter (**3.12**).

**Exit:** nothing writes to `wp-config.php`; no outbound HTTP to any host backend;
`grep -r "eigproserve\|can-we-migrate\|manifestScan"` is empty.

### Phase 2 — Core, package format, stepping engine · **L**

- Write `docs/package-format.md` — schema v1. A deliverable, not a side effect.
- Build `Package/` (writer, reader, manifest, checksum, checkpoint) on `ZipArchive`, including
  the large-file loose-storage rule.
- Collapse six file archivers into one `FileCollector` driven by `PartSpec` (**4.1**);
  ~2000 lines become ~400.
- Fix exclusions properly: normalised absolute paths, and a **strict allowlist** for
  `root-extras` (**2.4**). Core is never walked.
- Add the `content-other` part so nothing under `wp-content` is silently dropped — today
  `wp-content/languages/` is lost.
- Introduce `ProgressReporter`, delete `Utils\Status` (**3.4, 3.5**).
- Establish `step( $state, $budget )` as the core execution contract.

**Exit:** `Core/Export` produces a valid package when driven from a plain PHP script with no
REST and no WP-CLI; parts open in `unzip`; `wp-config.php` provably absent; killing the driver
mid-run and re-driving resumes correctly.

### Phase 3 — Export in the UI · **L**

- `POST /export/step`, `GET /export/manifest`, authenticated ranged download endpoint.
- Harden the storage directory: deny rules, `random_bytes()` filenames.
- SPA: replace `useInterval` polling with a sequential step loop; real progress; resume on
  reload; honest failure states.
- Rework `Preflight/Checker` to return a structured `Report` and **fail closed** when a check
  cannot complete (**2.6, 4.6**). Include disk-space and upload/execution limit detection, and
  the `CREATE`/`RENAME`/`DROP` scratch-table probe that decides which import mode
  [§8.3](#83-atomic-swap) can use.
- **Destination WordPress version >= source** is a hard gate, since core is not carried
  ([§6](#6-the-package-format)). Also record `WP_CONTENT_DIR`, PHP version and extensions, and
  server software for the manual-follow-ups report ([§8.6](#86-manual-follow-ups)).

**Exit:** a non-technical user exports a real site through wp-admin and downloads a verified
package. Closing and reopening the tab mid-export resumes.

### Phase 4 — Import in the UI · **XL** — *the genuinely new work*

None of this exists today. Largest and riskiest phase; **spike [§8](#8-surviving-the-database-swap) first.**

- Chunked upload endpoint + client, with resume and assembly verification.
- Drop-in-folder detection as the large-site escape hatch.
- `FileRestorer` with **path-traversal guards** — reject `..`, absolute paths, and symlinks
  escaping the root. This is untrusted archive input and the primary security surface.
- `DatabaseImporter`: stream SQL into **temp-prefix tables**, chunk on statement boundaries
  respecting `max_allowed_packet`, wire up the dead `is_*_query()` predicates.
- **Atomic swap** ([§8.3](#83-atomic-swap)): verify, multi-table `RENAME`, retain `wpold_` for
  rollback. Plus the in-place-with-backup fallback when the preflight probe says `RENAME` is
  unavailable, and view recreation after the swap.
- **Destination admin preservation** ([§8.4](#84-preserving-destination-access)): capture row +
  usermeta before, re-insert with a fresh ID after, prefix-correct capabilities key, collision
  suffixing.
- `SearchReplace` + prefix reconciliation via `replace_serialized_values()`.
- `Fixups`: `siteurl`/`home`, permalinks, dropins, `autoload` hygiene.
- The rest of the survival kit ([§8.5](#85-the-rest-of-the-survival-kit)): file-based
  checkpoint, import-window token auth, temporary mu-plugin, post-import credential messaging.
- Rollback: a "revert this migration" action while `wpold_` tables survive.
- Import UI: entirely new — upload, verify, preview manifest, confirm, progress, completion.

**Exit:** round-trip green (see [§11](#11-testing-strategy)) — export site A, import into clean
site B at a different URL *and* table prefix, B functionally equivalent to A; the preserved
destination admin can still log in; and a deliberately failed import leaves site B untouched.

### Phase 5 — Direct site-to-site transfer · **L** *(v2)*

The Migrate Guru-like experience. Removes manual file handling entirely.

- Source: generate a one-time transfer key (scoped, expiring, single-use, rate-limited,
  `random_bytes()`).
- Destination: paste key, pull each part server-to-server over HTTP with `Range` resume.
- Same `Importer` core; only the byte source changes — `PackageReader` gains a remote backend.
- Security review is mandatory here: this is the first time the plugin exposes site content to
  a network caller.

### Phase 6 — WP-CLI adapter · **M** *(v3)*

Cheap **if** `Core/` stayed clean. `Cli/Commands.php` marshals arguments and loops
`step( $state, 0 )`.

```
wp <ns> preflight  [--format=json]
wp <ns> export     --to=<dir> [--exclude=<parts>] [--split=<size>] [--resume]
wp <ns> inspect    <package> [--format=json]
wp <ns> verify     <package> [--format=json]
wp <ns> import     <package> [--url=<new>] [--dry-run] [--resume] [--yes]
```

Machine contract: data on stdout, progress on stderr; versioned `--format=json`; documented
exit codes (`0` ok, `1` failure, `2` incompatible, `3` resumable interrupt, `4` invalid
package); never prompt.

### Phase 7 — Tests and CI · **M**

Runs *alongside* phases 2–4, not after. Listed separately because it needs its own
infrastructure.

### Phase 8 — Hardening and distribution · **S/M**

- Raise the PHP floor to 7.4+ (**4.8**). The 5.6 header is already inconsistent with phpcs
  `testVersion 7.0-` and with `esc_xml()` needing WP 5.5+ (**3.8**).
- Produce an installable zip; document install on both sites.

---

## 10. What gets deleted

Current PHP is ~7000 lines across `includes/` + root.

| Removed | Lines | Replaced by |
|---|---|---|
| `Archiver/` custom container | ~563 | `ZipArchive` in `Package/` |
| Five of six file archivers | ~1700 | `FileCollector` + `PartSpec` (~400) |
| `RootArchiver`'s broken exclusion | ~369 | Strict allowlist in a `PartSpec` (~20 lines of config) |
| `MigrationManager/` | ~104 | stepping engine |
| CWM client, regions, tokens | ~150 | nothing |
| `Utils/Status` | ~56 | `ProgressReporter` |
| `wp-module-tasks` (vendor) | ~880 | nothing |

Net ~2500 lines deleted before new work. The importer, package layer, chunked upload, and
import UI add back substantially more than the CLI-first plan assumed — expect the plugin to
end up **larger** than today, not smaller, while being far simpler per unit of function.

---

## 11. Testing strategy

Today there are **zero PHP tests**, and Cypress stubs the entire REST layer with
`cy.intercept` — it verifies React renders against fixtures and cannot catch a single defect in
the analysis. This must change before the importer is written, not after.

1. **Unit (PHPUnit).** Manifest round-trip, `PartSpec` filtering and exclusion,
   `replace_serialized_values()` against nested/serialized/base64 fixtures, path-traversal
   rejection, checkpoint resume logic, chunk assembly.
2. **Round-trip integration — the test that matters.** Two wp-env instances: provision site A
   (posts, a serialized-option-heavy plugin, uploads, a non-default table prefix) → export →
   import into clean site B at a **different URL and prefix** → assert per-table row counts,
   option values after unserialization, upload checksums, and rendered permalink output.
3. **Constraint simulation.** Run the round trip against a PHP config with a low
   `max_execution_time`, small `upload_max_filesize`, and a modest memory limit. v1's entire
   value proposition is working on constrained shared hosting; if it is only tested on a
   generous local box we will learn nothing.
4. **Fault injection.** Kill mid-export, resume, assert validity. Corrupt a part, assert
   `verify` catches it and import refuses. **Kill mid-database-import and assert the live site
   is completely untouched** — this is the property the atomic swap exists to provide, so it is
   the test that proves [§8.3](#83-atomic-swap) works.
5. **Swap and rollback.** Assert the `wpold_` tables are complete and that a rollback restores
   the destination exactly. Run the round trip a second time with `RENAME TABLE` privileges
   revoked, to exercise the in-place fallback path.
6. **Destination admin preservation.** Assert the preserved admin can log in after import, that
   their capabilities key uses the destination's final prefix, and that a username collision
   with a source user is suffixed rather than silently overwriting either account.
7. **Contract tests.** Snapshot the manifest schema and (from v3) `--format=json`; a change
   must fail CI loudly.
8. **Cypress** should drive at least one **unstubbed** path end to end.

CI: replace the wp.org/SVN workflows with lint + PHPUnit + round-trip. The round-trip job is
slow — run it on PRs to `main` rather than every push.

---

## 12. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| **Database-swap survival** ([§8](#8-surviving-the-database-swap)) | Import stalls half-done, site left broken | Atomic swap collapses the inconsistent window to an instant; file-based checkpoint + token auth + mu-plugin cover the rest. Spike before phase 4 is estimated |
| **`RENAME TABLE` unavailable or restricted** | Falls back to in-place import, reopening a real inconsistent window | Preflight probes with a scratch table rather than inferring from grants; pre-import SQL backup in the fallback path; UI states which mode is in effect before the user commits |
| **2x database size needed for the swap** | Import refused on tight-quota hosts | Counted in the preflight free-space check alongside the file-side estimate |
| **Import is entirely new and large** | Phase 4 slips | Round-trip test is the definition of done, not a formality |
| **Chunked upload on hostile hosts** | v1 unusable for its target user | Drop-in-folder escape hatch ships in v1; constraint simulation in CI |
| **Path traversal on import** | Arbitrary file write from a malicious package | Untrusted input from day one; dedicated tests; never `extractTo()` blindly |
| **Disk exhaustion mid-run** | Corrupt package or half-restored site | Preflight free-space check on both sides; refuse early |
| **`DatabaseBase` is a stale AIO fork** | Inherited unknown bugs; upstream fixes never arrive | Diff against current upstream once; record the fork point in `docs/` |
| **Transfer key exposure (v2)** | Whole-site disclosure | Scoped, expiring, single-use, rate-limited, `random_bytes()`; dedicated security review in phase 5 |
| **No upgrade path from 1.0.x** | Existing installs orphaned | Accepted — renamed and unpublished. State it rather than half-supporting it |
| **Core leaks transport concerns** | v3 stops being cheap | Enforce by lint: no `WP_CLI`/`WP_REST`/superglobals under `Core/` |

---

## 13. Open decisions for review

**D1 — Naming.** Recommend `NewfoldLabs\WP\SiteMigrator\`, prefix `nfd_sm_`, constants
`NFD_SM_*`, slug and text domain `nfd-site-migrator`, option key `nfd_site_migrator`. Matches
the convention in `vendor/newfold-labs/`. *Alternative:* vendor-neutral (`SiteMigrator\`,
`sm_`, `site-migrator`) if this should not read as a Newfold product. **Phase 0 is blocked on
this.**

**D2 — Release shape.** ~~Is v1 manual or direct?~~ **Resolved 2026-08-22:** v1 manual
download/upload → v2 direct site-to-site → v3 WP-CLI, as in [§1](#1-release-shape).

**D3 — Large-file threshold and part size.** Proposed 64MB loose-storage threshold, 1GB parts.
Both are guesses that should be validated against a real site on real shared hosting early in
phase 2.

**D4 — Does v1 need `verify` and `inspect` in the UI?** They are cheap once `PackageReader`
exists and they turn "it failed" into "part 3 checksum mismatch." Recommend yes.

**D5 — v2 architecture: package-then-pull, or stream directly?** Phase 5 as written has the
destination pull a package the source already built. The alternative is skipping packaging
entirely — the source exposes a file listing and streams files individually. That removes the
source's ~1x disk overhead, which is a top cause of failure on shared hosting, and is closer to
how commercial services work. It is a different `PackageReader` backend rather than a different
core, so the decision can be deferred to phase 5 — but not later.

**D6 — Multisite.** Currently hard-blocked by `is_not_multisite()`. Recommend explicitly out of
scope for v1, with preflight reporting it clearly instead of failing opaquely.

**D7 — Content-only scope.** **Resolved 2026-08-22, file side:** packages carry content only;
WordPress core is never included, and `wp-config.php` never is either. Codified in
[§6](#6-the-package-format). Note this was already the code's behaviour — `RootArchiver` never
descends into directories, so core was never packaged; only its exclusion filter was broken.
The root part survives as `root-extras.zip`, a strict allowlist of non-core top-level files,
because losing custom `.htaccess` rules and domain-verification files breaks sites in ways the
database cannot repair.

> **Still open — the database side.** The original D7 asked a different question: should a mode
> exist that *keeps the destination's user accounts* and remaps imported authorship to one
> chosen user? That is independent of file scope. Default remains full replace
> ([§8.2](#82-replace-not-merge)) plus destination-admin preservation
> ([§8.4](#84-preserving-destination-access)). Needs an explicit answer.

**D8 — Rollback retention.** **Resolved 2026-08-22:** `wpold_` tables are retained until the
user confirms the migration succeeded, with a dashboard notice, and a **hard cap of 30 days**
after which they are dropped automatically. Preflight's free-space check must account for the
second copy persisting for that window.

---

## Appendix — findings coverage

Every finding in `code-analysis.md`, mapped to the phase that resolves it.

| Finding | Phase | How |
|---|---|---|
| 2.1 `wp-config.php` truncation | 1 | Deleted outright |
| 2.2 dropped by-reference params | 2 | Custom container replaced by `ZipArchive` |
| 2.3 `self::execute()` recursion | 1, 2 | Replaced by the stepping engine |
| 2.4 `wp-config.php` archived and published | 1, 2, 3 | Allowlist exclusion; no public URLs; hardened storage dir |
| 2.5 `sslverify => is_ssl()` | 1 | No outbound backend calls remain |
| 2.6 first-load failure blanks the screen | 3 | Fail-closed preflight; SPA `return` fix |
| 2.7 task queue cannot recover | 1 | Dependency removed; browser is the scheduler |
| 3.1 `append_eof()` inverted | 2 | `Archiver/` deleted |
| 3.2 handle leaks | 2 | `PackageWriter` owns handle lifecycle |
| 3.3 autoloaded state blob | 2 | On-disk checkpoint (also required by §8) |
| 3.4 `set_status()` per file | 2 | `ProgressReporter` |
| 3.5 progress is theatre | 2, 3 | Real byte-based progress |
| 3.6 `vsprintf` placeholder mismatch | 2 | Option exclusion list rebuilt |
| 3.7 division by zero | 2 | Rewritten with guards |
| 3.8 `esc_xml()` / version floors | 0, 8 | Floors made consistent |
| 3.9 text-domain typo | 0 | De-brand pass |
| 3.10 redirect without `exit` | 1 | Deleted |
| 3.11 dead encryption params | 2 | `Archiver/` deleted |
| 3.12 `set_time_limit()` in a getter | 1 | Deleted |
| 4.1–4.8 refactors | 2, 3, 8 | As described above |
