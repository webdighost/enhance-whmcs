<?php
/**
 * Enhance daily synchronisation hook (v1.4.0)
 *
 * Runs with the WHMCS daily cron for every enabled Enhance server and mirrors
 * the Enhance suspension state onto all linked services (created or imported).
 * Disk and bandwidth usage are updated by the module's UsageUpdate function,
 * which WHMCS runs when "Update Usage Statistics" is enabled in the cron settings.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

// includes/hooks/<file> -> dirname(__FILE__, 3) is the WHMCS root.
require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceSync.php';

add_hook('DailyCronJob', 1, function () {
    $servers = Capsule::table('tblservers')->where('type', 'enhance')->where('disabled', 0)->get();
    foreach ($servers as $server) {
        try {
            $api = EnhanceApi::fromServerRow($server);
            $result = enhance_sync_server_status($api);
            logActivity(EnhanceLang::t('log.daily_sync', [
                'server'  => (int) $server->id,
                'checked' => $result['checked'],
                'updated' => $result['updated'],
                'deleted' => $result['deleted'],
                'errors'  => $result['errors'],
            ]));
        } catch (Throwable $e) {
            logActivity(EnhanceLang::t('log.daily_sync_failed', ['server' => (int) $server->id, 'detail' => $e->getMessage()]));
        }
    }
});
