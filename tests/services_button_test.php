<?php
// Buttons in the client's services list (clientarea.php?action=services): hook output and permissions.
// With Playwright available, also checks the placement in a page shaped like the WHMCS services table.
namespace WHMCS\Authentication {
    // WHMCS 8 user/client authentication, driven by the test.
    class CurrentUser
    {
        public static $user = null;
        public static $client = null;
        public function user() { return self::$user; }
        public function client() { return self::$client; }
    }
}

namespace {
require __DIR__ . '/support/setting_stub.php';
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
require __DIR__ . '/support/whmcs_boot.php';
use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

$GLOBALS['HOOKS'] = [];
if (!function_exists('add_hook')) {
    function add_hook($name, $priority, $fn) { $GLOBALS['HOOKS'][$name] = $fn; }
}
require $argv[1] . '/includes/hooks/enhance_services.php';
EnhanceLang::reset('portuguese-pt');
EnhanceSettings::reset();
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };

$s = Capsule::schema();
if (!$s->hasTable('tblusers_clients')) {
    $s->create('tblusers_clients', function ($t) { $t->increments('id'); $t->integer('auth_user_id'); $t->integer('client_id'); $t->integer('owner')->default(0); $t->text('permissions')->nullable(); });
}
Capsule::table('tblproducts')->where('id', 11)->delete();
Capsule::table('tblhosting')->whereIn('id', [51, 52, 53, 60, 70])->delete();
Capsule::table('tblproducts')->insert(['id' => 11, 'servertype' => 'cpanel']);
Capsule::table('tblhosting')->insert(['id' => 51, 'userid' => 9, 'packageid' => 10, 'server' => 1, 'domain' => 'Suspenso.pt', 'domainstatus' => 'Suspended']);
Capsule::table('tblhosting')->insert(['id' => 52, 'userid' => 9, 'packageid' => 11, 'server' => 1, 'domain' => 'outro-modulo.pt']);
Capsule::table('tblhosting')->insert(['id' => 53, 'userid' => 9, 'packageid' => 10, 'server' => 1, 'domain' => 'cancelado.pt', 'domainstatus' => 'Cancelled']);
Capsule::table('tblhosting')->insert(['id' => 60, 'userid' => 77, 'packageid' => 10, 'server' => 1, 'domain' => 'de-outro-cliente.pt']);
Capsule::table('tblusers_clients')->insert([
    ['auth_user_id' => 1, 'client_id' => 9, 'owner' => 1, 'permissions' => ''],
    ['auth_user_id' => 2, 'client_id' => 9, 'owner' => 0, 'permissions' => 'profile,products,productsso'],
    ['auth_user_id' => 3, 'client_id' => 9, 'owner' => 0, 'permissions' => 'profile,products'],
]);
$as = function (int $userId, int $clientId) { CurrentUser::$user = (object) ['id' => $userId]; CurrentUser::$client = (object) ['id' => $clientId]; };
$hook = $GLOBALS['HOOKS']['ClientAreaFooterOutput'] ?? null;
$data = function (string $out) { return preg_match('/var services = (\{.*?\}), text/s', $out, $m) ? json_decode($m[1], true) : null; };

$check('hook registered on ClientAreaFooterOutput', is_callable($hook));
$as(1, 9);
$out = $hook(['templatefile' => 'clientareaproducts']);
$d = $data($out);
$check('owner: active service with panel login, suspended with "Manage" only', $d && $d['50']['sso'] === true && $d['51']['sso'] === false && $d['51']['domain'] === 'suspenso.pt', $out);
$check('only Enhance services that are active or suspended, of this client', $d && array_keys($d) === [50, 51], json_encode($d));
$check('labels translated, link to the existing single sign-on of the service', strpos($out, 'Aceder ao painel') !== false && strpos($out, 'Gerir') !== false && strpos($out, '&dosinglesignon=1') !== false);
$check('other client area pages: nothing', $hook(['templatefile' => 'clientareahome']) === '' && $hook(['templatefile' => 'clientareaproductdetails']) === '');
$as(2, 9);
$d = $data($hook(['templatefile' => 'clientareaproducts']));
$check('user with the Single Sign-On permission: panel login offered', $d && $d['50']['sso'] === true);
$as(3, 9);
$d = $data($hook(['templatefile' => 'clientareaproducts']));
$check('user without the Single Sign-On permission: "Manage" only', $d && $d['50']['sso'] === false);
$as(1, 77);
$d = $data($hook(['templatefile' => 'clientareaproducts']));
$check('client without access link: no panel login', $d && array_keys($d) === [60] && $d['60']['sso'] === false);
CurrentUser::$client = null;
$check('nobody logged in: nothing', $hook(['templatefile' => 'clientareaproducts']) === '');
$as(1, 9);
EnhanceSettings::save(['services_button' => ''], ['services_button']);
$check('turned off in Enhance Manager > Settings: nothing', $hook(['templatefile' => 'clientareaproducts']) === '');
EnhanceSettings::save(['services_button' => '1'], ['services_button']);
Capsule::table('tblhosting')->insert(['id' => 70, 'userid' => 9, 'packageid' => 10, 'server' => 1, 'domain' => '</script><img src=x onerror=alert(1)>']);
$out = $hook(['templatefile' => 'clientareaproducts']);
$check('data escaped inside the script', strpos($out, '</script><img') === false && substr_count($out, '</script>') === 1);
Capsule::table('tblhosting')->where('id', 70)->delete();

// Browser: a page shaped like the WHMCS services table (row opens the service on click).
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
$hasPw = $node !== '' && trim((string) shell_exec('node -e "require(\'playwright\')" 2>&1')) === '';
if (!$hasPw) {
    echo "SKIP browser placement (Playwright not available)\n";
} else {
    $out = $hook(['templatefile' => 'clientareaproducts']);
    $rows = '';
    foreach ([[50, 'acme.pt', 'Ativo'], [51, 'Suspenso.pt', 'Suspenso'], [52, 'outro-modulo.pt', 'Ativo']] as [$id, $dom, $st]) {
        $rows .= '<tr onclick="clickableSafeRedirect(event, \'clientarea.php?action=productdetails&amp;id=' . $id . '\', false)"><td><span class="ssl"></span></td><td class="text-center"><strong>Prime</strong><br>' . $dom . '</td><td>66,00 €</td><td>28.10.2027</td><td>' . $st . '</td></tr>';
    }
    $page = '<!doctype html><html><head><meta charset="utf-8"></head><body><script>window.redirects=[];function clickableSafeRedirect(e,u){window.redirects.push(u);}</script>'
        . '<table id="tableServicesList"><tbody>' . $rows . '</tbody></table>' . $out . '</body></html>';
    $file = __DIR__ . '/.tmp/services_list.html';
    file_put_contents($file, $page);
    $js = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage();
  await p.route('**/*', r => r.request().url().startsWith('file:') ? r.continue() : r.fulfill({ status: 204, body: '' }));
  await p.goto('file://' + process.argv[2]);
  const r = await p.evaluate(() => {
    const row = id => document.querySelector('tr[data-enhance-actions="' + id + '"]');
    const links = id => row(id) ? [...row(id).querySelectorAll('.enhance-svc-actions a')].map(a => [a.textContent, a.getAttribute('href'), a.target]) : null;
    const inDomainCell = row(50) && row(50).children[1].querySelector('.enhance-svc-actions') !== null;
    return { a: links(50), s: links(51), other: !!document.querySelector('tr .enhance-svc-actions') && document.querySelectorAll('.enhance-svc-actions').length, inDomainCell };
  });
  await p.click('tr[data-enhance-actions="50"] .enhance-svc-actions a:first-child', { noWaitAfter: true }).catch(() => {});
  r.redirects = await p.evaluate(() => window.redirects);
  await p.click('tr[data-enhance-actions="50"] td:nth-child(3)');
  r.redirectsAfterRow = await p.evaluate(() => window.redirects);
  console.log(JSON.stringify(r)); await b.close();
})();
JS;
    file_put_contents(__DIR__ . '/.tmp/services_list.js', $js);
    $res = json_decode((string) shell_exec('node ' . escapeshellarg(__DIR__ . '/.tmp/services_list.js') . ' ' . escapeshellarg($file) . ' 2>&1'), true);
    $check('browser: "Aceder ao painel" (new tab) and "Gerir" under the domain of the active service', $res && $res['inDomainCell'] && $res['a'] === [['Aceder ao painel', 'clientarea.php?action=productdetails&id=50&dosinglesignon=1', '_blank'], ['Gerir', 'clientarea.php?action=productdetails&id=50', '']], json_encode($res));
    $check('browser: suspended service gets "Gerir" only; other modules untouched', $res && $res['s'] === [['Gerir', 'clientarea.php?action=productdetails&id=51', '']] && $res['other'] === 2, json_encode($res));
    $check('browser: a click on a button does not trigger the row, the rest of the row still does', $res && $res['redirects'] === [] && count($res['redirectsAfterRow']) === 1, json_encode($res));
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
}
