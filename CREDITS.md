# Credits

## All-in-One WP Migration (ServMask, Inc.)

Parts of this plugin are derived from
[All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/)
by [ServMask, Inc.](https://servmask.com/), licensed **GPL-2.0-or-later**.

The derived files are:

| File | Derived from |
|---|---|
| `includes/Database/DatabaseBase.php` | AIO's database dumper — view handling, collation downgrades, table prefixes, `max_allowed_packet` chunking, and base64 page-builder payload rewriting |
| `includes/Database/DatabaseMysqli.php` | AIO's mysqli driver |
| `includes/Archiver/Archiver.php` | AIO's archive container format |
| `includes/Archiver/Compressor.php` | AIO's incremental archive writer |
| `includes/Utils/DatabaseUtility.php` | AIO's serialized-value search/replace helpers |

The code entered this repository in commit `41f4196` (2023-08-21) with no attribution
attached. That was an oversight, not a claim of authorship; this file and the per-file
headers correct it. The exact upstream version the fork was taken from was not recorded
at the time and has not yet been established — see the risk register in
`docs/implementation-plan.md`.

`includes/Archiver/` is scheduled for removal (replaced by `ZipArchive`); the `Database/`
files are retained and actively developed.

## Original authors

This plugin began as the Bluehost Site Migrator. Its commit history is preserved in full,
and the contributors recorded there retain authorship of their work.
