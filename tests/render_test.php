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
$text = fn($html) => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$check('PT: title, SSL date in d/m/Y, days left', strpos($text($pt), 'Painel de Alojamento') !== false && strpos($text($pt), 'Certificado SSL válido até 08/11/2026') !== false && strpos($text($pt), 'faltam') !== false, $text($pt));
$check('EN: SSL date in Y-m-d', strpos($text($en), 'SSL certificate valid until 2026-11-08') !== false);
$check('tool links (webmail, phpMyAdmin, WordPress) with encoded parameters', strpos($pt, 'a=WebmailSso&amp;website=abababab-0000-4000-8000-000000000001&amp;email=info%40acme.pt') !== false && strpos($pt, 'a=PhpMyAdminSso') !== false && strpos($pt, 'a=WordpressSso') !== false);
$check('mailbox without mailbox (forwarder only) not listed', strpos($pt, 'fwd%40acme.pt') === false);
$check('restore form only for the successful backup, with CSRF token', substr_count($pt, 'name="backup"') === 1 && strpos($pt, 'name="backup" value="7"') !== false && strpos($pt, 'name="token" value="CSRF1"') !== false);
preg_match('#<table class="wd-enhance-backups">.*?</table>#s', $pt, $tbl);
$backups = $text($tbl[0] ?? '');
$check('failed backup with data shows "Falhou" once; 0-byte incremental shows neither "Falhou" nor "0 MB"', substr_count($backups, 'Falhou') === 1 && !preg_match('/(?<![\d,.])0 MB/', $backups) && strpos($backups, '1 MB') !== false, $backups);
$check('WordPress in the website root: brand icon, no "(/public_html)"', strpos($pt, 'fab fa-wordpress') !== false && strpos($pt, 'public_html') === false);
$check('WordPress in a subfolder keeps its path', _enhance_app_subfolder('public_html/blog') === '/blog' && _enhance_app_subfolder('blog') === '/blog' && _enhance_app_subfolder('') === '' && _enhance_app_subfolder('/public_html/') === '');
$check('no restore form when the product option is off', (function () use ($argv) {
    EnhanceLang::reset('english');
    $p = $GLOBALS['PARAMS']; $p['configoption4'] = '';
    $ca = enhance_ClientArea($p);
    $smarty = new Smarty(); $smarty->setCompileDir(__DIR__ . '/.tmp/smarty_c');
    foreach ($ca['templateVariables'] as $k => $v) $smarty->assign($k, $v);
    return strpos($smarty->fetch($argv[1] . '/modules/servers/enhance/templates/clientarea.tpl'), 'name="backup"') === false;
})());

// Pagination: 12 websites, 5 per page; tools only for the websites of the page shown.
$ctx = stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]]);
$logCount = fn() => count(json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true));
$renderPage = function (array $get) use ($argv) {
    $_GET = $get;
    EnhanceLang::reset('portuguese-pt');
    $ca = enhance_ClientArea($GLOBALS['PARAMS']);
    $smarty = new Smarty(); $smarty->setCompileDir(__DIR__ . '/.tmp/smarty_c');
    foreach ($ca['templateVariables'] as $k => $v) $smarty->assign($k, $v);
    return [$smarty->fetch($argv[1] . '/modules/servers/enhance/templates/clientarea.tpl'), $ca['templateVariables']['sites']];
};
$before = $logCount();
[$html1, $s1] = $renderPage([]);
$log = array_slice(json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true), $before);
$backupCalls = count(array_filter($log, fn($r) => substr(explode('?', $r[1])[0], -8) === '/backups'));
$check('12 websites listed in pages of 5 (page 1 of 3)', $s1['total'] === 12 && $s1['pages'] === 3 && count($s1['items']) === 5 && substr_count($html1, 'class="wd-enhance-tools"') === 5, json_encode([$s1['total'], $s1['pages'], count($s1['items'])]));
$check('tools loaded only for the 5 websites of the page', $backupCalls === 5, (string) $backupCalls);
$check('pager with links and "Página 1 de 3"', strpos($html1, 'enhpage=2') !== false && strpos($html1, 'enhpage=3') !== false && strpos($text($html1), 'Página 1 de 3') !== false);
$check('search field shown when there are more websites than one page', strpos($html1, 'name="enhsearch"') !== false);
[$html3, $s3] = $renderPage(['enhpage' => '3']);
$check('page 3 shows the last 2 websites', $s3['page'] === 3 && array_column($s3['items'], 'domain') === ['site10.pt', 'site11.pt'], json_encode(array_column($s3['items'], 'domain')));
[$html9, $s9] = $renderPage(['enhpage' => '99']);
$check('out-of-range page shows the last page', $s9['page'] === 3);
[$htmlS, $sS] = $renderPage(['enhsearch' => 'SITE1']);
$check('search is case-insensitive and keeps the term in the page links', array_column($sS['items'], 'domain') === ['site10.pt', 'site11.pt'] && $sS['pages'] === 1 && strpos($htmlS, 'value="SITE1"') !== false, json_encode(array_column($sS['items'], 'domain')));
[$htmlN, $sN] = $renderPage(['enhsearch' => 'zzz']);
$check('search without results shows a message', $sN['items'] === [] && strpos($text($htmlN), 'Nenhum website corresponde a "zzz"') !== false);
$links = _enhance_pager_links(10, 200, fn($n) => "p$n");
$check('"no SSL" only when Enhance answered (not when the SSL query failed)', strpos($text($html1), 'Sem certificado SSL') === false);
$check('pager for 200 pages: 1 ... 8 9 [10] 11 12 ... 200', implode(' ', array_map(fn($l) => $l['gap'] ? '...' : ($l['current'] ? '[' . $l['label'] . ']' : $l['label']), $links)) === '1 ... 8 9 [10] 11 12 ... 200');
$_GET = [];

echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
