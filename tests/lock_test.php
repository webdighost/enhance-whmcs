<?php
require __DIR__ . '/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager;
$m = new Manager; $m->addConnection(['driver' => 'mysql', 'host' => getenv('ENHANCE_TEST_MYSQL_HOST') ?: '127.0.0.1', 'database' => getenv('ENHANCE_TEST_MYSQL_DB') ?: 'whmcs_test', 'username' => getenv('ENHANCE_TEST_MYSQL_USER') ?: 't', 'password' => getenv('ENHANCE_TEST_MYSQL_PASSWORD') ?: 't']); $m->setAsGlobal();
class_alias(Manager::class, 'WHMCS\Database\Capsule');
define('WHMCS', true);
function logActivity($m) {}
require $argv[1] . '/modules/servers/enhance/EnhanceStore.php';
EnhanceLang::reset($argv[3] ?? 'english');
$key = EnhanceStore::clientLockKey(1, 5);
$mode = $argv[2];
$t0 = microtime(true);
try {
    if ($mode === 'hold') {
        EnhanceStore::withLock($key, function () { echo "A: got lock, working 4s\n"; sleep(4); echo "A: done\n"; });
    } elseif ($mode === 'try') {
        EnhanceStore::withLock($key, function () { echo "B: got lock\n"; }, 1);
    } elseif ($mode === 'wait') {
        EnhanceStore::withLock($key, function () use ($t0) { printf("C: got lock after %.1fs\n", microtime(true) - $t0); }, 10);
    } elseif ($mode === 'throw') {
        try { EnhanceStore::withLock($key, function () { throw new RuntimeException('boom'); }); } catch (RuntimeException $e) { echo "D: exception propagated\n"; }
        EnhanceStore::withLock($key, function () { echo "D: lock free again after exception\n"; }, 1);
    }
} catch (Exception $e) {
    printf("%s: refused after %.1fs -> %s\n", strtoupper($mode[0]) === 'T' ? 'B' : $mode, microtime(true) - $t0, $e->getMessage());
}
