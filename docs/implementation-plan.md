# Implementation Plan — Site Migrator rework

**Status:** Draft for review · **Written:** 2026-08-21 · **Baseline:** `87e9a6e`, branch `rework`

Companion to [`code-analysis.md`](./code-analysis.md). That document says what is wrong;
this one says what to build instead, in what order, and what "done" means for each step.

---

## Contents

1. [Goal and constraints](#1-goal-and-constraints)
2. [The core bet](#2-the-core-bet)
3. [Target architecture](#3-target-architecture)
4. [The package format](#4-the-package-format)
5. [The CLI surface](#5-the-cli-surface)
6. [Phased delivery](#6-phased-delivery)
7. [What gets deleted](#7-what-gets-deleted)
8. [Testing strategy](#8-testing-strategy)
9. [Risk register](#9-risk-register)
10. [Open decisions](#10-open-decisions-for-review)

---

## 1. Goal and constraints

**Goal.** A WordPress plugin that exports a site to a documented, portable package and
imports that package into another WordPress install — drivable end to end by an automated
hosting migration tool over WP-CLI, with no dependency on any particular host's backend.

**Fixed constraints** (given):

| Constraint | Consequence |
|---|---|
| Plugin will be renamed | Full de-brand pass; option/table names change, so no upgrade path from 1.0.x |
| Not published to wp.org for now | No SVN deploy, no readme.txt discipline, no "tested up to" treadmill. Distribution is a zip or a git/Composer install |
| Primary consumer is a machine | Structured output, exit codes, no prompts, stable contracts. Human UI is secondary |
| Bluehost CWM is out | The plugin owns both halves — export *and* import. No remote service performs the restore |

**Non-goals for this rework:** a hosted transfer service, incremental/delta sync,
scheduled backups, multi-site-network-to-network migration (see [§10](#10-open-decisions-for-review)).

---

## 2. The core bet

Everything difficult in the current codebase exists to survive a web request timing out:
the 10-second slice budget, byte-offset resume, the cron-driven task queue, and offset state
marshalled through `wp_options`. That machinery is also where nearly every critical defect
lives — findings 2.1, 2.2, 2.3, 2.7, 3.3 and 3.4 are all consequences of it.

PHP's CLI SAPI defaults to `max_execution_time = 0`. Moving the primary interface to WP-CLI
does not *fix* that machinery; it **removes the reason it exists**. The plan is therefore
biased hard toward deletion:

| Current subsystem | Reason it exists | Disposition |
|---|---|---|
| `MigrationManager/MigrationTasks` + `wp-module-tasks` | Run packaging from wp-cron | **Delete.** Removes the queue defects (2.7) and the Satis dependency |
| `wp-config.php` rewrite to force `DISABLE_WP_CRON=false` | Make the queue run | **Delete.** Removes 2.1, the site-breaking defect |
| `self::execute()` recursion in packagers | Continue past the 10s bail | **Delete.** Removes 2.3 |
| Offset state in the autoloaded option blob | Survive between web requests | **Replace** with a checkpoint file in the package dir. Removes 3.3 |
| `Status::set_status()` per archived file | Give a polling UI something to read | **Replace** with a `ProgressReporter` interface. Removes 3.4 |
| Custom binary container (`Archiver/`) | Mid-file resume | **Replace** with standard zip. Removes 2.2, 3.1, 3.11 |

The single most important consequence: **resumability drops from mid-byte to per-part.** A
CLI run that dies restarts the part it was in, not the whole export. That is a large
simplification for a small, acceptable cost.

---

## 3. Target architecture

One transport-agnostic core, with thin adapters. Nothing in `Core/` may reference `WP_CLI`,
`WP_REST_Request`, or emit output directly.

```
includes/
  Core/
    Package/
      PackageWriter.php      creates parts, writes manifest, checksums
      PackageReader.php      opens + validates a package, streams parts out
      Manifest.php           schema, versioning, read/write
      PartSpec.php           value object: name, source root, filters, priority
    Export/
      Exporter.php           orchestrates: manifest -> db -> file parts
      DatabaseExporter.php   wraps Database/ (kept, see below)
      FileCollector.php      one implementation, driven by PartSpec (replaces 6 archivers)
    Import/
      Importer.php           orchestrates: validate -> db -> files -> fixups
      DatabaseImporter.php   NEW. streams SQL, honours the existing is_*_query() predicates
      FileRestorer.php       NEW. unpack with path traversal guards
      SearchReplace.php      NEW. wraps the existing replace_serialized_values()
      Fixups.php             NEW. prefix reconciliation, siteurl/home, rewrite flush
    Preflight/
      Checker.php            local-only checks (was MigrationChecks/)
      Report.php             structured result, no filter-chain boolean smuggling
    Progress/
      ProgressReporter.php   interface: start/advance/finish/warn
      NullReporter, CliReporter, OptionReporter
  Cli/
    Commands.php             registers `wp <ns> ...`, thin argument marshalling only
  Rest/                      existing controllers, re-pointed at Core (see §6 phase 5)
  Admin/                     existing WP_Admin, optional
  Database/                  KEPT largely as-is (see below)
  Manifest/                  KEPT, folded under Core/Preflight as the site-facts source
```

**What is worth keeping, and why**

- **`Database/DatabaseBase.php` (1533 lines).** The most valuable asset in the repo. It is a
  fork of All-in-One WP Migration's dumper and correctly handles views, collation downgrades,
  table prefixes, `max_allowed_packet`, and base64-encoded page-builder payloads (Visual
  Composer, Oxygen, Avada, BeTheme, OptimizePress). `mysqldump` and `wp db export` do **not**
  do the builder-payload rewriting. Keep it; fix the by-reference plumbing around it.
- **A third of that file is already import-side scaffolding.** `is_drop_table_query()`,
  `is_create_table_query()`, `is_insert_into_query()`, `is_start_transaction_query()`,
  `is_commit_query()`, `is_transient_query()`, `is_cache_query()` and `repair_table()` are all
  transitively dead — reachable only from `is_atomic_query()`, which nothing calls. They are
  the query classifiers an importer needs. The import half was stripped out of the fork; this
  plan puts it back, and those predicates tell us its required shape.
- **`Manifest/` (509 lines).** Clean, useful, already collects the site facts a preflight
  report needs.
- **`Utils/DatabaseUtility::replace_serialized_values()` (147 lines).** Serialized-safe
  search/replace, currently called from nowhere but its own recursion. Exactly what the
  importer needs.

---

## 4. The package format

The format is the product. If a hosting tool can consume it without this plugin, the plugin
has real value beyond convenience; if it cannot, we have rebuilt a proprietary blob.

**Shape:** a directory (optionally tarred/zipped whole for transport).

```
site-package/
  manifest.json              schema_version, source facts, part index, checksums
  database.sql               (or database.NNN.sql when split)
  parts/
    plugins.zip
    themes.zip
    uploads.001.zip
    uploads.002.zip
    mu-plugins.zip
    dropins.zip
    root.zip
  checkpoint.json            transient; present only mid-run, deleted on success
```

**Rules:**

- Each part is a **standard zip** (`ZipArchive`, ZIP64 enabled), independently openable with
  `unzip`. No custom container.
- Parts are split at a size cap (`--split`, default 2GB) so no single file is unwieldy and a
  failed run resumes at part granularity.
- `manifest.json` carries an explicit `schema_version`. It is a **published contract** — see
  the deliverable in phase 2.
- `checksums` (SHA-256 per part) live in the manifest; `PackageReader` verifies before import.
- **Nothing secret ships in the package.** `wp-config.php` is excluded by an allowlist, not a
  blocklist (finding 2.4 was a blocklist that silently matched nothing). Credentials and salts
  are reconstructed on the destination, never carried.
- The package is a **faithful snapshot**. No URL rewriting happens at export time — the
  destination URL is frequently unknown when packaging, and one package should be restorable
  to many destinations. Search-replace is an *import*-time step.

---

## 5. The CLI surface

Namespace placeholder `<ns>` pending the naming decision in [§10](#10-open-decisions-for-review).

```
wp <ns> preflight  [--format=json]
wp <ns> export     --to=<dir> [--exclude=<parts>] [--split=<size>] [--resume] [--format=json]
wp <ns> inspect    <package> [--format=json]
wp <ns> import     <package> [--url=<new-url>] [--dry-run] [--resume] [--yes] [--format=json]
wp <ns> verify     <package> [--format=json]
```

**Machine-consumer contract** — this is the part that matters more than the verbs:

- **Data on stdout, progress and logs on stderr.** `wp <ns> preflight --format=json | jq` works.
- **`--format=json` output is versioned** and covered by tests. A consumer parsing
  human-readable output will break on our next release.
- **Exit codes are meaningful and documented.** At minimum: `0` success; `1` unexpected
  failure; `2` incompatible (preflight said no); `3` interrupted but resumable; `4` package
  invalid or checksum mismatch.
- **Never prompt.** Every destructive path takes `--yes`; without a TTY it must fail rather
  than block.
- **`--resume`** reads `checkpoint.json` and skips completed parts.
- **`--dry-run` on import** validates the package, checks disk space and DB privileges, and
  reports what it *would* do, touching nothing.

Registered behind `defined( 'WP_CLI' ) && WP_CLI`, so the plugin stays loadable on hosts
without WP-CLI.

---

## 6. Phased delivery

Sizes are relative (S/M/L/XL), not calendar estimates. Phases 0–4 are the spine; 5–7 depend on
decisions still open.

### Phase 0 — Rename, de-brand, repo hygiene · **M**

Mechanical but touches everything, so it goes first — doing it later means re-doing every
reference written in between.

- Namespace, constants, function prefix, text domain, option names, main plugin file, REST
  namespace, CSS/JS class prefixes. Target names pending [§10](#10-open-decisions-for-review).
- Delete the wp.org machinery: `.wporg/`, `readme.txt`, `svn-deploy-*.yml`.
- Collapse the four-place version scheme (analysis §"Version bumping") to **one** source of
  truth — read the version from the plugin header at build time so `build/` can never diverge
  from `BH_SITE_MIGRATOR_VERSION` again.
- Update `composer.json`: name, description, autoload prefix, drop `newfold-labs/wp-module-tasks`
  and with it the Satis `repositories` block.

**Exit:** plugin activates cleanly under the new name; `composer lint` passes; no string
matching `bluehost|bh_sm|bhsm|BH_SITE_MIGRATOR` remains outside `docs/`.

### Phase 1 — Stop the bleeding · **S**

Pure deletion. Independently valuable: after this the plugin can no longer break a site.

- Delete `MigrationManager/MigrationTasks.php` entirely — including the `wp-config.php`
  rewrite (**2.1**).
- Delete the CWM API call from `Checker`, the `migrationId`/auth-token/region options, and
  `send_files` / `report-errors` from `MigrationTasksController` (**2.4, 2.5**).
- Remove the `wp-module-tasks` dependency (**2.7**).
- Remove the `admin_init` redirect hijack (**3.10**) and `set_time_limit()` buried in a getter
  (**3.12**).

**Exit:** no code path writes to `wp-config.php`; no outbound HTTP to any host backend;
`grep -r "eigproserve\|can-we-migrate\|manifestScan"` is empty.

### Phase 2 — Core extraction and package format · **L**

- Define `manifest.json` schema v1 and **write the format spec** to `docs/package-format.md`.
  This is a deliverable, not a side effect.
- Build `Package/` (writer, reader, manifest, checksums) against `ZipArchive`.
- Collapse the six file archivers into one `FileCollector` driven by `PartSpec` (**4.1**).
  ~2000 lines become ~400.
- Fix the exclusion logic properly: compare normalised absolute paths, and use an **allowlist**
  for the root part (**2.4**).
- Introduce `ProgressReporter` and delete `Utils\Status` (**3.4, 3.5**).
- Move offsets/checkpoint out of the autoloaded option into `checkpoint.json` (**3.3**).

**Exit:** `Core/Export` can produce a valid package when called from a plain PHP script with
no WP-CLI and no REST involved; parts open in `unzip`; `wp-config.php` is provably absent.

### Phase 3 — Export over WP-CLI · **M**

- `Cli/Commands.php`: `preflight`, `export`, `inspect`, `verify`.
- `CliReporter` with `WP_CLI\Utils\make_progress_bar()`; JSON formatter for `--format=json`.
- Exit-code mapping; `--resume` against the checkpoint.
- Rework `Preflight/Checker` to return a structured `Report` instead of smuggling a boolean
  through a filter chain, and make an unreachable/failed check **fail closed** (**2.6, 4.6**).

**Exit:** `wp <ns> export --to=/tmp/pkg` completes on a real site; `--format=json` output
validates against the documented schema; killing the process mid-run and re-running with
`--resume` completes correctly.

### Phase 4 — Import over WP-CLI · **XL** — *the genuinely new work*

None of this exists today. This is the largest and riskiest phase.

- `DatabaseImporter`: stream `database.sql`, chunk on statement boundaries respecting
  `max_allowed_packet`, wire up the dead `is_*_query()` predicates for atomic-table and
  transaction handling.
- `FileRestorer`: unpack with **path-traversal guards** (reject `..`, absolute paths, symlinks
  escaping the root) — this is untrusted archive input and the primary security surface of the
  import half.
- `SearchReplace`: old URL → new URL via `replace_serialized_values()`, plus table-prefix
  reconciliation when source and destination prefixes differ.
- `Fixups`: `siteurl`/`home`, `.htaccess`/permalinks, active-plugin sanity, object-cache and
  other dropins, `wp_options.autoload` hygiene.
- Handle the **self-modification problem**: importing a database from a PHP process running on
  that same database is hazardous. Likely `--skip-plugins --skip-themes` for the import
  command plus a late-stage handoff; needs a spike before committing to a shape.

**Exit:** round-trip test green (see [§8](#8-testing-strategy)) — export site A, import into
clean site B at a different URL and table prefix, and B is functionally equivalent to A.

### Phase 5 — Adapters: REST and UI · **M** *(scope depends on [§10](#10-open-decisions-for-review))*

- Re-point the existing REST controllers at `Core`, so hosts without WP-CLI still work.
- Long-running work over REST still needs a job runner. Options: keep a much smaller
  in-plugin runner, or declare REST **export-only for small sites** and CLI-required beyond a
  size threshold. Recommend the latter — reintroducing a queue undoes phase 1.
- Fix the SPA's missing `return` (**2.6**) if the UI survives at all.

### Phase 6 — Tests and CI · **M**

See [§8](#8-testing-strategy). Should run *alongside* phases 2–4, not after; listed separately
because it needs its own infrastructure work.

### Phase 7 — Hardening and distribution · **S/M**

- Raise the PHP floor to 7.4+ (**4.8**) — modern syntax, typed properties, real exceptions.
  The 5.6 floor is currently inconsistent with phpcs `testVersion 7.0-` and with `esc_xml()`
  requiring WP 5.5+ (**3.8**).
- Replace `uniqid()` filenames with `random_bytes()`; add `index.php`/`.htaccess` denial in the
  storage dir.
- Build/release: produce an installable zip artifact; document install via Composer or
  `wp plugin install <url>` for the hosting-tool consumer.

---

## 7. What gets deleted

Rough accounting, current PHP is ~7000 lines across `includes/` + root:

| Removed | Lines | Replaced by |
|---|---|---|
| `Archiver/` custom container | ~563 | `ZipArchive` in `Package/` |
| Five of six file archivers | ~1700 | `FileCollector` + `PartSpec` (~400) |
| `MigrationManager/` | ~104 | nothing |
| CWM client + region/token handling | ~150 | nothing |
| `Utils/Status` | ~56 | `ProgressReporter` |
| `wp-module-tasks` dependency | ~880 (vendor) | nothing |

Net: roughly **2500 lines deleted** before the importer is written. The importer and package
layer add back perhaps 1200–1500. The result should be meaningfully smaller than today while
doing strictly more.

---

## 8. Testing strategy

Today there are **zero PHP tests**, and the Cypress suite stubs the entire REST layer with
`cy.intercept` — it verifies that React renders against fixtures, and cannot catch a single
defect in the analysis. That must change before, not after, the importer is written.

1. **Unit (PHPUnit).** Manifest schema round-trip, `PartSpec` filtering and exclusion rules,
   `replace_serialized_values()` against nested/serialized/base64 fixtures, path-traversal
   rejection in `FileRestorer`, exit-code mapping.
2. **Round-trip integration — the test that matters.** In CI with two wp-env instances:
   provision site A with known content (posts, a serialized-option-heavy plugin, uploads,
   a custom table prefix) → `wp <ns> export` → `wp <ns> import` into clean site B at a
   *different URL and prefix* → assert equivalence: row counts per table, option values after
   unserialization, uploads checksums, rendered permalink output.
3. **Fault injection.** `kill -9` mid-export, re-run with `--resume`, assert the package is
   valid. Corrupt a part, assert `verify` catches it and `import` refuses.
4. **Contract tests.** Snapshot `--format=json` output; a schema change must fail CI loudly,
   since a machine consumer depends on it.
5. **Cypress** stays only if the UI stays, and should drive at least one *unstubbed* path.

CI: replace the wp.org/SVN workflows with lint + PHPUnit + the round-trip job. The round-trip
job is slow; it can run on PR to `main` rather than every push.

---

## 9. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| **Import is genuinely hard and entirely new** | Phase 4 slips | Spike the self-modification problem before committing to a shape. Treat the round-trip test as the definition of done, not a formality |
| **`DatabaseBase` is a stale fork of AIO WP Migration** | Inherited bugs we don't know about; upstream fixes don't reach us | Diff against current upstream once before building on it; record the fork point in `docs/` |
| **Losing mid-file resume hurts very large sites** | A 40GB uploads dir restarts a part on failure | Size the `--split` default so a part is a tolerable unit of loss; revisit only if it bites |
| **No upgrade path from 1.0.x** | Existing installs orphaned | Accepted — the plugin is renamed and unpublished; state it explicitly rather than half-supporting it |
| **Path traversal on import** | Arbitrary file write from a malicious package | Treat packages as untrusted input from day one; dedicated unit tests; never `extractTo()` blindly |
| **Destination needs WP + the plugin already installed** | Chicken-and-egg for bare targets | Explicitly in scope for the hosting-tool consumer (they scaffold WP first). Document the precondition; do **not** attempt a Duplicator-style standalone `installer.php` |
| **PHP floor raise breaks a host** | Install failures | 7.4 is already below every supported PHP; low risk, but gate with a header requirement and an activation check |

---

## 10. Open decisions for review

These change the plan's shape and are yours to call.

**D1 — Naming.** Recommend `NewfoldLabs\WP\SiteMigrator\` namespace, `nfd_sm_` function prefix,
`NFD_SM_*` constants, text domain and slug `nfd-site-migrator`, option key `nfd_site_migrator`.
Matches the convention already in `vendor/newfold-labs/`. *Alternative:* fully vendor-neutral
(`SiteMigrator\`, `sm_`, `site-migrator`) if this should not read as a Newfold product.
**Phase 0 is blocked on this.**

**D2 — Does the React UI survive?** Recommend **keep, minimally**: re-point it at `Core` and
fix 2.6, but stop treating it as the primary interface. *Alternative:* delete `src/` entirely
(931 lines, plus the Tailwind build and Cypress suite) and ship CLI-only. Cheaper and more
focused, but leaves non-CLI hosts with nothing. **Affects phase 5 substantially.**

**D3 — Is REST export expected to work for large sites?** If yes, we need a job runner again
and phase 1's deletions partly come back. Recommend: REST handles small sites synchronously,
and returns a clear "use CLI" error above a size threshold.

**D4 — Multisite.** Currently hard-blocked by `is_not_multisite()`. For a hosting migration
tool this is plausibly a requirement, and it is a significant scope increase (network tables,
per-site uploads, domain mapping). Recommend explicitly **out of scope for v1**, with the
preflight check reporting it clearly rather than failing opaquely.

**D5 — Package transport.** This plan produces a package on local disk and stops there. Does
the hosting tool need the plugin to *push* it somewhere (S3, SFTP, an HTTP endpoint), or will
the caller move the bytes? Recommend the latter for v1 — a pluggable `Destination` interface
can come later without reshaping anything.

**D6 — Fork or fresh start?** This plan assumes evolving the existing code, justified almost
entirely by `Database/` and `Manifest/`. If D2 deletes the UI and phase 1+2 delete most of the
rest, we are keeping ~2000 of 7000 lines. A greenfield repo that vendors just those two
directories is a legitimate alternative and would shed the CI, build, and version-scheme
baggage in one move.

---

## Appendix — findings coverage

Every finding in `code-analysis.md`, mapped to the phase that resolves it.

| Finding | Phase | How |
|---|---|---|
| 2.1 `wp-config.php` truncation | 1 | Code deleted outright |
| 2.2 dropped by-reference params | 2 | Custom container replaced by `ZipArchive` |
| 2.3 `self::execute()` recursion | 1 | No slice budget under CLI |
| 2.4 `wp-config.php` archived and published | 1, 2 | No public URL; allowlist exclusion |
| 2.5 `sslverify => is_ssl()` | 1 | No outbound backend calls remain |
| 2.6 first-load failure blanks the screen | 3, 5 | Fail-closed preflight; SPA `return` fix |
| 2.7 task queue cannot recover | 1 | Dependency removed |
| 3.1 `append_eof()` inverted | 2 | `Archiver/` deleted |
| 3.2 handle leaks | 2 | `PackageWriter` owns handle lifecycle |
| 3.3 autoloaded state blob | 2 | Checkpoint file |
| 3.4 `set_status()` per file | 2 | `ProgressReporter` |
| 3.5 progress is theatre | 2, 3 | Real byte-based progress |
| 3.6 `vsprintf` placeholder mismatch | 2 | Option exclusion list rebuilt |
| 3.7 division by zero | 2 | Rewritten with guards |
| 3.8 `esc_xml()` / version floors | 0, 7 | Floors made consistent |
| 3.9 text-domain typo | 0 | De-brand pass |
| 3.10 redirect without `exit` | 1 | Deleted |
| 3.11 dead encryption params | 2 | `Archiver/` deleted |
| 3.12 `set_time_limit()` in a getter | 1 | Deleted |
| 4.1–4.8 refactors | 2, 3, 7 | As described above |
