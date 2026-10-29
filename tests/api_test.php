<?php
// Minimal WHMCS stubs.
namespace WHMCS\Database {
    class FakeQuery {
        public function __call($n, $a) {
            switch ($n) {
                case 'value': return null;
                case 'exists': return false;
                case 'first': return null;
                case 'get': return new \ArrayObject([]);
                case 'insertGetId': return 7;
                case 'insert': case 'update': case 'delete': return true;
                case 'pluck': return new class { function all() { return []; } };
            }
            return $this;
        }
    }
    class Capsule {
        public static function table($t) { return new FakeQuery(); }
        public static function schema() { return new class { function hasTable($t) { return true; } }; }
    }
}
namespace {
define('WHMCS', true);
$GLOBALS['modlog'] = [];
function logModuleCall($m, $a, $req, $resp, $proc, $mask) { $GLOBALS['modlog'][] = compact('a', 'mask'); }
function logActivity($m) {}
require $argv[1] . '/modules/servers/enhance/EnhanceApi.php';

$MASTER = '11111111-2222-4333-8444-555555555555';
$CUST = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$fails = 0;
function check($name, $cond, $extra = '') { global $fails; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond ? '' : ' :: ' . $extra) . "\n"; if (!$cond) $fails++; }

EnhanceLang::reset('english');
// Normalisation
check('token: html entity decoded', EnhanceApi::normaliseToken('tok_GOOD&amp;abc') === 'tok_GOOD&abc');
check('token: bearer prefix + newline', EnhanceApi::normaliseToken("Bearer tok_GOOD\n&amp;abc ") === 'tok_GOOD&abc');
check('host: scheme/path stripped', EnhanceApi::normaliseHost('https://CP.Example.com/admin/') === 'cp.example.com');
check('org: braces/case', EnhanceApi::normaliseOrgId(' {' . strtoupper($MASTER) . '} ') === $MASTER);

$p = ['serverhostname' => 'https://localhost:18443/', 'serverusername' => $MASTER, 'serveraccesshash' => "Bearer tok_GOOD&amp;\nabc", 'serverpassword' => '', 'serverid' => 3];
$api = EnhanceApi::fromParams($p);
$t = $api->testConnection();
check('test connection OK with WHMCS-encoded token', $t['success'] === true, json_encode($t));

$plans = $api->getPlans();
check('pagination collects 5 plans', count($plans['items'] ?? []) === 5, json_encode($plans));

$bad = EnhanceApi::fromParams(['serveraccesshash' => 'wrong'] + $p);
$t = $bad->testConnection();
check('401 explained', !$t['success'] && strpos($t['error'], 'HTTP 401') !== false && strpos($t['error'], 'Access Hash') !== false, $t['error']);
echo "   -> " . $t['error'] . "\n";

$alt = EnhanceApi::fromParams(['serveraccesshash' => 'stale', 'serverpassword' => 'tok_GOOD&abc'] + $p);
$t = $alt->testConnection();
check('token found in the other field', !$t['success'] && strpos($t['error'], '"Password"') !== false, $t['error']);
echo "   -> " . $t['error'] . "\n";

$pw = EnhanceApi::fromParams(['serveraccesshash' => '', 'serverpassword' => 'tok_GOOD&abc'] + $p);
check('Password field used when Access Hash empty', $pw->testConnection()['success'] === true);

$wrongOrg = EnhanceApi::fromParams(['serverusername' => '00000000-0000-4000-8000-000000000000'] + $p);
$t = $wrongOrg->testConnection();
check('404 org explained', !$t['success'] && strpos($t['error'], 'HTTP 404') !== false, $t['error']);

$html = EnhanceApi::fromParams(['serverhostname' => 'localhost:18444'] + $p);
$t = $html->testConnection();
check('HTML page detected as not the API', !$t['success'] && strpos($t['error'], 'did not answer as an Enhance') !== false, $t['error']);

$redir = EnhanceApi::fromParams(['serverhostname' => 'localhost:18445'] + $p);
$t = $redir->testConnection();
check('redirect reported (was a false success in 1.2.1)', !$t['success'] && strpos($t['error'], 'cp.real.test') !== false, $t['error']);

$dns = EnhanceApi::fromParams(['serverhostname' => 'nonexistent.invalid'] + $p);
$t = $dns->testConnection();
check('DNS failure explained', !$t['success'] && strpos($t['error'], 'cURL') !== false, $t['error']);
$ver = array_values(array_filter(json_decode(file_get_contents('https://localhost:18443/api/__log', false, stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]])), true), fn($r) => $r[1] === '/api/version'));
check('/version called without the token', $ver && $ver[0][2] === null, json_encode($ver));

try { EnhanceApi::fromParams(['serverusername' => 'admin'] + $p); check('invalid org rejected', false); }
catch (Exception $e) { check('invalid org rejected', strpos($e->getMessage(), 'UUID') !== false); }

$sso = $api->ownerSsoUrl($CUST);
check('SSO URL from JSON string, owner without isActive', $sso['url'] === 'https://cp.test/sso?token=xyz', json_encode($sso));
check('bandwidth numeric JSON', $api->getSubscriptionBandwidth($CUST, '5') === 123456);
$calc = $api->calculateResourceUsage($CUST, '5');
check('PUT without body succeeds (204)', !EnhanceApi::isError($calc), json_encode($calc));

$rec = $api->triggerPasswordRecovery('client@example.com');
check('password recovery uses PUT and succeeds', !EnhanceApi::isError($rec), json_encode($rec));

$own = $api->linkMemberAsOwner('cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'eeeeeeee-1111-4222-8333-444444444444');
check('stuck org: SuperAdmin member recreated as Owner (real Enhance behaviour)', !EnhanceApi::isError($own), json_encode($own));
$log2 = json_decode(file_get_contents('https://localhost:18443/api/__log', false, stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]])), true);
$seq = array_map(fn($r) => $r[0] . ' ' . preg_replace('#^/api/orgs/[^/]+#', '', explode('?', $r[1])[0]), array_values(array_filter($log2, fn($r) => strpos($r[1], 'cccccccc') !== false && $r[0] !== 'GET')));
echo '   sequence: ' . implode(' -> ', $seq) . "\n";
check('never tries to put Owner via member update', !in_array('PUT /members/dddddddd-0000-4000-8000-000000000099', $seq, true));
$own2 = $api->linkMemberAsOwner('cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'eeeeeeee-1111-4222-8333-444444444444');
check('retry is idempotent (already owner)', !EnhanceApi::isError($own2), json_encode($own2));
$own3 = $api->linkMemberAsOwner('cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'ffffffff-1111-4222-8333-444444444444');
check('existing owner is never replaced automatically', EnhanceApi::isError($own3) && strpos($own3['detail'], 'never changed automatically') !== false, json_encode($own3));

$ctxR = stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]]);
$mark = json_decode(file_get_contents('https://localhost:18443/api/__reset', false, $ctxR), true)['mark'];
$new = $api->linkMemberAsOwner('cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'eeeeeeee-1111-4222-8333-444444444444');
$log3 = array_slice(json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctxR), true), $mark);
$seq3 = array_map(fn($r) => $r[0] . ' ' . preg_replace('#^/api/orgs/[^/]+#', '', explode('?', $r[1])[0]), array_values(array_filter($log3, fn($r) => strpos($r[1], 'cccccccc') !== false && $r[0] !== 'GET')));
echo '   new org sequence: ' . implode(' -> ', $seq3) . "\n";
check('new org: owner created directly, like the official module', !EnhanceApi::isError($new) && $seq3 === ['POST /members'], json_encode($new));

// Token masked in module log
$allMasked = true;
foreach ($GLOBALS['modlog'] as $l) if ($l['a'] !== 'GET /version' && !in_array('tok_GOOD&abc', $l['mask'], true) && !in_array('wrong', $l['mask'], true) && !in_array('stale', $l['mask'], true)) $allMasked = false;
check('token always masked in module log', $allMasked);

// Server-side view of the requests
$ctx = stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]]);
$log = json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true);
$put = array_values(array_filter($log, fn($r) => $r[0] === 'PUT'));
check('PUT sent Content-Length: 0', ($put[0][3] ?? null) === '0', json_encode($put));
$auth = array_unique(array_column(array_filter($log, fn($r) => $r[1] === '/api/orgs/' . $MASTER), 2));
check('Authorization header is exactly "Bearer <token>"', in_array('Bearer tok_GOOD&abc', $auth, true), json_encode($auth));

// Translations
EnhanceLang::reset('portuguese-pt');
$t = $bad->testConnection();
check('PT message', strpos($t['error'], 'O Enhance recusou o token') !== false, $t['error']);
echo "   -> " . $t['error'] . "\n";
EnhanceLang::reset('portuguese-br');
check('portuguese-br maps to pt-PT', EnhanceLang::current() === 'portuguese-pt');
EnhanceLang::reset('german');
check('unknown language falls back to English', EnhanceLang::current() === 'english');
check('placeholders', EnhanceLang::t('store.org_taken', ['org' => 'X', 'client' => 5]) === 'Organisation X is already linked to WHMCS client #5.');
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
}
