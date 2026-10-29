<?php
require __DIR__ . '/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager;
$m = new Manager; $m->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $m->setAsGlobal();
class_alias(Manager::class, 'WHMCS\Database\Capsule');
define('WHMCS', true);
function logActivity($m) { echo "  [log] $m\n"; }
$s = Manager::schema();
$s->create('tblservers', function ($t) { $t->increments('id'); $t->string('type'); $t->string('hostname')->default(''); $t->string('username')->default(''); });
$s->create('tblcustomfields', function ($t) { $t->increments('id'); $t->string('type'); $t->integer('relid')->default(0); $t->string('fieldname'); $t->string('fieldtype')->default('text'); $t->text('description')->default(''); $t->text('fieldoptions')->default(''); $t->text('regexpr')->default(''); $t->string('adminonly')->default(''); $t->string('required')->default(''); $t->string('showorder')->default(''); $t->string('showinvoice')->default(''); $t->integer('sortorder')->default(0); });
$s->create('tblcustomfieldsvalues', function ($t) { $t->increments('id'); $t->integer('fieldid'); $t->integer('relid'); $t->text('value'); });
$s->create('tblproducts', function ($t) { $t->increments('id'); $t->string('servertype'); });
$s->create('tblhosting', function ($t) { $t->increments('id'); $t->integer('userid'); $t->integer('packageid'); $t->integer('server'); $t->string('domainstatus')->default('Active'); $t->string('domain')->default(''); });
$s->create('tblconfiguration', function ($t) { $t->string('setting'); $t->text('value'); });
$s->create('tblemailtemplates', function ($t) { $t->increments('id'); $t->string('type'); $t->string('name'); $t->text('subject'); $t->text('message'); $t->string('language')->default(''); $t->integer('custom')->default(0); $t->integer('disabled')->default(0); $t->integer('plaintext')->default(0); });
$db = fn($t) => Manager::table($t);
$db('tblservers')->insert(['id' => 1, 'type' => 'enhance', 'hostname' => 'cp.test', 'username' => '11111111-2222-4333-8444-555555555555']);
$db('tblproducts')->insert([['id' => 10, 'servertype' => 'enhance'], ['id' => 20, 'servertype' => 'othermodule']]);
// Official module data: enhOrgId (client) + enhanceSubscriptionId (product 10, "name|label" form) + generic subscriptionId on another module's product
$db('tblcustomfields')->insert([
  ['id' => 1, 'type' => 'client', 'relid' => 0, 'fieldname' => 'enhOrgId'],
  ['id' => 2, 'type' => 'product', 'relid' => 10, 'fieldname' => 'enhanceSubscriptionId|Enhance Subscription'],
  ['id' => 3, 'type' => 'product', 'relid' => 20, 'fieldname' => 'subscriptionId'],
]);
$db('tblhosting')->insert([['id' => 100, 'userid' => 5, 'packageid' => 10, 'server' => 1], ['id' => 200, 'userid' => 6, 'packageid' => 20, 'server' => 0]]);
$db('tblcustomfieldsvalues')->insert([
  ['fieldid' => 1, 'relid' => 5, 'value' => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE'],
  ['fieldid' => 2, 'relid' => 100, 'value' => '42'],
  ['fieldid' => 3, 'relid' => 200, 'value' => '99'],
]);
require $argv[1] . '/modules/servers/enhance/EnhanceStore.php';
EnhanceLang::reset('english');
EnhanceStore::ensure();
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$check('client org migrated from enhOrgId (lowercased)', EnhanceStore::clientOrg(5, 1) === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', json_encode($db('mod_enhance_clients')->get()));
$svc = EnhanceStore::service(100);
$check('service migrated from enhanceSubscriptionId|label', $svc && $svc->subscription_id === '42' && $svc->org_id === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', json_encode($svc));
$check('other module subscriptionId ignored', EnhanceStore::service(200) === null);
$check('version stored', $db('tblconfiguration')->where('setting', 'EnhanceModuleStoreVersion')->value('value') === EnhanceStore::VERSION);
$check('EnhanceOrgId display field created', $db('tblcustomfields')->where('fieldname', 'EnhanceOrgId')->exists());
$check('email template EN + PT created', $db('tblemailtemplates')->count() === 2, json_encode($db('tblemailtemplates')->pluck('language')));
EnhanceStore::clearService(100);
$check('clearService also removes the legacy value', !$db('tblcustomfieldsvalues')->where('fieldid', 2)->exists() && EnhanceStore::service(100) === null);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
