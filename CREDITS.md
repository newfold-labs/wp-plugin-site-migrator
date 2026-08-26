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

## Bundled fonts

`assets/fonts/` carries two typefaces, both under the SIL Open Font License 1.1, with the
licence text alongside them:

| Files | Typeface | Copyright |
|---|---|---|
| `public-sans-latin*-wght-normal.woff2` | [Public Sans](https://public-sans.digital.gov/) | The Public Sans Project Authors |
| `jetbrains-mono-latin*-wght-normal.woff2` | [JetBrains Mono](https://www.jetbrains.com/lp/mono/) | The JetBrains Mono Project Authors |

They are the faces the design handoff specifies, and they are bundled rather than requested
from Google Fonts: a plugin distributed through wp.org may not make a third-party request to
render its own admin screen.

The files are the latin and latin-ext variable subsets from `@fontsource-variable/public-sans`
and `@fontsource-variable/jetbrains-mono`, both at 5.3.0. Neither is a dependency of this
project — the four woff2 files are committed, and nothing in the build reaches for the
packages — so refreshing them means installing the two, copying `files/*-latin*-wght-normal.woff2`
and `LICENSE` out of each, and checking the `unicode-range` declarations in
`assets/styles/app.css` still match the ones in the package's own `wght.css`.
