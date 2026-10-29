<?php
// Renders templates/clientarea.tpl with the real Smarty engine and the data produced by enhance_ClientArea().
require __DIR__ . '/support/setting_stub.php';
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
require __DIR__ . '/support/whmcs_boot.php';
$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$render = function (string $language) use ($argv) {
    EnhanceLang::reset($language);
    $_SESSION = ['uid' => 9];
    $ca = enhance_ClientArea($GLOBALS['PARAMS']);
    $smarty = new Smarty();
    $smarty->setCompileDir(__DIR__ . '/.tmp/smarty_c');
    foreach ($ca['templateVariables'] as $k => $v) $smarty->assign($k, $v);
    return $smarty->fetch($argv[1] . '/modules/servers/enhance/' . $ca['tabOverviewReplacementTemplate']);
};
$pt = $render('portuguese-pt');
$en = $render('english');
$text = fn($html) => preg_replace('/\s+/', ' ', strip_tags($html));
$check('PT: title, SSL date in d/m/Y, days left', strpos($text($pt), 'Painel de Alojamento') !== false && strpos($text($pt), 'Certificado SSL válido até 08/11/2026') !== false && strpos($text($pt), 'faltam') !== false, $text($pt));
$check('EN: SSL date in Y-m-d', strpos($text($en), 'SSL certificate valid until 2026-11-08') !== false);
$check('tool links (webmail, phpMyAdmin, WordPress) with encoded parameters', strpos($pt, 'a=WebmailSso&amp;website=abababab-0000-4000-8000-000000000001&amp;email=info%40acme.pt') !== false && strpos($pt, 'a=PhpMyAdminSso') !== false && strpos($pt, 'a=WordpressSso') !== false);
$check('mailbox without mailbox (forwarder only) not listed', strpos($pt, 'fwd%40acme.pt') === false);
$check('restore form only for the successful backup, with CSRF token', substr_count($pt, 'name="backup"') === 1 && strpos($pt, 'name="token" value="CSRF1"') !== false && strpos($text($pt), 'Falhou') !== false);
$check('no restore form when the product option is off', (function () use ($argv) {
    EnhanceLang::reset('english');
    $p = $GLOBALS['PARAMS']; $p['configoption4'] = '';
    $ca = enhance_ClientArea($p);
    $smarty = new Smarty(); $smarty->setCompileDir(__DIR__ . '/.tmp/smarty_c');
    foreach ($ca['templateVariables'] as $k => $v) $smarty->assign($k, $v);
    return strpos($smarty->fetch($argv[1] . '/modules/servers/enhance/templates/clientarea.tpl'), 'name="backup"') === false;
})());
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
