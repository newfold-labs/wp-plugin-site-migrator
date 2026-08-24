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
transfer directly," say so — it collapses v1 and v2 and changes [§10](#10-phased-delivery)
significantly.

---

## Contents

1. [Release shape](#1-release-shape) · [what the user does](#11-what-the-user-actually-does-end-to-end)
2. [Goal and constraints](#2-goal-and-constraints)
3. [Migrate Guru parity](#3-migrate-guru-parity)
4. [The execution model](#4-the-execution-model)
5. [Target architecture](#5-target-architecture)
6. [The package format](#6-the-package-format)
7. [Moving the package between sites](#7-moving-the-package-between-sites)
8. [Compatibility: the destination handshake](#8-compatibility-the-destination-handshake)
9. [Surviving the database swap](#9-surviving-the-database-swap)
10. [Phased delivery](#10-phased-delivery)
11. [What gets deleted](#11-what-gets-deleted)
12. [Testing strategy](#12-testing-strategy)
13. [Risk register](#13-risk-register)
14. [Open decisions](#14-open-decisions-for-review)

---

## 1. Release shape

| Release | Interface | Transfer | Consumer |
|---|---|---|---|
| **v1** | wp-admin UI on both sites | Package: manual download → upload. Compatibility check: live paired fetch | Any WordPress user |
| **v2** | wp-admin UI on both sites | Direct pull, source → destination | Any WordPress user |
| **v3** | WP-CLI | Local package or direct pull | Hosting migration tooling |

Each is independently shippable. v3 is cheap **only if** the core stays transport-agnostic
from day one — that constraint is the whole reason for [§5](#5-target-architecture), and it
must not be traded away under v1 delivery pressure.

### 1.1 What the user actually does, end to end

The whole product, in the order a person experiences it. Everything after step 4 is the
plugin's job, not theirs.

| # | Where | Step |
|---|---|---|
| 1 | Both | Install and activate the plugin on the **source** and the **destination**. |
| 2 | Destination | **Receive a site → Pair.** The plugin shows the site URL and a short single-use pairing code. Copy both. |
| 3 | Source | Paste them into the export screen. The source calls the destination directly, pulls its **live** facts, and shows a verdict: green, warnings, or blocked with a reason. Fix a blocked gate and click **Re-check** — no round trip. If the destination is unreachable, fall back to pasting a profile blob; if there is no destination yet, skip the check and the package is marked *unverified destination* ([§8.2](#82-pairing-the-source-asks-the-destination-directly)). |
| 4 | Source | **Start export.** The browser drives `POST /export/step` in a loop until done. Output is a package directory: manifest, database dump, zipped content parts, and any oversized files stored loose. Closing the tab is safe — reopening resumes. |
| 5 | Source → Destination | **Download the parts, then upload them.** Download is authenticated and `Range`-resumable; upload is chunked so it does not meet `upload_max_filesize`. For very large sites, the escape hatch is to place the parts into the destination's storage directory by SFTP and let it detect them. |
| 6 | Destination | **Verify and preview.** Checksums per part, manifest read, and the *authoritative* compatibility re-check against live facts. The destination then shows exactly what will happen: URL change, prefix change, which accounts merge, what will be replaced. Nothing has been written yet. |
| 7 | Destination | **Confirm.** This is the one explicit destructive-action consent in the flow. |
| 8 | Destination | **Import.** Files first. Then the database into temp-prefix tables, users merged, URLs rewritten, and everything verified — all while the live site is still untouched. Only then the atomic `RENAME` swap. Post-swap work is limited to what genuinely cannot happen earlier: recreating views and flushing permalinks. |
| 9 | Destination | **Review.** Completion screen: what changed, any login that was renamed, the manual follow-ups ([§9.7](#97-manual-follow-ups)), and the `wp-config.php` block to paste if wanted ([§9.5](#95-wp-configphp-never-written-always-reported)). |
| 10 | Destination | **Confirm success.** This drops the `wpold_` rollback tables. Until it happens, "revert this migration" is one click — for at most 30 days ([D8](#14-open-decisions-for-review)), after which they are dropped automatically. |

Three properties of this sequence are load-bearing and easy to lose:

- **The 400-byte check and the 20GB transfer are separate problems.** The source pairs with the
  destination to read its profile, because that is small, live, and re-runnable. The *package*
  is still hand-carried in v1 — streaming that between two servers is what v2 is for. Every
  step from 3 onward degrades gracefully to a manual path when the destination cannot be
  reached at all.
- **Verification happens before the commit, not after.** Step 8 verifies the staged tables while
  the live site is still intact, because after the swap "verify" has nothing useful to offer —
  the change is already made. The atomic swap exists precisely so that all checking can happen
  while backing out is free.
- **Step 10 is part of the migration.** The job is not done when the site loads; it is done when
  the user says it is and the rollback copy is released.

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
  archiving first (see [D5](#14-open-decisions-for-review)). That removes the source's need for
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
      UserMerger.php        NEW. merges destination users into the staged users table
      Fixups.php            NEW. prefix reconciliation, siteurl/home, permalinks, dropins
    Preflight/
      Checker.php           local-only checks (was MigrationChecks/)
      SiteProfile.php       NEW. gather local facts; serve + parse the profile
      Pairing.php           NEW. issue/redeem single-use codes; authenticated profile fetch
      Compatibility.php     NEW. compare two profiles -> gates, warnings, collation plan
      Report.php            structured result; no boolean smuggled through a filter chain
    Progress/
      ProgressReporter.php  interface: start/advance/finish/warn
  Rest/                     v1 primary adapter — thin
  Admin/                    wp-admin page + SPA mount
  Cli/                      harness from phase 2; supported surface in v3 — thin either way
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
[§9.7](#97-manual-follow-ups).

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

## 8. Compatibility: the destination handshake

Nothing should be packaged before we know it can land. Because packages carry content only
([§6](#6-the-package-format)), the destination's own core, PHP, and database must be able to
run the source's site — and that is not a safe assumption.

### 8.1 The problem with checking late

In v1 there is no live link between the two sites at export time. The obvious design checks
compatibility when the package is *opened* on the destination — which is correct, necessary,
and far too late. By then the user has waited through a full export, downloaded a multi-gigabyte
file, and uploaded it again, only to be told their destination runs an older WordPress.

So compatibility is checked at **three points**, and the earliest one is the one that saves the
user's afternoon. It does not require the package transfer to be solved first — see
[§8.2](#82-pairing-the-source-asks-the-destination-directly).

### 8.2 Pairing: the source asks the destination directly

The package must be hand-carried in v1 — it is gigabytes, and the transfer machinery to stream
it is what v2 is *for*. The compatibility profile is roughly 400 bytes. Those are different
problems, and forcing the small one through the large one's constraint buys nothing.

**So the source fetches the profile from the destination over HTTP, and the user pastes a
pairing code once.**

1. On the destination: **Receive a site → Pair.** It generates a short, single-use code and
   shows it with the site URL:

   ```
   https://destination.example.com   A7K2-9F3P-XQ41
   ```

2. The user pastes both into the source's export screen.
3. The source calls the destination's profile endpoint, authenticated with that code, and gets
   **live** facts back. The verdict appears in seconds.

What this buys over a pasted snapshot:

- **The facts are current**, not a snapshot from whenever the blob was minted. No staleness, no
  expiry window to reason about.
- **The check is re-runnable.** A blocked gate is usually fixable — update core, free some disk,
  raise a limit. With a live pairing that is *fix it, click Re-check, green*. With a pasted blob
  it is a round trip back to the other site to re-mint and re-copy, every time.
- **It de-risks v2.** v2 needs an authenticated channel from source to destination. Building the
  pairing handshake now means v2 is "stream the package over the channel that already exists"
  rather than a new subsystem invented late.

This does not collapse v1 into v2. What v2 adds is sustained, resumable, multi-gigabyte transfer
between two servers — timeouts, bandwidth, ranged reads served to a machine instead of a
browser. Fetching a few hundred bytes once is not a down payment on that; it is a different
thing that happens to use the same protocol.

#### The endpoint must not become a version oracle

The profile discloses exact WordPress, PHP, and database versions, loaded extensions, and free
disk space. That is reconnaissance data. An unauthenticated endpoint present on every install of
this plugin would let anyone scan for it and harvest a list of sites running software with known
CVEs. So:

- **Unauthenticated requests get `404`, not `401`.** The endpoint must not be discoverable, and
  must not confirm the plugin is even installed.
- The pairing code is **single-use, short-lived, and rate-limited** on the destination.
- Its scope is **read the profile, nothing else**. It is not a general API key. v2's broader
  access is a separate, explicit authorization step.
- The destination records the pairing and shows it in its own UI, so an unexpected one is
  visible.
- **TLS verification is unconditionally on** — `'sslverify' => true`, never `is_ssl()`
  (finding 2.5). If the destination is plain HTTP, say so plainly: the profile crosses the wire
  in the clear.

#### Fallback: paste the profile

Outbound HTTP from PHP is blocked or firewalled on a meaningful share of hosts, and plenty of
destinations are not publicly reachable at all — behind HTTP basic auth, a staging password, an
IP allowlist, a VPN, or simply not yet DNS-pointed. When the fetch fails the source says so and
offers the manual path: go back to the destination, copy the profile blob, paste it here.

Same comparison, same gates, same verdict. What it loses is liveness and one-click re-checking,
so it is the fallback rather than the default. Skipping the check entirely stays allowed too —
exporting as a backup, or to a destination that does not exist yet — and marks the package
*unverified destination*.

The profile carries, by either route:

| Group | Facts |
|---|---|
| WordPress | `version`, `db_version`, `is_multisite`, `locale`, table `prefix`, `WP_CONTENT_DIR` |
| PHP | `version`, loaded extensions, `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`, `disable_functions`, `open_basedir` |
| Database | server `version`, MySQL vs MariaDB, `max_allowed_packet`, available charsets and collations, `RENAME TABLE` probe result, granted privileges as observed |
| Host | free disk space at `WP_CONTENT_DIR`, server software, `is_ssl()` |
| Envelope | `schema_version`, minted-at timestamp, 7-day expiry *(pasted route only)*, random profile ID |

It is **facts about a server, not secrets** — no credentials, no salts, no site content.

### 8.3 The three checkpoints

| # | Where | When | Authority |
|---|---|---|---|
| 1 | Destination | Generating the profile | Local self-check; catches "this host cannot receive anything" before the user leaves the page |
| 2 | Source | Before packaging | **Advisory.** Live when paired, a snapshot when pasted, absent when skipped |
| 3 | Destination | After upload, before the first write | **Authoritative** — live facts, re-read on the machine being changed |

Checkpoint 3 always runs, even when 2 passed, and even when 2 was live. Between them the user
has exported, downloaded, and uploaded a package — hours, sometimes days. Core can have been
updated and disk can have filled in that window. The manifest's copy of the source facts is
input to checkpoint 3, never a substitute for it.

### 8.4 The gates

**Hard block — the import cannot proceed.**

| Gate | Why |
|---|---|
| Destination WP `version` < source | Core is not carried ([§6](#6-the-package-format)). The source's content expects the source's core. |
| Destination `db_version` < source `db_version` | WordPress migrates the schema forward on upgrade and has **no downgrade path**. A database stamped at a newer `db_version` than the running core is not reconciled — core simply believes it is up to date. |
| Multisite mismatch in either direction | Different schema (`wp_blogs`, `wp_site`, per-blog prefixes). Out of scope for v1 ([D6](#14-open-decisions-for-review)). |
| Destination PHP < the highest `Requires PHP` among the source's active plugins and theme | A fatal on the first page load after the swap, with the rollback tables as the only way out. |
| Source collation unavailable on the destination **and** not downgradable | `Unknown collation` aborts the import mid-stream. See below. |
| Free space < package + extracted + 2× database | The atomic swap needs a second copy, and [D8](#14-open-decisions-for-review) keeps it for 30 days. |
| No `CREATE` / `DROP` / `ALTER` on the destination database | Nothing can be staged at all. |

**Warn — proceed, but say so, and record it in the completion report.**

- Destination PHP **major** version ahead of the source's. Old plugin code meets removed
  functions; this is the most common "site white-screens after migration" cause that is not our
  bug.
- MySQL ⇄ MariaDB crossing, or a major server-version jump.
- PHP extensions present on the source but missing on the destination (`imagick`, `intl`,
  `soap`, `zip`, `bcmath`, `gd`, `exif`).
- Different server software — decides whether the `.htaccess` advice in
  [§9.7](#97-manual-follow-ups) even applies.
- Non-standard `WP_CONTENT_DIR` on either side.
- Destination is not empty — show post, page, and user counts, and state plainly that this
  content will be replaced.
- Destination `max_allowed_packet` smaller than the source's largest single row.
- `upload_max_filesize` / `post_max_size` below the chosen upload chunk size.
- Table prefixes differ. Handled ([§9.6](#96-the-rest-of-the-survival-kit)), but reported.

### 8.5 Collation, specifically

This deserves its own note because it is the single most common hard failure when moving
between hosts of different vintage, and because **the fix is already written and dead in this
repo.**

`DatabaseBase::replace_table_collations()` (`includes/Database/DatabaseBase.php:1160`) maps
`utf8mb4_0900_ai_ci` → `utf8mb4_unicode_520_ci` → `utf8mb4_unicode_ci` → `utf8_unicode_ci`
according to `$wpdb->has_cap()` on the machine it runs on. It is import-side code — it
downgrades incoming SQL to what *this* server supports — and it has **zero call sites**. Like
the `is_*_query()` predicates, it is scaffolding left behind when the import half was stripped
from the All-in-One WP Migration fork.

Wiring it up is most of the work. Two gaps to close while doing so:

- **MariaDB's `uca1400` collations.** MariaDB 10.10+ emits `utf8mb4_uca1400_ai_ci`, which no
  MySQL server recognises. The existing map does not cover it.
- **`utf8mb4` → `utf8` is lossy.** It silently truncates at 4-byte characters — emoji, and a
  great deal of CJK. The current code performs this downgrade with no signal. It must become an
  explicit, warned-about choice, not a silent one, and preflight should surface it as a gate
  rather than discovering it mid-import.

Preflight compares the source's actual per-table collations (already available from the export)
against `SHOW COLLATION` on the destination, and reports one of: *exact match*, *safe
downgrade available*, *lossy downgrade only* (warn hard), or *no path* (block).

---

## 9. Surviving the database swap

The hardest problem in the import half, and the one most likely to sink v1 if it is not
designed in from the start.

### 9.1 The problem

**The importer runs inside WordPress, on the database it is replacing.** The moment the source
database lands, three things change underneath the running process:

1. **The users table is rewritten.** Even with the merge in
   [§9.4](#94-users-merge-not-replace) preserving the acting account, the table is swapped
   wholesale in one instant. The current session's cookie may stop authenticating —
   `manage_options` checks on subsequent step requests fail, and the import stalls half-done.
2. **`active_plugins` is replaced** with the source's list, which does not include this plugin
   (it correctly excludes itself from its own archive). On the next request the importer is not
   loaded at all.
3. **Our own options are gone**, including all state about the in-progress import.

Duplicator avoids this entirely by running its installer as a standalone PHP script outside
WordPress. That is not available to us — "plugin installed on both sites" is a fixed constraint
— so the mitigations below are the price of the chosen model.

### 9.2 Replace, not merge

**Decision: import is a full replace, with exactly one deliberate exception — the users table
([§9.4](#94-users-merge-not-replace)). No other table is merged row-by-row.**

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

**The users exception does not weaken this argument** — it depends on it. What makes the users
merge tractable is precisely that the destination's *content* is being replaced, so destination
user IDs have almost nothing left pointing at them, while source user IDs never move.
[§9.4](#94-users-merge-not-replace) sets that out in full. Extend merge to any table whose
referents survive on both sides and the argument above applies again immediately.

A related non-option: *skipping* the users table rather than merging it produces posts whose
`post_author` points at users that no longer exist. WordPress degrades quietly (blank author
names) rather than erroring, which makes it worse, not better.

**If a genuine content merge is ever needed**, the sound mechanism is WXR export/import through
WordPress's own importer, which re-creates content via WP APIs so WordPress assigns new IDs and
the importer maintains a remap table. It is slow and lossy — no options, no settings, no plugin
configuration, some meta dropped, media re-downloaded — and it is a *content* tool, not a
migration tool. The dividing line: **SQL-level means replace; API-level means merge is possible
but lossy.** Whoever assigns the IDs decides which you get.

### 9.3 Atomic swap

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

### 9.4 Users: merge, not replace

**Decision: the users table is the one deliberate exception to [§9.2](#92-replace-not-merge).
The destination's accounts are kept, and the source's accounts are merged into them.** On by
default.

The reason is the one that motivates it: the content arriving from the source was written by the
source's users, so their accounts must come across or authorship breaks. And the people who
already work on the destination should not lose their logins because a site landed on top of
them.

#### Why this is sound where general merge is not

[§9.2](#92-replace-not-merge) rejects merge because renumbering a primary key requires rewriting
every reference to it, including references buried inside serialized and JSON blobs that no
schema declares. That argument still holds. It does not apply here, for a specific structural
reason:

> **We are merging into a table whose referencing side is being discarded.**
> The destination's posts, comments, and meta do not survive the swap. So destination user IDs
> have almost no referents left to rewrite — while source user IDs, which have referents
> everywhere, never move at all.

That inverts the usual cost. The rule that makes it work:

**Source user IDs are preserved verbatim. Only destination-origin users are renumbered.**

Under that rule the complete set of columns needing a rewrite is:

| Column | Rows affected |
|---|---|
| `usermeta.user_id` | Destination-origin users only |

That is the whole list. `posts.post_author`, `comments.user_id`, and `links.link_owner` — the
only other user-ID columns in the core schema — are untouched, because every row in them came
from the source and every source ID is unchanged. Nothing inside a serialized blob needs
inspecting, because no ID a blob might reference has moved.

This is not "users are a special table." It is that this particular merge is one-directional
into an ID space nobody else references.

#### Matching identities

For each destination user, find a source counterpart by `user_email` (case-insensitive) first,
then `user_login`. Three outcomes:

**(a) Match — same person on both sites.** Keep the **source row's ID**, so the source's content
stays attributed. Reconcile the columns:

| Field | Winner | Why |
|---|---|---|
| `ID` | Source | Everything already points at it |
| `user_login`, `user_nicename` | Source | `/author/{nicename}` permalinks are linked from the migrated content and indexed |
| `user_pass`, `user_activation_key` | **Destination** | These are the credentials the person used ten minutes ago to start the migration |
| `session_tokens` meta | Destination | Best chance the current login survives the swap |
| `display_name`, `user_url`, `description`, profile meta | Source | Matches the content |
| Role and capabilities | Source — **except** the acting user is always guaranteed `administrator` | A source-side Editor must not be able to lock themselves out mid-import |

When `user_login` changes as a result, say so on the completion screen in plain words: *you now
sign in as `alice`, with the same password.* WordPress accepts the email address at the login
form either way, so nobody is stranded.

**(b) No match — destination-only account.** Insert with a **fresh ID** above the highest source
ID, carry its `usermeta` with `user_id` remapped, and rewrite the prefix-dependent meta keys
(below). The account has no content on the new site, which is expected.

**(c) Login collision between different people.** `admin` on both sides with different emails is
common. Suffix the destination one (`admin-2`), keep both, and report it prominently.

#### The database will not catch our mistakes here

`wp_users` declares `KEY user_login_key (user_login)` and `KEY user_email (user_email)` —
**both non-unique** (`wp-admin/includes/schema.php`). WordPress enforces login and email
uniqueness in `wp_insert_user()`, at the API layer, not in the schema. Since the merge writes
rows with direct SQL, a duplicate does not error: it inserts cleanly, and afterwards
`get_user_by()` returns whichever row the index happens to yield.

So uniqueness is **our** invariant to enforce, before the swap, with an explicit verification
pass over the staged table. Treat a duplicate as a failed import, not a warning.

#### Prefix-dependent meta keys

Several `usermeta` keys embed the **table prefix**, not a user ID:

```
{prefix}capabilities        {prefix}user_level        {prefix}user-settings
{prefix}user-settings-time  {prefix}dashboard_quick_press_last_post_id
```

Source-origin rows carry the *source's* prefix and destination-origin rows carry the
*destination's*. Every one of them must be rewritten to the destination's **final** prefix. The
companion option `{prefix}user_roles` in `wp_options` needs the same treatment. Get this wrong
and every user on the site is silently a subscriber — the classic symptom of a hand-rolled
migration.

#### Roles that no longer exist

`{prefix}user_roles` comes from the source. A destination user whose role was
`shop_manager` on a WooCommerce site landing on a source that has no WooCommerce ends up
holding a capability key for a role that is not defined — which yields no capabilities at all,
silently.

After the swap, validate every preserved destination user's role against the source's role list.
Anything unmatched is demoted to a configurable fallback (default `subscriber`), and named
explicitly in the completion report.

#### What this costs

Honest residual risks, none of them silent-and-delayed in the way [§9.2](#92-replace-not-merge)
describes:

- A destination user ID appearing inside a plugin's serialized options (`wpseo`'s excluded
  authors, a membership plugin's grants) will not be rewritten — but those options come from the
  **source** and never referenced destination users to begin with, so the exposure is limited to
  destination-side plugin state that is being discarded anyway.
- Two accounts genuinely belonging to one person, with different emails and different logins on
  each site, merge as two users. Detectable only by a human; offer a post-migration review list
  rather than guessing.
- The staged users table is larger than either input, so the row-count verification in
  [§9.3](#93-atomic-swap) must expect that rather than flagging it.

#### The alternative remains available

**Replace mode** — source users only, plus re-inserting the acting administrator with a fresh ID
— stays as an option for the common case of a brand-new destination whose only account is a
throwaway admin. It is no longer the default.

### 9.5 `wp-config.php`: never written, always reported

**Decision: `wp-config.php` is never packaged, never overwritten, and never merged. The
destination's own file is left byte-for-byte untouched.**

Neither of the two obvious options is acceptable:

- **Overwrite** hands the destination the source's database credentials, and the site cannot
  connect to its own database. This is not a subtle failure; it is an immediate, total outage
  with no UI left to fix it from.
- **Merge** means parsing PHP and splicing statements into it. `wp-config.php` is executable
  code, not configuration: hosts inject their own blocks, caching plugins insert `WP_CACHE`,
  security plugins add `DISALLOW_FILE_EDIT`, some files `include` a second file, and a good
  number carry conditional logic keyed on `$_SERVER`. A parser that is right 95% of the time
  breaks one site in twenty in a way that leaves no working admin screen. There is no rollback
  from a syntax error in `wp-config.php`.

There is a third reason, which is the decisive one. **Almost nothing in that file should
travel.** Grouping its usual contents:

| Group | Examples | Should it move? |
|---|---|---|
| Database credentials | `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` | **Never.** Destination-specific by definition |
| Security salts | `AUTH_KEY` … `NONCE_SALT` | **Never.** Copying them invalidates every destination session and gains nothing; keeping the destination's is what lets a login survive the swap ([§9.4](#94-users-merge-not-replace)) |
| Table prefix | `$table_prefix` | **Never.** The destination's prefix is authoritative; the *staged tables* are renamed to match it instead ([§9.6](#96-the-rest-of-the-survival-kit)) |
| Paths | `WP_CONTENT_DIR`, `WP_HOME`, `WP_SITEURL` | **Never.** Host-specific. `WP_HOME`/`WP_SITEURL` if present would override the URL rewrite and pin the site to the source domain |
| Host and platform blocks | Anything the host injected | **Never.** Belongs to the machine, not the site |
| Resource tuning | `WP_MEMORY_LIMIT`, `WP_MAX_MEMORY_LIMIT` | **Report.** May be needed, may be capped by the host |
| Behaviour flags | `WP_DEBUG`, `WP_CACHE`, `DISALLOW_FILE_EDIT`, `AUTOSAVE_INTERVAL`, `WP_POST_REVISIONS` | **Report.** These genuinely belong to the site, not the server |
| Plugin defines | License keys, API endpoints, feature flags | **Report, redacted.** Named, never printed |

So the answer to "is there a better way" is: **stop treating it as a file to migrate, and treat
it as a small set of facts to carry.**

**How it works.** Export parses the source's `wp-config.php` **read-only**, extracting only
`define()` calls and `$table_prefix` via `token_get_all()` — a tokeniser, never `include`, never
a regex, and never the file itself. Only names on a **known-safe allowlist** have their values
recorded; everything else is recorded as a name with its value replaced by `«redacted»`, and
anything matching a secret-shaped pattern (`KEY`, `SALT`, `SECRET`, `TOKEN`, `PASS`) is redacted
regardless of allowlist status. The result is a small array in `manifest.json`.

On the destination, the completion screen shows a **copy-pasteable block** of just the lines
worth adding, above the `/* That's all, stop editing! */` marker, with one line of explanation
each. The user applies them, or does not.

We do not write it for them, and this is deliberate. An automated edit to `wp-config.php` is the
one action in this entire pipeline with no rollback path — the atomic swap cannot help, because
a fatal parse error means WordPress never boots to run our rollback. A block of text the user
pastes is slower and strictly safer.

**Why not an mu-plugin instead?** Tempting, and wrong for most of these. `WP_MEMORY_LIMIT`,
`WP_CACHE`, and `WP_CONTENT_DIR` are read during WordPress's own bootstrap, before mu-plugins
load. Defining them later is silently ineffective — which is worse than not defining them,
because the setting appears to have been applied.

### 9.6 The rest of the survival kit

All of these must be in place before the first line of `DatabaseImporter`:

- **Checkpoint lives on disk, never in the database.** Required for resumability; here it is
  required for correctness.
- **Import-window authentication is file-based.** At import start, mint a token, store it in
  the protected storage directory, and hand it to the client. Step endpoints accept that token
  *instead of* relying solely on the cookie session, so authority survives the users table
  changing. Single-purpose, time-limited, deleted on completion. This is still needed even with
  the users merge ([§9.4](#94-users-merge-not-replace)), because the swap replaces the session
  store in one instant and the cookie's survival cannot be assumed.
- **A temporary mu-plugin keeps the importer loaded**, written at import start and removed at
  the end. This is the answer to problem 2 in [§9.1](#91-the-problem).
- **Ordering: files first, database last**, so the destructive step is also the last step.
- **The destination's `wp-config.php` is never touched** ([§9.5](#95-wp-configphp-never-written-always-reported)).
- **Table prefix reconciliation.** Source and destination prefixes routinely differ. The
  destination's prefix always wins: the staged tables are renamed to it during the swap, and the
  prefix-bearing `usermeta` keys and the `{prefix}user_roles` option are rewritten to match
  ([§9.4](#94-users-merge-not-replace)). `DatabaseBase` handles prefixes on export; import needs
  the mirror.
- **Post-import messaging.** With the users merge, most people keep the credentials they already
  had — but logins reconciled to a source username change, and the acting session may need
  re-establishing. State all of it before the import starts and again on the completion screen;
  it is a classic support burden.

### 9.7 Manual follow-ups

Some things cannot be migrated safely because they describe the *environment*, not the site.
Copying them can actively break the destination — a source `.htaccess` applied to an nginx host
does nothing, and applied to a differently-configured Apache host can 500 it.

For these, **capture and report** rather than copy. The manifest records them, and the
completion screen presents a short "manual steps" list:

- **Custom `.htaccess` rules** beyond WordPress's own permalink block. The block itself is
  regenerated by the permalink flush in `Fixups`; anything else is shown for review.
- **Custom `wp-config.php` constants**, as a copy-pasteable block. Covered in full in
  [§9.5](#95-wp-configphp-never-written-always-reported).
- **PHP version and extension deltas** between source and destination, since a source plugin
  may depend on an extension the destination lacks.
- **Server software difference** (Apache vs nginx vs LiteSpeed), which decides whether the
  `.htaccess` advice is even applicable.

This list is cheap to produce, and it converts the most common category of post-migration
support ticket into something the user can see and act on immediately.

### 9.8 Summary of the decision

| | |
|---|---|
| **Default** | Full replace of every table — the only sound SQL-level operation |
| **Except** | **Users are merged**, destination accounts kept, source IDs preserved ([§9.4](#94-users-merge-not-replace)) |
| **Plus** | Atomic swap, with `wpold_` tables retained for rollback |
| **Plus** | Compatibility gated before packaging and again before the first write ([§8](#8-compatibility-the-destination-handshake)) |
| **Not** | Row-level merge for any table other than users |
| **Not** | Any write to `wp-config.php`, ever ([§9.5](#95-wp-configphp-never-written-always-reported)) |
| **Fallback** | In-place import with a pre-import SQL backup, when `RENAME TABLE` is unavailable |
| **File scope** | Content only — WordPress core is never packaged ([§6](#6-the-package-format)) |
| **Rollback window** | `wpold_` retained until the user confirms success, hard cap **30 days** |
| **Out of scope** | True content merge — that is a WXR-based feature, not this pipeline |

**This area still needs a spike before phase 4a is estimated with confidence** — specifically
the `RENAME TABLE` probe across real shared hosts, the behaviour of the session cookie across
the swap, and the users merge against a pair of real sites with overlapping accounts.

---

## 10. Phased delivery

Sizes are relative (S/M/L/XL), not calendar estimates.

### Phase 0 — Stop the bleeding · **S** — ✅ *done 2026-08-23*

Pure deletion; independently valuable. After this the plugin can no longer break a site — and
whatever is gone here never has to be renamed in Phase 1.

- Delete `MigrationManager/MigrationTasks.php` including the `wp-config.php` rewrite (**2.1**).
- Delete the CWM client, `migrationId` / auth-token / region options, `send-files`,
  `report-errors` (**2.4, 2.5**).
- Drop `wp-module-tasks` (**2.7**).
- Remove the `admin_init` redirect hijack (**3.10**) and `set_time_limit()` in a getter (**3.12**).

**Exit:** nothing writes to `wp-config.php`; no outbound HTTP to any host backend;
`grep -r "eigproserve\|can-we-migrate\|manifestScan"` is empty.

**Done.** All four criteria verified, the last two behaviourally rather than by grep: the plugin
was booted against a WordPress stub whose `wp_remote_post`, `wp_remote_get`, and
`wp_safe_redirect` all throw, and a full compatibility run completed without tripping any of
them. Result: ~1,400 lines removed, `newfold-labs/wp-module-tasks` dropped along with **five
transitive dependencies** it pulled in, leaving `wp-forge/wp-plugin-check` as the only runtime
dependency. Zero activation hooks, one deactivation hook, no `admin_init` hook. Four local
compatibility checks survive and pass; three REST routes remain, all under `migration-check`.
The export flow is intentionally gone until phase 3 rebuilds it.

### Phase 1 — Rename, de-brand, repo hygiene · **M** — ✅ *done 2026-08-23*

Mechanical, but touches everything, so it goes before any *new* code is written — otherwise
Phase 2 authors fresh files under a name already known to be wrong. It goes *after* Phase 0
because there is no point renaming code that is about to be deleted.

**Kept deliberately separate from cleanup, and that separation is the point.** A rename is a
mechanically verifiable transformation: grep the old prefix, expect zero hits, and every changed
line should be an identifier and nothing else. A deletion is a judgement call that needs reading.
Combine them and the diff becomes unreviewable — and when something breaks you cannot tell
whether the rename missed a reference or the deletion removed something live. Two commits make
that a two-second question.

- Namespace, constants, function prefix, text domain, option names, main plugin file, REST
  namespace, CSS/JS prefixes. Target names settled in [D1](#14-open-decisions-for-review).
- **Hazard: the namespace appears inside string literals.** Task executors are strings —
  `'BluehostSiteMigrator\Packager\DatabaseDumper::execute'` — as are hook callbacks and option
  keys. An IDE's symbol-aware "rename namespace" silently misses every one of them, and the
  failure is a runtime fatal, not a compile error. Drive this pass with text search, and verify
  with a grep that covers strings, not just symbols.
- Delete wp.org machinery: `.wporg/`, `readme.txt`, `svn-deploy-*.yml`.
- Collapse the four-place version scheme to **one** source of truth — read the version from the
  plugin header at build time so `build/` can never diverge again.
- `composer.json`: name, description, autoload prefix. `newfold-labs/wp-module-tasks` went in
  phase 0. **Correction: the Satis `repositories` block stays.** This item assumed no
  `newfold-labs/*` dependency would remain, but `newfold-labs/wp-php-standards` is still the
  phpcs standard and returns 404 on Packagist, so Satis is the only way to resolve it. The block
  is dev-only and already scoped to `newfold-labs/*`.
- **Add the missing `LICENSE` file** and **restore upstream attribution** (**3.14**). The plugin
  header has declared `GPL-2.0-or-later` since 2020 with no licence text in the repository, and
  `Database/DatabaseBase.php` — 1533 lines the plan explicitly keeps — is a fork of All-in-One WP
  Migration carrying no notice of its origin. Add the GPL text, a `CREDITS` or `NOTICE` entry
  naming ServMask and the fork point, and a header comment in each derived file. Cheap now,
  awkward later, and re-releasing under a new name without it makes it worse rather than
  neutral.

**Exit:** activates cleanly under the new name; `composer lint` passes; no branded **identifier**
outside `docs/`; `LICENSE` present and derived files attributed.

The identifier check is
`grep -nE 'BluehostSiteMigrator|BH_SITE_MIGRATOR|nfd_bhsm_|bluehost[-_]site[-_]migrator|bh_site_migrat|bh-site-migrator|bh-sm|bhsm'`.
It deliberately does **not** ban the bare word: `CREDITS.md` has to name the plugin's origin for
the attribution to mean anything, and `CLAUDE.md` explains where the code came from. Banning
provenance prose would make the licence fix impossible to write.

**Done.** 390 substitutions across 47 files, plus the corrections verification caught — see
below. Everything green: `composer lint` clean, every non-vendor file lints, all local JS imports
resolve, every image the stylesheet references exists, `composer validate` passes with a synced
lock, and the plugin boots against the WordPress stub with four checks registered and three REST
routes under the new namespace.

**Two misses the mechanical pass made, both instructive:**

1. `WP_Admin.php` declared `namespace BluehostSiteMigrator;` — the **root** namespace, with no
   trailing separator, so the `BluehostSiteMigrator\` rule did not match it. A single unconverted
   namespace declaration is a fatal on load. This is the same class of hazard as the string
   literals, in a different disguise: pattern-based renames miss the case that lacks the
   delimiter you anchored on.
2. `DatabaseBase.php` emitted `-- Bluehost Site MIgrator SQL Dump` into every dump — a
   capital-I typo in the original meant the `Bluehost Site Migrator` rule skipped it.

Both were caught by grepping after the pass rather than trusting it. **Verify renames by
re-searching for the old identifiers; do not assume the substitution was total.**

**Also folded in, since the rename exposed them:** the four Tailwind rules for the transfer
screens deleted in phase 0 (which kept five now-unused illustrations alive), a Bluehost logo
embedded in the admin menu icon as base64 and another inside `computer-transfer-broken.svg`, the
dead `geo` manifest entry reading an option nothing writes, and the `NFD_SM_ENTRYPOINT_URL`
constant orphaned when the redirect hijack went.

**Version scheme collapsed, differently than planned.** Rather than making three files agree,
build output is now **unversioned** — `build/`, not `build/<version>/` — so drift cannot break
enqueueing at all; cache busting already came from the content hash in the generated
`.asset.php`. `build/` and `src/styles/nfd-site-migrator.css` are generated, so both are now
untracked; committing generated output is what let the versions diverge in the first place.

### Phase 2 — Core, package format, stepping engine · **L** — ✅ *done 2026-08-23*

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
- **Ship a minimal CLI harness** — `wp <ns> export --to=<dir>` and `wp <ns> import --from=<dir>`,
  nothing else. No flags, no JSON contract, no progress bars; those are Phase 6. Its purpose is
  to be the **second consumer of `Core/` from the day `Core/` exists**. A lint rule catches
  `$_POST` in the core; it cannot catch an API quietly shaped around one caller's assumptions.
  Only a real second caller does that, and this one costs a day or two.

**Exit:** `Core/Export` produces a valid package driven from the CLI harness, with no REST
involved; parts open in `unzip`; `wp-config.php` provably absent; killing the driver mid-run and
re-driving resumes correctly.

**Done.** ~3,150 lines of packaging code replaced by ~1,500. All criteria verified against a
fixture WordPress tree, driven headless with no WordPress bootstrap and no REST: every part
opens under both `unzip -t` and Python's `zipfile`; `wp-config.php` is absent from every part
and its secret appears nowhere in the package bytes; no `wp-admin/`, `wp-includes/` or core root
file is collected; `wp-content/languages/` and unrecognised `wp-content` directories are carried
(the old silent-loss gap); the migrator excludes itself; oversized files land loose in `large/`.
Killing the driver after 3 of 7 steps left a checkpoint and no manifest, and re-driving in a
fresh process produced a file set **identical to the uninterrupted run** — 511 entries, no gaps,
no duplicates.

**Three bugs the tests caught, none of which review would have:**

1. `FileCollector::step()` broke out of its loop on end-of-list *before* flushing the batch it
   had just read. Any part with fewer than one batch of files silently produced an empty
   archive. The first run collected 1 file out of 15 and still reported success.
2. `nfd_sm_themes_dir()` returns an **array** — WordPress supports multiple theme roots via
   `register_theme_directory()` — and was being passed where a string was expected. `PartSpecs`
   now emits one part per theme root, and derives every prefix from where a directory actually
   is relative to `ABSPATH` rather than assuming `wp-content/<name>`, so relocated plugin, theme
   and upload directories are recorded at the right path.
3. Drop-ins were collected twice, by `dropins` and again by `content-other`, and stored twice.
   `PartSpec` gained file-level exclusion.

**Validated against a real site.** The fixture harness stubs the database stage, so the export
was afterwards run against a live WordPress 7.1 install — 77 tables, 2.7GB of content, MySQL
8.0.35 — read-only, with the package written outside it. Two real defects surfaced that the
fixture could not have found:

1. **The dump was not loadable.** `mysql < database.sql` failed at line 9 with
   `Invalid default value for 'scheduled_date_gmt'`. WordPress schemas are full of
   `DEFAULT '0000-00-00 00:00:00'`, which strict mode rejects — and strict mode has been the
   default since MySQL 5.7. The dump also writes tables alphabetically rather than in dependency
   order, so a plugin's foreign key can reference a table that does not exist yet. `get_header()`
   now emits a session preamble (`SQL_MODE='NO_AUTO_VALUE_ON_ZERO,ALLOW_INVALID_DATES'`,
   `FOREIGN_KEY_CHECKS=0`, `UNIQUE_CHECKS=0`) saved into user variables, and a matching footer
   restores them — written only on completion, so a resumed dump does not get a footer in the
   middle. **This would have surfaced in phase 4a as "import fails on some sites and not
   others."**
2. **`DatabaseMysqli` emitted two PHP 8.4 deprecations per query** for
   `MYSQLI_STORE_RESULT_COPY_DATA`, which has been ignored since 8.1. Across ~7,000 queries that
   is 14,000 notices per export; on a site with debug logging on it fills a disk. Now guarded by
   `PHP_VERSION_ID`. Fixing it also made the dump three times faster.

**Round-trip proof.** The corrected dump was loaded into a scratch database on the same server
and compared with the source: **77 tables → 77 tables**, none missing or extra; **7,015 rows →
7,015 rows**, no per-table mismatches; the first five published posts byte-identical; and
`active_plugins` and `wp_user_roles` identical and still unserializing. The scratch database was
then dropped. This is stronger evidence than the exit criterion asked for, and it de-risks phase
4a considerably.

One incidental finding worth recording: **two exports of the same site are never byte-identical.**
Consecutive dumps differed in exactly two places — the options table's `AUTO_INCREMENT` and the
`cron` option's next-run timestamp — because WordPress writes to itself continuously. Checksums
therefore verify that a package is intact, never that two packages match.

**Deviation worth noting:** the plan called for the harness to ship `export` and `import`.
`import` is registered but errors clearly, pointing at phase 4a — there is no importer to drive
yet, and a command that silently does nothing is worse than one that says why. `verify` was
added instead, because `PackageReader` needed a caller and "part 3 checksum mismatch" is a
usable failure report.

### Phase 3 — Export in the UI · **L** — ✅ *done 2026-08-23*

- `POST /export/step`, `GET /export/manifest`, authenticated ranged download endpoint.
- Harden the storage directory: deny rules, `random_bytes()` filenames.
- SPA: replace `useInterval` polling with a sequential step loop; real progress; resume on
  reload; honest failure states.
- Rework `Preflight/Checker` to return a structured `Report` and **fail closed** when a check
  cannot complete (**2.6, 4.6**). Include disk-space and upload/execution limit detection, and
  the `CREATE`/`RENAME`/`DROP` scratch-table probe that decides which import mode
  [§9.3](#93-atomic-swap) can use.
- **The compatibility handshake** ([§8](#8-compatibility-the-destination-handshake)):
  `SiteProfile` gathers local facts and serves them; `Pairing` issues single-use codes and
  performs the authenticated fetch; `Compatibility` compares two profiles into gates plus
  warnings. The export screen renders the verdict *before* packaging begins, with a **Re-check**
  action. The profile endpoint returns `404` when unauthenticated, is rate-limited, and uses
  `'sslverify' => true` unconditionally (**2.5**). Both fallbacks ship with it: pasted profile
  blob, and skip-with-"unverified destination".
- Record `WP_CONTENT_DIR`, PHP version and extensions, `.htaccess` extras, and the
  `wp-config.php` constant summary ([§9.5](#95-wp-configphp-never-written-always-reported)) into
  the manifest for the destination's follow-up report.

**Exit:** a non-technical user exports a real site through wp-admin and downloads a verified
package. Closing and reopening the tab mid-export resumes.

**Done.** `Core/Preflight/` (Report, Checker, SiteProfile, Pairing, Compatibility), `Rest/`
replacing `RestApi/` with eleven routes, and the export screens from the prototype. Verified
against the live WordPress 7.1 site, including **in a real browser**: the plugin was temporarily
symlinked in, the admin page rendered with live facts, a pairing code was minted, and the source
fetched the destination's profile over HTTP and rendered the verdict — the whole handshake, end
to end, with no console errors. The site was then deactivated, unlinked, and its options and
transients cleaned up.

Checked programmatically as well: 404-not-401 on a missing *and* a wrong pairing code, rate
limiting holding after twelve attempts (the correct code is refused too), three path-traversal
attempts on the download endpoint all refused, the step loop driving a real export through REST,
`/export/state` reporting `in_progress` mid-run and `complete` after, and `/export/manifest`
verifying.

**Three defects found by testing:**

1. **Preflight took 22 seconds.** `nfd_sm_get_dir_size()` walks the whole content directory, and
   it was being called once per profile gather — four times per request on this 2.7GB site. Now
   `nfd_sm_measure_dir()` has a time budget and a transient cache: 3.3s cold, 0.2s warm. A walk
   that runs out of budget returns a **floor** rather than a total, and says so.
2. **That created a second bug immediately**, caught by the same test: treating a floor as
   grounds to warn meant a destination with 1KB free stopped blocking. Only one direction is
   conclusive — free space *below* the floor definitely will not fit and blocks; above it proves
   nothing and warns.
3. **The Range header handler mishandled suffix ranges.** `bytes=-100` means *the last hundred
   bytes*; the code read it as bytes 0–100. A resumed download would have silently received the
   wrong bytes and produced a corrupt zip far from the cause. The arithmetic is now a pure
   `resolve_range()` with nine cases under test.

**Two repo defects fixed in passing.** `eslint-plugin-cypress` was referenced by `.eslintrc` but
never listed in `package.json`, so `yarn lint:js` had never run at all; v7 is flat-config only,
so it is pinned to `^2.15.1` for eslint 8. And the site profile was 6.5KB because `SHOW
COLLATION` returns ~290 rows — filtered to the utf8/latin1/ascii/binary families it is 3.7KB,
which matters because the fallback path asks a human to copy and paste it.

**Not yet done:** a full 2.7GB export through the browser UI. The step loop, resume, manifest and
download are verified through REST against that site; what has not been watched end to end is the
progress screen during a multi-gigabyte run.

### Phase 4a — Import core, driven headless · **L** — ✅ *done 2026-08-24*

None of this exists today, and it carries nearly all the project's risk. **Spike
[§9](#9-surviving-the-database-swap) first.**

Split from the import UI deliberately. The dangerous half — replacing a live site's database —
is proven through the Phase 2 CLI harness, with **no browser in the picture**, before a single
import screen is written. That means the round-trip test becomes this phase's exit criterion
rather than a Phase 7 validation, which is the largest single de-risking move available in this
plan.

- `FileRestorer` with **path-traversal guards** — reject `..`, absolute paths, and symlinks
  escaping the root. This is untrusted archive input and the primary security surface.
- `DatabaseImporter`: stream SQL into **temp-prefix tables**, chunk on statement boundaries
  respecting `max_allowed_packet`, wire up the dead `is_*_query()` predicates.
- **Atomic swap** ([§9.3](#93-atomic-swap)): verify, multi-table `RENAME`, retain `wpold_` for
  rollback. Plus the in-place-with-backup fallback when the preflight probe says `RENAME` is
  unavailable, and view recreation after the swap.
- **`UserMerger`** ([§9.4](#94-users-merge-not-replace)) — the largest single piece of new
  logic in this phase. Match destination users to source users by email then login; keep source
  IDs; renumber only destination-origin `usermeta.user_id`; rewrite prefix-bearing meta keys and
  `{prefix}user_roles`; enforce login and email uniqueness ourselves, since the schema does not;
  suffix genuine collisions; guarantee the acting user `administrator`; demote roles the source
  does not define. Replace mode stays available as a non-default option.
- **Authoritative pre-write compatibility check** (checkpoint 3 in
  [§8.3](#83-the-three-checkpoints)) against live destination facts, plus the collation plan —
  which means finally wiring up the dead `replace_table_collations()`
  ([§8.5](#85-collation-specifically)) and extending it to MariaDB's `uca1400` collations.
- **`wp-config.php` constant report** ([§9.5](#95-wp-configphp-never-written-always-reported)):
  `token_get_all()` extraction on the source, allowlist plus secret redaction, copy-pasteable
  block on the completion screen. Nothing is written to the file.
- `SearchReplace` + prefix reconciliation via `replace_serialized_values()`.
- `Fixups`: `siteurl`/`home`, permalinks, dropins, `autoload` hygiene.
- The rest of the survival kit ([§9.6](#96-the-rest-of-the-survival-kit)): file-based
  checkpoint, import-window token auth, temporary mu-plugin, post-import credential messaging.
- Rollback as a core operation while `wpold_` tables survive.

**Exit: round-trip green** (see [§12](#12-testing-strategy)), run entirely from the CLI harness —
export site A, import into site B at a different URL *and* table prefix, B functionally
equivalent to A; B's pre-existing users can still log in and A's authorship is intact; and a
deliberately failed import leaves site B untouched. Nothing in Phase 4b starts until this is
green.

#### What shipped

`Core/Import/`: `Importer` (eight stages behind the same `step( $budget )` contract as the
exporter), `PathMap`, `FileRestorer`, `DatabaseImporter`, `SearchReplace`, `UserMerger`, `Swap`,
`Fixups`, `ImportCheckpoint`. `DatabaseBase` gained the `import()` half it was forked without.
`Core/Export/ConfigScanner` reads the source's `wp-config.php` with `token_get_all()`. The CLI
gained `import`, `rollback` and `cancel`.

Every orphaned symbol listed in [§11.1](#111-dead-code-that-must-not-be-deleted) now has a caller:
`is_drop_table_query()`, `is_create_table_query()`, `is_insert_into_query()`,
`is_start_transaction_query()`, `is_commit_query()`, `is_atomic_query()`, `is_cache_query()`,
`replace_table_collations()`, `replace_table_name()`, and
`DatabaseUtility::replace_serialized_values()`. Keeping them was worth it.

The stage order is the design: files before database, database into staging tables, and every
remaining way to fail — the merge, the uniqueness check, the verification — placed before the swap.
By the time anything irreversible runs, the only operation left is one `RENAME TABLE`.

#### The round trip

Two real WordPress 7.1 installs against one MySQL 8.0.35: source at `http://source.test` with
prefix `wp_`, destination at `http://dest.test` with prefix `dst_`. The destination is seeded to
hit all three merge outcomes at once — `alice@example.com` on both sides under different logins,
`carol` who exists only on the destination, `admin` on both sides belonging to two different
people, and `dave` holding a `shop_manager` role the source has never heard of.

**44 assertions, all green**, over one command and again over the same import driven one step per
*process*, thirteen separate PHP invocations, to prove that resuming works rather than that a loop
works. Beyond the exit criterion, the run checks that a serialized option survives rewriting two
levels deep and still unserializes; that a non-string neighbour inside it is untouched; that
`wp-config.php` is byte-for-byte unchanged; that the prefix-bearing usermeta keys and
`{prefix}user_roles` all moved to `dst_`; and that each of the four accounts signs in with the
password the design says it should keep.

Rollback returns the destination to its own name, posts, users, IDs and passwords.

Three deliberate failures, each leaving the destination completely untouched: a package whose
checksum no longer matches (refused at precheck), a package with valid checksums and invalid SQL
(fails mid-load, one staged table left, live site serving normally), and — unplanned — a PHP fatal
in the middle of the transform stage, which turned out to be the most convincing demonstration of
the lot.

#### Defects found by building and testing it

**`replace_serialized_values()` was broken on PHP 7+ and could never have worked.** The recursion
hands itself arrays, and from PHP 8.0 `unserialize()` throws a `TypeError` for a non-string
argument. A `TypeError` is an `Error`, not an `Exception`, so the function's own
`catch ( \Exception $e )` does not stop it: the first serialized option in the database kills the
whole import. This is retained All-in-One WP Migration code that has been dead since the fork, so
nothing had ever executed it. Fixed with an `is_string()` guard and by testing `is_serialized()`
before the call rather than after it.

**The symlink guard had a hole exactly where it mattered.** `FileRestorer` checked for a symlinked
ancestor only when the parent directory did *not* exist. If it did exist and was a link pointing
out of the tree, every path check passed and the write followed it out — and `safe_path()` cannot
catch that, because the *name* is entirely innocent. Now the deepest existing ancestor is resolved
with `realpath()`, which follows every link, and the result has to still be under the root.

**Import state written into the package travelled with the package.** Copying a package carried a
finished import's checkpoint into the copy, which the importer read as "already done" and reported
as `Success: Imported 447 files` without doing anything at all. [D15](#14-open-decisions-for-review)
moves it to the destination's own storage. A run is bound to its package only once precheck passes,
so a rejected package does not lock the site.

**`run()` spun forever on a failed stage** — `step()` catches the error and returns neither done nor
advanced, so the loop retried a step that had already decided it could not proceed.

**The `nfd_sm_completed_timeout` filter was added per step and never removed**, in the exporter as
well as the importer, leaving one closure per step in a CLI run. Fixed in both.

**`composer fix` silently rewrote data keys, and the preflight caught it.** Newfold's ruleset
includes a "spell WordPress correctly" sniff; phpcbf applies it inside string literals without
knowing which are prose and which are data, and turned `->get( 'wordpress.version' )` — a dot path
into the site profile — into `'WordPress.version'`. Every lookup then returned its default, every
compatibility gate went indeterminate, and the import refused to run. That the *failure mode* was a
refusal rather than a bad migration is [§8](#8-compatibility-the-destination-handshake) working as
designed: indeterminate blocks. The profile key is now `wp`, which the sniff has no opinion about.
The general hazard is the same one [§11.2](#112-why-static-analysis-will-lie-to-you-here) describes
about symbol graphs, from a different tool: this codebase keys on strings, so anything that edits
strings automatically can change behaviour.

**The manifest could not say where a part's files came from.** Part names were derived from the
zip's file name, so `plugins.002.zip` became a part called `plugins.002`, and nothing recorded the
source-relative prefix — which makes it impossible to place files correctly when the destination
keeps `wp-content` somewhere else. Both are now carried explicitly.

#### Not done

The **in-place-with-backup fallback** for hosts where `RENAME TABLE` is unavailable is specified in
[§9.3](#93-atomic-swap) and is not built. Preflight probes for it and reports it, so such a host is
told it cannot proceed rather than being allowed to start and failing at the swap. The probe has
also not met a host that actually refuses.

The **import-window token** and the **temporary mu-plugin** ([§9.6](#96-the-rest-of-the-survival-kit))
remain Phase 4b, as planned: neither exists under CLI, where there is no cookie session to lose and
no plugin loader to survive.

`SearchReplace` walks every row of every table. That is what every tool in this category does, but
it has only been measured against a small database, and keyset pagination falls back to
`LIMIT/OFFSET` on tables with a composite primary key or none.

The collation map is exercised for the case that matters — MariaDB's `utf8mb4_uca1400_ai_ci`, which
no MySQL server knows, is rewritten to `utf8mb4_unicode_ci`, and a collation the destination already
supports is correctly left alone. The **lossy** `utf8mb4` → `utf8` branch is not exercised, because
it needs a server old enough to lack `utf8mb4`. The code records the downgrade and the importer
reports it; nobody has watched it happen.

Views are captured during the load and recreated against their final names after the swap, but the
fixture has no views, so that path has been read and not run.

### Phase 4b — Import in the UI · **L** — ✅ *done 2026-08-24*

Wrapping a proven core. Every failure discovered here is a browser or transport failure, not a
migration-correctness failure — which is exactly why the split is worth it.

- Chunked upload endpoint + client, with resume and assembly verification.
- Drop-in-folder detection as the large-site escape hatch.
- The browser-specific half of the survival kit
  ([§9.6](#96-the-rest-of-the-survival-kit)): import-window token auth and the temporary
  mu-plugin. Neither exists under CLI — there is no cookie session to lose and no plugin loader
  to survive — so this is the first time they are exercised.
- Import UI: upload, verify, preview the manifest and the user-merge plan, explicit confirm,
  progress with the swap called out, completion report, and the rollback action.

**Exit:** a non-technical user completes the full import through wp-admin, including the
uploads, on a host with an 8MB `upload_max_filesize`.

#### What shipped

`Core/Import/Upload` (chunked, resumable, plus drop-in-folder discovery), `Core/Import/ImportToken`
and `Core/Import/Loader` — the two survival-kit pieces that only exist in a browser —
`Rest/ImportController` with twelve routes, and the four screens: choose, review, run, done.
`UserMerger::plan()` was extracted as a pure static so the confirmation screen and the merge
itself cannot disagree; the manifest now carries the source's account list so that plan can be
computed before anything is written.

The review screen is the one that matters. It shows what arrives, what it replaces, the exact
account-by-account outcome with the resulting usernames and which password each person will need,
the `wp-config.php` block to paste, and a checkbox naming the site about to be overwritten. The
run screen lists every stage with the swap marked as the point of no return, and the safety strip
flips the moment it passes.

#### Verified in a browser

Two WordPress installs, the destination served over HTTP, driven through Chrome: upload five loose
files (matched to their manifest paths by basename), verify, review, confirm, import 447 files and
194 rows past the swap, land on the completion screen **still signed in**, then undo — back to the
destination's own name, posts, users and password with no tables left behind. The drop-in-folder
route was exercised the same way.

#### Four defects, all browser-only

Every one of these is invisible from the CLI, which is exactly why the phase split put them here.

**REST URLs were built by string concatenation and broke on plain permalinks.** `rest_url()`
returns `/index.php?rest_route=/ns/v1/` when a site has no permalink structure, so appending
`?file=…` produced a second `?` and the route stopped resolving. This had shipped in Phase 3 —
**the download buttons on the export screen were dead on any plain-permalink site** — and the new
upload endpoint inherited it.

**The REST root the page was rendered with stopped existing mid-import.** apiFetch pins
`/wp-json/…` at render time. The import then replaces `permalink_structure` with the source's, and
if the two sites differ — the destination had `/%year%/%monthnum%/…`, the source had none — that
root starts serving the home page instead of JSON, one stage after the point of no return. Every
import call now goes through the `?rest_route=` form, which no permalink setting can invalidate.
The general rule this is an instance of: *the import must not depend on anything the swap can
change.*

**WordPress's nonce check rejected the requests before the token could speak.**
`rest_cookie_check_errors()` runs on `rest_authentication_errors`, ahead of any permission
callback, and the nonce is derived from the acting user — so it dies with the users table. A
filter now clears that specific error for a request carrying a valid import token, which is a
stronger claim than the nonce it stands in for. It has to run at priority **200**: core registers
its check at 100, and the first attempt at 99 saw a null result, did nothing, and watched the
error be raised immediately afterwards.

**The acting user could be logged out by their own migration.** WordPress's auth cookie names the
*login*, and the merge is allowed to change it — a matched account takes the source's username, a
collision suffixes the destination's. `Fixups` now re-issues the cookie under whatever identity
the person ended up with, and says so.

Each step is also wrapped in an output buffer, because one `echo` from any hook still loaded would
put text in front of the JSON and strand a user mid-import.

#### Not done

No test has been run against a host with a genuinely small `upload_max_filesize`; the chunk size is
derived from `post_max_size` and `upload_max_filesize` and was exercised at 256KB and 2MB, but not
against a server that actually refuses a larger request. The browser upload was verified with a
104KB package and the REST harness with 13MB across 58 chunks — a multi-gigabyte upload through the
UI remains unwatched, as does resuming one after a genuine connection drop.

### Phase 4c — Export performance · **S** — ✅ *done 2026-08-24*

Not a planned phase. The export was tried on a real site and was too slow, so it was measured.

**The volume was a splitting convenience; it needed to be the unit of writing.** `FileCollector`
opened the current volume, added 64 files, and closed it — and `ZipArchive::close()` does not
append. It rebuilds the archive into a temporary file and renames it over the original. Adding to
a growing archive therefore rewrote everything already in it, so packaging N bytes in K sittings
cost roughly N×K/2 in disk traffic rather than N. You could watch it happen: a 698MB
`uploads.zip` sitting next to a 708MB `uploads.zip.bbdl2i.part`.

A volume is now opened, filled, and closed **exactly once**, and never reopened. The limit came
down from 1GB to **128MB**, because the limit is what bounds a single close — and therefore what
keeps one step inside a shared host's execution budget.

**Already-compressed files are stored, not deflated.** Uploads are almost entirely JPEG, PNG, WebP
and MP4; deflating them spends CPU to make each file fractionally larger.

**Sizes and checksums are taken as each volume is closed.** They were all computed in `finalize`,
which is one step and cannot be split — minutes of hashing in a single un-resumable request on a
large site, in direct contradiction of [§4](#4-the-execution-model).

Measured on 1.5GB across 5,760 files, shaped like a real uploads directory:

| | Before | After |
|---|---|---|
| uploads part | 60.6s | 18.2s |
| finalize | 8.8s | 0.2s |
| **total** | **69.5s** | **18.5s** |
| throughput | 22 MB/s | 83 MB/s |

The same package — sixteen volumes — was then exported, verified, and imported into the
destination, and every one of the 5,761 files compared byte for byte against the source. The only
differences were the importer's own state files, which is correct.

A local SSD is the *friendly* case for the old behaviour. The rewrite amplification lands on disk
I/O, which is the scarcest resource on the shared hosting this plugin exists for, so the
improvement there should be larger than 3.8×.

One guard added while in there: a volume also closes at 20,000 entries. The size cap alone does
not bound memory, because `ZipArchive` holds a record per pending entry until close, and a cache
plugin writing a hundred thousand 1KB files into uploads would exhaust the memory limit long
before reaching 128MB.

### Phase 5 — Direct site-to-site transfer · **L** *(v2)*

The Migrate Guru-like experience. Removes manual file handling entirely.

- Source: generate a one-time transfer key (scoped, expiring, single-use, rate-limited,
  `random_bytes()`).
- Destination: paste key, pull each part server-to-server over HTTP with `Range` resume.
- Same `Importer` core; only the byte source changes — `PackageReader` gains a remote backend.
- Security review is mandatory here: this is the first time the plugin exposes site content to
  a network caller.

### Phase 6 — WP-CLI as a supported surface · **S/M** *(v3)*

Not "write the CLI" — that happened in Phase 2 and has been driving the test suite ever since.
This phase promotes the harness into a product: the full command set, the machine contract, and
the documentation. Cheap **because** `Core/` had a second consumer the whole way.

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
infrastructure. Note that the round-trip test itself is **not** deferred to here — it is
Phase 4a's exit criterion, made possible by the Phase 2 harness.

### Phase 8 — Hardening and distribution · **S/M**

- Raise the PHP floor to 7.4+ (**4.8**). The 5.6 header is already inconsistent with phpcs
  `testVersion 7.0-` and with `esc_xml()` needing WP 5.5+ (**3.8**).
- Produce an installable zip; document install on both sites.

---

## 11. What gets deleted

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
import UI add back substantially more — expect the plugin to end up **larger** than today, not
smaller, while being far simpler per unit of function.

### 11.1 Dead code that must not be deleted

A cleanup pass run on instinct will get this wrong, so it is worth stating plainly. There are
**two kinds of dead code in this repository and they have opposite fates.**

**Abandoned — delete it.** Code that was written, stopped being used, and was never removed:

- `Compressor::add_file()`'s `$encrypt` / `$encrypt_pass` parameters, never called with a real
  key (**3.11**).
- `Archiver`'s protected path helpers, duplicated as globals in `functions.php`, where only the
  globals are called (**3.11**).
- The computed `$progress` variable, dead in all but one packager (**3.5**).

**Orphaned — keep every line.** Code that is dead because a *feature* was stripped out around
it, not because it was abandoned. All of it is import-side scaffolding left behind when
`DatabaseBase` was forked from All-in-One WP Migration, and **Phase 4a revives all of it**:

| Dead today | Call sites | Revived by |
|---|---|---|
| `is_drop_table_query()`, `is_create_table_query()`, `is_insert_into_query()`, `is_start_transaction_query()`, `is_commit_query()` | reachable only from `is_atomic_query()`, which nothing calls | `DatabaseImporter`'s statement classifier |
| `replace_table_collations()` | zero | the collation plan ([§8.5](#85-collation-specifically)) |
| `DatabaseUtility::replace_serialized_values()` | its own recursion only | `SearchReplace` |
| `repair_table()` | via `is_atomic_query()` only | import error recovery |

Deleting these would throw away the most valuable code in the repository — the half of a working
migration engine that this project exists to rebuild. They are not clutter; they are a head
start.

### 11.2 Why static analysis will lie to you here

Do not drive the cleanup from an IDE's "unused symbol" report. This codebase reaches code
through **string literals** that no symbol graph follows:

```php
->set_task_execute( 'BluehostSiteMigrator\Packager\DatabaseDumper::execute' )
```

Task executors, WordPress hook callbacks, and option keys are all strings. A symbol-aware tool
reports these targets as unreferenced, and removing one produces a runtime fatal rather than a
compile error. Confirm every deletion with a text search for the *string*, not just the symbol —
the same discipline the rename in Phase 1 needs, for the same reason.

---

## 12. Testing strategy

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
   the test that proves [§9.3](#93-atomic-swap) works.
5. **Swap and rollback.** Assert the `wpold_` tables are complete and that a rollback restores
   the destination exactly. Run the round trip a second time with `RENAME TABLE` privileges
   revoked, to exercise the in-place fallback path.
6. **Users merge** ([§9.4](#94-users-merge-not-replace)). Provision A and B with a deliberately
   nasty account overlap: one shared email with different logins, one shared login with
   different emails, one account unique to each side, and a destination user holding a role the
   source does not define. Then assert — every source post's `post_author` still resolves to the
   right person; every pre-existing destination user can log in; logins and emails are unique in
   the final table; capability keys use the destination's final prefix; the undefined role is
   demoted and reported; and the collision is suffixed rather than silently overwriting either
   account. Run it again with a different table prefix on each side.
7. **Compatibility gates** ([§8](#8-compatibility-the-destination-handshake)). Assert each hard
   gate blocks: destination on older WP, older `db_version`, multisite mismatch, PHP below a
   plugin's `Requires PHP`, and a source collation with no path on the destination. Assert the
   lossy `utf8mb4` → `utf8` downgrade warns loudly rather than proceeding quietly. Assert a
   stale profile is rejected and that checkpoint 3 still runs when checkpoint 2 passed.
8. **Contract tests.** Snapshot the manifest schema, the site-profile schema, and (from v3)
   `--format=json`; a change must fail CI loudly. Phase 4a supplied the argument for this: renaming
   one key inside the site profile broke every compatibility gate, and nothing caught it until a
   package built before the change was fed to a destination built after it.
9. **Cypress** should drive at least one **unstubbed** path end to end.

CI: replace the wp.org/SVN workflows with lint + PHPUnit + round-trip. The round-trip job is
slow — run it on PRs to `main` rather than every push.

### 12.1 What exists as of Phase 4a

Items **2**, **4**, **5** (rollback half), **6** and part of **1** are covered by a shell harness
that provisions two real WordPress installs against one MySQL server — source at `source.test`
with prefix `wp_`, destination at `dest.test` with prefix `dst_` — and runs 44 assertions over the
result. The destination is seeded with the "deliberately nasty account overlap" item 6 asks for,
all four cases at once. Fault injection covers a package that fails verification, a package with
valid checksums and invalid SQL, and six hostile archive paths including one that writes through a
pre-existing symlink; each asserts the live site is untouched afterwards. The same import is also
driven **one step per process** — thirteen separate PHP invocations — because a loop inside one
process proves the loop works, not that resuming does.

It is a shell script, not PHPUnit, and it is not in CI. That is the gap: the harness proves the
behaviour today but nothing stops it regressing tomorrow. Converting it is Phase 7's first job,
and it is now a translation rather than a design problem.

Still uncovered: item **3** entirely (no constrained-host simulation), the in-place fallback in
item **5**, and item **7**'s gates beyond the ones the fixture happens to exercise.

---

## 13. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| **Database-swap survival** ([§9](#9-surviving-the-database-swap)) | Import stalls half-done, site left broken | **Held in 4a.** Atomic swap collapses the inconsistent window to an instant; three deliberate failures — including a PHP fatal mid-transform — each left the destination untouched. Token auth and the mu-plugin are 4b, and only matter in a browser |
| **`RENAME TABLE` unavailable or restricted** | Falls back to in-place import, reopening a real inconsistent window | Preflight probes with a scratch table rather than inferring from grants; pre-import SQL backup in the fallback path; UI states which mode is in effect before the user commits |
| **2x database size needed for the swap** | Import refused on tight-quota hosts | Counted in the preflight free-space check alongside the file-side estimate |
| **Users merge writes rows the schema will not police** ([§9.4](#94-users-merge-not-replace)) | Duplicate logins insert silently; `get_user_by()` then returns an arbitrary row | **Built in 4a.** `user_login`, `user_email` and `user_nicename` uniqueness verified explicitly on the staged table before the swap; a duplicate fails the import rather than warning. A shared username with different emails is a collision, not a match ([D14](#14-open-decisions-for-review)) |
| **Destination arrives incompatible after the package is built** | Wasted export, upload, and the user's afternoon | Live paired profile checked before packaging ([§8.2](#82-pairing-the-source-asks-the-destination-directly)), re-runnable after a fix, and re-checked authoritatively before the first write |
| **Outbound HTTP blocked, or destination not publicly reachable** | Pairing fetch fails | Documented fallbacks ship in v1: paste the profile blob, or skip the check and mark the package unverified. Never a dead end |
| **Profile endpoint becomes a version-disclosure oracle** | Every install of this plugin advertises its WP/PHP/DB versions to scanners | `404` (not `401`) when unauthenticated, single-use rate-limited codes, scope limited to reading the profile, `sslverify` always on |
| **Collation with no path on the destination** | `Unknown collation` aborts the import mid-stream | Gated in preflight; the already-written `replace_table_collations()` wired up and extended to MariaDB `uca1400` ([§8.5](#85-collation-specifically)) |
| **Import is entirely new and large** | Phase 4 slips | Split into 4a (core, headless) and 4b (UI). Round-trip green is 4a's exit criterion, so correctness is proven before any import screen is built |
| **`Core/` quietly grows transport assumptions** | v3 stops being cheap; the CLI turns into a rewrite | A lint rule blocks `WP_CLI`/`WP_REST`/superglobals under `Core/`, *and* the phase 2 CLI harness gives the core a real second consumer from day one — the part a lint rule cannot enforce |
| **Chunked upload on hostile hosts** | v1 unusable for its target user | Drop-in-folder escape hatch ships in v1; constraint simulation in CI |
| **Path traversal on import** | Arbitrary file write from a malicious package | **Held in 4a**, against six crafted entries. Never `extractTo()`. Note that the name check alone was not enough: a *pre-existing symlinked directory* turns an innocent-looking path into a write outside the site, so the deepest existing ancestor is resolved with `realpath()` and has to still be under the root |
| **Disk exhaustion mid-run** | Corrupt package or half-restored site | Preflight free-space check on both sides; refuse early |
| **`DatabaseBase` is a stale AIO fork** | Inherited unknown bugs; upstream fixes never arrive | Diff against current upstream once; record the fork point in `docs/`. The first inherited bug surfaced in 4a: `replace_serialized_values()` had been fatal on PHP 7+ for years, invisibly, because nothing called it (**3.15**) |
| **Transfer key exposure (v2)** | Whole-site disclosure | Scoped, expiring, single-use, rate-limited, `random_bytes()`; dedicated security review in phase 5 |
| **No upgrade path from 1.0.x** | Existing installs orphaned | Accepted — renamed and unpublished. State it rather than half-supporting it |

---

## 14. Open decisions for review

**D1 — Naming.** **Resolved 2026-08-23:** namespace `NewfoldLabs\WP\SiteMigrator\`, function
prefix `nfd_sm_`, constants `NFD_SM_*`, plugin slug and text domain `nfd-site-migrator`, single
option key `nfd_site_migrator`, storage directory `wp-content/uploads/nfd-site-migrator/`.
Matches the convention in `vendor/newfold-labs/`. **Phase 0 is unblocked.**

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

**Resolved 2026-08-23, database side:** the destination's user accounts are **kept**, and the
source's accounts are **merged into them** — not remapped to a single chosen user, and not
replaced. Source user IDs are preserved so migrated authorship stays intact; only
destination-origin users are renumbered. Full design in
[§9.4](#94-users-merge-not-replace). Replace mode survives as a non-default option. Every other
table remains full-replace ([§9.2](#92-replace-not-merge)).

**D8 — Rollback retention.** **Resolved 2026-08-22:** `wpold_` tables are retained until the
user confirms the migration succeeded, with a dashboard notice, and a **hard cap of 30 days**
after which they are dropped automatically. Preflight's free-space check must account for the
second copy persisting for that window.

**D9 — Users merge: conflict rules.** The merge itself is settled
([D7](#14-open-decisions-for-review), [§9.4](#94-users-merge-not-replace)). **Both sub-choices
resolved 2026-08-24, as recommended, and both are now under test in the round trip.**

- **Whose password wins on a matched account?** The **destination's** — it is the credential the
  person used minutes earlier to start the migration, and a source password set up years ago by
  an agency may be unknown to them. *Rejected:* source, for a cleaner "the site moved wholesale"
  story, at the cost of a likely password-reset round trip.
- **Whose role wins on a matched account?** The **source's**, since the content and the role
  definitions both come from there — with a hard override that the acting user is always
  `administrator`, so nobody can demote themselves out of finishing the import. *Rejected:*
  taking the higher of the two roles, which is friendlier but has no well-defined ordering once
  custom roles are involved.

**D14 — Does a shared username mean the same person?** **Resolved 2026-08-24: no. The email
address is the identity, and a shared username with different emails is a collision, not a
match.** [§9.4](#94-users-merge-not-replace) was ambiguous on this and the two halves of it
disagreed: the matching rule said "by `user_email` first, then `user_login`", while outcome (c)
gave `admin` on both sides with different emails as the archetypal *collision* to keep both of
and report. Implementing the matching rule literally collapses them, and that is the wrong way to
be wrong. `admin` exists on nearly every WordPress site and belongs to a different person on each
one; merging two of them destroys one person's email address and grafts their password onto the
other's identity, with no way back. Keeping two accounts is something a human resolves in a
minute. So the destination's account is carried over with a suffixed login (`admin-2`), keeps its
own password, and the rename is reported prominently. Login matching survives as
`nfd_sm_match_users_by_login`, off by default, for the narrow case where someone changed their
email on one of the two sites.

**D15 — Where does import state live?** **Resolved 2026-08-24: in the destination's own storage
directory, never in the package.** The export checkpoint lives in the package because the export
is what builds it. The import's does not, for two reasons found by testing: a package may sit on
read-only or shared storage, and state written inside one *travels with it* — copy a package that
carries a finished import's checkpoint and the copy reads as already-imported, which the importer
honours by doing nothing and reporting success. One file per site rather than one per package,
because an import replaces the whole site: two at once is not a thing to support, it is a thing to
refuse. The package a run belongs to is recorded inside so the refusal can name it, and it is
recorded only once precheck has passed, so a package rejected before anything was written does not
lock the site out of trying another.

**D13 — Cleanup alongside the rename?** **Resolved 2026-08-23: no — adjacent, never combined.**
Deletion is Phase 0, rename is Phase 1, and they stay separate commits because a rename is
mechanically verifiable while a deletion is a judgement call; merged, the diff is unreviewable
and a breakage cannot be attributed to one or the other. The order was flipped so nothing
doomed gets renamed. Two hard rules on the cleanup itself, both in
[§11.1](#111-dead-code-that-must-not-be-deleted) and
[§11.2](#112-why-static-analysis-will-lie-to-you-here): the orphaned import scaffolding in
`DatabaseBase` is **kept**, not deleted, and no deletion is driven by a symbol-graph "unused"
report, because this codebase dispatches through string literals.

**D12 — Keep the git history, or start fresh?** **Recommend keeping it**, on the `rework`
branch as it stands. The repository is 291 commits and 3 MB across six years — no size problem.
Nothing that would force a rewrite is present: no committed credentials, key material, `.env`,
or `.pem` files (scanned). Against that, three reasons to keep it. It is the **provenance record
for vendored GPL code** — `DatabaseBase.php` entered in `41f4196` with attribution already
stripped (**3.14**), and squashing removes the evidence of when and how without curing the
obligation. It keeps **`git blame` useful on the 1533 lines the plan retains**, which is how the
dead import scaffolding was identified in the first place. And the commit record **is** the
contributor attribution that GPL asks be preserved — 14 people over six years. A rename is
documented more honestly by the Phase 0 commit than by a manufactured genesis. *If a clean
break must be visible*, tag the last pre-rework commit (`v1.0.13-archive`) and let Phase 0 be
the visible boundary.

**D11 — Build order: is the CLI first, or the UI?** **Resolved 2026-08-23:** neither, exactly.
A **minimal CLI harness ships in phase 2** and a **supported CLI surface ships in v3**, while the
UI remains the v1 product. Rejected outright: having the UI shell out to `wp` commands — that
needs `exec()`/`proc_open()` and the `wp` binary on `PATH`, both commonly absent on exactly the
shared hosting v1 targets, and it turns user input into shell construction. Rejected as an
ordering: full CLI-first, because the CLI does not exercise the risky work at all — under
`WP_CLI` there is no execution-time budget and no cookie session, so chunked upload,
browser-driven stepping, and session survival across the swap would all stay unproven. The
harness captures the benefits — a real second consumer of `Core/`, and a shell-scriptable
round-trip test — without deferring browser risk. Phase 4 is split accordingly.

**D10 — How the source obtains the destination profile.** **Resolved 2026-08-23:** the source
**pairs with the destination and fetches it live** over HTTP, authenticated by a single-use code
the user pastes once. The compatibility check is a few hundred bytes and does not need to
inherit the package transfer's manual constraint; making it live also makes it re-runnable,
which matters because most blocked gates are fixable in a minute. Two documented fallbacks:
paste a profile blob when the destination is unreachable, and skip the check entirely when there
is no destination yet. Full rationale and the endpoint's security requirements in
[§8.2](#82-pairing-the-source-asks-the-destination-directly).

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
| 3.3 autoloaded state blob | 2 | On-disk checkpoint (also required by §9) |
| 3.4 `set_status()` per file | 2 | `ProgressReporter` |
| 3.5 progress is theatre | 2, 3 | Real byte-based progress |
| 3.6 `vsprintf` placeholder mismatch | 2 | Option exclusion list rebuilt |
| 3.7 division by zero | 2 | Rewritten with guards |
| 3.8 `esc_xml()` / version floors | 0, 8 | Floors made consistent |
| 3.9 text-domain typo | 0 | De-brand pass |
| 3.10 redirect without `exit` | 1 | Deleted |
| 3.11 dead encryption params | 2 | `Archiver/` deleted |
| 3.12 `set_time_limit()` in a getter | 1 | Deleted |
| 3.13 dead import scaffolding | 4a | `replace_table_collations()` and the `is_*_query()` predicates wired up by `DatabaseImporter`; collation map extended to MariaDB `uca1400` and the lossy `utf8mb4`→`utf8` step made an explicit warned choice ([§8.5](#85-collation-specifically)) |
| 3.14 no LICENSE; stripped GPL attribution | 0 | `LICENSE` added, ServMask attribution and fork point recorded in `CREDITS` and in each derived file's header |
| 3.15 `replace_serialized_values()` fatal on PHP 7+ | 4a | `is_string()` guard, and `is_serialized()` tested before `unserialize()` rather than after |
| 3.16 REST URLs broken on plain permalinks | 4b | One helper builds them, choosing `?` or `&` from the base; the import uses the permalink-independent `?rest_route=` form throughout |
| 4.1–4.8 refactors | 2, 3, 8 | As described above |
