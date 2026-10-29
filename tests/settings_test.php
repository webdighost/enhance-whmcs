<?php
// Settings: storage, validation, config.php locks, the Settings tab form and their effect on the client area.
require __DIR__ . '/support/setting_stub.php';
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
if (($argv[2] ?? '') === 'locked') {
    define('ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE', 7);
    define('ENHANCE_COLOR_PRIMARY', '#ABCDEF');
}
require __DIR__ . '/support/whmcs_boot.php';
require $argv[1] . '/modules/addons/enhance_importer/enhance_importer.php';
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
EnhanceSettings::reset();
EnhanceLang::reset('portuguese-pt');

if (($argv[2] ?? '') === 'locked') {
    $invalid = EnhanceSettings::save(['websites_per_page' => '3', 'color_primary' => '#111111']);
    $check('config.php constant wins and saving it is ignored', EnhanceSettings::get('websites_per_page') === 7 && EnhanceSettings::get('color_primary') === '#abcdef' && $invalid === []);
    $form = enhance_importer_settings_form(EnhanceSettings::all(), 'addonmodules.php?module=enhance_importer&tab=settings');
    $check('locked settings disabled in the form, with the constant name', strpos($form, 'Bloqueado por ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE') !== false && preg_match('/name="websites_per_page"[^>]*disabled/', $form) === 1);
    echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
    exit;
}

$check('defaults', EnhanceSettings::get('websites_per_page') === 5 && EnhanceSettings::get('show_backups') === true && EnhanceSettings::get('color_primary') === '' && EnhanceSettings::get('cache_ttl') === 300);
$invalid = EnhanceSettings::save(['color_primary' => '#17B8B0', 'color_secondary' => 'red', 'websites_per_page' => '500', 'backups_shown' => 'x', 'language' => 'klingon', 'show_ssl' => '1', 'show_usage' => '1']);
$check('valid colour stored lower-case; invalid colour, number and language rejected', EnhanceSettings::get('color_primary') === '#17b8b0' && EnhanceSettings::get('color_secondary') === '' && array_values(array_intersect(['color_secondary', 'backups_shown', 'language'], $invalid)) === ['color_secondary', 'backups_shown', 'language'], json_encode($invalid));
$check('numbers are clamped to their range (500 -> 50)', EnhanceSettings::get('websites_per_page') === 50);
$check('unchecked checkboxes (not posted) are saved as off', EnhanceSettings::get('show_backups') === false && EnhanceSettings::get('show_ssl') === true);
EnhanceSettings::save(['language' => 'english', 'show_usage' => '1']);
EnhanceLang::reset();
$check('language setting forces the interface language', EnhanceLang::current() === 'english');
EnhanceLang::reset('portuguese-pt');
EnhanceSettings::reset();
$check('reset restores the defaults', EnhanceSettings::get('websites_per_page') === 5 && EnhanceSettings::get('show_backups') === true);

$form = enhance_importer_settings_form(EnhanceSettings::all(), 'addonmodules.php?module=enhance_importer&tab=settings');
$check('settings form: every setting, CSRF token, PT labels, preview', substr_count($form, 'name="token" value="CSRF1"') === 2 && strpos($form, 'name="cache_ttl"') !== false && strpos($form, 'name="show_features"') !== false && strpos($form, 'Websites por página') !== false && strpos($form, 'enh-preview') !== false && strpos($form, 'disabled') === false);

// Effect on the client area
$ctx = stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]]);
$calls = function (callable $fn) use ($ctx) {
    $before = count(json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true));
    $out = $fn();
    $log = array_slice(json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true), $before);
    return [$out, array_map(fn($r) => explode('?', $r[1])[0], $log)];
};
$_SESSION = ['uid' => 9];
EnhanceSettings::save(['show_ssl' => '1', 'show_webmail' => '1', 'show_phpmyadmin' => '1', 'show_wordpress' => '1', 'show_usage' => '', 'websites_per_page' => '3', 'color_primary' => '#17b8b0', 'cache_ttl' => '0']);
[$ca, $paths] = $calls(fn() => enhance_ClientArea($GLOBALS['PARAMS']));
$v = $ca['templateVariables'];
$backupCalls = count(array_filter($paths, fn($p) => substr($p, -8) === '/backups'));
$bwCalls = count(array_filter($paths, fn($p) => substr($p, -10) === '/bandwidth'));
$check('3 websites per page from the settings', count($v['sites']['items']) === 3 && $v['sites']['pages'] === 4, json_encode([count($v['sites']['items']), $v['sites']['pages']]));
$check('hidden backups and usage are not requested from Enhance', $backupCalls === 0 && $bwCalls === 0 && $v['enhance']['disk'] === null, json_encode($paths));
$check('backups hidden also disables restore; features off; colour applied', $v['allowRestore'] === false && $v['showFeatures'] === false && $v['colorPrimary'] === '#17b8b0');
EnhanceSettings::reset();
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
