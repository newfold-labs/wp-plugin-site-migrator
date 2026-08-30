#!/usr/bin/env bash
#
# Provision the site, then serve it. One command, because Playwright starts `webServer` *before*
# `globalSetup` — so a global setup that builds the site runs too late to be of any use.
#
set -euo pipefail

SITE="${1:?Give me a directory}"
PORT="${NFD_E2E_PORT:-8781}"

bash "$( dirname "${BASH_SOURCE[0]}" )/provision.sh" "$SITE" >&2

exec php -d error_reporting=24575 -S "127.0.0.1:$PORT" -t "$SITE"
