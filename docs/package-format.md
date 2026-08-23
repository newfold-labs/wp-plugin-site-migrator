# Site Migrator package format — schema v1

A **package** is a directory. Everything needed to reconstruct a site's content lives inside it,
and nothing outside it is consulted at import time.

```
<package>/
  manifest.json              schema, source facts, part index, checksums
  database.sql               single SQL dump of the source database
  parts/
    plugins.zip
    themes.zip
    mu-plugins.zip
    dropins.zip
    uploads.001.zip          volumes, split at the volume limit
    uploads.002.zip
    content-other.zip        everything else under wp-content
    root-extras.zip          non-core top-level files, strict allowlist
  large/
    wp-content/uploads/2024/03/film.mp4     files above the loose threshold, stored as-is
  .lists/                    transient: one file list per part, deleted on finalize
  checkpoint.json            transient: present only mid-run, deleted on success
```

## Design constraints

Three properties drive every decision below.

**It must survive a 10-second execution limit.** Shared hosting kills long requests, so export
runs as a sequence of short steps. Any step can be the last one before the process dies, so
on-disk state must be consistent after each step, and the next step must be able to pick up from
it. `checkpoint.json` is on disk rather than in the database because the import half replaces the
database underneath itself.

**It must not contain secrets.** `wp-config.php` cannot appear in a package under any
circumstances. `root-extras` is therefore an **allowlist** — a fixed set of filenames — rather
than a directory walk with exclusions. A walk-and-exclude got this wrong before (finding 2.4):
the exclusion list held absolute paths but was compared against basenames, so it matched nothing
and `wp-config.php` was archived and served from a guessable public URL.

**It must be openable without this plugin.** Parts are standard zip. `unzip` reads them. If the
importer has a bug, the user's data is still recoverable with ordinary tools — which was not true
of the previous custom container format.

## Content scope

**WordPress core is never packaged.** The destination already has WordPress; shipping
`wp-admin/`, `wp-includes/`, and the core root files would be gigabytes of bytes identical to
what is already there. The consequence is a hard preflight gate: the destination's WordPress
version must be greater than or equal to the source's, because the source's database expects the
source's schema and WordPress has no downgrade path.

| Part | Source | Notes |
|---|---|---|
| `plugins` | `WP_PLUGIN_DIR` | This plugin excludes itself |
| `themes` | theme root | All registered theme directories |
| `mu-plugins` | `WPMU_PLUGIN_DIR` | |
| `dropins` | `WP_CONTENT_DIR` | `advanced-cache.php`, `object-cache.php`, `db.php`, … — allowlist |
| `uploads` | uploads basedir | Usually the largest part; split into volumes |
| `content-other` | `WP_CONTENT_DIR` | Everything not claimed by another part, so `languages/` and custom directories are not silently dropped |
| `root-extras` | `ABSPATH` | Allowlist only: `.htaccess`, `robots.txt`, `ads.txt`, `favicon.ico`, and verification files |

`root-extras` never descends into subdirectories, and its allowlist is closed. Adding a name to
it is a deliberate act.

## Large files

Standard zip cannot append a single entry incrementally: a 2GB video cannot be written across
several 10-second steps. Rather than reintroduce a custom container to solve that one case,
files at or above the **loose threshold** (default 64MB) are copied into `large/` as plain files,
preserving their relative path. A plain copy resumes trivially from a byte offset.

This costs almost nothing: files that large are overwhelmingly already-compressed media, which
zip cannot shrink anyway.

## Volumes

A part is split when the current volume reaches the **volume limit** (default 1GB), producing
`uploads.001.zip`, `uploads.002.zip`, and so on. A part that fits in one volume has no numeric
suffix. Volumes keep individual files small enough to upload and to resume.

## `manifest.json`

```jsonc
{
  "schema_version": 1,
  "generator": { "plugin": "nfd-site-migrator", "version": "0.1.0" },
  "created_at": "2026-08-23T14:22:05+00:00",
  "source": {
    "site_url": "https://oldsite.example.com",
    "home_url": "https://oldsite.example.com",
    "wp_version": "6.5.5",
    "db_version": 57155,
    "php_version": "8.1.27",
    "table_prefix": "wp_",
    "content_dir": "/srv/www/wp-content",
    "is_multisite": false,
    "locale": "en_US"
  },
  "database": { "file": "database.sql", "bytes": 431…, "sha256": "…" },
  "parts": [
    { "name": "plugins", "file": "parts/plugins.zip", "bytes": 195…, "sha256": "…", "files": 4210 }
  ],
  "large": [
    { "path": "wp-content/uploads/2024/03/film.mp4", "bytes": 398…, "sha256": "…" }
  ],
  "totals": { "bytes": 4021…, "files": 18422 }
}
```

Checksums are SHA-256 over the file as written. The manifest is written **last**, once every
part has been finalised, so a manifest's presence means the package is complete. An interrupted
export leaves `checkpoint.json` and no `manifest.json` — that is how a partial package is
recognised.

## `checkpoint.json`

Transient. Records the stage, the index of the part in progress, the byte offset into that part's
list file, and — when a large file is being copied — the offset within it. Database progress is
the four offsets `DatabaseBase::export()` already maintains by reference.

It is written **after** the on-disk state it describes has been flushed, never before. A
checkpoint therefore always points at or behind the true state: the worst case on resume is
repeating a little work, never skipping any.

## Compatibility

`schema_version` is checked on import. A reader must refuse a version it does not understand
rather than guess. Additive fields do not bump the version; a change in the meaning or layout of
anything above does.
