#!/usr/bin/env bash
#
# Provision a WordPress and serve it, for machines without Docker.
#
# The suite's normal server is wp-env, which is what CI uses and what the other plugins in this
# org use. This is the fallback, selected with `NFD_E2E_SERVER=builtin` — it needs only `wp`, a
# database and PHP.
#
# It has one property wp-env does not: PHP's built-in server ignores `.htaccess`, exactly as nginx
# does. That is the only way `Checker::check_storage_reachable()` gets exercised the way it behaves
# on a real nginx host, where the package directory is *not* protected by the rules the plugin
# writes.
#
set -euo pipefail

HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
SITE="${NFD_E2E_SITE:-${TMPDIR:-/tmp}/nfd-sm-e2e/site}"
PORT="${NFD_E2E_PORT:-8888}"

bash "$HERE/provision.sh" "$SITE" >&2

# Single-threaded by default, and the admin app fires several REST requests at once — one slow
# response would block the others and a test would time out for a reason unrelated to the plugin.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec php -d error_reporting=24575 -S "127.0.0.1:$PORT" -t "$SITE"
