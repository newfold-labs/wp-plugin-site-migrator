#!/usr/bin/env bash
#
# Provision the site, then serve it. One command, because Playwright starts `webServer` *before*
# `globalSetup` — so a global setup that builds the site runs too late to be of any use.
#
set -euo pipefail

SITE="${1:?Give me a directory}"
PORT="${NFD_E2E_PORT:-8781}"

bash "$( dirname "${BASH_SOURCE[0]}" )/provision.sh" "$SITE" >&2

# PHP's built-in server is single-threaded by default, and the admin app fires several REST
# requests at once -- one slow one would block the rest and a test would time out for a reason
# that has nothing to do with the plugin. Workers are supported since PHP 7.4.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec php -d error_reporting=24575 -S "127.0.0.1:$PORT" -t "$SITE"
