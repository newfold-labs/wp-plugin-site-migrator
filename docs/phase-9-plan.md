# Phase 9 — choosable package contents, and one code instead of two

## Context

Two gaps in the shipped flow.

**1. The export packages everything or nothing.** `PartSpecs::all()` builds a fixed list of parts and
`DatabaseExporter::tables()` takes every table with the site's prefix. The only way to leave anything out
is a filter in code (`nfd_sm_part_specs`, `nfd_sm_excluded_names`). A user with a 40GB uploads directory,
a staging-only plugin, or a 3GB `actionscheduler_logs` table has no way to say so — they wait for the whole
thing, then carry it. The exclusions that do exist are ones nobody would ever want carried (`.git`,
`node_modules`, the package itself); this is about the ones a person has to decide.

**2. The migration costs two copy-pastes in opposite directions.** The destination mints a *pairing code*
that the user carries to the source; then the source mints a *transfer key* that the user carries back to
the destination. The second trip is the one that feels redundant — the two sites have already spoken.

They cannot literally be one code, and the plan does not try to make them one: the pairing code
authenticates the **source reading the destination**, is stored on the destination only as a
`wp_hash_password()` hash, is deliberately *not* kept by the source (`Destination`'s docblock), and dies in
fifteen minutes. The transfer authenticates the opposite direction, hours later, and needs a credential
built for hundreds of requests. What *can* go is the second copy-paste: pairing leaves a durable link
between the two sites, and the destination uses it to collect the offer by itself.

Outcome: a *Contents* screen before packaging, and a destination that shows **"source.example is offering a
package — Start the transfer"** instead of two empty fields. The manual key stays as the fallback it always
was, and nothing about who authorises what changes: the source's user still presses Offer, the
destination's user still presses Start.

Decisions taken with the user: parts + individual items + database filters; **no measured sizes** (names
and checkboxes only, no directory survey); destination asks and the user clicks Start.

---

## Part 1 — Choosable contents

### Selection model

New `includes/Core/Export/Selection.php`. Stored under the `selection` key of the single
`nfd_site_migrator` option via `Utils\Options` (small, only written between runs).

```php
array(
  'parts'    => array( 'uploads' => false, … ),          // absent = included
  'paths'    => array( 'plugins' => array( 'akismet' ) ), // keyed by part, relative to its root
  'database' => array(
      'skip_tables'     => array( 'wp_actionscheduler_logs' ),
      'skip_revisions'  => true,
      'skip_spam'       => true,
      'skip_transients' => true,
  ),
)
```

Paths are keyed by **part**, not expressed relative to the WordPress root. Plugins, themes and uploads
can all be moved outside `wp-content`, and `PartSpecs` already derives each part's prefix from where its
directory actually is; one root-relative string cannot name "this plugin" on a site whose plugin
directory lives somewhere else. A part name plus a path below that part's root can, and it lands
directly on `PartSpec::exclude_dirs()`.

`sanitize()` is the security boundary and does the refusing: a path may contain no `..`, no leading
slash and no drive letter, and **core tables can never be skipped** — the list of tables the import
needs is enforced server-side, not just hidden in the UI.

### Honouring it in the export

- `PartSpecs::all( Selection $selection = null )` — build the full list exactly as today, **then** filter.
  - **Trap to get right:** `content-other` excludes the other parts' directories via `$covered`. If
    `uploads` is deselected and `$covered` is built from the surviving parts only, `content-other` sweeps
    uploads straight back in — a "skip uploads" that packages uploads. `$covered` must be built from every
    part that *would* exist, and deselected roots must additionally be excluded from `content-other`.
  - Excluded paths map onto the existing `PartSpec::exclude_dirs()` / `exclude_files()` (relative to the
    part root) — no new collection machinery.
- `Exporter` must not resolve specs in the constructor any more. A resumed run's `part_index` indexes into
  the spec array, so a selection changed mid-run would shift the parts underneath the checkpoint. So:
  **the selection is written into the checkpoint on a fresh start** (next to the `is_fresh()` /
  `PackageWriter::reset()` logic) and a resume rebuilds its specs from the checkpoint's copy. `Checkpoint::SCHEMA`
  3 → 4, which correctly discards older checkpoints.
- `DatabaseExporter`: `tables()` drops skipped tables; the filters go through the existing
  `DatabaseBase::set_table_where_query()` (already used for the options table) —
  `posts` → `post_type != 'revision'`, `comments` → `comment_approved NOT IN ('spam','trash')`, and the
  transient clause appended to the existing `options_exclusion()`, keeping its `esc_sql()` discipline.

### Recording it

`Manifest` gains a `contents` block — parts included and excluded, excluded paths, database filters,
excluded tables — additive, absent meaning "everything" so older packages still read. This is what lets the
destination say what is missing rather than the user discovering it later. `Importer::manual_steps()`
gains an entry built from that block ("This package deliberately left out: uploads (media), 3 plugins, post
revisions"), which the Review screen and the Done screen already render.

### Surfaces

- REST (`Rest/ExportController`): `GET /export/contents` returns the choosable items — parts, the immediate
  children of plugins / themes / uploads, the non-core tables, and the current selection — and
  `POST /export/contents` saves it. Both `manage_options`. The listing is one `readdir` per part root plus
  `SHOW TABLES`; no walk, no sizes. Saving is **refused while an export is running** (`Exporter::is_running()`
  / an unfinished checkpoint), because the checkpoint owns the selection for the duration of a run.
- UI: new screen `src/components/screens/Contents.js` at `/contents`, sharing `step="export"` in the stepper
  — the same idiom as `/send` and `/download` sharing `step="deliver"`. Reached from Compatibility and from
  the pre-run state of `/export` ("Including everything · Choose what to include"). Checkboxes with a plain
  sentence each, expandable per-item lists, the database toggles, a "Leaving out: …" summary, and a
  `.nfd-sm-note--warn` for the choices that hurt (no uploads → broken media; no plugins/themes → a site that
  loads but does nothing).
- CLI: `wp site-migrator contents [--show] [--set=<file.json>]`, `export` honours the stored selection and
  `export --all` ignores it for one run. New `Cli\Output::SCHEMA` entry.

---

## Part 2 — No second code

### Mechanism

Pairing already proves both ends: the destination minted the code, and the user carried it to the source.
The **source** mints a long *link token* (48 hex, `TransferKey`-shaped) and sends it as a header on the
pairing request it already makes; the destination stores it **only after `Pairing::redeem()` succeeds**, so
no stranger can plant one. Later, the destination presents that token back to the source and asks whether a
package is being offered. Pull, not push — the same direction the transfer itself runs, so a link that
works proves the transfer can work.

New, mirroring the existing `TransferKey` (source) / `Source` (destination) pair:

- `includes/Core/Transfer/Link.php` — **source side**: `issue()`, `verify( $token, $ip )`, `offer()`,
  `revoke()`, `status()`. Stores a SHA-256 hash only, IP-binds on first use, rate-limits, 404s on anything
  wrong — the same posture as `TransferKey`, whose docblock explains each of those choices.
- `includes/Core/Transfer/LinkedSource.php` — **destination side**: the source URL, the raw token, when.
- Two new constants in `constants.php`, both added to `NFD_SM_OPTIONS_LIST` — which is not bookkeeping:
  that list is what `DatabaseExporter::options_exclusion()` keeps **out of the dump**, and what
  `nfd_sm_purge_all()` deletes. A link secret that travels inside the package would be a real leak.

### Flow

1. `Pairing::fetch_profile()` sends `X-NFD-SM-Link: <token>`; on failure the token is revoked so nothing is
   left behind. `PairingController::profile()` stores it after a successful redeem and answers
   `linked: true`, so a source talking to an older destination degrades to the manual key by itself.
2. Source `/send`: the button becomes **"Offer it to destination.example"** — it marks an offer outstanding
   (`Link::offer()`); it does **not** mint or store a key. "Show a key to paste by hand" stays beside it.
3. New public source route `/transfer/handoff`, gated on the link token, 404 for everything else:
   `GET` answers *whether* a package is offered plus its summary (no credential); `POST` **mints the
   transfer key at that moment** and returns it, once per offer. This is what keeps the source's promise
   that only a hash is ever stored — there is no raw key sitting in an option waiting to be collected.
4. Destination `/import/pull`: when a `LinkedSource` exists, the screen polls `GET /import/pull/offer`
   (which asks the source's `GET /transfer/handoff`) and renders "source.example is offering 550 MB, created
   4 minutes ago — **Start the transfer**". The button calls `POST /import/pull/link`, which claims the key
   and hands it to the existing `Source::connect()` + `Puller`. **Nothing downstream changes.**
5. Manual url+key entry stays, collapsed behind "Enter a key by hand", and is the only path when nothing is
   linked. `/import` (Choose) shows a one-line hint when an offer is waiting.
6. CLI: `offer --link` marks the offer; `pull --linked` claims it with no url or key.
7. Small join with Part 1: the handoff summary carries the `contents` block, so the destination can warn
   about what is missing *before* it spends an hour fetching.

---

## Files

**New:** `includes/Core/Export/Selection.php`, `includes/Core/Transfer/Link.php`,
`includes/Core/Transfer/LinkedSource.php`, `src/components/screens/Contents.js`.

**Changed (as built):** `includes/Core/Export/{PartSpecs,Exporter,DatabaseExporter}.php`,
`includes/Core/Package/{Checkpoint,Manifest}.php`, `includes/Core/Import/Importer.php` (`manual_steps()`),
`includes/Core/Preflight/Pairing.php`, `includes/Rest/{ExportController,PairingController,TransferController,ImportController,PreflightController}.php`,
`includes/Cli/{Commands,Output}.php`, `constants.php`, `src/routes.js`, `src/utils/api.js`,
`src/components/screens/{Compatibility,Receive,Send}.js`, `src/components/screens/import/{Choose,Pull,Review}.js`,
`src/utils/usePull.js` (a `claim()` beside `connect()`), `assets/styles/app.css`, `tests/bootstrap.php`
(the content-directory constants and a theme-root stub), `tests/roundtrip.sh`,
`docs/implementation-plan.md`, `docs/package-format.md`, `CLAUDE.md`.

---

## Verification

- `composer test` — new unit cases: `sanitize()` refusing a `..` path and refusing to skip `wp_posts`;
  `PartSpecs::all()` with `uploads` deselected still excluding uploads from `content-other` (the trap
  above, asserted directly); the checkpoint carrying the selection across a resume; the manifest
  `contents` round trip; `Link::verify()` refusing a second IP, a wrong token, and a token with no offer
  outstanding.
- `composer lint` — and read `composer fix`'s diff rather than trusting it (string literals).
- `npx wp-scripts lint-js src`, then `npm run build`.
- `composer test:roundtrip` — extend it with an export driven by a selection that skips one plugin and post
  revisions, asserting the package does not contain that plugin's files, that `wp_posts` has no revision
  rows, that the import still completes, and that the manifest names what was left out. Fixture guards
  first, per the existing rule in that script.
- Playwright (`NFD_E2E_SERVER=builtin`): the Contents screen renders and saves; the pull screen renders its
  linked-offer state. Both assert a *child* of `#nfd-sm-app` — these are exactly the shapes that have
  produced blank admin pages here before.
- By hand between the two real installs: pair, choose contents, package, Offer on the source, Start on the
  destination with nothing pasted, import, roll back. Then repeat with the destination on an older build to
  confirm it falls back to the manual key.

## What was actually run

`composer test` (85 tests), `composer lint`, `npx wp-scripts lint-js`, `npm run build`, and
`composer test:roundtrip` against two real WordPress installs — 51 assertions, including the thirteen
new ones for a narrowed export. All of it re-run after the four defects below were fixed: 85 unit
tests and 51 round-trip assertions, none failing.

The round trip earned its keep immediately, and not on the plugin: the first version of the new
fixture revised *the most recent post*, which is the Gutenberg block fixture, and quietly destroyed
the escaped-slash attributes the suite exists to protect. The assertion that caught it was two
sections further down. The revision fixture now creates its own post.

### Both halves, between two real installs

Run by hand between a 5GB Local source (`wordpress-netsol-local`) and a second install on
`localhost:10095`, which is what the round trip cannot stand up: two sites answering HTTP.

**The partial migration.** Uploads deselected, four plugins deselected, all three database flags on.
The package came to 62.4MB against the 806MB the whole site would have been, and every claim it made
held: 11,178 entries with no `wp-content/uploads/` path anywhere and none of the four refused
plugins — `jetpack` gone while `jetpack-boost` stayed, so nothing is matching on prefixes — the dump
carrying 214 posts and zero revisions against a live table holding 57, and zero core transient rows
while the eleven `_wpforms_transient_*` and `jb_transient_*` rows stayed, which is right: those are
plugin-namespaced names WordPress will not rebuild. `Link` carried the handover with nothing pasted:
the source offered, and the destination's own screen said *"http://wordpress-netsol-local.local is
offering a package right now — 62.4 MB, waiting for this site to take it"*. The contents block
crossed with it, so the destination warned about the missing media **before** fetching rather than
after. After the import the destination held the source's 214 posts, zero revisions, its accented
title intact, and — the assertion the whole feature rests on — all 357 of its own upload files byte
for byte identical. Rollback put back the title, the 4 posts, the 1 user and both plugin
directories, and left the uploads alone again.

**One bug, found by doing it.** Changing the selection and pressing *Save and build the package*
saved the selection and then handed back the **previous** package without building anything.
`Exporting` starts a run only when the package is not already complete — correct in itself, or every
visit to the step would re-package a finished site — so a finished package short-circuited straight
to Deliver. The damage was not just a stale package: its manifest still carried the *old* `contents`
block, so a source whose user had just asked for everything would have offered a partial package and
the destination would have announced "uploads were left out on purpose" for a run nobody narrowed.
`ExportController::choose()` now takes the manifest off a finished package whose recorded selection
differs from the one being saved — the same `PackageWriter::invalidate()` that phase 4f added for
the same reason, that a manifest must never describe a package that is no longer what it says.
Only a real difference counts, so opening the screen and saving an unchanged selection still costs
nothing.

**The full migration** was then run on the fix, everything included: 200.2MB, 36,881 files, and
the same site arriving whole — 214 posts *and* the 57 revisions the narrowed run had dropped, all
four plugins it had refused, and the media byte for byte identical to the source's. Rollback put
the destination back to its own four posts, one user and two plugin directories with no backup
tables left. The database filters are genuinely opt-in, which the two runs together are what shows:
the same source, one dump with 0 revision rows and one with 57.

Its second half ran through the **CLI**, because the destination's browser session expired
mid-test. That turned out to be worth more than the browser would have been — `pull --linked`
claimed the offer with no address and no key, which is the whole of part 2 exercised on the surface
the round trip cannot reach, and the import then refused to run at all:

**A rolled-back run blocked the next package.** `Importer::is_complete()` asked only whether a
finished run named this directory, so a *settled* one still counted — and the refusal said "roll it
back first", which is exactly what had just been done. A linked pull always stages into the same
directory, so the package path matches every time; this is the state the *Try a different package*
button on the Put back screen leads straight into. The guard exists for the run that finished and
was neither undone nor kept, because its `nfdold_` tables are the only copy of the site as it was,
and that case still refuses. `ImportCheckpoint::is_settled()` already drew the line — the same one
`step()`, `Resume` and the Done screen were taught in phase 4h; `is_complete()` had been missed.
Covered by a regression test, checked red before green.

**And a new export left the last package's credential live.** The source's Deliver screen drew the
previous transfer's 62.4MB against the new package's 200.2MB total — a progress bar comparing two
different packages — because `TransferKey` and the outstanding offer both survived the rebuild.
Worse than the display: a destination still holding that key would have been fetching a different
site under a credential issued for another. `Exporter::step()` now revokes the key and withdraws
the offer in the same fresh-run branch that calls `PackageWriter::reset()`, which is where the old
package stops existing. `withdraw()` rather than `revoke()`, so the pairing survives and the user
does not carry a code again.

**Two smaller things from watching the screens.** The pull screen rendered the manual url-and-key
form expanded *underneath* an offer, so two identical "Start the transfer" buttons sat on one
screen; it is a `<details>` now, open only when it is the only way through. And the contents summary
read "Leaving out: … leave out post revisions", because it reused the checkbox labels — it uses the
same noun phrases `Selection::describe()` does, so the picker and the destination's review screen
now say it the same way.
