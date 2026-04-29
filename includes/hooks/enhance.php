<?php
/**
 * Enhance WHMCS Hook — Admin Client Profile Tab
 *
 * Adds an Enhance widget to the admin client profile page showing org status,
 * subscription/website/email counts and action buttons.
 * All POST actions are CSRF-protected.
 *
 * PT: Adiciona widget Enhance ao perfil do cliente no admin.
 * All POST actions are protected by CSRF validation.
 */

require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceApi.php';

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

add_hook('ClientEdit', 1, function (array $vars): void {
    $server = Capsule::table('tblservers')
        ->where('type', 'enhance')
        ->where('disabled', '0')
        ->select('hostname', 'username', 'accesshash')
        ->first();

    if (!$server || empty($vars['userid'])) return;

    try {
        $client = Capsule::table('tblclients')->where('id', (int) $vars['userid'])->first();
        if (!$client) return;

        $api  = new EnhanceApi($server->hostname, $server->username, $server->accesshash);
        $name = trim((string) ($client->firstname ?? '') . ' ' . (string) ($client->lastname ?? ''));
        $api->syncCustomerFromWhmcs((int) $client->id, $name, (string) $client->email, (string) ($client->companyname ?? ''));
    } catch (Exception $e) {
        logActivity('Enhance client sync failed for client #' . (int) $vars['userid'] . ': ' . $e->getMessage());
    }
});
add_hook('AdminClientProfileTabFields', 1, function (array $vars): array {

    // Resolve the first active Enhance server
    // PT: Encontrar o primeiro servidor Enhance activo
    $server = Capsule::table('tblservers')
        ->where('type', 'enhance')
        ->where('disabled', '0')
        ->select('hostname', 'username', 'accesshash')
        ->first();

    if (!$server) return [];

    $api    = new EnhanceApi($server->hostname, $server->username, $server->accesshash);
    $userId = (int) $vars['userid'];
    $orgId  = $api->getClientOrgId($userId);

    // -------------------------------------------------------------------------
    // Handle POST actions (CSRF-protected)
    // -------------------------------------------------------------------------

    $feedback = '';

    if (!empty($_POST['enhance_action'])) {
        if (empty($_POST['token']) || !check_token('CSRF_enhance_' . $userId, $_POST['token'])) {
            $feedback = _ehAlert('danger', 'Invalid security token.');
        } else {
            $action    = $_POST['enhance_action'];
            $postOrgId = $_POST['orgId'] ?? $orgId;

            switch ($action) {
                case 'linkOrg':
                    $newOrgId = trim($_POST['newOrgId'] ?? '');
                    if ($newOrgId !== '') {
                        Capsule::table('tblcustomfieldsvalues')
                            ->where(['fieldid' => $api->clientOrgFieldId, 'relid' => $userId])
                            ->delete();
                        Capsule::table('tblcustomfieldsvalues')
                            ->insert(['fieldid' => $api->clientOrgFieldId, 'relid' => $userId, 'value' => $newOrgId]);
                        $orgId    = $newOrgId;
                        $feedback = _ehAlert('success', 'Organisation linked.')
                                  . '<meta http-equiv="refresh" content="2">';
                    }
                    break;

                case 'resetPassword':
                    $orgInfo = $api->getOrg($postOrgId);
                    $email   = $orgInfo['ownerEmail'] ?? '';
                    $resp    = $email ? $api->triggerPasswordRecovery($email) : ['code' => 'no_email'];
                    $feedback = empty($resp['code'])
                        ? _ehAlert('success', 'Password reset email sent.')
                        : _ehAlert('danger', 'Error: ' . ($resp['message'] ?? $resp['code']));
                    break;

                case 'suspendOrg':
                    $resp     = $api->setOrgSuspended($postOrgId, true);
                    $feedback = empty($resp['code'])
                        ? _ehAlert('success', 'Organisation suspended.')
                              . '<meta http-equiv="refresh" content="2">'
                        : _ehAlert('danger', 'Error: ' . ($resp['message'] ?? $resp['code']));
                    break;

                case 'reactivateOrg':
                    $resp     = $api->setOrgSuspended($postOrgId, false);
                    $feedback = empty($resp['code'])
                        ? _ehAlert('success', 'Organisation reactivated.')
                              . '<meta http-equiv="refresh" content="2">'
                        : _ehAlert('danger', 'Error: ' . ($resp['message'] ?? $resp['code']));
                    break;

                case 'deleteOrg':
                    $resp = $api->deleteOrg($postOrgId);
                    if (empty($resp['code'])) {
                        Capsule::table('tblcustomfieldsvalues')
                            ->where(['fieldid' => $api->clientOrgFieldId, 'relid' => $userId])
                            ->delete();
                        $orgId    = null;
                        $feedback = _ehAlert('success', 'Organisation deleted.')
                                  . '<meta http-equiv="refresh" content="2">';
                    } else {
                        $feedback = _ehAlert('danger', 'Error: ' . ($resp['message'] ?? $resp['code']));
                    }
                    break;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Build UI
    // -------------------------------------------------------------------------

    $token    = generate_token('plain');
    $fieldKey = 'customfield' . $api->clientOrgFieldId;
    $content  = $feedback;

    if ($orgId) {

        $orgInfo   = $api->getOrg($orgId);
        $emailInfo = $api->getEmails($orgId);

        if (isset($orgInfo['id'])) {

            $suspended   = !empty($orgInfo['suspendedBy']);
            $statusLabel = $suspended ? 'Suspended' : ucfirst($orgInfo['status'] ?? 'active');
            $subCount    = (int) ($orgInfo['subscriptionsCount'] ?? 0);
            $siteCount   = (int) ($orgInfo['websitesCount']      ?? 0);
            $emailCount  = (int) ($emailInfo['total']            ?? 0);

            $badges = _ehBadge('status-badge-green',  'fa-thermometer-empty', $statusLabel, 'Status')
                    . _ehBadge('status-badge-orange', 'fa-users',   $subCount,   'Subscriptions')
                    . _ehBadge('status-badge-pink',   'fa-globe',   $siteCount,  'Websites')
                    . _ehBadge('status-badge-cyan',   'fa-envelope',$emailCount, 'Emails');

            $content .= <<<HTML
<div style="background:#372c62;padding:10px 16px;border-radius:6px;margin-bottom:12px;">
    <img width="130" src="https://community.enhance.com/assets/logo-uf00slfz.png" alt="Enhance">
</div>
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    {$badges}
</div>
HTML;
            // Action buttons
            $content .= '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            if ($suspended) {
                $content .= _ehBtn($userId, $orgId, 'reactivateOrg', 'btn-success', 'Reactivate / Reactivar', $token);
            } else {
                $content .= _ehBtn($userId, $orgId, 'suspendOrg', 'btn-warning', 'Suspend / Suspender', $token);
            }
            $content .= _ehBtn($userId, $orgId, 'resetPassword', 'btn-primary', 'Reset Password / Repor Senha', $token);
            $content .= _ehBtn($userId, $orgId, 'deleteOrg',    'btn-danger',  'Delete Org / Eliminar Org',    $token, true);
            $content .= '</div>';

        } else {
            $content .= _ehAlert('warning', "Could not load org {$orgId}.");
        }

    } else {

        // No org yet — show link form
        $customers = $api->getCustomers();
        $options   = '<option value="">— Select —</option>';
        foreach ($customers['items'] ?? [] as $c) {
            $cid   = htmlspecialchars($c['id'],            ENT_QUOTES);
            $cname = htmlspecialchars($c['name'],          ENT_QUOTES);
            $cemail= htmlspecialchars($c['ownerEmail'] ?? '', ENT_QUOTES);
            $options .= "<option value=\"{$cid}\">{$cname} — {$cemail}</option>";
        }

        $content .= <<<HTML
<form method="POST" style="margin-top:8px;">
    <input type="hidden" name="enhance_action" value="linkOrg">
    <input type="hidden" name="token" value="{$token}">
    <input type="hidden" name="userid" value="{$userId}">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <select name="newOrgId" class="form-control" style="max-width:340px;">{$options}</select>
        <button type="submit" class="btn btn-primary btn-sm">Link Org / Vincular Org</button>
    </div>
</form>
HTML;
    }

    $contentJson = json_encode($content);

    return [
        '' => <<<JS
<script>
document.addEventListener("DOMContentLoaded", function () {
    var field = document.getElementById("{$fieldKey}");
    if (!field) return;
    var wrap = document.createElement("div");
    wrap.style.marginTop = "14px";
    wrap.innerHTML = {$contentJson};
    var tr = field.closest("tr");
    if (tr) {
        var newRow = document.createElement("tr");
        newRow.innerHTML = "<td colspan='2'>" + wrap.innerHTML + "</td>";
        tr.parentNode.insertBefore(newRow, tr.nextSibling);
    } else {
        field.parentNode.appendChild(wrap);
    }
});
</script>
JS,
    ];
});

// =============================================================================
// Helpers (procedural so they work outside closures)
// =============================================================================

function _ehAlert(string $type, string $msg): string
{
    return '<div class="alert alert-' . $type . '" role="alert">' . htmlspecialchars($msg, ENT_QUOTES) . '</div>';
}

function _ehBadge(string $class, string $icon, $count, string $label): string
{
    return <<<HTML
<div class="health-status-block {$class} clearfix" style="min-width:130px;">
    <div class="icon" style="float:right;"><i class="fas {$icon}"></i></div>
    <div class="detail">
        <span class="count">{$count}</span>
        <span class="desc">{$label}</span>
    </div>
</div>
HTML;
}

function _ehBtn(int $userId, string $orgId, string $action, string $cls, string $label, string $token, bool $confirm = false): string
{
    $conf = $confirm ? ' onclick="return confirm(\'Are you sure? / Tem a certeza?\')"' : '';
    return <<<HTML
<form method="POST" style="display:inline;">
    <input type="hidden" name="enhance_action" value="{$action}">
    <input type="hidden" name="orgId"          value="{$orgId}">
    <input type="hidden" name="userid"         value="{$userId}">
    <input type="hidden" name="token"          value="{$token}">
    <button type="submit" class="btn {$cls} btn-sm"{$conf}>{$label}</button>
</form>
HTML;
}

// =============================================================================
// Daily synchronisation for imported Enhance services
// Daily synchronization for imported Enhance services
// =============================================================================
add_hook('DailyCronJob', 1, function (array $vars): void {
    $addon = ROOTDIR . '/modules/addons/enhance_importer/enhance_importer.php';
    if (!file_exists($addon)) {
        return;
    }
    require_once $addon;
    if (!function_exists('enhance_importer_run_daily_sync')) {
        return;
    }
    try {
        $result = enhance_importer_run_daily_sync(true, 0);
        logActivity('Enhance daily sync completed: ' . ($result['message'] ?? 'done'));
    } catch (Exception $e) {
        logActivity('Enhance daily sync failed: ' . $e->getMessage());
    }
});
