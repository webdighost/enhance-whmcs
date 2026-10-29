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
Manager::schema()->table('tblservers', function ($t) { $t->string('ipaddress')->default(''); $t->text('password')->default(''); $t->text('accesshash')->default(''); $t->integer('disabled')->default(0); });
Manager::table('tblservers')->insert(['id' => 1, 'type' => 'enhance', 'hostname' => 'localhost:18443', 'username' => $MASTER, 'accesshash' => 'tok_GOOD&amp;abc']);
Manager::table('tblclients')->insert(['id' => 9, 'firstname' => 'Ana', 'lastname' => 'Silva', 'companyname' => 'Acme Lda', 'email' => 'a@x.pt']);
$HOOKS = [];
function add_hook($n, $p, $f) { $GLOBALS['HOOKS'][$n] = $f; }
function generate_token($t) { return 'TOKEN123'; }
function check_token($ns, $t) { return $t === 'TOKEN123'; }
require $argv[1] . '/modules/servers/enhance/EnhanceApi.php';
EnhanceLang::reset('english');
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };

require $argv[1] . '/includes/hooks/enhance.php';
EnhanceLang::reset('portuguese-pt');
$render = function (array $post) { $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET'; $out = $GLOBALS['HOOKS']['AdminClientProfileTabFields'](['userid' => 9]); preg_match('/var data = (\{.*?\});\n/s', $out[''], $m); return json_decode($m[1], true)['html']; };
$fails = 0; $check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };

$h = $render([]);
$check('profile view: search field prefilled with company, no list', strpos($h, 'value="Acme Lda"') !== false && strpos($h, '<select') === false, $h);
$h = $render(['enhance_action' => 'searchOrg', 'enhance_search' => 'acme', 'token' => 'TOKEN123', 'userid' => '9']);
$check('search shows matching organisation in PT', strpos($h, 'Acme Lda - acme@x.pt') !== false && strpos($h, 'Associar organização') !== false, $h);
$h = $render(['enhance_action' => 'searchOrg', 'enhance_search' => 'zzz', 'token' => 'TOKEN123', 'userid' => '9']);
$check('no results message', strpos($h, 'Nenhuma organização') !== false, $h);
$h = $render(['enhance_action' => 'linkOrg', 'newOrgId' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'token' => 'TOKEN123', 'userid' => '9']);
$check('link under lock works and stores the org', EnhanceStore::clientOrg(9, 1) === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' && strpos($h, 'Organização associada') !== false, $h);
$h = $render(['enhance_action' => 'searchOrg', 'enhance_search' => 'acme', 'token' => 'BAD', 'userid' => '9']);
$check('bad CSRF token refused', strpos($h, 'Token de segurança inválido') !== false, $h);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
