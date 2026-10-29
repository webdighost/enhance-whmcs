#!/usr/bin/env bash
# Calls the client area tool functions through "php -S", so the real HTTP
# redirect (Location header) of each SSO can be checked. Started by run.sh.
U="http://127.0.0.1:${ENHANCE_WEB_PORT:-18500}/"
WEB=abababab-0000-4000-8000-000000000001; APP=cdcdcdcd-0000-4000-8000-000000000001
pass=0; fail=0
t(){ name="$1"; expect="$2"; shift 2; out=$(curl -s -i "$@"); if echo "$out" | grep -q -- "$expect"; then echo "PASS $name"; pass=$((pass+1)); else echo "FAIL $name :: $(echo "$out" | grep -i -E 'location|RESULT' | head -2)"; fail=$((fail+1)); fi; }
t "webmail SSO follows Enhance's 302" "Location: https://cp.test/roundcube/?_sso=abc" "${U}?fn=WebmailSso&website=$WEB&email=info@acme.pt"
t "phpMyAdmin SSO" "Location: https://cp.test/phpmyadmin/?sso=1" "${U}?fn=PhpMyAdminSso&website=$WEB&db=acme_wp"
t "WordPress SSO picks the administrator user" "Location: https://acme.pt/wp-admin/?sso=2" "${U}?fn=WordpressSso&website=$WEB&app=$APP"
t "unknown mailbox refused" "RESULT: Invalid request" "${U}?fn=WebmailSso&website=$WEB&email=root@other.pt"
t "unknown database refused" "RESULT: Invalid request" "${U}?fn=PhpMyAdminSso&website=$WEB&db=mysql"
t "website of another subscription refused" "RESULT: Invalid request" "${U}?fn=PhpMyAdminSso&website=abababab-0000-4000-8000-000000000999&db=acme_wp"
t "other client refused" "RESULT: Your user does not have permission" "${U}?fn=PhpMyAdminSso&website=$WEB&db=acme_wp&uid=10"
t "restore via GET refused" "RESULT: Invalid request" "${U}?fn=RestoreBackup&website=$WEB&backup=7"
t "restore without CSRF token refused" "RESULT: Invalid or expired security token" -d "website=$WEB&backup=7&confirm=1" "${U}?fn=RestoreBackup"
t "restore disabled in product refused" "RESULT: Backup restore is not available" -d "token=CSRF1&website=$WEB&backup=7&confirm=1" "${U}?fn=RestoreBackup&restore="
t "restore without confirmation refused" "RESULT: Confirm that" -d "token=CSRF1&website=$WEB&backup=7" "${U}?fn=RestoreBackup"
t "restore of a failed backup refused" "RESULT: Invalid request" -d "token=CSRF1&website=$WEB&backup=8&confirm=1" "${U}?fn=RestoreBackup"
t "restore of a valid backup" "RESULT: success" -d "token=CSRF1&website=$WEB&backup=7&confirm=1&include_emails=1" "${U}?fn=RestoreBackup"
t "PT message" "RESULT: O seu utilizador não tem permissão" "${U}?fn=PhpMyAdminSso&website=$WEB&db=acme_wp&uid=10&lang=portuguese-pt"
st=$(curl -s -H "Authorization: Bearer tok_GOOD&abc" --cacert "$ENHANCE_TEST_CA" https://localhost:18443/api/__state)
if echo "$st" | grep -q '"restoreFiles": true, "restoreAllEmails": true'; then echo "PASS restore body sent to Enhance"; pass=$((pass+1)); else echo "FAIL restore body :: $st"; fail=$((fail+1)); fi
[ $fail -eq 0 ] && echo "ALL PASSED" || echo "$fail FAILED"
