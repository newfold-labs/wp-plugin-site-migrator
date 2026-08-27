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

## In wp-admin

**Site Migrator** appears in the menu on both sites. Every screen carries a strip along the top
that answers the only question anyone actually has — *has anything broken yet?* — so you never
have to infer it from a progress bar.

**On the source:** check compatibility against the destination, package the site, then either hand
it over directly or download it.

**On the destination:** point it at the package. Before anything is written you get the full
preview — the URL rewrite, what happens to every account, which plugins and themes arrive, what
the source had in its `wp-config.php` that you don't. Then, and only then, a button.

Afterwards the site you replaced is still on the server until you say you're done with it.

---

## From the command line

```bash
# on the source
wp site-migrator export                  # package this site
wp site-migrator offer                   # mint a transfer key and wait

# on the destination
wp site-migrator pull <url> <key>        # fetch it, checksumming as it lands
wp site-migrator import <dir>            # stage, verify, swap

# afterwards
wp site-migrator rollback                # put the old site back
wp site-migrator confirm                 # keep it, drop the old tables
wp site-migrator cancel                  # abandon an import that never swapped in
```

`verify <dir>` re-reads every byte against the manifest if you want the reassurance.

An import replaces every table on the destination, so it asks first — `--yes` skips that.
Accounts are merged by default; `--mode=replace` keeps only the source's.

The rollback window isn't a countdown. Your old tables stay until you keep the import or start
another migration, whichever comes first.

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
