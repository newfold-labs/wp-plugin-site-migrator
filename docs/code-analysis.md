# Bluehost Site Migrator — Code Analysis

**Analysed:** 2026-08-18 · **Commit:** `87e9a6e` (`master`) · **Repo version:** 1.0.13

A review of the plugin's viability as a migration tool, a register of defects found in
the source, and a proposed refactor plan. Line references are against commit `87e9a6e`.

---

## Contents

1. [Verdict on usefulness](#1-verdict-on-usefulness)
2. [Critical defects](#2-critical-defects)
3. [Lower-severity findings](#3-lower-severity-findings)
4. [Refactors worth doing](#4-refactors-worth-doing)
5. [Recommendation](#5-recommendation)
6. [How these findings were verified](#6-how-these-findings-were-verified)

---

## 1. Verdict on usefulness

**As a general-purpose WordPress migration tool: no.** As a Bluehost customer-acquisition
funnel it was fit for purpose in 2023, but it is not viable today.

| Signal | Value |
|---|---|
| wp.org released version | **1.0.14**, last updated **2023-10-03** |
| Tested up to | **6.2.11** |
| Ratings | **26/100** — 25 of 29 reviews are 1-star |
| Downloads | 121,134 |
| Last functional code commit (`includes/`, `src/`) | **2023-09-08** (everything since is CI/dependabot) |
| Backend API cert (`cwm.eigproserve.com`) | **expired 2024-07-26** — server answers TCP, TLS fails |

Two structural points on top of that:

**It is one-directional and vendor-locked.** The plugin only packages a site and hands a
"transfer key" to Bluehost's CWM (Can We Migrate) service. There is no import side in this
codebase. If CWM is not answering, the archives it produces are inert. Compare with
All-in-One WP Migration (which this is a clear derivative of — the `a255/a14/a12/a4096`
block header and the `wbk_*` Webba Booking table special-case at `DatabaseDumper.php:70`
are lifted from it) or Duplicator, both of which produce a self-contained restorable package.

**`master` is not what shipped.** Tag `1.0.14` lives on `origin/release/1.0.14`, a divergent
older lineage (it still contains `tests/cypress/integration/` and a different webpack config).
`master` is the 1.0.13 "rewrite_plugin" branch. The rewrite in this repo was effectively
never released.

### Why the reviews are 1-star

A specific code path converts a backend outage into a silent, hours-long failure.
`MigrationChecks/Checker.php`:

```php
if ( is_wp_error( $response ) ) {
    self::$results['cwm_api'] = $response->get_error_message();
    return $can_migrate;   // ← returns TRUE, unchanged
}
```

With the expired certificate, `wp_remote_post` fails. The user is nonetheless told
"Look's like we're compatible!", packaging runs for potentially hours, and then
`send_files` POSTs to `/migration//files` with an empty migration ID and fails — landing on
"It looks like your site didn't transfer. Call us at 888-401-4678."

**A dead backend is indistinguishable from a successful compatibility check until after the
entire site has been packaged.**

---

## 2. Critical defects

Ordered by damage. The first three are showstoppers independent of the backend.

### 2.1 Clicking "Start Transfer" can take the site down

**`includes/MigrationManager/MigrationTasks.php:25-32`**

```php
$config_file_path = ABSPATH . 'wp-config.php';
$config_content   = $wp_filesystem->get_contents( $config_file_path );
$config_content   = preg_replace( '/define\(\s*\'DISABLE_WP_CRON\'\s*,\s*true\s*\);/', ..., $config_content );
$wp_filesystem->put_contents( $config_file_path, $config_content );
```

On the very common layout where `wp-config.php` sits one directory *above* `ABSPATH`,
`get_contents()` returns `false`, `preg_replace` yields `''`, and this **writes an empty
`wp-config.php` into the WordPress root**. `wp-load.php` checks `ABSPATH . 'wp-config.php'`
first, finds the empty file, and the site drops to the install screen. Fatal, and hard for a
non-technical user to diagnose.

`Utils\Common::locate_wp_config_file()` exists and handles exactly this case — it is simply
not used here.

Secondary problems in the same eight lines:

- `WP_Filesystem()` can return false, leaving `$wp_filesystem` null → fatal on PHP 8.
- The edit is never reverted after migration.
- No backup of `wp-config.php` is taken.

**Fix direction:** editing a user's `wp-config.php` to flip a cron constant is the wrong
mechanism entirely. Use `spawn_cron`/loopback requests, detect `DISABLE_WP_CRON` and inform
the user, or drive the queue from the admin side.

### 2.2 The resumable-chunking engine does not resume

**`includes/Archiver/Compressor.php:35`**

```php
public function add_file( $file_name, $new_file_name = '', $file_written = 0, $file_offset = 0, ... )
```

`$file_written` and `$file_offset` are **passed by value**. Upstream (All-in-One WP Migration)
declares them `&$file_written, &$file_offset`. The `&` was dropped in the port, and every
caller depends on them being references:

```php
// includes/Packager/DatabaseArchiver.php:36,85
$database_bytes_written = 0;
$completed = $archive->add_file( $database_dump_path, 'database.sql', $database_bytes_written, $database_bytes_offset );
```

Consequences:

- **`$file_written` is always 0** in the caller, so `$processed_files_size` never grows and
  every progress percentage computed from it is permanently 0.
- **`$file_offset` never advances**, so a file that hits the 10-second budget restarts from
  byte 0 on the next pass — re-writing its header block and its content into the archive again.
- In `DatabaseArchiver` this is an **unbounded loop**: the stored `database_bytes_offset` is
  always 0, `self::execute()` recurses (line 137), and each pass appends another copy of the
  dump until the disk fills. Any SQL dump that cannot be copied in 10s triggers it.

Note that the "Compressor" does not actually compress — it is a raw byte copy into a custom
container — so this is purely disk-speed-bound.

### 2.3 `self::execute()` recursion cancels out the chunking it exists to support

All eight packagers end with `self::execute()` when incomplete
(`UploadsArchiver.php:343`, `DatabaseDumper.php:270`, and six others).

The entire 10-second-budget design exists so each cron tick does a slice and yields.
Recursing in-process means the request never yields — it runs until PHP's
`max_execution_time` kills it, with stack depth growing per slice.

This also creates a real corruption path: a task request running past
`WP_CRON_LOCK_TIMEOUT` (60s) lets WordPress spawn a **second concurrent cron process** for the
same task. Both loaded the same offsets from `Options`, both append to the same archive, and
the last one to shut down wins the offset write.

**Fix direction:** `return` instead of recursing, and let the task queue re-invoke. That is
what the queue is for.

### 2.4 `wp-config.php` is archived and published to a public URL

**`includes/Packager/RootArchiver.php:57-98`**

```php
$exclude_filters = array( ABSPATH . 'index.php', ..., ABSPATH . 'wp-config.php', ... );  // full paths
...
if ( in_array( $file->getFilename(), $exclude_filters, true ) ) { return false; }        // basenames
```

`getFilename()` returns `wp-config.php`; the array holds `/var/www/html/wp-config.php`.
Strict comparison, never matches. **Nothing in that exclusion list is actually excluded**,
including `wp-config.php` with its DB credentials and auth salts. (`UploadsArchiver` gets this
right — it compares a bare directory name.)

That archive is written to `wp-content/uploads/bluehost-site-migrator/`, and its **public URL**
is computed and POSTed to CWM (`PackagerBase.php:46`) because the remote fetches it over HTTP.
The only protection is filename secrecy, and the filename is
`backup-{Y-m-d-His}-{sitename}-{uniqid}-root.zip` where `uniqid()` (`functions.php:173`) is
derived from the same microtime as the timestamp already printed in the name — so an attacker
who knows the second is brute-forcing roughly 2^20 microsecond values, not a real secret.

The `index.php` "silence is golden" file (`functions.php:188`) prevents directory listing on
Apache and does nothing to stop direct file access anywhere.

Also on the exposure surface: the plaintext `.sql` dump and the `.list` CSVs stay in that
directory indefinitely — they are only removed on cancel or deactivation.

### 2.5 TLS verification is tied to the wrong thing

**`includes/MigrationChecks/Checker.php:63`, `includes/RestApi/MigrationTasksController.php:181,253`**

```php
'sslverify' => is_ssl(),
```

Whether the *local* site is served over HTTPS has no bearing on whether the *remote* API's
certificate should be verified.

- On an HTTP site this silently disables certificate verification while transmitting the
  manifest and the `x-auth-token`.
- On an HTTPS site it enables verification — which, with the currently-expired cert, is what
  makes the plugin fail for essentially every modern install.

Should be `true`, unconditionally.

### 2.6 Any first-load API failure blanks the admin screen

**`src/components/Migration.js:29-43`**

```js
if ( response.failed ) {
    setStepResult( { loading: false, error: response.error, failed: true } );
}
setStepResult( { loading: false, compatible: response.compatible, ... } );   // ← no return above
```

The missing `return` means the error state is immediately overwritten with all-`undefined`
fields. Every guard then falls through, the component returns `undefined`, and React throws
*Nothing was returned from render*. This is precisely the path taken when the backend is
unreachable — i.e. today.

`src/components/transfer/TransferSuccess.js` has the same missing-return after
`navigate('/error')`, plus `for (const region of response.regions)` which throws when
`regions` is `null` (its stored default).

Separately, `navigate()` is called during render rather than from an effect — should be
`<Navigate to=... replace />`.

---

## 3. Lower-severity findings

| # | Finding | Location |
|---|---|---|
| 3.1 | **`append_eof()` is inverted** — throws on the *success* branch, and checks `strlen() !== false` on the failure branch. It always throws. Latent only because no caller passes `close( true )` — which means no archive ever gets its EOF block, so `is_valid()` would return false for every archive the plugin produces. | `Archiver/Archiver.php:155` |
| 3.2 | **Handle leaks / unfinalized archives** — `DatabaseArchiver`'s success path never calls `$archive->close()`; `UploadsArchiver`'s `feof` branch never `fclose()`s the list file. | `Packager/DatabaseArchiver.php`, `Packager/UploadsArchiver.php` |
| 3.3 | **Autoloaded state blob** — `update_option( ..., true )` forces `autoload=yes` on a blob containing the full manifest (every plugin and theme with descriptions), all task params, and `packaged_files`. Tens to hundreds of KB loaded on **every** request, persisting until deactivation. | `Utils/Options.php:83` |
| 3.4 | **`Status::set_status()` inside the per-file loop** — one `update_option()` per archived file. On a 50k-file uploads directory that is 50k writes of a barely-changing value. | all `Packager/*Archiver.php` |
| 3.5 | **Progress is theatre** — nearly every `set_status()` call passes a hardcoded band (`55`, `58`, `60`, `62`…). The computed `$progress` variable is dead in all but one place. | all packagers; live use only at `DatabaseDumper.php:246` |
| 3.6 | **Placeholder/argument mismatch** — `vsprintf` with **9** `%s` against `BH_SITE_MIGRATOR_OPTIONS_LIST`'s **10** entries. The 10th (`bh_site_migrator_redirect`) is not excluded and rides along into the exported dump. | `Packager/DatabaseDumper.php:194` |
| 3.7 | **Division by zero** — divides by `filesize()` of the dump with no zero guard; fatal on PHP 8 if the dump came out empty. | `Packager/DatabaseArchiver.php` |
| 3.8 | **`esc_xml()` requires WP 5.5+** and is used 35 times across archiver error paths, but the plugin header claims `Requires at least: 4.7`. On WP < 5.5 every error path fatals with an undefined function. (`Requires PHP: 5.6` vs phpcs `testVersion 7.0-` is inconsistent too.) | `bluehost-site-migrator.php`, `Archiver/*`, `Packager/*` |
| 3.9 | **Text domain typo** — `bluehost_site_migrator` (underscores) means those two menu strings can never be translated. Many other user-facing strings are not wrapped at all (`'Cloning your website'`, `'Check Compatibility'`, `'Done retrieving a list of WordPress root files.'`). | `WP_Admin.php:32-33` |
| 3.10 | **`wp_safe_redirect()` without `exit`**, firing on every `admin_init` including AJAX — and it hijacks bulk plugin activation. | `functions.php` (redirect handler) |
| 3.11 | **Dead/unsafe code** — `add_file()`'s `$encrypt` / `$encrypt_pass = 'pass'` parameters are never used with a real key; `nfd_bhsm_encrypt_string()` would encrypt each 512KB chunk under an independent IV, which no extractor could reverse. `Archiver` defines `replace_forward_slash_with_directory_separator()` etc. as protected methods *and* `functions.php` defines them again as globals — `Compressor` calls the globals. | `Archiver/Compressor.php:35`, `functions.php` |
| 3.12 | **`set_time_limit( 90 )` buried in a getter** (`nfd_bhsm_get_dir_size`). | `functions.php:224` |

---

## 4. Refactors worth doing

### 4.1 Collapse the six file archivers into one

`Uploads`, `Themes`, `Plugins`, `MuPlugins`, `Dropins`, and `Root` are ~330 lines each and
**~85% identical** — a normalized diff between `UploadsArchiver` and `ThemesArchiver` is 170
lines out of 683. They differ only in: source directory (or directories), exclude filter,
status messages, and the hardcoded progress band.

A `FileListArchiver` base with a template method plus a small per-type config array takes
**~1,700 lines to ~250** — and, critically, means each of the bugs above is fixed **once**
rather than six times. The exclusion bug in [2.4](#24-wp-configphp-is-archived-and-published-to-a-public-url)
exists precisely because these were copy-pasted and one copy diverged.

### 4.2 Make the offset state an object, not a bag of scalars

Every packager repeats twelve `if ( isset( $params['x'] ) ) { $x = (int) $params['x']; } else { $x = 0; }`
blocks, then twelve mirrored `$params['x'] = $x;` writes.

A `PackagingState` value object with typed accessors and `load()`/`save()` removes ~150 lines
per archiver and makes the by-reference bug in [2.2](#22-the-resumable-chunking-engine-does-not-resume)
structurally impossible — state advances by method call, not by hoping a parameter was
declared with `&`.

### 4.3 Replace the custom container format with `ZipArchive`

The custom `a255/a14/a12/a4096` format exists to support mid-file resume, but that resume is
broken (2.2) and the format is not actually compressed. `ZipArchive` is available on
effectively every host that meets the plugin's own `zlib` requirement, produces something the
user can open and verify, and deletes `Archiver` + `Compressor` (563 lines) outright.

If per-file resume genuinely matters, resume at *file* granularity rather than byte
granularity — far simpler and sufficient, since the pathological case is many files, not one
enormous one.

### 4.4 Change the handoff from pull to push

The "write to a public uploads URL and tell the remote to fetch it" design is what forces the
archives to be world-readable. Having the plugin PUT to a pre-signed, expiring URL lets the
archives live outside the webroot (or behind a deny rule), removes filename-guessing as the
only control, and allows deleting artifacts immediately after transfer.

This is the change that **retires** finding 2.4 rather than patching it.

### 4.5 Move state off the single autoloaded option

Split volatile packaging state into a non-autoloaded option (or a custom table, which the
tasks module already provides infrastructure for), and keep only small config in the
autoloaded blob. Add optimistic locking, or accept that the read-once/write-whole-array
pattern loses writes under concurrent cron.

### 4.6 Fail the compatibility check when the API is unreachable

Returning `$can_migrate` unchanged on `WP_Error` is what converts a backend outage into hours
of wasted packaging and a support call. It should return `false` with an actionable message.

### 4.7 Add PHP tests

There are none. The Cypress suite stubs the REST layer completely, so the ~3,000 lines of
packaging engine — the part that writes files, edits `wp-config.php`, and loops — has **zero**
coverage. The archiver is pure enough to unit-test against a fixture directory; that alone
would have caught 2.2 and 2.4.

### 4.8 Raise the PHP floor to 7.4+

`Requires PHP: 5.6` is holding the codebase to `array()` syntax and no type declarations while
WordPress itself has long moved on. Pair this with a PHP 8.x deprecation audit.

---

## 5. Recommendation

**If the goal is migrating WordPress sites:** use All-in-One WP Migration, Duplicator, or a
host-native tool. This plugin cannot complete a migration today regardless of code quality,
because the service it depends on is not serving a valid certificate and has not been touched
in three years.

**If the goal is reviving this for Bluehost**, in order:

1. Confirm CWM is still operated at all. This is a business question, and it gates everything else.
2. Fix [2.1](#21-clicking-start-transfer-can-take-the-site-down) and
   [2.4](#24-wp-configphp-is-archived-and-published-to-a-public-url) — actively harmful to
   users who install the current build.
3. Fix [2.2](#22-the-resumable-chunking-engine-does-not-resume) and
   [2.3](#23-selfexecute-recursion-cancels-out-the-chunking-it-exists-to-support) — why large
   sites never finish.
4. Then the refactors in section 4.

**If CWM is decommissioned**, the right move is to close the wp.org listing rather than leave
a plugin that bricks configs and publishes `wp-config.php`.

---

## 6. How these findings were verified

Reproduction commands for the external/environmental claims:

```bash
# Backend API certificate — expired 2024-07-26
echo | openssl s_client -connect cwm.eigproserve.com:443 -servername cwm.eigproserve.com 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates

# Server is reachable, so this is a cert problem and not a decommissioned host
nc -z -v -w 5 cwm.eigproserve.com 443

# wp.org release metadata and ratings
curl -s "https://api.wordpress.org/plugins/info/1.0/bluehost-site-migrator.json" | python3 -m json.tool

# Released tag is a divergent lineage from master
git branch -a --contains 1.0.14
git diff --stat master 1.0.14

# Last functional (non-CI) change to plugin code
git log -1 --date=short --pretty='%ad' -- includes/ src/

# Duplication between archivers: 170 differing lines out of 683
diff <(sed 's/uploads/XX/g;s/Uploads/XX/g' includes/Packager/UploadsArchiver.php) \
     <(sed 's/themes/XX/g;s/Themes/XX/g' includes/Packager/ThemesArchiver.php) | wc -l
```

### Scope and caveats

- This review covers the plugin source in `includes/`, `src/`, `functions.php`, and
  `constants.php`.
- **`newfold-labs/wp-module-tasks` was not reviewed.** `vendor/` is not installed in this
  working copy, so claims about task retry, scheduling, and locking semantics are inferred
  from the call sites rather than read from the module. Run `composer install` and re-trace
  the queue if those semantics matter to a fix.
- Findings were identified by reading the source, not by executing the packaging pipeline
  against a live site. The defects in section 2 are traceable in code; their runtime
  thresholds (e.g. how large a database must be to trigger 2.2) will vary with host disk speed.
