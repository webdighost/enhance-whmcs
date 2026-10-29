#!/usr/bin/env bash
# Runs every test of the Enhance WHMCS module.
#
#   tests/run.sh               all tests except the MySQL lock test
#   ENHANCE_TEST_MYSQL=1 tests/run.sh   also the lock test (needs MySQL/MariaDB with
#                                        database whmcs_test and user t/t, or the
#                                        ENHANCE_TEST_MYSQL_* variables)
#
# Needs: PHP >= 8.1 with curl, pdo_sqlite (and pdo_mysql for the lock test),
# Composer, Python 3 and OpenSSL. Nothing here talks to a real Enhance panel.
set -uo pipefail
cd "$(dirname "$0")"
export ENHANCE_ROOT="$(cd .. && pwd)"
TMP="$PWD/.tmp"
mkdir -p "$TMP"
[ -f vendor/autoload.php ] || composer install -q --no-interaction
if [ ! -f "$TMP/cert.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$TMP/key.pem" -out "$TMP/cert.pem" -days 30 \
        -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost" 2>/dev/null
fi
export ENHANCE_TEST_CA="$TMP/cert.pem"
PHP="php -d curl.cainfo=$TMP/cert.pem -d openssl.cafile=$TMP/cert.pem"

MOCK=""; WEB=""
start_mock() { (cd "$TMP" && exec python3 "$OLDPWD/mock/enhance_mock.py") & MOCK=$!; sleep 1; }
stop_mock() { [ -n "$MOCK" ] && kill "$MOCK" 2>/dev/null; wait "$MOCK" 2>/dev/null; MOCK=""; }
cleanup() { stop_mock; [ -n "$WEB" ] && kill "$WEB" 2>/dev/null; }
trap cleanup EXIT

failed=0
run() { # name, command...
    local name="$1"; shift
    echo "== $name"
    rm -f "$TMP/tools.db" "$TMP/activity.log"
    start_mock
    out=$("$@" 2>&1); echo "$out" | grep -E "^(PASS|FAIL)|FAILED|PASSED|Fatal|Warning|Notice|Deprecated" 
    echo "$out" | grep -q "ALL PASSED" || failed=$((failed + 1))
    stop_mock
}

run "API client (mock Enhance API)"      $PHP api_test.php "$ENHANCE_ROOT"
run "Migration (Eloquent/SQLite)"        $PHP migration_test.php "$ENHANCE_ROOT"
run "Customer sync and importer paging"  $PHP sync_test.php "$ENHANCE_ROOT"
run "Client profile panel (hook)"        $PHP widget_test.php "$ENHANCE_ROOT"
run "Product options and username"       $PHP product_test.php "$ENHANCE_ROOT"
run "Admin service tab"                  $PHP admin_test.php "$ENHANCE_ROOT"
run "Client area template (Smarty)"      $PHP render_test.php "$ENHANCE_ROOT"

echo "== Client area tools (HTTP redirects)"
rm -f "$TMP/tools.db" "$TMP/activity.log"
start_mock
ENHANCE_WEB_PORT=18500 $PHP -S 127.0.0.1:18500 support/tools_router.php >/dev/null 2>"$TMP/web.log" & WEB=$!
sleep 1
out=$(./client_tools_test.sh); echo "$out"; echo "$out" | grep -q "ALL PASSED" || failed=$((failed + 1))
kill "$WEB" 2>/dev/null; WEB=""
if grep -E -i "fatal|warning|notice|deprecated" "$TMP/web.log"; then failed=$((failed + 1)); fi
stop_mock

if [ "${ENHANCE_TEST_MYSQL:-}" = "1" ]; then
    echo "== Per-client lock (MySQL)"
    out=$(./lock_test.sh); echo "$out"; echo "$out" | grep -q "ALL PASSED" || failed=$((failed + 1))
fi

echo
[ "$failed" -eq 0 ] && echo "All test groups passed." || echo "$failed test group(s) failed."
exit "$failed"
