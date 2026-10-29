<?php
// Served by "php -S": calls one module function per request, so real HTTP headers are sent.
$GLOBALS['ENHANCE_ROOT'] = getenv('ENHANCE_ROOT');
require __DIR__ . '/whmcs_boot.php';
EnhanceLang::reset($_GET['lang'] ?? 'english');
$_SESSION = ['uid' => (int) ($_GET['uid'] ?? 9)];
$params = $GLOBALS['PARAMS'];
if (isset($_GET['restore'])) $params['configoption4'] = $_GET['restore'];
$fn = 'enhance_' . preg_replace('/[^A-Za-z]/', '', $_GET['fn'] ?? '');
echo 'RESULT: ' . $fn($params);
