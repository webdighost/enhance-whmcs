<?php
/**
 * Enhance WHMCS Hook - admin client profile widget (v1.4.0)
 *
 * Adds an Enhance panel to the admin client profile with the organisation
 * status, subscription/website/email counts and action buttons, and keeps the
 * organisation name in sync when the client profile is edited.
 *
 * The widget is injected inside the WHMCS profile <form>, so its buttons do not
 * use nested forms (browsers drop them, which made every button submit the
 * whole profile). Each action posts its own form, built on click.
 * All actions are CSRF-protected.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceApi.php';

use WHMCS\Database\Capsule;

/**
 * Enhance server for a client: the server of its first active Enhance service,
 * otherwise the first enabled Enhance server.
 */
function _ehServerForClient(int $userId)
{
    $columns = ['s.id', 's.hostname', 's.ipaddress', 's.username', 's.password', 's.accesshash'];
    $server = Capsule::table('tblhosting as h')
        ->join('tblservers as s', 's.id', '=', 'h.server')
        ->where('h.userid', $userId)
        ->where('s.type', 'enhance')
        ->where('s.disabled', 0)
        ->whereIn('h.domainstatus', ['Active', 'Suspended', 'Pending'])
        ->orderBy('h.id')
        ->select($columns)
        ->first();
    if ($server) return $server;

    return Capsule::table('tblservers as s')
        ->where('s.type', 'enhance')
        ->where('s.disabled', 0)
        ->orderBy('s.id')
        ->select($columns)
        ->first();
}

add_hook('ClientEdit', 1, function (array $vars): void {
    if (empty($vars['userid'])) return;
    $userId = (int) $vars['userid'];
    try {
        $server = _ehServerForClient($userId);
        if (!$server) return;
        $client = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$client) return;

        $api = EnhanceApi::fromServerRow($server);
        // Profile edits only rename an already linked organisation.
        $name = trim((string) ($client->firstname ?? '') . ' ' . (string) ($client->lastname ?? ''));
        $result = $api->updateCustomerOrgName($userId, $name, (string) $client->email, (string) ($client->companyname ?? ''));
        if (empty($result['success'])) {
            logActivity(EnhanceLang::t('log.client_sync_failed', ['client' => $userId, 'detail' => $result['message'] ?? '']));
        }
    } catch (Throwable $e) {
        logActivity(EnhanceLang::t('log.client_sync_failed', ['client' => $userId, 'detail' => $e->getMessage()]));
    }
});

add_hook('AdminClientProfileTabFields', 1, function (array $vars): array {
    $userId = (int) ($vars['userid'] ?? 0);
    if ($userId <= 0) return [];

    try {
        $server = _ehServerForClient($userId);
        if (!$server) return [];
        $api = EnhanceApi::fromServerRow($server);
    } catch (Throwable $e) {
        return ['Enhance' => _ehAlert('danger', $e->getMessage())];
    }

    $masterOrgId = $api->getMasterOrgId();
    $orgId = $api->getClientOrgId($userId);
    $orgId = $orgId ? strtolower(trim($orgId)) : null;

    // -------------------------------------------------------------------------
    // POST actions (CSRF-protected)
    // -------------------------------------------------------------------------

    $feedback = '';

    if (!empty($_POST['enhance_action'])) {
        $tokenOk = false;
        try { $tokenOk = !empty($_POST['token']) && check_token('WHMCS.admin.default', $_POST['token']); } catch (Throwable $e) { $tokenOk = false; }
        if (!$tokenOk) {
            $feedback = _ehAlert('danger', EnhanceLang::t('widget.bad_token'));
        } elseif ((int) ($_POST['userid'] ?? 0) !== $userId) {
            $feedback = _ehAlert('danger', EnhanceLang::t('widget.bad_client'));
        } else {
            $action = (string) $_POST['enhance_action'];
            // Actions always target the organisation stored on this client, never a posted id.
            $currentOrg = (string) $orgId;
            if (in_array($action, ['resetPassword', 'suspendOrg', 'reactivateOrg', 'unlinkOrg'], true) && $currentOrg === '') {
                $action = '';
                $feedback = _ehAlert('danger', EnhanceLang::t('widget.no_org'));
            }

            try {
                switch ($action) {
                    case 'searchOrg':
                        // Read-only: the results are rendered in the link form below.
                        break;

                    case 'linkOrg':
                        $newOrgId = strtolower(trim((string) ($_POST['newOrgId'] ?? '')));
                        if ($newOrgId === '') {
                            $feedback = _ehAlert('warning', EnhanceLang::t('widget.choose_org'));
                            break;
                        }
                        if (!EnhanceStore::isUuid($newOrgId)) {
                            $feedback = _ehAlert('danger', EnhanceLang::t('store.org_uuid_invalid'));
                            break;
                        }
                        $candidate = $api->getOrg($newOrgId);
                        if (EnhanceApi::isError($candidate) || strtolower((string) ($candidate['parentId'] ?? '')) !== $masterOrgId || ($candidate['status'] ?? '') === 'deleted') {
                            $feedback = _ehAlert('danger', EnhanceLang::t('widget.org_not_ours'));
                            break;
                        }
                        EnhanceStore::withLock(EnhanceStore::clientLockKey((int) $server->id, $userId), fn() => $api->saveClientOrgId($userId, $newOrgId));
                        _ehCacheClear($newOrgId);
                        $orgId = $newOrgId;
                        $feedback = _ehAlert('success', EnhanceLang::t('widget.linked', ['server' => (int) $server->id]));
                        break;

                    case 'resetPassword':
                        $orgInfo = $api->getOrg($currentOrg);
                        $email   = (string) ($orgInfo['ownerEmail'] ?? '');
                        $resp    = $email !== '' ? $api->triggerPasswordRecovery($email) : ['code' => 'no_owner_email', 'detail' => EnhanceLang::t('svc.no_owner_email'), '_httpCode' => 0];
                        $feedback = !EnhanceApi::isError($resp)
                            ? _ehAlert('success', EnhanceLang::t('widget.recovery_sent', ['email' => $email]))
                            : _ehAlert('danger', EnhanceLang::t('widget.error', ['detail' => EnhanceApi::errorSummary($resp)]));
                        break;

                    case 'suspendOrg':
                    case 'reactivateOrg':
                        $suspend = $action === 'suspendOrg';
                        $resp = $api->setOrgSuspended($currentOrg, $suspend);
                        if (!EnhanceApi::isError($resp)) {
                            _ehCacheClear($currentOrg);
                            $feedback = _ehAlert('success', EnhanceLang::t($suspend ? 'widget.suspended' : 'widget.reactivated'));
                        } else {
                            $feedback = _ehAlert('danger', EnhanceLang::t('widget.error', ['detail' => EnhanceApi::errorSummary($resp)]));
                        }
                        break;

                    case 'deleteOrg': // Old cached pages must also be safe: never deletes in Enhance.
                    case 'unlinkOrg':
                        $api->clearClientOrgId($userId);
                        _ehCacheClear($currentOrg);
                        $orgId = null;
                        $feedback = _ehAlert('success', EnhanceLang::t('widget.unlinked'));
                        break;
                }
            } catch (Throwable $e) {
                $feedback = _ehAlert('danger', $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Build UI
    // -------------------------------------------------------------------------

    $token   = (string) generate_token('plain');
    $content = $feedback;
    $serverLabel = htmlspecialchars(EnhanceLang::t('widget.server', ['server' => (int) $server->id, 'host' => $api->getHost(), 'version' => EnhanceApi::VERSION]), ENT_QUOTES, 'UTF-8');
    $content .= <<<HTML
<div style="background:#372c62;padding:10px 16px;border-radius:6px;margin-bottom:12px;">
    <strong style="color:#fff;font-size:15px;letter-spacing:.3px;">Enhance</strong>
    <span style="color:rgba(255,255,255,.75);font-size:12px;margin-left:8px;">{$serverLabel}</span>
</div>
HTML;

    if ($orgId) {
        $orgInfo   = _ehCacheGet('org_' . $orgId, 90, fn() => $api->getOrg($orgId));
        $emailInfo = _ehCacheGet('emails_' . $orgId, 90, fn() => $api->getEmails($orgId));

        if (!EnhanceApi::isError($orgInfo) && isset($orgInfo['id'])) {
            $suspended   = !empty($orgInfo['suspendedBy']) || !empty($orgInfo['isSuspended']);
            $statusLabel = htmlspecialchars($suspended ? EnhanceLang::t('widget.status_suspended') : EnhanceLang::t('widget.status_' . (($orgInfo['status'] ?? 'active') === 'deleted' ? 'deleted' : 'active')), ENT_QUOTES, 'UTF-8');
            $subCount    = (int) ($orgInfo['subscriptionsCount'] ?? 0);
            $siteCount   = (int) ($orgInfo['websitesCount'] ?? 0);
            $emailCount  = EnhanceApi::isError($emailInfo) ? '-' : (int) ($emailInfo['total'] ?? 0);
            $orgLabel    = htmlspecialchars((string) ($orgInfo['name'] ?? '') . ' · ' . $orgId, ENT_QUOTES, 'UTF-8');
            $ownerLabel  = htmlspecialchars((string) ($orgInfo['ownerEmail'] ?? ''), ENT_QUOTES, 'UTF-8');

            $content .= '<p style="margin:0 0 10px;"><strong>' . $orgLabel . '</strong>' . ($ownerLabel !== '' ? '<br><small>' . htmlspecialchars(EnhanceLang::t('widget.owner'), ENT_QUOTES, 'UTF-8') . ': ' . $ownerLabel . '</small>' : '') . '</p>';
            $content .= '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">'
                . _ehBadge('status-badge-green',  'fa-thermometer-empty', $statusLabel, EnhanceLang::t('widget.status'))
                . _ehBadge('status-badge-orange', 'fa-users',    $subCount,   EnhanceLang::t('widget.subscriptions'))
                . _ehBadge('status-badge-pink',   'fa-globe',    $siteCount,  EnhanceLang::t('widget.websites'))
                . _ehBadge('status-badge-cyan',   'fa-envelope', $emailCount, EnhanceLang::t('widget.emails'))
                . '</div>';

            $content .= '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            $content .= $suspended
                ? _ehBtn('reactivateOrg', 'btn-success', EnhanceLang::t('widget.btn_reactivate'))
                : _ehBtn('suspendOrg', 'btn-warning', EnhanceLang::t('widget.btn_suspend'), EnhanceLang::t('widget.confirm_suspend'));
            $content .= _ehBtn('resetPassword', 'btn-primary', EnhanceLang::t('widget.btn_reset'), EnhanceLang::t('widget.confirm_reset'));
            $content .= _ehBtn('unlinkOrg', 'btn-default', EnhanceLang::t('widget.btn_unlink'), EnhanceLang::t('widget.confirm_unlink'));
            $content .= '</div>';
        } else {
            $content .= _ehAlert('warning', EnhanceLang::t('widget.org_load_failed', ['org' => $orgId, 'detail' => EnhanceApi::errorSummary($orgInfo)]));
            $content .= _ehBtn('unlinkOrg', 'btn-default', EnhanceLang::t('widget.btn_unlink'), EnhanceLang::t('widget.confirm_unlink'));
        }
    } else {
        // No organisation yet: search form. Only searches when asked, so opening a
        // client profile never lists every customer of the Enhance panel.
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $searched = isset($_POST['enhance_action']) && $_POST['enhance_action'] === 'searchOrg' && $tokenOk;
        $term = $searched ? trim((string) ($_POST['enhance_search'] ?? '')) : '';
        if (!$searched) {
            $client = Capsule::table('tblclients')->where('id', $userId)->first();
            $term = $client ? trim((string) (($client->companyname ?? '') ?: trim($client->firstname . ' ' . $client->lastname))) : '';
        }

        $content .= '<p style="margin:0 0 8px;">' . $h(EnhanceLang::t('widget.not_linked')) . '</p>'
            . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">'
            . '<input type="text" data-enhance-search="1" class="form-control" style="max-width:380px;" value="' . $h($term) . '" placeholder="' . $h(EnhanceLang::t('widget.search_placeholder')) . '">'
            . _ehBtn('searchOrg', 'btn-default', EnhanceLang::t('widget.btn_search'))
            . '</div>';

        if ($searched) {
            if (mb_strlen($term) < 2) {
                $content .= _ehAlert('warning', EnhanceLang::t('widget.search_short'));
            } else {
                $found = $api->searchCustomers($term);
                if (EnhanceApi::isError($found)) {
                    $content .= _ehAlert('danger', 'Enhance: ' . EnhanceApi::errorSummary($found));
                } else {
                    $options = '';
                    foreach ($found['items'] ?? [] as $c) {
                        if (($c['status'] ?? '') === 'deleted' || empty($c['id'])) continue;
                        $linkedTo = EnhanceStore::clientByOrg((int) $server->id, (string) $c['id']);
                        if ($linkedTo && $linkedTo !== $userId) continue;
                        $options .= '<option value="' . $h($c['id']) . '">' . $h(($c['name'] ?? '') . ' - ' . ($c['ownerEmail'] ?? '') . ' (' . $c['id'] . ')') . '</option>';
                    }
                    if ($options === '') {
                        $content .= _ehAlert('info', EnhanceLang::t('widget.search_none', ['term' => $term]));
                    } else {
                        $more = (int) ($found['total'] ?? 0) > count($found['items'] ?? []) ? '<small>' . $h(EnhanceLang::t('widget.search_more', ['total' => (int) $found['total']])) . '</small>' : '';
                        $content .= '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
                            . '<select data-enhance-org-select="1" class="form-control" style="max-width:520px;">' . $options . '</select>'
                            . _ehBtn('linkOrg', 'btn-primary', EnhanceLang::t('widget.btn_link'))
                            . '</div>' . $more;
                    }
                }
            }
        }
    }

    $payload = json_encode([
        'html'   => $content,
        'userid' => $userId,
        'token'  => $token,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $fieldKey = 'customfield' . $api->clientOrgFieldId();

    return [
        '' => <<<JS
<div id="enhance-widget-anchor"></div>
<script>
(function () {
    var data = {$payload};
    function submitAction(action, extra) {
        // Standalone form appended to <body>: never nested in the profile form.
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        var fields = { enhance_action: action, userid: data.userid, token: data.token };
        for (var k in extra) { fields[k] = extra[k]; }
        for (var name in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = fields[name];
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }
    function mount() {
        var wrap = document.createElement('div');
        wrap.className = 'enhance-widget';
        wrap.style.marginTop = '14px';
        wrap.innerHTML = data.html;
        var field = document.getElementById('{$fieldKey}');
        var row = field ? field.closest('tr') : null;
        if (row) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 4;
            td.appendChild(wrap);
            tr.appendChild(td);
            row.parentNode.insertBefore(tr, row.nextSibling);
        } else {
            var anchor = document.getElementById('enhance-widget-anchor');
            (anchor ? anchor.parentNode : document.body).appendChild(wrap);
        }
        wrap.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && ev.target.hasAttribute('data-enhance-search')) {
                // Do not submit the surrounding profile form.
                ev.preventDefault();
                var btn = wrap.querySelector('[data-enhance-action="searchOrg"]');
                if (btn) btn.click();
            }
        });
        wrap.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-enhance-action]');
            if (!btn) return;
            ev.preventDefault();
            var confirmText = btn.getAttribute('data-enhance-confirm');
            if (confirmText && !window.confirm(confirmText)) return;
            var extra = {};
            var action = btn.getAttribute('data-enhance-action');
            if (action === 'linkOrg') {
                var select = wrap.querySelector('[data-enhance-org-select]');
                extra.newOrgId = select ? select.value : '';
            }
            if (action === 'searchOrg') {
                var input = wrap.querySelector('[data-enhance-search]');
                extra.enhance_search = input ? input.value : '';
            }
            submitAction(action, extra);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
</script>
JS,
    ];
});

// =============================================================================
// Helpers
// =============================================================================

function _ehAlert(string $type, string $msg): string
{
    return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" role="alert">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * Short-lived cache for the widget's API reads, stored in WHMCS TransientData
 * (database), so it works with several web nodes and leaves no temp files.
 */
function _ehCacheStore()
{
    return class_exists('WHMCS\\TransientData') ? \WHMCS\TransientData::getInstance() : null;
}

function _ehCacheGet(string $key, int $ttlSeconds, callable $fetch): array
{
    $store = _ehCacheStore();
    $key = 'enhance_widget_' . md5($key);
    if ($store) {
        $cached = $store->retrieve($key);
        $decoded = is_string($cached) ? json_decode($cached, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $data = $fetch();
    if ($store && is_array($data) && !EnhanceApi::isError($data)) {
        $store->store($key, json_encode($data), $ttlSeconds);
    }
    return $data;
}

function _ehCacheClear(string $orgId): void
{
    $store = _ehCacheStore();
    if (!$store || $orgId === '') return;
    $store->delete('enhance_widget_' . md5('org_' . $orgId));
    $store->delete('enhance_widget_' . md5('emails_' . $orgId));
}

function _ehBadge(string $class, string $icon, $count, string $label): string
{
    $count = htmlspecialchars((string) $count, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
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

function _ehBtn(string $action, string $cls, string $label, string $confirm = ''): string
{
    $action  = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $label   = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $confirm = $confirm !== '' ? ' data-enhance-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"' : '';
    return "<button type=\"button\" class=\"btn {$cls} btn-sm\" data-enhance-action=\"{$action}\"{$confirm}>{$label}</button>";
}
