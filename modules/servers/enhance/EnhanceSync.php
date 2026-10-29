<?php
/**
 * Enhance WHMCS Module - status and usage synchronisation (v1.4.0)
 * Shared by the daily cron hook, the importer addon and the server module.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceApi.php';

use WHMCS\Database\Capsule;

if (!function_exists('enhance_sync_server_status')) {
    /**
     * Mirrors the Enhance suspension state onto every linked service of a server.
     * Only toggles between Active and Suspended; never touches Pending, Terminated,
     * Cancelled or Fraud services, and never reactivates a service whose
     * subscription was deleted in Enhance or could not be read.
     */
    function enhance_sync_server_status(EnhanceApi $api): array
    {
        $checked = 0; $updated = 0; $errors = 0; $deleted = 0;
        foreach (EnhanceStore::servicesForServer($api->getServerId()) as $row) {
            $checked++;
            $orgId = $row->org_id ?: EnhanceStore::clientOrg((int) $row->userid, (int) $row->server_id);
            if (!$orgId) { $errors++; continue; }

            $sub = $api->getSubscription((string) $orgId, (string) $row->subscription_id);
            if (EnhanceApi::isError($sub)) {
                $errors++;
                continue;
            }
            if (($sub['status'] ?? '') === 'deleted') {
                $deleted++;
                if (function_exists('logActivity')) logActivity(EnhanceLang::t('log.sync_deleted', ['sub' => $row->subscription_id, 'service' => (int) $row->service_id, 'status' => $row->domainstatus]));
                continue;
            }

            $newStatus = (!empty($sub['isSuspended']) || !empty($sub['suspendedBy'])) ? 'Suspended' : 'Active';
            if ($row->domainstatus !== $newStatus) {
                $result = localAPI('UpdateClientProduct', ['serviceid' => (int) $row->service_id, 'status' => $newStatus]);
                if (($result['result'] ?? '') === 'success') {
                    $updated++;
                    if (function_exists('logActivity')) logActivity(EnhanceLang::t('log.sync_updated', ['service' => (int) $row->service_id, 'status' => $newStatus]));
                } else {
                    $errors++;
                }
            }
        }
        return ['checked' => $checked, 'updated' => $updated, 'errors' => $errors, 'deleted' => $deleted];
    }

    /** Writes Enhance disk and transfer usage (bytes -> MB) into tblhosting. */
    function enhance_store_service_usage(EnhanceApi $api, int $serviceId, string $orgId, string $subId, ?array $sub = null): bool
    {
        if ($sub === null) {
            $sub = $api->getSubscription($orgId, $subId);
            if (EnhanceApi::isError($sub)) return false;
        }
        $mb = fn($bytes) => $bytes === null ? 0 : (int) round($bytes / 1048576);
        $disk = EnhanceApi::resourceUsage($sub, 'diskspace');
        $transfer = EnhanceApi::resourceUsage($sub, 'transfer');
        $bandwidth = $api->getSubscriptionBandwidth($orgId, $subId);

        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'diskusage'  => $mb($disk['usage']),
            'disklimit'  => $mb($disk['total']),          // 0 = unlimited
            'bwusage'    => $mb($bandwidth ?? $transfer['usage']),
            'bwlimit'    => $mb($transfer['total']),      // 0 = unlimited
            'lastupdate' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    function enhance_update_server_usage(EnhanceApi $api): array
    {
        $checked = 0; $errors = 0;
        foreach (EnhanceStore::servicesForServer($api->getServerId()) as $row) {
            $checked++;
            $orgId = $row->org_id ?: EnhanceStore::clientOrg((int) $row->userid, (int) $row->server_id);
            if (!$orgId || !enhance_store_service_usage($api, (int) $row->service_id, (string) $orgId, (string) $row->subscription_id)) {
                $errors++;
            }
        }
        return ['checked' => $checked, 'errors' => $errors];
    }
}
