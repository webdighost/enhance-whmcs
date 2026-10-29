#!/usr/bin/env bash
# Per-client lock on a real MySQL/MariaDB (ENHANCE_TEST_MYSQL=1). Started by run.sh.
# A holds the lock for 4 s; B (1 s timeout) must be refused; C (10 s) must get it
# when A finishes; D checks the lock is released when the work throws.
cd "$(dirname "$0")"; R="$ENHANCE_ROOT"; d=$(mktemp -d)
php lock_test.php "$R" hold > "$d/a" & sleep 1
php lock_test.php "$R" try > "$d/b"
php lock_test.php "$R" wait > "$d/c"; wait
php lock_test.php "$R" throw > "$d/d"
cat "$d/a" "$d/b" "$d/c" "$d/d"
grep -q "refused after 1" "$d/b" && grep -q "C: got lock after" "$d/c" && grep -q "D: lock free again after exception" "$d/d" && echo "ALL PASSED" || echo "1 FAILED"
rm -rf "$d"
