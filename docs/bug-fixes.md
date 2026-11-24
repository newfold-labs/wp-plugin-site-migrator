# Bug fixes from external testing (v0.2.6)

An external tester drove two real installs through pairing, a code-only export, a whole-site
export, direct transfer, import, rollback, keep, uninstall and reinstall. Each item below was
checked against the code before it was listed; the cause is stated where it was found, and
**needs repro** marks the two where it was not.

IDs are `T1`… so they can be cited in commits the way `docs/code-analysis.md` findings are.

**Status: all twenty are fixed on `fix/tester-feedback`.** T1, T2, T4, T7, T10 and T11 have unit
tests in `tests/Unit/TesterFeedbackTest.php`. T3, T5, T6, T8, T9 and T12–T20 are screen behaviour
or depend on a real browser session, so they want a second pass by the tester. T5's session
restore and T6's login prompt in particular have not been watched in a browser.

## Breaks a second migration or loses data

| ID | Problem | Cause | Fix |
|---|---|---|---|
| T1 | A re-pull of the *same* package reports "185.7 MB of a different package was already staged here and has been removed". | `Puller::reconcile()` asks `Source::matches()`, which compares against the stored source record. That record is gone after *Stop and forget the source*, and is replaced when an import swaps `wp_options` — so an identical staged package is judged foreign and deleted. | Also compare against the manifest already staged in `incoming/` (`site_url` + `created_at`), so what is on disk decides. |
| T2 | After uninstall and reinstall, the first pull warns "1 KB of a different package was already staged here". | Not leftover files: `Upload::dir()` writes `index.php`, `.htaccess` and `web.config` into `incoming/` whenever it is asked for, and `Puller::staged_bytes()` counts them. | Leave the protection files out of `staged_bytes()`. |
| T3 | With an old pull on record, *Fetch from the source* reopens it instead of offering a new key form; the way out was deleting the staged package and *Stop and forget the source*. | `Source::complete()` drops the key but keeps the record, so a finished pull still reads as `connected`. | Report a finished source as not connected (keep it as `last`, for the screen to mention), so the key form and any waiting offer show. |
| T4 | `plugins/.DS_Store` is listed among the plugins a rollback removed. | `PartSpecs::$excluded_names` only applies to directories, so OS metadata files are packaged; `AddedCode` then counts any entry in `plugins/` as a plugin. | Leave out `.DS_Store`, `._*`, `Thumbs.db`, `desktop.ini` at export. `AddedCode` ignores them too. |
| T5 | Rollback signs the administrator out ("Cookie check failed"). | `Fixups` re-issues the auth cookie for the merged user during the import; rollback swaps the users table back and re-issues nothing. | Record the acting user's id and login in the checkpoint before the swap; after rollback, re-issue the cookie for that user. |
| T6 | Once the session has expired, buttons do nothing — no request, no console error. **Needs repro** for the "no request" part. | WordPress's session-expiry dialog (`wp-auth-check`) is not loaded on the plugin's page, and a failed call has nothing to hand the user to. | Enqueue `wp-auth-check`; when a call fails with 401/403 or `rest_cookie_invalid_nonce`, open the login dialog. T5 removes the common way into this state. |

## Leftover or stale state

| ID | Problem | Cause | Fix |
|---|---|---|---|
| T7 | The source's Deliver screen still shows "Being fetched / Claimed by … 3 h ago" from an earlier manual-key session beside a newer pairing and offer. "Last read" jumps between 3 h and 37 min. | A claimed transfer key outlives a new offer and a new pairing. The jumping time is two timestamps feeding one label (**needs repro** to confirm). | Revoke the manual key when a new offer is made or a new destination paired. |
| T8 | No way to start over on the source: it jumps straight to Deliver with an old package, and uninstalling was the only reset. | `export/cancel` exists in the REST API and `api.js`; no screen calls it. | A *Start over* action on Deliver that discards the package (and its key and offer). |
| T9 | *Build the package* after re-pairing goes straight to Deliver and reuses the existing package without saying so. | Intended — an unchanged selection reuses the finished package — but silent. | Deliver says which package it is offering and when it was built, with *Start over* (T8) as the way to rebuild. |

## Wrong or misleading information

| ID | Problem | Cause | Fix |
|---|---|---|---|
| T10 | With the database off, WooCommerce tables (`wp_wc_order_*`) are offered under Jetpack Boost, and `wp_woocommerce_api_keys` under OptinMonster. | `TableOwners` treats *naming* a table as *owning* it, and scans each plugin separately: Boost's WooCommerce compatibility code names those tables, while WooCommerce names them in classes the shallow scan does not read. | A table or setting whose name starts with another installed plugin's recognised prefix belongs to that plugin; one claimed by several plugins is shown as shared. |
| T11 | Ticking one plugin's belongings also ticks another plugin that uses the same setting (`mm_brand`), with no explanation. | The selection is keyed by name, so a shared option is one checkbox in two places. | Mark shared tables and settings in the picker ("also used by …"). |
| T12 | Export progress shows "Database: Done" on a code-only export. | `Exporting.js` marks the database done as soon as files start. | Show "Skipped" when the run's selection skips the database. |
| T13 | While paused, the estimate doubles ("about 26 minutes left"); after a reload the progress jumps from 10 MB to 35 MB. | The rate is measured from when the run started, so paused time counts as slow work. The jump is the step Pause abandoned finishing in the background, which is by design. | No estimate while paused, and the clock restarts on resume. The paused note says the current volume may still finish. |

## Copy

| ID | Problem | Fix |
|---|---|---|
| T14 | After *Keep*, the Done screen still says "Have a look around before you decide to keep it." | Intro and safety strip follow the settled state: kept, rolled back, or undecided. |
| T15 | Rollback details repeat import-time notes ("You are still signed in, now as admin", "…can be rolled back until you keep it"). | After a rollback, show the rollback's notes only. |
| T16 | The destination's Connect step reads "Exporting only reads. Nothing here is modified." | Give `Receive.js` a destination `safetyDetail`, as the other five destination screens have. |
| T17 | Code-only Review says "the database that arrives will still refer to files the package did not carry" when no database arrives. | Word the contents note by whether the package carries a database. |
| T18 | The manual transfer key stays on screen marked "Shown once" after the destination has claimed it. | Once claimed, show the claim, not the key. |

## Smaller additions

| ID | Problem | Fix |
|---|---|---|
| T19 | After pairing, the destination's Connect screen does not change until the source offers a package. | Once a source has linked, say so: paired with X, waiting for it to offer a package. |
| T20 | The pairing code screen has no "attempts remaining" counter. | Show the attempts left on the code, from the count `Pairing` already keeps. |

## Not plugin fixes

- **Workflow doc** — button labels, where the transfer key lives on an unpaired source, Review's
  acknowledgement checkbox and *Worth knowing* section, and when *Fix compatible PHP syntax*
  appears. Update the doc to match 0.2.6.
- **Untested paths** — the PHP syntax fix needs a fixture with code the destination cannot run;
  the account merge needs a user present on both sites. Both belong in the next round of testing.
