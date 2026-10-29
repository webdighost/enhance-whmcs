<?php
require __DIR__ . '/support/setting_stub.php';
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
require __DIR__ . '/support/whmcs_boot.php';
use WHMCS\Database\Capsule;
EnhanceLang::reset('portuguese-pt');
$fails = 0; $check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$p = $GLOBALS['PARAMS'];
$_SESSION = ['adminid' => 3];

// 1. Last action tracking
$r = enhance_SuspendAccount($p);
$e = EnhanceStore::lastEvent(50);
$check('failed action recorded with its message', $r !== 'success' && $e && !$e->success && $e->action === 'SuspendAccount' && strpos((string) $e->last_error, 'HTTP 500') !== false, json_encode([$r, $e]));
$r2 = enhance_RefreshUsage($p);
$e2 = EnhanceStore::lastEvent(50);
$check('later success recorded, last error kept', $r2 === 'success' && $e2->success && $e2->action === 'RefreshUsage' && strpos((string) $e2->last_error, 'HTTP 500') !== false, json_encode([$r2, $e2]));

// 2. Service tab: last action + link selector
$fields = enhance_AdminServicesTabFields($p);
$tab = implode("\n", $fields);
$check('tab shows last action and last error', isset($fields['Última ação do módulo']) && strpos($tab, 'Último erro') !== false && strpos($tab, 'RefreshUsage') !== false, json_encode(array_keys($fields), JSON_UNESCAPED_UNICODE));
$check('selector lists other subscriptions, hides deleted and current', strpos($tab, 'value="6"') !== false && strpos($tab, 'value="9"') === false && strpos($tab, 'value="5"') === false && strpos($tab, 'value="unlink"') !== false, $tab);

// 3. Save: link subscription 6
$_POST = ['enhance_link_subscription' => '6'];
enhance_AdminServicesTabFieldsSave($p);
$svc = EnhanceStore::service(50);
$check('saving links subscription #6 and clears the old website', $svc->subscription_id === '6' && $svc->website_id === null, json_encode($svc));
// a subscription of another client's organisation is refused
$_POST = ['enhance_link_subscription' => '777'];
enhance_AdminServicesTabFieldsSave($p);
$e3 = EnhanceStore::lastEvent(50);
$check('invalid subscription refused and reported in the tab', EnhanceStore::service(50)->subscription_id === '6' && !$e3->success && $e3->action === 'LinkSubscription', json_encode($e3));
$_POST = ['enhance_link_subscription' => 'unlink'];
enhance_AdminServicesTabFieldsSave($p);
$check('unlink removes the association', EnhanceStore::service(50) === null);
EnhanceStore::setService(50, 1, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '5');

// 4. Admin login as client is logged
@unlink(__DIR__ . '/.tmp/activity.log');
$sso = enhance_ServiceSingleSignOn($p);
$log = (string) @file_get_contents(__DIR__ . '/.tmp/activity.log');
$check('admin SSO works and is written to the activity log', !empty($sso['success']) && strpos($log, 'administrador #3') !== false, json_encode([$sso, $log], JSON_UNESCAPED_UNICODE));
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
