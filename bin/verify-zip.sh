#!/usr/bin/env bash
#
# Install the built zip into a throwaway WordPress and check it actually works.
#
# Building a zip proves a zip exists. This proves WordPress accepts it, activates it, finds the
# built assets, and registers the commands — the difference between "we shipped" and "it works",
# which for this plugin has historically been a blank admin page.
#
#   Usage:  bin/build-zip.sh && bin/verify-zip.sh
#
#   Needs:  the same things tests/roundtrip.sh needs. See that script's header.
#
set -uo pipefail

PHP_BIN="${NFD_PHP_BIN:-php}"
WP_BIN="${NFD_WP_BIN:-$(command -v wp)}"

wp() { "$PHP_BIN" -d memory_limit=1G -d error_reporting=24575 "$WP_BIN" "$@"; }

[ -n "${NFD_MYSQL_DIR:-}" ] && export PATH="$NFD_MYSQL_DIR:$PATH"

DB_HOST="${NFD_DB_HOST:-127.0.0.1}"
DB_USER="${NFD_DB_USER:-root}"
DB_PASS="${NFD_DB_PASS:-root}"
DB_NAME="${NFD_SRC_DB:-nfd_src}"
URL="http://ziptest.test"
SLUG="nfd-site-migrator"

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
ZIP="${1:-$ROOT/dist/$SLUG.zip}"
WORK="$(mktemp -d)"
SITE="$WORK/site"

PASS=0
FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }
assert() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected [$2], got [$3]"; fi; }

cleanup() { [ -n "${NFD_KEEP:-}" ] || rm -rf "$WORK"; }
trap cleanup EXIT

[ -f "$ZIP" ] || { echo "No zip at $ZIP. Run bin/build-zip.sh first."; exit 1; }

printf '\n\033[1mInstalling %s into a throwaway WordPress\033[0m\n' "$(basename "$ZIP")"

mkdir -p "$SITE"
wp --path="$SITE" --allow-root core download --quiet || exit 1
wp --path="$SITE" --allow-root config create --dbname="$DB_NAME" --dbuser="$DB_USER" \
	--dbpass="$DB_PASS" --dbhost="$DB_HOST" --dbprefix=zip_ --force --quiet || exit 1
wp --path="$SITE" --allow-root db reset --yes --quiet || exit 1
wp --path="$SITE" --allow-root core install --url="$URL" --title="Zip test" \
	--admin_user=admin --admin_password=password --admin_email=admin@ziptest.test \
	--skip-email --quiet || exit 1

site() { wp --path="$SITE" --url="$URL" --allow-root "$@"; }

# The install itself. `wp plugin install` unpacks the zip exactly as the admin uploader does, so
# a zip whose entries are not under one slug-named directory fails here rather than in the wild.
site plugin install "$ZIP" --quiet
assert "the zip installs" "0" "$?"
assert "and unpacks to the slug, not the repository name" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/$SLUG.php" ] && echo yes || echo no)"

site plugin activate "$SLUG" --quiet
assert "it activates" "0" "$?"
assert "and WordPress agrees it is active" "active" "$(site plugin get "$SLUG" --field=status 2>/dev/null)"

# The header WordPress read out of the zip, not the one in the working tree.
assert "the PHP requirement travelled" "7.4" "$(site plugin get "$SLUG" --field=requires_php 2>/dev/null)"

# No dev dependencies, no test suite.
assert "the test suite did not ship" "no" \
	"$([ -e "$SITE/wp-content/plugins/$SLUG/tests" ] && echo yes || echo no)"
assert "nor did phpunit" "no" \
	"$([ -e "$SITE/wp-content/plugins/$SLUG/vendor/phpunit" ] && echo yes || echo no)"

# The built assets are the whole point: without them the admin page renders an empty div, which
# looks exactly like a working install until somebody opens it.
assert "the JS bundle is there" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/build/nfd-site-migrator.js" ] && echo yes || echo no)"
assert "so is the stylesheet" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/build/nfd-site-migrator.css" ] && echo yes || echo no)"
assert "and the fonts it references" "4" \
	"$(find "$SITE/wp-content/plugins/$SLUG/build/fonts" -name '*.woff2' 2>/dev/null | wc -l | tr -d ' ')"

# Cleanup happens on delete, and only if the file that does it shipped. Deactivation used to
# purge, which meant switching the plugin off destroyed the package; the fix moves that to
# `uninstall.php`, and a fix that does not ship is not a fix.
assert "uninstall.php shipped" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/uninstall.php" ] && echo yes || echo no)"
assert "and deactivation no longer purges" "no" \
	"$(grep -q "register_deactivation_hook( __FILE__, 'nfd_sm_purge_all' )" \
		"$SITE/wp-content/plugins/$SLUG/nfd-site-migrator.php" && echo yes || echo no)"

# Licence obligations.
assert "the GPL text shipped" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/LICENSE" ] && echo yes || echo no)"
assert "the attribution record shipped" "yes" \
	"$([ -f "$SITE/wp-content/plugins/$SLUG/CREDITS.md" ] && echo yes || echo no)"
assert "and the font licences shipped" "2" \
	"$(find "$SITE/wp-content/plugins/$SLUG/assets/fonts" -name '*LICENSE*' 2>/dev/null | wc -l | tr -d ' ')"

# It has to actually run, not merely sit there.
assert "the commands register from the installed copy" "10" \
	"$(site site-migrator 2>&1 | grep -cE '^(usage|   or): wp site-migrator')"
assert "preflight runs and reports itself ok" "True" \
	"$(site site-migrator preflight --format=json 2>/dev/null | "$PHP_BIN" -r 'echo json_decode(file_get_contents("php://stdin"),true)["local"]["ok"] ? "True" : "False";')"

# The admin page is where a broken asset path shows up, and `plugin_dir_url()` has been wrong here
# before. Assert the enqueued URL points inside the installed plugin directory.
assert "assets resolve to a URL under the plugin directory" "yes" \
	"$(site eval "echo 0 === strpos( nfd_sm_plugin_url( 'build/nfd-site-migrator.js' ), '$URL/wp-content/plugins/$SLUG/' ) ? 'yes' : 'no';" 2>/dev/null)"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
