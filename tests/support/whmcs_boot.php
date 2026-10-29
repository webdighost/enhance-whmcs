<?php
// Minimal WHMCS environment: real Eloquent on a SQLite file + stubbed WHMCS functions.
require dirname(__DIR__) . '/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager;
$dbFile = dirname(__DIR__) . '/.tmp/tools.db';
$fresh = !is_file($dbFile);
if ($fresh) touch($dbFile);
$m = new Manager; $m->addConnection(['driver' => 'sqlite', 'database' => $dbFile]); $m->setAsGlobal();
class_alias(Manager::class, 'WHMCS\Database\Capsule');
if (!defined('WHMCS')) define('WHMCS', true);
$GLOBALS['activity'] = [];
function logActivity($m, $c = 0) { $GLOBALS['activity'][] = $m; file_put_contents(dirname(__DIR__) . '/.tmp/activity.log', $m . "\n", FILE_APPEND); }
function logModuleCall(...$a) {}
function localAPI($cmd, $args) { return ['result' => 'success']; }
function generate_token($t) { return 'CSRF1'; }
function check_token($ns, $t) { return $t === 'CSRF1'; }
if ($fresh) {
    $s = Manager::schema();
    $s->create('tblservers', function ($t) { $t->increments('id'); $t->string('type'); $t->string('hostname')->default(''); $t->string('username')->default(''); $t->integer('diskusage')->default(0); $t->integer('disklimit')->default(0); $t->integer('bwusage')->default(0); $t->integer('bwlimit')->default(0); $t->dateTime('lastupdate')->nullable(); });
    $s->create('tblcustomfields', function ($t) { $t->increments('id'); $t->string('type'); $t->integer('relid')->default(0); $t->string('fieldname'); $t->string('fieldtype')->default('text'); $t->text('description')->default(''); $t->text('fieldoptions')->default(''); $t->text('regexpr')->default(''); $t->string('adminonly')->default(''); $t->string('required')->default(''); $t->string('showorder')->default(''); $t->string('showinvoice')->default(''); $t->integer('sortorder')->default(0); });
    $s->create('tblcustomfieldsvalues', function ($t) { $t->increments('id'); $t->integer('fieldid'); $t->integer('relid'); $t->text('value'); });
    $s->create('tblproducts', function ($t) { $t->increments('id'); $t->string('servertype'); });
    $s->create('tblhosting', function ($t) { $t->increments('id'); $t->integer('userid'); $t->integer('packageid'); $t->integer('server'); $t->string('domainstatus')->default('Active'); $t->string('domain')->default(''); $t->string('username')->default(''); $t->integer('diskusage')->default(0); $t->integer('disklimit')->default(0); $t->integer('bwusage')->default(0); $t->integer('bwlimit')->default(0); $t->dateTime('lastupdate')->nullable(); });
    $s->create('tblconfiguration', function ($t) { $t->string('setting'); $t->text('value'); });
    $s->create('tblemailtemplates', function ($t) { $t->increments('id'); $t->string('type'); $t->string('name'); $t->text('subject'); $t->text('message'); $t->string('language')->default(''); $t->integer('custom')->default(0); $t->integer('disabled')->default(0); $t->integer('plaintext')->default(0); });
    $s->create('tblclients', function ($t) { $t->increments('id'); $t->string('firstname'); $t->string('lastname'); $t->string('companyname')->default(''); $t->string('email'); $t->string('status')->default('Active'); $t->string('language')->default(''); });
    Manager::table('tblservers')->insert(['id' => 1, 'type' => 'enhance', 'hostname' => 'localhost:18443', 'username' => '11111111-2222-4333-8444-555555555555']);
    Manager::table('tblproducts')->insert(['id' => 10, 'servertype' => 'enhance']);
    Manager::table('tblclients')->insert(['id' => 9, 'firstname' => 'Ana', 'lastname' => 'Silva', 'companyname' => 'Acme Lda', 'email' => 'a@x.pt']);
    Manager::table('tblhosting')->insert(['id' => 50, 'userid' => 9, 'packageid' => 10, 'server' => 1, 'domain' => 'acme.pt']);
}
require $GLOBALS['ENHANCE_ROOT'] . '/modules/servers/enhance/enhance.php';
if ($fresh) {
    EnhanceStore::ensure();
    EnhanceStore::setClientOrg(9, 1, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    EnhanceStore::setService(50, 1, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '5');
}
$GLOBALS['PARAMS'] = [
    'serverid' => 1, 'serverhostname' => 'localhost:18443', 'serverusername' => '11111111-2222-4333-8444-555555555555',
    'serveraccesshash' => 'tok_GOOD&abc', 'serviceid' => 50, 'packageid' => 10, 'domain' => 'acme.pt',
    'clientsdetails' => ['userid' => 9, 'email' => 'a@x.pt'], 'configoption1' => '1', 'configoption4' => 'on',
];
