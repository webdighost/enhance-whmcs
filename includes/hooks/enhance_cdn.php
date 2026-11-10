<?php
/**
 * WDH-CDN statistics synchronisation hook (v1.4.0)
 *
 * Runs after every WHMCS cron execution and synchronises the CDN statistics when
 * the interval set in Addons > Enhance Manager > WDH-CDN has passed (default 24 h).
 * Does nothing while the integration is disabled or has no API key.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// includes/hooks/<file> -> dirname(__FILE__, 3) is the WHMCS root.
require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceApi.php';
require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceCdnSync.php';

add_hook('AfterCronJob', 1, function () {
    try {
        if (EnhanceCdn::isConfigured() && EnhanceCdnSync::isDue()) {
            EnhanceCdnSync::syncAll();
        }
    } catch (Throwable $e) {
        logActivity(EnhanceLang::t('cdn.log_sync_failed', ['detail' => $e->getMessage()]));
    }
});
