#!/usr/bin/env bash
#
# Stand up a real WordPress for Playwright to drive.
#
# Deliberately a real install rather than a mocked backend. The Cypress spec this replaces stubbed
# every REST route with `cy.intercept`, which meant it verified that React renders fixtures — it
# could not have caught a single defect in the plugin, and by the end it was driving element IDs
# that no longer existed anywhere in `src/`. Plan §12 item 9 asks for one unstubbed path; this is
# it.
#
#   Usage:  tests/e2e/provision.sh <directory>
#
#   Needs:  the same things tests/roundtrip.sh needs. See that script's header.
#
set -euo pipefail

SITE="${1:?Give me a directory to build the site in}"
PORT="${NFD_E2E_PORT:-8781}"
URL="http://127.0.0.1:$PORT"

PHP_BIN="${NFD_PHP_BIN:-php}"
WP_BIN="${NFD_WP_BIN:-$(command -v wp)}"

DB_HOST="${NFD_DB_HOST:-127.0.0.1}"
DB_USER="${NFD_DB_USER:-root}"
DB_PASS="${NFD_DB_PASS:-root}"
DB_NAME="${NFD_E2E_DB:-nfd_e2e}"

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"

[ -n "${NFD_MYSQL_DIR:-}" ] && export PATH="$NFD_MYSQL_DIR:$PATH"

wp() { "$PHP_BIN" -d memory_limit=1G -d error_reporting=24575 "$WP_BIN" --path="$SITE" --allow-root "$@"; }

mkdir -p "$SITE"

if [ ! -f "$SITE/wp-settings.php" ]; then
	wp core download --quiet
fi

wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
	--dbprefix=e2e_ --force --quiet
wp db reset --yes --quiet
wp core install --url="$URL" --title="Site Migrator e2e" \
	--admin_user=admin --admin_password="${NFD_E2E_PASS:-password}" --admin_email=admin@e2e.test \
	--skip-email --quiet

# Symlinked rather than copied so the test drives the working tree. `bin/verify-zip.sh` is what
# exercises the packaged copy; this is for the code as it stands.
mkdir -p "$SITE/wp-content/plugins"
ln -sfn "$ROOT" "$SITE/wp-content/plugins/nfd-site-migrator"
wp plugin activate nfd-site-migrator --quiet

echo "$URL"
