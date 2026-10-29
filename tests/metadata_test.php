<?php
// whmcs.json files (WHMCS Apps & Integrations): valid, right type and name, version in sync with the code.
define('WHMCS', true);
require $argv[1] . '/modules/servers/enhance/EnhanceApi.php';
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
foreach ([
    ['modules/servers/enhance/whmcs.json', 'whmcs-servers', 'enhance'],
    ['modules/addons/enhance_importer/whmcs.json', 'whmcs-addons', 'enhance_importer'],
] as [$file, $type, $name]) {
    $json = json_decode((string) @file_get_contents($argv[1] . '/' . $file), true);
    $check("$file is valid JSON (schema 1.0)", is_array($json) && ($json['schema'] ?? '') === '1.0', json_last_error_msg());
    $check("$file type $type, name $name", ($json['type'] ?? '') === $type && ($json['name'] ?? '') === $name);
    $check("$file tagline shows v" . EnhanceApi::VERSION, strpos((string) ($json['description']['tagline'] ?? ''), 'v' . EnhanceApi::VERSION . ' ') === 0, (string) ($json['description']['tagline'] ?? ''));
    $check("$file has description, features and authors", !empty($json['description']['long']) && !empty($json['description']['features']) && !empty($json['authors'][0]['name']));
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
