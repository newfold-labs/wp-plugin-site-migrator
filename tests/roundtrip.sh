#!/usr/bin/env bash
#
# The test that matters: a real migration between two real WordPress installs.
#
# The unit suite runs against a bootstrap that fakes WordPress, which is fast and proves the
# arithmetic. It cannot prove that a site arrives. Everything this plugin exists to do -- the
# atomic swap, the users merge, URLs rewritten through serialized data, a package that survives
# being carried over HTTP -- needs two installs and a database, so that is what this does.
#
# Every defect found in this plugin since the rework began was found by running it against real
# sites. None came from review.
#
#   Usage:  tests/roundtrip.sh
#
#   Needs:  wp-cli, a MySQL/MariaDB server *and its `mysql` client on PATH*, PHP with the zip
#           extension, and permission to create two databases. Configure with the environment
#           variables below; the defaults suit CI, where all of that is already true.
#
#           On macOS the client is usually the missing piece -- `wp db` shells out to `mysql`,
#           which Homebrew PHP does not bring with it. Point NFD_MYSQL_DIR at a directory
#           containing one if it is not already on PATH.
#
#   Exits:  0 every assertion held, 1 something did not.
#
set -uo pipefail

# WP-CLI is invoked through PHP explicitly rather than by its own shebang, because two of PHP's
# defaults get in the way and `WP_CLI_PHP_ARGS` only reaches WP-CLI's *bash* wrapper -- a
# package-manager install is usually a plain PHP file, which ignores it. Extracting the core
# tarball needs more than the usual 128M, and wp-cli emits a wall of deprecation notices on PHP
# 8.4+ that buries the output. 24575 is E_ALL & ~E_DEPRECATED.
PHP_BIN="${NFD_PHP_BIN:-php}"
WP_BIN="${NFD_WP_BIN:-$(command -v wp)}"

if [ -z "$WP_BIN" ]; then
	echo "wp-cli is not on PATH. Set NFD_WP_BIN to point at it."
	exit 1
fi

wp() {
	"$PHP_BIN" -d memory_limit=1G -d error_reporting=24575 "$WP_BIN" "$@"
}

# `wp db reset` and `wp db query` both shell out to the `mysql` client.
if [ -n "${NFD_MYSQL_DIR:-}" ]; then
	PATH="$NFD_MYSQL_DIR:$PATH"
	export PATH
fi

if ! command -v mysql >/dev/null 2>&1; then
	echo "The mysql client is not on PATH; wp db needs it. Set NFD_MYSQL_DIR."
	exit 1
fi

DB_HOST="${NFD_DB_HOST:-127.0.0.1}"
DB_USER="${NFD_DB_USER:-root}"
DB_PASS="${NFD_DB_PASS:-root}"
SRC_DB="${NFD_SRC_DB:-nfd_src}"
DST_DB="${NFD_DST_DB:-nfd_dst}"
SRC_URL="${NFD_SRC_URL:-http://source.test}"
DST_URL="${NFD_DST_URL:-http://dest.test}"

# Different prefixes on each side on purpose. A migration that only works when both sites use
# `wp_` is a migration that works on a test rig and nowhere else.
ESC_SRC="${SRC_URL//\//\\/}"
ESC_DST="${DST_URL//\//\\/}"

SRC_PREFIX="${NFD_SRC_PREFIX:-wp_}"
DST_PREFIX="${NFD_DST_PREFIX:-dst_}"

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
WORK="${NFD_WORK:-$(mktemp -d)}"
SRC="$WORK/source"
DST="$WORK/dest"
PKG="$WORK/package"
CORE="$WORK/core"

PASS=0
FAIL=0

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

# assert <description> <expected> <actual>
assert() {
	if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected [$2], got [$3]"; fi
}

assert_contains() {
	case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 — [$3] does not contain [$2]" ;; esac
}

src() { wp --path="$SRC" --url="$SRC_URL" --allow-root "$@"; }
dst() { wp --path="$DST" --url="$DST_URL" --allow-root "$@"; }

# Counts posts whose content contains a literal string. The needle is bound through `prepare()`
# rather than pasted into a LIKE, because MySQL's LIKE treats a backslash as an escape -- so a
# pattern for the escaped URL `http:\/\/site` silently matches the plain one too, and an
# assertion written that way passes whatever the code does.
posts_containing() {
	local site="$1" needle="$2"

	NFD_NEEDLE="$needle" "$site" eval \
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE INSTR( post_content, %s ) > 0", getenv( "NFD_NEEDLE" ) ) );' \
		2>/dev/null
}



cleanup() { [ -n "${NFD_KEEP:-}" ] || rm -rf "$WORK"; }
trap cleanup EXIT

# --------------------------------------------------------------------------------------------
say "Provisioning two installs in $WORK"

# Taken as four arguments rather than a delimited string on purpose: the first version split the
# fields on ':' and the URLs contain '://', so every destination silently became a site at "http"
# with the rest of the line as its table prefix.
provision() {
	local dir="$1" db="$2" url="$3" prefix="$4"

	mkdir -p "$dir" || return 1

	# Downloaded once and copied for the second site. Two fetches double the exposure to a
	# wordpress.org hiccup, and a suite that fails for reasons unrelated to the code is a suite
	# people learn to ignore.
	if [ ! -f "$CORE/wp-settings.php" ]; then
		mkdir -p "$CORE"
		local try=1
		until wp --path="$CORE" --allow-root core download --quiet; do
			try=$((try+1))
			[ "$try" -gt 3 ] && { echo "Could not download WordPress after 3 attempts."; return 1; }
			sleep 3
		done
	fi

	cp -R "$CORE/." "$dir/" || return 1
	wp --path="$dir" --allow-root config create \
		--dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
		--dbprefix="$prefix" --force --quiet || return 1
	wp --path="$dir" --allow-root db reset --yes --quiet || return 1
	wp --path="$dir" --allow-root core install \
		--url="$url" --title="$(basename "$dir")" \
		--admin_user=admin --admin_password=password --admin_email="admin@$(basename "$dir").test" \
		--skip-email --quiet || return 1
	mkdir -p "$dir/wp-content/plugins"
	ln -sfn "$ROOT" "$dir/wp-content/plugins/nfd-site-migrator"
	wp --path="$dir" --url="$url" --allow-root plugin activate nfd-site-migrator --quiet || return 1
}

# Provisioning is not one of the things under test, so a failure here is fatal rather than a
# recorded assertion: every later result would be meaningless.
provision "$SRC" "$SRC_DB" "$SRC_URL" "$SRC_PREFIX" || { echo "Could not provision the source."; exit 1; }
provision "$DST" "$DST_DB" "$DST_URL" "$DST_PREFIX" || { echo "Could not provision the destination."; exit 1; }

# --------------------------------------------------------------------------------------------
say "Seeding the source with things that break naive migrations"

# A serialized option holding the site's own URL. Getting this wrong is the classic migration
# failure: a byte-length prefix that no longer matches makes unserialize() refuse the whole value.
# Stored as a structure and left to WordPress to serialize, which is how a real plugin does it.
# Handing `wp option add` an already-serialized *string* does not reproduce this: `maybe_serialize()`
# deliberately serializes anything that already looks serialized, so it comes back as a string and
# the test measures the wrong thing.
src option add nfd_test_serialized \
	"{\"home\":\"$SRC_URL\",\"nested\":{\"link\":\"$SRC_URL/page\"}}" \
	--format=json --quiet

# Guard the fixture. If the option did not go in as a nested structure holding the source URL,
# every assertion about serialized data below would pass or fail for reasons of its own.
if [ "$(src eval 'echo (string) nfd_sm_data_get( get_option( "nfd_test_serialized" ), "nested.link", "" );')" != "$SRC_URL/page" ]; then
	echo "The serialized fixture did not store as expected; the test itself is broken."
	exit 1
fi

# `--post_author` is given explicitly. WP-CLI has no logged-in user, so `wp post create` leaves
# post_author at 0 -- content attributed to nobody, which a migration then carries across
# faithfully and which would make the authorship assertion below pass for the wrong reason.
SRC_ADMIN=$(src user get admin --field=ID)

src post create --post_title="Hello from the source" --post_status=publish --post_name=hello --post_author="$SRC_ADMIN" --quiet
src post create --post_title="Second post" --post_status=publish --post_name=second --post_author="$SRC_ADMIN" --quiet
src post create --post_title="A page" --post_type=page --post_status=publish --post_name=about --post_author="$SRC_ADMIN" --quiet

# A block whose attributes hold the site's own URL with escaped slashes, which is how Gutenberg
# and any JSON-encoded meta store one. The plain form never matches these, so they need their own
# replacement pair -- and without this fixture the suite cannot tell whether that pair exists.
src post create --post_title="A block post" --post_status=publish --post_name=blocky \
	--post_author="$SRC_ADMIN" \
	--post_content="<!-- wp:image {\"url\":\"${SRC_URL//\//\\/}\/img.png\",\"id\":7} --><figure><img src=\"$SRC_URL/img.png\"/></figure><!-- /wp:image -->" \
	--quiet

# And guard it: if the escaped form did not survive being written, the assertion for it later
# would pass without ever having had anything to find.
if [ "$(posts_containing src "$ESC_SRC")" = "0" ]; then
	echo "The escaped-slash fixture did not store as expected; the test itself is broken."
	exit 1
fi

# An upload, so the files half has something to carry and checksum.
mkdir -p "$SRC/wp-content/uploads/2026/01"
head -c 65536 /dev/urandom > "$SRC/wp-content/uploads/2026/01/asset.bin"
UPLOAD_SUM=$(shasum -a 256 "$SRC/wp-content/uploads/2026/01/asset.bin" | cut -d' ' -f1)

# And enough small files that a one-second slice cannot swallow the whole import in a single
# process. Packaging is bound by the first read of each file, so file *count* is what makes a run
# take more than one step -- size would not.
mkdir -p "$SRC/wp-content/uploads/bulk"
for i in $(seq 1 "${NFD_BULK_FILES:-1200}"); do
	printf 'file %s\n' "$i" > "$SRC/wp-content/uploads/bulk/f$i.txt"
done

# A user who exists on both sides under the same login, and one unique to the source. The merge
# has to keep the destination's password for the shared account and carry the other across.
src user create shared shared@example.com --role=editor --user_pass=sourcepass --quiet
src user create onlysource only@source.test --role=author --user_pass=sourcepass --quiet
dst user create shared shared@example.com --role=subscriber --user_pass=destpass --quiet
dst user create onlydest only@dest.test --role=editor --user_pass=destpass --quiet

SRC_POSTS=$(src post list --post_type=post --format=count)
DST_USER_ID=$(dst user get shared --field=ID)

# One more guard on the fixture rather than on the plugin: if the source's own posts are
# unattributed there is nothing for the merge to preserve, and every authorship assertion below
# would be vacuous.
if [ "$(src db query "SELECT COUNT(*) FROM ${SRC_PREFIX}posts WHERE post_type = 'post' AND post_author = 0" --skip-column-names 2>/dev/null | tr -d '[:space:]')" != "0" ]; then
	echo "The source fixture has posts with no author; the test itself is broken."
	exit 1
fi

# --------------------------------------------------------------------------------------------
say "Preflight"

src site-migrator preflight --format=json >"$WORK/preflight.json" 2>/dev/null
assert "preflight exits 0 on a healthy site" "0" "$?"
assert "preflight reports itself ok" "True" \
	"$(python3 -c "import json;print(json.load(open('$WORK/preflight.json'))['local']['ok'])")"
assert "preflight json carries a schema" "1" \
	"$(python3 -c "import json;print(json.load(open('$WORK/preflight.json'))['schema'])")"

# --------------------------------------------------------------------------------------------
say "Export"

src site-migrator export --to="$PKG" >/dev/null 2>&1
assert "export exits 0" "0" "$?"
assert "the manifest is written last, and is there" "yes" "$([ -f "$PKG/manifest.json" ] && echo yes || echo no)"

# The dump must contain exactly one trailer. Two means a previous run's tail survived, which is
# how a package once verified perfectly and then failed on import with `ERT INTO`.
assert "the database dump has exactly one trailer" "1" \
	"$(grep -c -- '-- Dump complete.' "$PKG/database.sql")"
assert "and no truncated INSERT from an older dump" "0" \
	"$(grep -c '^ERT INTO' "$PKG/database.sql")"

src site-migrator verify "$PKG" >/dev/null 2>&1
assert "verify exits 0 on a good package" "0" "$?"

src site-migrator inspect "$PKG" --format=json >"$WORK/inspect.json" 2>/dev/null
assert "inspect names the source" "$SRC_URL" \
	"$(python3 -c "import json;print(json.load(open('$WORK/inspect.json'))['source']['site_url'])")"

# --------------------------------------------------------------------------------------------
say "Fault injection: a package that does not match its manifest"

cp -R "$PKG" "$PKG-damaged"
printf 'corruption' >> "$PKG-damaged/database.sql"
dst site-migrator verify "$PKG-damaged" >/dev/null 2>&1
assert "verify exits 4 on a damaged package" "4" "$?"

dst site-migrator import "$PKG-damaged" --yes >/dev/null 2>&1
assert "import refuses a damaged package" "4" "$?"
assert "and the destination is untouched" "$DST_URL" "$(dst option get siteurl)"

# --------------------------------------------------------------------------------------------
say "Import"

BEFORE_TITLE=$(dst option get blogname)
BEFORE_POSTS=$(dst post list --post_type=post --format=count)

dst site-migrator import "$PKG" --yes >/dev/null 2>&1
assert "import exits 0" "0" "$?"

# --------------------------------------------------------------------------------------------
say "The site actually moved"

assert "the destination now serves the source's posts" "$SRC_POSTS" "$(dst post list --post_type=post --format=count)"
assert "the title came across" "source" "$(dst option get blogname)"
assert "but the destination keeps its own URL" "$DST_URL" "$(dst option get siteurl)"
assert "and its own home" "$DST_URL" "$(dst option get home)"

# The whole point of the search-replace: this has to unserialize, which it cannot do if a byte
# length was left describing the old URL.
# `get_option()` unserializes, so a value that survived comes back as an array. A byte length left
# describing the old URL makes unserialize() refuse the whole thing and this returns a string.
assert "the serialized option is still an array" "array" \
	"$(dst eval 'echo gettype( get_option( "nfd_test_serialized" ) );')"
assert "its top-level URL was rewritten" "$DST_URL" \
	"$(dst eval 'echo (string) nfd_sm_data_get( get_option( "nfd_test_serialized" ), "home", "" );')"
assert "and so was the one nested inside it" "$DST_URL/page" \
	"$(dst eval 'echo (string) nfd_sm_data_get( get_option( "nfd_test_serialized" ), "nested.link", "" );')"

assert "the upload arrived byte for byte" "$UPLOAD_SUM" \
	"$(shasum -a 256 "$DST/wp-content/uploads/2026/01/asset.bin" | cut -d' ' -f1)"

assert "post content mentions the destination, not the source" "0" "$(posts_containing dst "$SRC_URL")"

# The escaped form specifically. A migration can rewrite every plain URL and still leave every
# Gutenberg block pointing at the old site.
assert "and no block attribute still holds the escaped source URL" "0" "$(posts_containing dst "$ESC_SRC")"
assert "the escaped destination URL is there instead" "1" "$(posts_containing dst "$ESC_DST")"

# --------------------------------------------------------------------------------------------
say "Users were merged, not replaced"

assert "the destination's own account survived" "onlydest" "$(dst user get onlydest --field=user_login 2>/dev/null)"
assert "the source's account came across" "onlysource" "$(dst user get onlysource --field=user_login 2>/dev/null)"
assert "the shared account kept the destination's id" "$DST_USER_ID" "$(dst user get shared --field=ID)"
assert "and the destination's password still works" "1" \
	"$(dst eval "echo wp_check_password( 'destpass', get_userdata( $DST_USER_ID )->user_pass, $DST_USER_ID ) ? 1 : 0;")"
ORPHANS=$(dst db query "SELECT COUNT(*) FROM ${DST_PREFIX}posts p LEFT JOIN ${DST_PREFIX}users u ON p.post_author = u.ID WHERE p.post_type = 'post' AND u.ID IS NULL" --skip-column-names 2>/dev/null | tr -d '[:space:]')
assert "every post still has an author that exists" "0" "$ORPHANS"

if [ "$ORPHANS" != "0" ]; then
	printf '       authors that do not resolve:\n'
	dst db query "SELECT p.ID, p.post_title, p.post_author FROM ${DST_PREFIX}posts p LEFT JOIN ${DST_PREFIX}users u ON p.post_author = u.ID WHERE p.post_type = 'post' AND u.ID IS NULL" 2>/dev/null | sed 's/^/         /'
	printf '       users present:\n'
	dst db query "SELECT ID, user_login FROM ${DST_PREFIX}users ORDER BY ID" 2>/dev/null | sed 's/^/         /'
fi

# --------------------------------------------------------------------------------------------
say "Rollback puts it back"

dst site-migrator rollback --yes >/dev/null 2>&1
assert "rollback exits 0" "0" "$?"
assert "the destination's title is its own again" "$BEFORE_TITLE" "$(dst option get blogname)"
assert "the destination's own posts are back" "$BEFORE_POSTS" "$(dst post list --post_type=post --format=count)"
assert "the destination's own user is still there" "onlydest" "$(dst user get onlydest --field=user_login 2>/dev/null)"
assert "and rolling back twice is refused rather than repeated" "1" \
	"$(dst site-migrator rollback --yes >/dev/null 2>&1; echo $?)"

# --------------------------------------------------------------------------------------------
say "Resuming: the same import driven one step per process"

# A loop inside one process proves the loop works. It does not prove that resuming works, because
# every checkpoint is still sitting in memory. Thirteen separate invocations do.
# `$?` has to be captured into a variable on the very next line: `STEPS=1` is itself a command
# whose success overwrites it, so testing `$?` in the loop condition below was reading the
# assignment rather than the import, and the loop ran zero times.
dst site-migrator import "$PKG" --yes --restart --max-time=1 >/dev/null 2>&1
LAST=$?
STEPS=1

while [ "$LAST" -eq 3 ] && [ "$STEPS" -lt 120 ]; do
	dst site-migrator import "$PKG" --yes --max-time=1 >/dev/null 2>&1
	LAST=$?
	STEPS=$((STEPS+1))
done

assert "a sliced import finishes with 0" "0" "$LAST"
assert "and it took more than one process to do it" "yes" "$([ "$STEPS" -gt 1 ] && echo yes || echo no)"
assert "the sliced import moved the site too" "$SRC_POSTS" "$(dst post list --post_type=post --format=count)"

dst site-migrator confirm --yes >/dev/null 2>&1
assert "confirm exits 0" "0" "$?"

# --------------------------------------------------------------------------------------------
printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
