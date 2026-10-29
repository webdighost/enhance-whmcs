<?php
// Integration: real Eloquent (SQLite) + HTTPS mock of the Enhance API.
require __DIR__ . '/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager;
$m = new Manager; $m->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $m->setAsGlobal();
class_alias(Manager::class, 'WHMCS\Database\Capsule');
define('WHMCS', true);
$GLOBALS['sent'] = [];
function logActivity($m) {}
function logModuleCall(...$a) {}
function localAPI($cmd, $args) { $GLOBALS['sent'][] = [$cmd, $args['messagename'] ?? '', unserialize(base64_decode($args['customvars'] ?? '')) ]; return ['result' => 'success']; }
$s = Manager::schema();
$s->create('tblservers', function ($t) { $t->increments('id'); $t->string('type'); $t->string('hostname')->default(''); $t->string('username')->default(''); });
$s->create('tblcustomfields', function ($t) { $t->increments('id'); $t->string('type'); $t->integer('relid')->default(0); $t->string('fieldname'); $t->string('fieldtype')->default('text'); $t->text('description')->default(''); $t->text('fieldoptions')->default(''); $t->text('regexpr')->default(''); $t->string('adminonly')->default(''); $t->string('required')->default(''); $t->string('showorder')->default(''); $t->string('showinvoice')->default(''); $t->integer('sortorder')->default(0); });
$s->create('tblcustomfieldsvalues', function ($t) { $t->increments('id'); $t->integer('fieldid'); $t->integer('relid'); $t->text('value'); });
$s->create('tblproducts', function ($t) { $t->increments('id'); $t->string('servertype'); $t->string('name')->default(''); $t->string('configoption1')->default(''); });
$s->create('tblhosting', function ($t) { $t->increments('id'); $t->integer('userid'); $t->integer('packageid'); $t->integer('server'); $t->string('domainstatus')->default('Active'); $t->string('domain')->default(''); });
$s->create('tblconfiguration', function ($t) { $t->string('setting'); $t->text('value'); });
$s->create('tblemailtemplates', function ($t) { $t->increments('id'); $t->string('type'); $t->string('name'); $t->text('subject'); $t->text('message'); $t->string('language')->default(''); $t->integer('custom')->default(0); $t->integer('disabled')->default(0); $t->integer('plaintext')->default(0); });
$s->create('tblclients', function ($t) { $t->increments('id'); $t->string('firstname'); $t->string('lastname'); $t->string('companyname')->default(''); $t->string('email'); $t->string('status')->default('Active'); $t->string('language')->default(''); });
$MASTER = '11111111-2222-4333-8444-555555555555';
Manager::table('tblservers')->insert(['id' => 1, 'type' => 'enhance', 'hostname' => 'localhost:18443', 'username' => $MASTER]);
require $argv[1] . '/modules/servers/enhance/EnhanceApi.php';
require $argv[1] . '/modules/addons/enhance_importer/enhance_importer.php';
EnhanceLang::reset('english');
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$api = EnhanceApi::fromParams(['serverhostname' => 'localhost:18443', 'serverusername' => $MASTER, 'serveraccesshash' => 'tok_GOOD&abc', 'serverid' => 1]);

// Realm-limited login lookup
$r = $api->findLoginsByEmail('dup@x.pt');
$check('logins limited to the master realm (1 of 2 with same email)', count($r) === 1 && $r[0]['id'] === '11111111-aaaa-4aaa-8aaa-000000000001', json_encode($r));
$check('whole panel lookup still available', count($api->findLoginsByEmail('dup@x.pt', true)) === 2);

// Full customer sync: login exists only in another realm -> createLogin 409 -> reuse it
$res = $api->syncCustomerFromWhmcs(7, 'Ana Silva', 'other@x.pt', '');
$check('409 on createLogin reuses the existing login', !empty($res['success']) && in_array('existing_login_linked_as_owner', $res['changed'], true), json_encode($res));
$check('organisation stored for client #7', EnhanceStore::clientOrg(7, 1) === 'cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee');

// Normal sync: new login -> password-setting link requested
$GLOBALS['sent'] = [];
// The mock always returns the same organisation id: free it (the module rightly refuses to link one org to two clients).
Manager::table('mod_enhance_clients')->where('client_id', 7)->delete();
$res2 = $api->syncCustomerFromWhmcs(8, 'Rui Costa', 'new@x.pt', 'Beta Lda');
$check('new login: owner created and new_login email', !empty($res2['success']) && in_array('owner_created', $res2['changed'], true) && ($GLOBALS['sent'][0][2]['new_login'] ?? null) === 1, json_encode([$res2, $GLOBALS['sent']]));

// Customer search
$found = $api->searchCustomers('acme');
$check('search by name', count($found['items'] ?? []) === 1 && $found['items'][0]['name'] === 'Acme Lda', json_encode($found));
$byId = $api->searchCustomers('CCCCCCCC-BBBB-4CCC-8DDD-EEEEEEEEEEEE');
$check('search by UUID', count($byId['items'] ?? []) === 1, json_encode($byId));

// Importer: pagination over linked clients
Manager::table('mod_enhance_clients')->truncate();
for ($i = 1; $i <= 45; $i++) {
    Manager::table('tblclients')->insert(['id' => 100 + $i, 'firstname' => 'C', 'lastname' => (string) $i, 'email' => "c$i@x.pt"]);
    Manager::table('mod_enhance_clients')->insert(['client_id' => 100 + $i, 'server_id' => 1, 'org_id' => sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $i)]);
}
$p1 = enhance_importer_scan_services($api, 1, 1);
$p3 = enhance_importer_scan_services($api, 1, 3);
$p9 = enhance_importer_scan_services($api, 1, 9);
$check('importer page 1: 20 clients, 3 pages', count($p1['rows']) === 20 && $p1['pages'] === 3 && $p1['total'] === 45, json_encode(['n' => count($p1['rows']), 'pages' => $p1['pages'], 'total' => $p1['total']]));
$check('importer page 3: remaining 5', count($p3['rows']) === 5);
$check('out-of-range page clamps to the last', $p9['page'] === 3);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
