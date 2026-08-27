#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
# The release workflow does this too, but only on a published release, which means the artefact
# nobody can test until it is too late to change. This produces the same thing on a laptop, and
# checks it afterwards -- a zip that installs and then renders a blank admin page is worse than
# no zip, because it looks like it worked.
#
#   Usage:  bin/build-zip.sh [output-directory]
#
#   Output: <output-directory>/nfd-site-migrator.zip, containing a single top-level directory
#           named for the plugin slug.
#
set -euo pipefail

# The directory inside the zip, and therefore the plugin directory once installed. It is the text
# domain and the slug, *not* the repository name -- the repository is `wp-plugin-site-migrator`,
# and a zip built from `${REPO##*/}` installs to a directory that does not match the slug. The
# plugin survives that (`nfd_sm_plugin_basename()` exists precisely because a directory is not a
# slug), but wp.org requires the two to agree and every support instruction reads better when
# the folder is called what the plugin is called.
SLUG="nfd-site-migrator"

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
BUILD="$STAGE/$SLUG"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

cd "$ROOT"

# --------------------------------------------------------------------------------------------
say "Building assets"

# `build/` is generated and untracked, so a zip made from a fresh checkout without this step
# ships a plugin whose admin page is an empty div. Built here rather than assumed.
npm run generate:css >/dev/null
npx wp-scripts build ./src/nfd-site-migrator.js >/dev/null

[ -f "$ROOT/build/nfd-site-migrator.js" ] || die "The JS bundle was not produced."
[ -f "$ROOT/build/nfd-site-migrator.asset.php" ] || die "The asset manifest was not produced."

# --------------------------------------------------------------------------------------------
say "Staging into $SLUG/"

mkdir -p "$BUILD"
rsync -r --exclude-from="$ROOT/.distignore" "$ROOT/." "$BUILD/"

# Composer is installed *into the stage*, so the working tree keeps its dev dependencies --
# running `--no-dev` in place would delete the test suite the moment somebody built a zip. The
# manifests are copied in for that and removed again, because `.distignore` rightly excludes them
# from the result while `composer install` cannot run without them.
say "Installing production dependencies"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$BUILD/"
rm -rf "$BUILD/vendor"
composer install --working-dir="$BUILD" --no-dev --optimize-autoloader --no-progress --quiet
rm -f "$BUILD/composer.json" "$BUILD/composer.lock"

# --------------------------------------------------------------------------------------------
say "Checking what is about to ship"

# Everything the plugin needs at runtime. Each of these has a reason:
required=(
	"$SLUG.php"                                 # the bootstrap, and the header WordPress reads
	"constants.php"
	"functions.php"
	"includes/Core/Export/Exporter.php"         # a representative of the PSR-4 tree
	"includes/Cli/Commands.php"
	"vendor/autoload.php"                       # PSR-4 for includes/ comes from here
	"build/nfd-site-migrator.js"                # without these the admin page is an empty div
	"build/nfd-site-migrator.css"
	"build/nfd-site-migrator.asset.php"
	"build/fonts"                               # bundled, never hot-linked
	"assets/fonts/public-sans-LICENSE.txt"      # OFL: a font ships with its licence
	"assets/fonts/jetbrains-mono-LICENSE.txt"
	"LICENSE"                                   # GPL: the licence text has to travel
	"CREDITS.md"                                # GPL: and so does the attribution record
)

for path in "${required[@]}"; do
	[ -e "$BUILD/$path" ] || die "Missing from the build: $path"
done

# And things that must not. Shipping the test suite or the working notes is untidy; shipping
# node_modules or the .git directory is a different order of mistake.
forbidden=(
	".git" ".github" ".vscode" "node_modules" "src" "tests" "cypress"
	"phpunit.xml.dist" ".phpunit.result.cache" "cypress.config.js" "tailwind.config.js"
	"composer.json" "composer.lock" "CLAUDE.md" "README.md" "assets/styles"
	"vendor/phpunit" "vendor/squizlabs"
)

for path in "${forbidden[@]}"; do
	[ ! -e "$BUILD/$path" ] || die "Should not be in the build: $path"
done

# The header is what WordPress reads to decide whether it can run this at all, and it has drifted
# from the runtime check before.
grep -q "Requires PHP:      7.4" "$BUILD/$SLUG.php" || die "The PHP requirement is not what was expected."
grep -q "Requires at least: 5.8" "$BUILD/$SLUG.php" || die "The WordPress requirement is not what was expected."

# --------------------------------------------------------------------------------------------
say "Zipping"

mkdir -p "$OUT"
rm -f "$OUT/$SLUG.zip"
( cd "$STAGE" && zip -qr "$OUT/$SLUG.zip" "$SLUG" )

# A zip whose entries do not all sit under one directory named for the slug unpacks as loose
# files into wp-content/plugins, which is a mess to undo by hand.
stray=$( unzip -Z1 "$OUT/$SLUG.zip" | grep -cv "^$SLUG/" || true )
[ "$stray" -eq 0 ] || die "$stray entries are not under $SLUG/."

printf '\n\033[1m%s\033[0m\n' "$OUT/$SLUG.zip"
printf '  %s files, %s\n' \
	"$( unzip -Z1 "$OUT/$SLUG.zip" | wc -l | tr -d ' ' )" \
	"$( du -h "$OUT/$SLUG.zip" | cut -f1 )"
