<?php
/**
 * Enhance Importer - Synchronisation tab: run the status synchronisation and the connection test on demand.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

function enhance_importer_sync_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if (enhance_importer_valid_post('run_daily_sync')) {
        $result = enhance_sync_server_status($api);
        $feedback = enhance_importer_alert('success', enhance_importer_t('imp.sync_done', [
            'checked' => (int) $result['checked'],
            'updated' => (int) $result['updated'],
            'deleted' => (int) $result['deleted'],
            'errors'  => (int) $result['errors'],
        ]));
    }
    if (enhance_importer_valid_post('test_connection')) {
        $test = $api->testConnection();
        $feedback = !empty($test['success'])
            ? enhance_importer_alert('success', enhance_importer_t('imp.test_ok'))
            : enhance_importer_alert('danger', enhance_importer_h($test['error'] ?? ''));
    }
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'sync');
    $action = enhance_importer_h($moduleLink . '&tab=sync&server_id=' . $selectedServerId . '&gid=' . $selectedGroupId . '&servergroupid=' . $selectedServerGroupId);
    $token  = enhance_importer_token_field();
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . enhance_importer_t('imp.tab_sync') . '</strong></div><div class="panel-body">';
    echo '<p>' . enhance_importer_t('imp.sync_help') . '</p>';
    echo '<form method="post" action="' . $action . '" style="display:inline-block;margin-right:8px;"><input type="hidden" name="enhance_importer_action" value="run_daily_sync">' . $token . '<button class="btn btn-primary" type="submit">' . enhance_importer_t('imp.sync_run') . '</button></form>';
    echo '<form method="post" action="' . $action . '" style="display:inline-block;"><input type="hidden" name="enhance_importer_action" value="test_connection">' . $token . '<button class="btn btn-default" type="submit">' . enhance_importer_t('imp.test_run') . '</button></form>';
    echo '</div></div>';
}
