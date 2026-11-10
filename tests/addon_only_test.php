<?php
// Enhance Manager as WHMCS runs it: only the addon is loaded, never the server module (enhance.php).
// Every tab that needs no Enhance server must render without undefined functions.
require __DIR__ . '/support/setting_stub.php';
define('ENHANCE_CDN_API_BASE', 'https://localhost:18443/bunny');
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
$GLOBALS['ENHANCE_ADDON_ONLY'] = true;
require __DIR__ . '/support/whmcs_boot.php';
require $argv[1] . '/modules/addons/enhance_importer/enhance_importer.php';
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
EnhanceLang::reset('portuguese-pt');
EnhanceSettings::reset();

$check('server module not loaded (as on the WHMCS addon page)', !function_exists('enhance_ClientArea'));
$render = function (string $tab) {
    $_REQUEST = ['tab' => $tab]; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try { $tab === 'cdn' ? enhance_importer_cdn_page('addonmodules.php?module=enhance_importer') : enhance_importer_settings_page('addonmodules.php?module=enhance_importer'); }
    catch (Throwable $e) { ob_end_clean(); return 'EXCEPTION ' . $e->getMessage(); }
    return ob_get_clean();
};
enhance_importer_ensure_schema();
EnhanceSettings::save(['cdn_enabled' => '1', 'cdn_api_key' => 'bunny_GOOD_key'], ['cdn_enabled', 'cdn_api_key']);
EnhanceCdnStore::saveSite('abababab-0000-4000-8000-000000000001', ['service_id' => 50, 'client_id' => 9, 'server_id' => 1, 'domain' => 'acme.pt', 'active' => true, 'zone_id' => 1001, 'cdn_host' => 'cdn.example.com', 'plan' => 'standard', 'activated_at' => date('Y-m-01')]);
EnhanceCdnStore::putDay('abababab-0000-4000-8000-000000000001', 1001, gmdate('Y-m-d'), 1073741824, 10);
EnhanceCdnStore::raiseAlert(EnhanceCdnStore::site('abababab-0000-4000-8000-000000000001'), 'month:' . gmdate('Y-m'), 80, 1, 1);

$html = $render('cdn');
$check('CDN tab renders with a website and an alert (plan name, usage)', strpos($html, 'EXCEPTION') === false && strpos($html, 'acme.pt') !== false && strpos($html, 'Standard') !== false && strpos($html, '1,00 GB') !== false, substr($html, 0, 300));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = ['tab' => 'cdn'];
$_POST = ['enhance_importer_action' => 'cdn_sync', 'token' => 'CSRF1'];
ob_start(); enhance_importer_cdn_page('addonmodules.php?module=enhance_importer'); $html = ob_get_clean();
$check('CDN "sync now" works without the server module', strpos($html, 'Sincronização CDN') !== false && strpos($html, 'alert-danger') === false, strip_tags(substr($html, 0, 600)));
$html = $render('settings');
$check('Settings tab renders', strpos($html, 'EXCEPTION') === false && strpos($html, 'websites_per_page') !== false);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
