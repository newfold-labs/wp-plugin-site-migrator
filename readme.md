# Site Migrator

Move a WordPress site between hosts. Install the plugin on both sites: export the source
into a package, then import that package on the destination.

> **Pre-release.** This plugin is being reworked and is not currently distributed. The
> export and import flows are under construction — see
> [`docs/implementation-plan.md`](docs/implementation-plan.md) for the phased plan and
> [`docs/code-analysis.md`](docs/code-analysis.md) for the analysis it came from.

## What it does

- **Content only.** WordPress core is never packaged — the destination already has it.
- **Resumable.** Work is driven in short steps, so it survives execution-time limits on
  shared hosting. Closing the tab and reopening it continues where it stopped.
- **Reversible.** The database is imported alongside the live one and switched over in a
  single atomic rename, so a failed import leaves the destination untouched and a completed
  one can be rolled back.
- **Users are merged, not replaced.** The destination's accounts are kept, and the source's
  accounts are merged in with their IDs preserved, so migrated content stays attributed.

## Requirements

WordPress 4.7+, PHP 5.6+ (the floor rises to 7.4 before release).

## Development

```bash
composer install
yarn install

yarn build          # generate:css + webpack -> build/
yarn start          # same, watching

composer lint       # phpcs, Newfold standard
composer fix        # phpcbf
yarn lint:js

npx wp-env start    # local WordPress at http://localhost:10004 (admin/password)
yarn test           # cypress
```

`build/` and `src/styles/nfd-site-migrator.css` are generated and not tracked; run
`yarn build` after cloning.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

Parts of this plugin derive from All-in-One WP Migration by ServMask, Inc. — see
[`CREDITS.md`](CREDITS.md).
