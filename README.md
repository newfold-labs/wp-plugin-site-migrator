# Site Migrator

**Move a WordPress site to another host without holding your breath.**

Install it on both sites. The old one packs itself into a box. The new one opens the box — and
shows you exactly what's inside before a single byte of your live site is touched.

> **Pre-release.** Not on wp.org yet, but the zip is real: every release carries one that CI has
> installed into a clean WordPress and run before publishing. A full migration works today, from
> wp-admin and from WP-CLI. The plan lives in
> [`docs/implementation-plan.md`](docs/implementation-plan.md); the autopsy that started it lives
> in [`docs/code-analysis.md`](docs/code-analysis.md).

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

**You choose what travels.** Everything goes unless you say otherwise, and the *Choose what to
include* screen lets you say otherwise: whole parts, individual plugins, themes and upload folders,
or the parts of the database nothing reads — post revisions, spam, cached transients, a log table
that has grown to gigabytes. Whatever you leave out, the destination simply keeps what it already
has in that place, and the package records the choice so the other site can tell "this site has no
media" from "the media was deliberately left behind".

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

**And you only ever carry one of them.** The two codes authenticate opposite directions and cannot
be merged — but they share a moment. At the instant pairing succeeds both ends have proved
themselves, so the source leaves a **link token** behind and the destination keeps it. Later, when
the source presses *Offer it to the destination*, the other site simply asks whether anything is
waiting and shows you a **Start the transfer** button. Nothing to copy the second time.

The link is worth nothing on its own: presenting it asks one question, and until somebody on the
source presses *Offer* the answer is the same 404 a stranger gets. The transfer key is minted at the
moment it is claimed, not when it is offered, so the source still never stores anything but a hash —
and the address that took it is shown on the source's own screen. Typing a key by hand still works,
and is what happens automatically if the other site is running an older build.

---

## Installing

The plugin goes on **both** sites — the one you are leaving and the one you are moving to. It is
the same plugin on each; which role a site plays is decided by what you click, not by what you
install.

Grab `nfd-site-migrator.zip` from the [releases](../../releases), then on each site:

*Plugins → Add New → Upload Plugin → Choose File → Install Now → Activate*

or, if you have a shell:

```bash
wp plugin install nfd-site-migrator.zip --activate
```

**Requirements:** WordPress 5.8+, PHP 7.4+, and the `zip` extension. The plugin checks all three
and refuses to activate rather than failing partway through your first export. Multisite is not
supported and is blocked at preflight on both sides.

Building the zip yourself:

```bash
composer build:zip     # -> dist/nfd-site-migrator.zip
composer verify:zip    # installs it into a throwaway WordPress and checks it runs
```

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
4. **Choose what to include** — optional, and reached from the Compatibility screen. Everything is
   packaged unless you tick something off: whole parts, individual plugins, themes or upload
   folders, and the database rows nothing reads. The screen warns about the choices that hurt, and
   sums up what you are leaving out before you commit to it. Skip it and you get the whole site.
5. **Package.** The site is archived in resumable steps. You can close the tab; reopening picks up
   where it stopped. Pause is safe.
6. **Deliver → Offer it to the destination.** Nothing to copy: the destination already holds a token
   from the pairing, and this tells it there is something to fetch. (*Generate a transfer key* is
   still there beside it, for a destination that was never paired with this one.)

**On the destination** — *Bring in a package → Fetch it from the source*

7. The screen already says *"…is offering a package right now"*, with the size and what — if
   anything — the source deliberately left out. Press **Start the transfer**. It pulls, checksumming
   each file as it lands. Close the tab, come back, open a second tab — the progress is measured
   from the files on disk, so all three agree.
8. When it finishes it verifies every file, then **See what it would do**.

**Both sites — the review**

9. **Review** shows everything before anything is written: the URL rewrite, every account and what
   it will sign in as, which plugins and themes arrive, anything the source left out of the package
   on purpose, and any `wp-config.php` settings the source had that this site does not. Nothing is
   written to your `wp-config.php` — it prints them for you to copy.
10. Tick the box, **Import it**. Files land first, the database loads into staging tables the live
    site never reads, and everything that can fail happens before the swap.
11. **Finish.** Check the front page, a few posts, your images, and signing in. Then **Keep it**, or
    **Undo the import** and the old site comes straight back.

> Until step 11, `Keep it` has not run and your old site is intact in `nfdold_` tables. After it,
> the old tables are dropped and the import cannot be undone.

---

### Flow B — Download and upload

Identical up to step 5. Use this when the destination cannot reach the source.

6. On **Deliver**, choose **Download the package instead**. Your browser asks for a folder and
   streams every file into it, subdirectories preserved — hand that folder straight to the
   destination's picker. If your browser doesn't support folder picking, files download
   individually instead.
7. On the destination, *Bring in a package → **Choose the package folder***, and select the folder
   you just downloaded. Uploads go in small pieces and resume where they stopped, so a dropped
   connection is not a lost upload.
8. Continue from step 8 above.

---

### Flow C — Put the package there yourself

For very large sites, this is the quickest route and the one least likely to time out.

1. Package the source — in wp-admin, or `wp site-migrator export`.
2. Copy the package directory to the destination by SSH, `rsync` or FTP. Anywhere works; inside
   `wp-content/uploads/nfd-site-migrator/` is tidiest.
3. On the destination, *Bring in a package* lists it under **Already on this server**. Click
   **Use this**, and continue from step 9 above.

---

### Entirely from the command line

For sites where the browser is the wrong tool — a hundred thousand files, or tens of gigabytes.

```bash
# on the source
wp site-migrator contents                     # optional -- what to leave out (see below)
wp site-migrator export                       # package this site
wp site-migrator offer                        # prints an address and a transfer key

# on the destination
wp site-migrator pull <address> <key>         # fetch it, checksumming as it lands
wp site-migrator import <dir>                 # stage, verify, swap

# then, once you have checked the site
wp site-migrator confirm                      # keep it, drop the old tables
wp site-migrator rollback                     # or put the old site back
```

If the two sites have been paired, neither the address nor the key needs carrying:

```bash
wp site-migrator offer --link                 # on the source: mark it offered
wp site-migrator pull --linked                # on the destination: take it, no arguments
```

`wp site-migrator cancel` abandons an import that has not yet swapped in — the live site is
untouched either way. Add `--yes` to skip a confirmation.

Mixing surfaces is fine. Package on the CLI, import in the browser; start a pull in the browser
and finish it from a shell. Progress lives on disk, not in a session.

### Choosing what goes in the package

The selection is stored on the site, not passed to `export`, so a run started here and a run started
in the browser package the same thing — and the *Choose what to include* screen shows whatever you
set here.

```bash
wp site-migrator contents            # what the next export will leave out
wp site-migrator contents --list     # the parts, and the names inside each one
wp site-migrator contents --reset    # forget it; carry the whole site again

echo '{"parts":{"uploads":false},
       "paths":{"plugins":["akismet"]},
       "database":{"skip_revisions":true,"skip_transients":true}}' \
  | wp site-migrator contents --set=-
```

`--set` takes a JSON file, or `-` for standard input. It answers with what it understood:

```
leaving_out
uploads (the media library)
1 item from plugins (akismet)
post revisions
cached transients
```

**Anything it cannot honour is dropped rather than obeyed.** A path that climbs out of its part, and
the tables WordPress cannot start without, are refused here and not merely hidden in the UI — the
same check runs whichever surface asks. So `{"database":{"skip_tables":["wp_posts"]}}` is quietly
ignored, and a package is never made unimportable by a typo.

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

Five commands report rather than act — `preflight`, `contents`, `inspect`, `verify` and `offer` —
and all five take `--format=table|json|csv|yaml`. **Data goes to stdout, everything else to stderr**, so the
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

## Before you trust it with a real site

Run `wp site-migrator preflight` on both. It refuses rather than half-works, and it checks things
that are easy to get wrong: whether the destination's WordPress and database schema are new enough,
whether its PHP satisfies what your plugins declare, whether `zip` is there, whether `RENAME TABLE`
works, whether there is room — and **whether your package directory can be downloaded from the
web**.

That last one matters more than it sounds. A package contains `database.sql`: every table, every
password hash, at a guessable path under `uploads`. The plugin writes `.htaccess` and `web.config`
to deny access, which covers Apache and IIS — **nginx reads neither**. So preflight does not take
its own word for it; it fetches the directory over HTTP and refuses to export if it comes back. If
you see that block, deny access to `wp-content/uploads/nfd-site-migrator` in your server config and
check again.

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

WordPress 5.8+, PHP 7.4+, the `zip` extension, and MySQL that can do `RENAME TABLE`. Preflight
checks every one of them and refuses the migration rather than half-finishing it. Multisite is not
supported yet, and is blocked on both sides rather than attempted.

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
npm run test:e2e                                  # Playwright, against a real WordPress (wp-env)
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

The browser suite is Playwright, and nothing in it is mocked. It runs against wp-env by default;
`NFD_E2E_SERVER=builtin` provisions with WP-CLI and PHP's own server instead, for machines without
Docker. It exists for one failure in particular — the
blank admin page — which has been caused by a symlinked plugin directory, by a REST payload
handing React an object, and by a redirect returning nothing. All three look the same to a user
and none show up in a unit test.

All of these need `wp`, a MySQL server **and its client on `PATH`**, and permission to create the
databases. On macOS the client is usually what is missing:

```bash
NFD_MYSQL_DIR=/path/to/mysql/bin \
NFD_DB_HOST="localhost:/path/to/mysqld.sock" \
composer test:roundtrip
```

`build/` and `src/styles/nfd-site-migrator.css` are generated and untracked. Build after cloning
or the admin page renders a very confident empty `<div>`.

> `npm run build` runs both steps together if you prefer. Node 20 or newer.

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
