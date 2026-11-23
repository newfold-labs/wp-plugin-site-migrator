# Site Migrator — Happy path migration

**Plugin:** NFD Site Migrator v0.2.6
**Route:** Linked direct transfer (pair → package → offer → pull → review → import → keep)
**Matches video:** [`02-full-migration.mp4`](videos/02-full-migration.mp4)

This guide moves a WordPress site from a **source** (the site you are moving from) to a **destination** (the site you are moving to). The destination's content is **replaced** when the import completes, so practise on a throwaway destination first.

---

## Before you start

- [ ] Site Migrator is installed and active on **both** sites.
- [ ] You are an **administrator** on both sites.
- [ ] Both sites can reach each other over HTTP/HTTPS.
- [ ] Both sites are single sites (multisite is not supported).
- [ ] You have both sites open in separate browser tabs.

> WordPress core is not included in the package — the destination already has it. Only your content moves.

---

## 1. Destination — get a pairing code

1. On the **destination**, open **Site Migrator** in wp-admin.
2. Under **Receive a site here**, click **Get a pairing code →**.
3. Click **Generate a pairing code**.
4. Note the **This site's address** and **Pairing code** (or use **Copy address** / **Copy code**).
5. **Leave this tab open.** When the package is ready, it appears here automatically.

> The code is single use, expires in 15 minutes, and only lets the source read this site's setup. It is not a download password. Use **New code** if it expires.

---

## 2. Source — pair with the destination

1. On the **source**, open **Site Migrator**.
2. Read the **This site** checks. A **Heads up** is advice; a blocker must be fixed first.
3. Under **Send this site somewhere else**, click **Start export →**.
4. On **Pair with the destination**, paste the destination's **address** and **pairing code**.
5. Click **Check compatibility**.

> Already paired before? The source shows **Paired with …**. Click **Continue**, or **Pair with a different destination** to start fresh.

---

## 3. Source — check compatibility

1. Read the **Compatibility** report (read live from the destination).
   - **Heads up** — worth reading (e.g. older PHP on the destination, free space).
   - **Blocker** — fix it, then click **Check again**.
2. When it shows **Ready to migrate**, click **Build the package**.

> To leave things out (uploads, specific plugins, the database), use **Choose what to include** instead. See [`04-choose-what-moves.mp4`](videos/04-choose-what-moves.mp4).

---

## 4. Source — build the package

1. **Building the package** runs: database first, then files in parts (plugins, themes, uploads…).
2. Keep the tab open if you can. Closing it is safe — reopening picks up where it stopped. **Pause** / **Resume** are available.
3. When done, you land on **Deliver**, which says when the package was built.

> Built a package earlier? **Build the package** reuses it when nothing in **Choose what to include** has changed. To build a fresh one, use **Start over** at the bottom of **Deliver**, then **Discard this package**. The pairing with the destination is kept.

> Anything published on the source after this point is not in the package. Put busy sites in maintenance mode first.

---

## 5. Source — offer the package

1. On **Send it to the destination**, click **Offer it to the destination**.
2. You'll see: *Offered. Open Site Migrator on the destination…* Nothing is handed over until the destination fetches it.

> Changed your mind? **Take the offer back**. No pairing? Use **Other ways to deliver this** (download) or a transfer key — see [`s2-manual-transfer-key.mp4`](videos/s2-manual-transfer-key.mp4).

---

## 6. Destination — fetch the package

1. Back on the destination tab, the screen changes to **A package is waiting**.
2. Click **Start the transfer**.
3. **Fetch it from the source** shows progress. This only writes to a staging folder — the live site is unchanged.
4. When it reports all files present and checksums matching, click **See what it would do**.

> You can close the tab; the transfer resumes from the same byte when you return.

---

## 7. Destination — review before you commit

Read **Check this before you commit** top to bottom:

| Section | What to check |
|---|---|
| **Heads up / blockers** | Blockers stop the import. Heads-ups are advice. |
| **What arrives** | Source address, WordPress version, file count and size, table prefix. |
| **What this replaces** | Every post, page, comment, setting, plugin and theme is replaced. Old tables are kept for undo. |
| **Who can sign in afterwards** | Destination accounts are kept. People on both sites keep their destination password. |
| **Worth knowing** | `wp-config.php` is never written. Copy any listed settings yourself. Secrets and salts are not carried. |

When you're ready:

1. Tick **I understand that the content currently on … will be replaced.**
2. Click **Import it**.

> Not sure? **Not yet** returns to the package list with nothing changed.

---

## 8. Destination — import

The **Importing** screen runs through:

1. Checking this server
2. Restoring files
3. Loading the database alongside the live one
4. Rewriting addresses
5. Reconciling accounts
6. Checking what arrived
7. **Switching over** — one instant, reversible afterwards
8. Tidying up

Keep the tab open; closing it pauses the import and it resumes where it stopped.

---

## 9. Destination — keep or undo

On **Migration complete**:

1. Click **View the site** and check the front page, a few posts, images and signing in.
2. Decide:
   - **Keep it** — removes the old tables and frees space. **The import can no longer be undone.**
   - **Undo the import** — restores the previous database and removes the plugins and themes the package added.

> After **Undo the import** you stay signed in, as your own account on this site.

---

## Done

You'll see **Kept. The previous site's tables have been removed and the migration is finished.**

### Quick reference

| Step | Site | Button |
|---|---|---|
| 1 | Destination | Get a pairing code → Generate a pairing code |
| 2 | Source | Start export → paste address + code → Check compatibility |
| 3 | Source | Build the package |
| 4 | Source | (wait) |
| 5 | Source | Offer it to the destination |
| 6 | Destination | Start the transfer → See what it would do |
| 7 | Destination | Tick acknowledgement → Import it |
| 8 | Destination | (wait) |
| 9 | Destination | View the site → Keep it *(or Undo the import)* |

### Related videos

| File | Covers |
|---|---|
| [`00-overview.mp4`](videos/00-overview.mp4) | One-minute overview of this happy path |
| [`01-install-first-look.mp4`](videos/01-install-first-look.mp4) | Installing and the Start screen |
| [`02-full-migration.mp4`](videos/02-full-migration.mp4) | This guide, end to end |
| [`03-destination-first.mp4`](videos/03-destination-first.mp4) | Pairing code and waiting, in detail |
| [`04-choose-what-moves.mp4`](videos/04-choose-what-moves.mp4) | Partial and code-only packages |
| [`05-review-users-php.mp4`](videos/05-review-users-php.mp4) | The Review screen in detail |
| [`06-keep-rollback.mp4`](videos/06-keep-rollback.mp4) | Keep, undo and importing again |
| [`s1-large-sites-pause-resume.mp4`](videos/s1-large-sites-pause-resume.mp4) | Pause, resume, CLI export |
| [`s2-manual-transfer-key.mp4`](videos/s2-manual-transfer-key.mp4) | Delivering without pairing |
| [`s3-ftp-folder-drop-in.mp4`](videos/s3-ftp-folder-drop-in.mp4) | Dropping a package in via FTP/SFTP |
