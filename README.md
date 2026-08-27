# Site Migrator

**Move a WordPress site to another host without holding your breath.**

Install it on both sites. The old one packs itself into a box. The new one opens the box — and
shows you exactly what's inside before a single byte of your live site is touched.

> **Pre-release.** Not distributed yet. A full migration works today, from wp-admin and from
> WP-CLI. The plan lives in [`docs/implementation-plan.md`](docs/implementation-plan.md); the
> autopsy that started it lives in [`docs/code-analysis.md`](docs/code-analysis.md).

---

## The one thing worth knowing

Most migration tools import *over* your site. If something breaks halfway, you own the rubble.

This one doesn't. The incoming database is built **beside** the live one, in tables prefixed
`nfdimp_` that WordPress never looks at. Files land first. Every way the import can fail is
arranged to happen *before* the moment of truth. And the moment of truth is one statement:

```sql
RENAME TABLE wp_posts TO nfdold_wp_posts, nfdimp_wp_posts TO wp_posts, ... ;
```

MySQL runs that atomically. All of it, or none of it. Until it executes, your destination is
exactly as you left it and you can walk away. After it executes, your old site is sitting in
`nfdold_` tables, one click from coming back.

There is no half-migrated state. That's the whole design.

---

## What it does

**Content only.** Core is never packaged. The destination already has WordPress; shipping another
copy is just postage.

**It refuses to pack junk.** `.git`, `.svn`, `node_modules`, core's `upgrade/` folders — gone,
wherever they hide. One real site had a 129.8MB `.git` nine levels deep inside a vendored
dependency. Symlinks are never followed, because following one quietly packages somebody else's
files. Everything skipped is named in the manifest, so "where did it go" always has an answer.

**It survives your host.** Work happens in short, budgeted steps that checkpoint and return, so a
30-second execution limit is an inconvenience rather than a wall. Close the tab. Come back
tomorrow. It picks up mid-file.

**It moves site-to-site, if you let it.** The destination fetches the package straight from the
source over HTTP — no 2GB round trip through your laptop. Or download it yourself; both routes
end in the same place.

**Your accounts survive.** The destination's users are kept and the source's merged in with their
IDs intact, so migrated posts stay attributed to whoever wrote them. You stay signed in — even
though the table holding your login was just replaced underneath you.

---

## Two sites, one code, no accounts

Nobody signs up for anything. Each site mints a credential for the other:

| | who makes it | what it stops | shape |
|---|---|---|---|
| **Pairing code** | the destination | strangers *targeting* your site | 12 characters, read aloud, hashed slowly |
| **Transfer key** | the source | strangers *reading* your site | 48 hex characters, checked on every request |

Neither half of a migration can be started from outside. The transfer key expires on **idleness**,
not on a clock — a fixed fifteen minutes would fail every 20GB move, which is the exact case it
exists for — and it binds to the first address that uses it, so a key that leaks mid-transfer is
already worthless.

Present a wrong key and you get a **404**, not a 401. A 401 would cheerfully confirm that a
WordPress site with this plugin lives at that address.

---

## Three ways to move a site

All three end in the same place: a package staged on the destination, previewed, then swapped in.
Pick on one question — **can the destination reach the source over the internet?**

| | when to use it | what it needs |
|---|---|---|
| **Direct transfer** | almost always | the destination can reach the source's URL |
| **Download and upload** | source is behind a firewall, on a laptop, or on an intranet | a browser and patience |
| **Put it there yourself** | very large sites, or you already have SSH/FTP | shell or FTP access to the destination |

The middle one moves every byte twice — down to your machine and back up. The other two don't.

---

## Step by step

### Before you start

Install and activate the plugin on **both** sites. **Site Migrator** appears in each admin menu.
You need an administrator account on both.

The destination's content is replaced. Its *user accounts* are kept.

---

### Flow A — Direct transfer

The destination fetches the package straight from the source. Nothing goes through your computer.

**On the destination** — *Site Migrator → Receive a site here*

1. **Give the source this code.** It shows a **pairing code** (`XXXX-XXXX-XXXX`) and this site's address. Leave the tab open.

**On the source** — *Site Migrator → Send this site somewhere else*

2. Paste the destination's address and pairing code. The source reads the destination's PHP,
   WordPress version, free space and database capabilities directly from it.
3. **Compatibility** shows what it found. Anything that would break the move blocks here — a check
   that could not run counts as a failure, not a pass.
4. **Package.** The site is archived in resumable steps. You can close the tab; reopening picks up
   where it stopped. Pause is safe.
5. **Deliver → Generate a transfer key.** Copy the key and the address. The key is shown once.

**On the destination** — *Bring in a package → Fetch it from the source*

6. Paste the address and the key. It starts pulling, checksumming each file as it lands. Close the
   tab, come back, open a second tab — the progress is measured from the files on disk, so all
   three agree.
7. When it finishes it verifies every file, then **See what it would do**.

**Both sites — the review**

8. **Review** shows everything before anything is written: the URL rewrite, every account and what
   it will sign in as, which plugins and themes arrive, and any `wp-config.php` settings the source
   had that this site does not. Nothing is written to your `wp-config.php` — it prints them for you
   to copy.
9. Tick the box, **Import it**. Files land first, the database loads into staging tables the live
   site never reads, and everything that can fail happens before the swap.
10. **Finish.** Check the front page, a few posts, your images, and signing in. Then **Keep it**, or
    **Undo the import** and the old site comes straight back.

> Until step 10, `Keep it` has not run and your old site is intact in `nfdold_` tables. After it,
> the old tables are dropped and the import cannot be undone.

---

### Flow B — Download and upload

Identical up to step 4. Use this when the destination cannot reach the source.

5. On **Deliver**, choose **Download the package instead**. Your browser asks for a folder and
   streams every file into it, subdirectories preserved — hand that folder straight to the
   destination's picker. If your browser doesn't support folder picking, files download
   individually instead.
6. On the destination, *Bring in a package → **Choose the package folder***, and select the folder
   you just downloaded. Uploads go in small pieces and resume where they stopped, so a dropped
   connection is not a lost upload.
7. Continue from step 7 above.

---

### Flow C — Put the package there yourself

For very large sites, this is the quickest route and the one least likely to time out.

1. Package the source — in wp-admin, or `wp site-migrator export`.
2. Copy the package directory to the destination by SSH, `rsync` or FTP. Anywhere works; inside
   `wp-content/uploads/nfd-site-migrator/` is tidiest.
3. On the destination, *Bring in a package* lists it under **Already on this server**. Click
   **Use this**, and continue from step 8 above.

---

### Entirely from the command line

For sites where the browser is the wrong tool — a hundred thousand files, or tens of gigabytes.

```bash
# on the source
wp site-migrator export                       # package this site
wp site-migrator offer                        # prints an address and a transfer key

# on the destination
wp site-migrator pull <address> <key>         # fetch it, checksumming as it lands
wp site-migrator import <dir>                 # stage, verify, swap

# then, once you have checked the site
wp site-migrator confirm                      # keep it, drop the old tables
wp site-migrator rollback                     # or put the old site back
```

`wp site-migrator cancel` abandons an import that has not yet swapped in — the live site is
untouched either way. Add `--yes` to skip a confirmation.

Mixing surfaces is fine. Package on the CLI, import in the browser; start a pull in the browser
and finish it from a shell. Progress lives on disk, not in a session.

### Looking before you leap

```bash
wp site-migrator preflight                                  # can this site be migrated at all?
wp site-migrator preflight --against=<url> --code=<code>    # ...and to that one?
wp site-migrator inspect <dir>                              # what is in this package?
wp site-migrator verify <dir>                               # ...and does it match its manifest?
```

`inspect` reads the manifest; `verify` re-reads every byte and checks it against that manifest,
which on a large package is minutes rather than milliseconds. Both are read-only.

### Scripting it

Four commands report rather than act — `preflight`, `inspect`, `verify` and `offer` — and all four
take `--format=table|json|csv|yaml`. **Data goes to stdout, everything else to stderr**, so the
JSON is the only thing in the pipe:

```bash
wp site-migrator preflight --format=json | jq '.local.ok'
KEY=$(wp site-migrator offer --format=json | jq -r '.key')
```

Every JSON payload carries a `schema` integer. It is bumped when a field changes meaning or
disappears — not when one is added, since a consumer reading by name is unaffected by a field it
has never heard of.

**Exit codes** say what happened without anyone reading English:

| | meaning |
|---|---|
| `0` | it worked |
| `1` | it failed |
| `2` | the sites are incompatible — the fix is on a server, not in the command |
| `3` | stopped early with work outstanding; run the same command again |
| `4` | the package is missing, unreadable, or does not match its manifest |

`3` is the one worth building around. `--budget=<seconds>` bounds a single step; **`--max-time=<seconds>`
bounds the whole command**, leaving a checkpoint and exiting `3` if it needs longer. That makes a
migration something cron can drive:

```bash
until wp site-migrator export --max-time=50; do
    [ $? -eq 3 ] || exit 1        # 3 means "not finished", anything else is real
done
```

Stopping is always safe. Every step checkpoints before it returns, and the swap is a single
statement that has either run or not.

**Nothing ever prompts when nobody can answer.** With `--format=json`, or when stdin is not a
terminal, a missing `--yes` is an error rather than a question that would wait forever.

---

## If something goes wrong

**During packaging or transfer** — nothing on either site has changed. Everything before the swap
is re-runnable. Close the tab and come back.

**During the import, before the swap** — the destination is still serving its own content. `Try
again` resumes; `wp site-migrator cancel` throws the staged copy away.

**After the swap** — **Undo the import**, or `wp site-migrator rollback`. The database goes back
exactly. Files go back only where it can be done honestly: plugins, themes, must-use plugins and
drop-ins the package *added* are removed, but anything it *overwrote* stays, because the
destination's own copy is already gone and deleting it would make an incomplete rollback into a
destructive one. `uploads` is never touched — a photo added after the import is indistinguishable
from one the package brought.

**Once you press Keep it** — the old tables are gone. That is the point of no return, and it is
the only one.

---

## Hard-won

Things this plugin knows because they went wrong on a real site first.

**A checksum proves a file arrived intact. It cannot prove it left intact.** A re-export once
wrote a new database dump over a longer old one without truncating, leaving the previous dump's
tail past the new one's end marker. The package verified perfectly at both ends — every hash
matched, because the corruption was already there when the hashing started — and then died on
import with `ERT INTO`, three bytes into a mangled `INSERT`. The export's own output is the thing
that has to be right; nothing downstream can save you.

**The export used to package its own package.** A 1GB site produced 2.2GB containing a copy of
itself. The storage directory is now excluded from every part that could contain it, computed from
where it actually is rather than where it's assumed to be.

**A zip volume is opened, filled, and closed exactly once.** `ZipArchive::close()` rebuilds the
entire archive rather than appending, so flushing every 64 files into a 1GB volume turned a 1.5GB
export into hundreds of gigabytes of writing.

**Packaging is bound by the first read of each file** — 8,000 small files cost 185s cold and 2.0s
warm. Volume size adapts on files per second, not bytes, because the cost is per file, not per
byte.

**Rollback undoes the database exactly and the files only where it honestly can.** Anything the
package overwrote that the destination already had stays put: its own copy is gone by then, and
deleting it would turn an incomplete rollback into a destructive one. `uploads` is never tracked,
because a photo added after the import is indistinguishable from one the package brought.

---

## Requirements

WordPress 4.7+. PHP 5.6+ today, 7.4 before release. MySQL that can do `RENAME TABLE` — preflight
checks, and refuses the migration rather than half-finishing it. Multisite is not supported yet
and is blocked on both sides rather than attempted.

---

## Development

```bash
composer install

npm run generate:css                              # assets/styles/app.css -> src/styles/
npx wp-scripts build ./src/nfd-site-migrator.js   # -> build/

composer lint                                     # phpcs, Newfold standard
npx wp-scripts lint-js src

composer test                                     # unit suite, no database, well under a second
composer test:roundtrip                           # two real WordPress installs
```

### Tests

The unit suite runs against a bootstrap that fakes the handful of WordPress functions the core
actually calls — possible only because the core never learned about HTTP — so it needs no database
and finishes before you look away.

The round trip is the one that matters. It provisions two WordPress installs from scratch at
different URLs and different table prefixes, migrates between them, and checks the things a
migration is actually judged on: that serialized options still unserialize, that Gutenberg block
attributes with escaped slashes were rewritten, that uploads match by checksum, that the users
merge kept both sides' accounts, that rollback puts it back — and that the same import driven one
process per step arrives at the same place, because a loop inside one process proves the loop
works, not that resuming does.

It needs `wp`, a MySQL server **and its client on `PATH`**, and permission to create two
databases. On macOS the client is usually what is missing:

```bash
NFD_MYSQL_DIR=/path/to/mysql/bin \
NFD_DB_HOST="localhost:/path/to/mysqld.sock" \
composer test:roundtrip
```

`build/` and `src/styles/nfd-site-migrator.css` are generated and untracked. Build after cloning
or the admin page renders a very confident empty `<div>`.

> `package.json`'s own `build` and `start` scripts hard-code `yarn`, so `npm run build` dies at the
> first step on a machine without it. Run the two commands above directly, or install yarn.

> `composer fix` rewrites string literals — Newfold's ruleset includes a "spell WordPress
> correctly" sniff that doesn't know which strings are prose and which are data. Read its diff. It
> once turned a dot-path lookup into a capitalised one and silently defaulted every compatibility
> check.

---

## Licence

GPL-2.0-or-later — see [`LICENSE`](LICENSE).

`Database/`, `Archiver/` and `Utils/DatabaseUtility.php` derive from All-in-One WP Migration by
ServMask, Inc., and carry their attribution headers. Public Sans and JetBrains Mono are bundled
under the OFL — never hot-linked, because a plugin on wp.org has no business calling a third party
to draw its own admin screen. Details in [`CREDITS.md`](CREDITS.md).
