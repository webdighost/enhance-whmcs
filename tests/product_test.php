<?php
require __DIR__ . '/support/setting_stub.php';
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
require __DIR__ . '/support/whmcs_boot.php';
EnhanceLang::reset('portuguese-pt');
$fails = 0; $check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$groups = enhance_LoadServerGroups($GLOBALS['PARAMS']);
$check('server group loader (default + groups)', $groups === ['' => 'Por omissão (definição do plano)', 'efefefef-0000-4000-8000-000000000001' => 'Lisboa (3)'], json_encode($groups, JSON_UNESCAPED_UNICODE));
$api = EnhanceApi::fromParams($GLOBALS['PARAMS']);
_enhance_store_login_username($api, 50, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
$u = \WHMCS\Database\Capsule::table('tblhosting')->where('id', 50)->value('username');
$check('service username = Enhance login email', $u === 'owner@acme.pt', $u);
$opts = enhance_ConfigOptions();
$check('product options: plan, termination, server group, restore (configoption1-4)', array_keys($opts) === ['plan', 'termination', 'server_group', 'allow_restore'], json_encode(array_keys($opts)));
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
