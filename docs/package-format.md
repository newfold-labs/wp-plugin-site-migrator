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

Nothing is ever written into a package by the **import** side. Import state lives in the
destination's own storage directory (`uploads/nfd-site-migrator/import/`), for two reasons: a
package may sit on read-only or shared storage, and state written inside one travels with it.
Copying a package that carried a finished import's checkpoint makes the copy look already-imported
— which the importer honours by doing nothing and reporting success.

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

Every part additionally refuses, at any depth:

| Refused | Why |
|---|---|
| `.git`, `.svn`, `.hg`, `.bzr`, `CVS` | Version control metadata. Not site content, restores to nothing useful, and routinely the largest thing in a plugin directory — one observed `.git` pack was 129.8MB |
| `node_modules` | Build-time dependencies. Never read by PHP at runtime, and the usual reason an install has a hundred thousand files, which is what the export's cost is measured in |
| `wp-content/upgrade`, `upgrade-temp-backup` | Core's update scratch space, temporary by definition |
| this plugin's storage directory | Otherwise the export packages the package. It sits inside uploads, so this is computed per part from where it actually is |
| symlinks, of either kind | Following one copies content from outside the site into the package |

The first three are filterable through `nfd_sm_excluded_names`. Everything refused is **named in
the manifest** — `skipped_paths` for directories, `skipped_links` for symlinks — because a package
that is quietly missing something is worse than one that is honestly smaller.

## Large files

Standard zip cannot append a single entry incrementally: a 2GB video cannot be written across
several 10-second steps. Rather than reintroduce a custom container to solve that one case,
files at or above the **loose threshold** (default 64MB) are copied into `large/` as plain files,
preserving their relative path. A plain copy resumes trivially from a byte offset.

This costs almost nothing: files that large are overwhelmingly already-compressed media, which
zip cannot shrink anyway.

## Volumes

A part is split when the current volume reaches the **volume limit** (default 128MB), producing
`uploads.001.zip`, `uploads.002.zip`, and so on. A part that fits in one volume has no numeric
suffix. Volumes keep individual files small enough to upload and to resume.

**A volume is the unit of writing, not just of splitting: it is opened, filled, and closed
exactly once, and never reopened.** That is a performance property, not a tidiness one.
`ZipArchive::close()` does not append — it rebuilds the archive into a temporary file and renames
it over the original. Reopening a growing archive to add a few more files therefore rewrites
everything already in it, so packaging N bytes in K sittings costs roughly N×K/2 in disk traffic
rather than N. An earlier design flushed every 64 files into a volume capped at 1GB, which turned
a 1.5GB uploads directory into hundreds of gigabytes of writing; the same export now costs 1.5GB
of writing and runs about six times faster.

The limit is what bounds one close, so it is also what keeps a single step inside a shared host's
execution budget — which is why it is 128MB and not something larger.

Each volume is also sized and checksummed as it is closed, rather than all of them being hashed at
the end. Hashing a whole package is minutes of work on a large site, and `finalize` is one step
that cannot be split.

## Compression

Files whose contents are already compressed — JPEG, PNG, WebP, MP4, PDF, woff2, and the rest —
are **stored**, not deflated. Uploads are almost entirely these, and deflating them spends CPU to
make each file fractionally larger. Everything else is deflated normally.

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
    "abspath": "/srv/www",
    "content_dir": "/srv/www/wp-content",
    "is_multisite": false,
    "locale": "en_US",
    "server": "Apache/2.4.57"
  },
  "wp_config": {
    "readable": true,
    "prefix": "wp_",
    "carry": [ { "name": "WP_MEMORY_LIMIT", "value": "'256M'", "note": "…" } ],
    "redacted": [ "AUTH_KEY", "SOME_PLUGIN_LICENSE" ]
  },
  "profile": { "…": "the full SiteProfile the preflight gates compare against" },
  "database": { "file": "database.sql", "bytes": 431…, "sha256": "…" },
  "parts": [
    { "name": "plugins", "prefix": "wp-content/plugins",
      "file": "parts/plugins.zip", "bytes": 195…, "sha256": "…", "files": 4210 }
  ],
  "large": [
    { "path": "wp-content/uploads/2024/03/film.mp4", "bytes": 398…, "sha256": "…" }
  ],
  "skipped_links": { "total": 2, "paths": [ "wp-content/uploads/shared-media" ] },
  "skipped_paths": { "total": 37, "paths": [ "wp-content/plugins/acme/vendor/x/.git" ] },
  "totals": { "bytes": 4021…, "files": 18422 }
}
```

`skipped_links` and `skipped_paths` each carry a full count and a sample of at most 50 paths, so
a site that symlinks a thousand things does not turn the manifest into a list of them.

Five fields exist purely so the import half is not left guessing.

**`source.abspath`** cannot be derived from `content_dir`: the two are related by convention, and
`WP_CONTENT_DIR` is precisely the constant people move. Import rewrites absolute paths out of the
database and needs both.

**`parts[].prefix`** records where a part's files sat relative to the source's WordPress root, so
the destination can map them onto wherever *it* keeps that kind of file. Extracting entries
straight into `ABSPATH` only works for two installs laid out identically. The part's `name` is
carried alongside it because a volume file name — `plugins.002.zip` — no longer identifies which
part it belongs to.

**`users`** is the source's account list — id, login, email, nicename, display name — so the
destination can show an accurate merge plan on its confirmation screen before anything is written.
It adds no exposure: the package already contains the whole users table. Sites above
`nfd_sm_manifest_user_limit` (5000) record only a count and a `truncated` flag; the merge itself
reads the real table and is unaffected.

**`profile`** is the source's full `SiteProfile` — what the pre-write compatibility check compares
against. It carries its own `schema_version`, separate from the package's, and a destination that
does not read that version refuses the package outright rather than reading it as best it can:
a shape change with the version left alone is worse than no version at all, because every lookup
then silently returns its default and the gates report "indeterminate" instead of the real reason.

**`wp_config`** is the read-only result of tokenising the source's `wp-config.php`. Credentials,
salts, paths and the table prefix are dropped outright; anything whose name looks like a secret is
recorded as a name with no value; the small remainder is offered to the user as a block of text to
paste. Nothing is ever written to the destination's own file. See §9.5 of the implementation plan.

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
