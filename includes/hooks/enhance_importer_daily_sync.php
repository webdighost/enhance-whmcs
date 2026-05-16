<?php
/**
 * Enhance Importer Daily Sync Hook
 * Runs with the WHMCS daily cron. It only synchronises already imported services.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

require_once dirname(__FILE__, 2) . '/modules/servers/enhance/EnhanceApi.php';
require_once dirname(__FILE__, 2) . '/modules/addons/enhance_importer/enhance_importer.php';

add_hook('DailyCronJob', 1, function () {
    try {
        if (!Capsule::schema()->hasTable('mod_enhance_service_import_log')) {
            return;
        }

        $serverIds = Capsule::table('mod_enhance_service_import_log')
            ->select('server_id')
            ->distinct()
            ->pluck('server_id')
            ->all();

        foreach ($serverIds as $serverId) {
            $server = Capsule::table('tblservers')
                ->where('id', (int)$serverId)
                ->where('type', 'enhance')
                ->where('disabled', '0')
                ->first();

            if (!$server) {
                continue;
            }

            $api = new EnhanceApi((string)$server->hostname, (string)$server->username, (string)$server->accesshash);
            $result = enhance_importer_run_daily_sync((int)$server->id, $api);
            logActivity('Enhance Importer daily sync: server #' . (int)$server->id . ', checked ' . (int)$result['checked'] . ', updated ' . (int)$result['updated']);
        }
    } catch (Throwable $e) {
        logActivity('Enhance Importer daily sync failed: ' . $e->getMessage());
    }
});
