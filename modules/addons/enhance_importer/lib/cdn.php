<?php
/**
 * Enhance Manager - WDH-CDN tab: integration settings (encrypted API key), plans,
 * connection test, synchronisation on demand, overview of every website with CDN
 * and the admin alerts. Needs no Enhance server.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// modules/addons/enhance_importer/lib/cdn.php -> dirname(__FILE__, 4) is the modules folder.
require_once dirname(__FILE__, 4) . '/servers/enhance/EnhanceCdnSync.php';

function enhance_importer_cdn_page(string $moduleLink): void
{
    $action = $moduleLink . '&tab=cdn';
    $feedback = '';
    try {
        if (enhance_importer_valid_post('cdn_save')) {
            if (!empty($_POST['cdn_api_key_remove'])) {
                EnhanceSettings::clearSecret('cdn_api_key');
            }
            $invalid = EnhanceSettings::save($_POST, EnhanceSettings::keys('cdn_'));
            $feedback = $invalid
                ? enhance_importer_alert('warning', enhance_importer_t('set.saved_invalid', ['fields' => implode(', ', array_map(fn($k) => EnhanceLang::t('cdnset.' . $k), $invalid))]))
                : enhance_importer_alert('success', enhance_importer_t('set.saved'));
        } elseif (enhance_importer_valid_post('cdn_test')) {
            $test = EnhanceCdn::fromSettings()->testConnection();
            $feedback = $test['success']
                ? enhance_importer_alert('success', enhance_importer_t('cdnset.test_ok'))
                : enhance_importer_alert('danger', enhance_importer_t('cdnset.test_failed', ['detail' => $test['error']]));
        } elseif (enhance_importer_valid_post('cdn_sync')) {
            $r = EnhanceCdnSync::syncAll();
            $feedback = enhance_importer_alert($r['errors'] ? 'warning' : 'success', enhance_importer_t('cdn.log_sync', $r));
        } elseif (enhance_importer_valid_post('cdn_ack')) {
            EnhanceCdnStore::acknowledge((int) ($_POST['alert_id'] ?? 0));
            $feedback = enhance_importer_alert('success', enhance_importer_t('cdnset.ack_done'));
        }
    } catch (Throwable $e) {
        $feedback = enhance_importer_alert('danger', enhance_importer_t('imp.error', ['detail' => $e->getMessage()]));
    }
    echo $feedback;
    echo enhance_importer_cdn_form(EnhanceSettings::all(), $action);
    echo enhance_importer_cdn_overview($action);
}

function enhance_importer_cdn_form(array $s, string $action): string
{
    $h = 'enhance_importer_h';
    $t = fn(string $k, array $v = []) => enhance_importer_t($k, $v);
    $token = enhance_importer_token_field();
    $lock = fn(string $k) => $s[$k]['locked'] ? ' <span class="label label-default">' . $t('set.locked', ['constant' => $s[$k]['constant']]) . '</span>' : '';
    $dis = fn(string $k) => $s[$k]['locked'] ? ' disabled' : '';
    $check = fn(string $k) => '<div class="checkbox" style="margin:6px 0;"><label><input type="checkbox" name="' . $h($k) . '" value="1"' . ($s[$k]['value'] ? ' checked' : '') . $dis($k) . '> <strong>' . $t('cdnset.' . $k) . '</strong> <span class="text-muted">' . $t('cdnset.' . $k . '_help') . '</span></label>' . $lock($k) . '</div>';
    $num = fn(string $k, string $suffix = '') => '<div class="form-group" style="margin-right:18px;"><label>' . $t('cdnset.' . $k) . '</label>' . $lock($k)
        . '<div style="display:flex;align-items:center;gap:6px;"><input type="number" class="form-control" style="width:120px;" name="' . $h($k) . '" min="' . (int) $s[$k]['min'] . '" max="' . (int) $s[$k]['max'] . '" value="' . $h($s[$k]['value']) . '"' . $dis($k) . '>' . $suffix . '</div></div>';
    $panel = fn(string $title, string $body) => '<div class="panel panel-default"><div class="panel-heading"><strong>' . $title . '</strong></div><div class="panel-body">' . $body . '</div></div>';

    $key = $s['cdn_api_key'];
    $keyState = $key['configured']
        ? '<span class="label label-success">' . $t('cdnset.key_configured', ['hint' => '••••' . $key['hint']]) . '</span>'
        : '<span class="label label-warning">' . $t('cdnset.key_missing') . '</span>';
    $integration = $check('cdn_enabled')
        . '<div class="form-group"><label for="enh-cdn-key">' . $t('cdnset.cdn_api_key') . '</label> ' . $keyState . $lock('cdn_api_key')
        . '<input type="password" class="form-control" style="max-width:420px;" id="enh-cdn-key" name="cdn_api_key" value="" autocomplete="new-password" placeholder="' . $t($key['configured'] ? 'cdnset.key_keep' : 'cdnset.key_paste') . '"' . $dis('cdn_api_key') . '>'
        . '<p class="help-block">' . $t('cdnset.cdn_api_key_help', ['provider' => EnhanceCdn::PROVIDER]) . '</p>'
        . ($key['configured'] && !$key['locked'] ? '<label style="font-weight:normal;"><input type="checkbox" name="cdn_api_key_remove" value="1"> ' . $t('cdnset.key_remove') . '</label>' : '')
        . '</div>' . $check('cdn_show_inactive');
    $sync = '<div style="display:flex;flex-wrap:wrap;">' . $num('cdn_sync_hours', $t('cdnset.hours')) . $num('cdn_retention_months', $t('cdnset.months')) . '</div>' . $check('cdn_debug');
    $last = EnhanceCdnSync::lastRun();
    $sync .= '<p class="text-muted">' . $t('cdnset.last_run', ['date' => $last ? date(EnhanceLang::t('fmt.date') . ' H:i', $last) : '-']) . '</p>';
    $alerts = $check('cdn_alerts') . '<div style="display:flex;flex-wrap:wrap;">' . $num('cdn_warn_pct', '%') . $num('cdn_crit_pct', '%') . '</div>'
        . '<p class="help-block">' . $t('cdnset.alerts_help') . '</p>';
    $plans = '<table class="table" style="max-width:640px;"><thead><tr><th>' . $t('cdnset.plan') . '</th><th>' . $t('cdnset.plan_gb') . '</th><th>' . $t('cdnset.plan_price') . '</th></tr></thead><tbody>';
    foreach (EnhanceSettings::CDN_PLANS as $plan) {
        $gb = 'cdn_plan_' . $plan . '_gb';
        $price = 'cdn_plan_' . $plan . '_price';
        $plans .= '<tr><td><strong>' . $h(EnhanceLang::t('cdn.plan_' . $plan)) . '</strong></td>'
            . '<td><input type="number" class="form-control input-sm" style="width:110px;" min="1" name="' . $gb . '" value="' . $h($s[$gb]['value']) . '"' . $dis($gb) . '></td>'
            . '<td><div style="display:flex;align-items:center;gap:6px;"><input type="text" class="form-control input-sm" style="width:110px;" name="' . $price . '" value="' . $h(number_format((float) $s[$price]['value'], 2, '.', '')) . '"' . $dis($price) . '> €</div></td></tr>';
    }
    $plans .= '</tbody></table><p class="help-block">' . $t('cdnset.plans_help') . '</p>';

    return '<form method="post" action="' . $h($action) . '">'
        . '<input type="hidden" name="enhance_importer_action" value="cdn_save">' . $token
        . $panel($t('cdnset.section_integration'), $integration)
        . $panel($t('cdnset.section_sync'), $sync)
        . $panel($t('cdnset.section_alerts'), $alerts)
        . $panel($t('cdnset.section_plans'), $plans)
        . '<button type="submit" class="btn btn-primary">' . $t('set.save') . '</button></form>'
        . '<div style="display:flex;gap:8px;margin:10px 0 20px;">'
        . '<form method="post" action="' . $h($action) . '"><input type="hidden" name="enhance_importer_action" value="cdn_test">' . $token . '<button type="submit" class="btn btn-default"' . (EnhanceSettings::get('cdn_api_key') === '' ? ' disabled' : '') . '>' . $t('imp.test_run') . '</button></form>'
        . '<form method="post" action="' . $h($action) . '"><input type="hidden" name="enhance_importer_action" value="cdn_sync">' . $token . '<button type="submit" class="btn btn-default"' . (EnhanceCdn::isConfigured() ? '' : ' disabled') . '>' . $t('cdnset.sync_now') . '</button></form>'
        . '</div>';
}

/** Every website with CDN configuration, and the open alerts. */
function enhance_importer_cdn_overview(string $action): string
{
    $h = 'enhance_importer_h';
    $t = fn(string $k, array $v = []) => enhance_importer_t($k, $v);
    $sites = EnhanceCdnStore::allSites();
    $rows = '';
    foreach ($sites as $site) {
        $u = EnhanceCdnStore::usage($site, gmdate('Y-m-d'));
        $status = $site->active ? '<span class="label label-success">' . $t('cdn.active') . '</span>' : '<span class="label label-default">' . $t('cdn.inactive') . '</span>';
        $sync = $h(EnhanceLang::t('cdn.sync_status_' . (in_array($site->last_sync_status, ['ok', 'error', 'never'], true) ? $site->last_sync_status : 'never')))
            . ($site->last_sync_at ? '<br><small>' . $h(date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $site->last_sync_at))) . '</small>' : '')
            . ($site->last_error ? '<br><small class="text-danger">' . $h(mb_substr((string) $site->last_error, 0, 160)) . '</small>' : '');
        $rows .= '<tr><td><strong>' . $h($site->domain) . '</strong><br><small>' . $h($site->cdn_host) . '</small></td>'
            . '<td><a href="clientsservices.php?userid=' . (int) $site->client_id . '&id=' . (int) $site->service_id . '">#' . (int) $site->service_id . '</a></td>'
            . '<td>' . $h(EnhanceLang::t('cdn.plan_' . $site->plan)) . '<br><small>' . $t('cdn.zone_short', ['zone' => (int) $site->zone_id]) . '</small></td>'
            . '<td>' . $status . '</td>'
            . '<td>' . ($u['has_data'] ? $h(_enhance_cdn_gb($u['month_bytes']) . ' / ' . _enhance_cdn_gb($u['limit_bytes'])) . ' (' . (int) $u['month_percent'] . '%)' : '-') . '</td>'
            . '<td>' . ($u['annuity'] && $u['has_data'] ? $h(_enhance_cdn_gb((int) $u['year_bytes']) . ' / ' . _enhance_cdn_gb($u['year_limit'])) : '-') . '</td>'
            . '<td>' . $sync . '</td></tr>';
    }
    $html = '<div class="panel panel-default"><div class="panel-heading"><strong>' . $t('cdnset.overview') . '</strong></div>'
        . ($rows === '' ? '<div class="panel-body">' . $t('cdnset.overview_empty') . '</div>'
            : '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>' . $t('imp.col_domain') . '</th><th>' . $t('cdnset.service') . '</th><th>' . $t('cdnset.plan') . '</th><th>' . $t('imp.col_status') . '</th><th>' . $t('cdnset.month') . '</th><th>' . $t('cdnset.annuity') . '</th><th>' . $t('cdnset.sync') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
        . '</div>';

    $alerts = EnhanceCdnStore::alerts(null, true, 100);
    $list = '';
    $token = enhance_importer_token_field();
    foreach ($alerts as $a) {
        $site = EnhanceCdnStore::site($a->website_id);
        $list .= '<tr><td>' . $h(date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $a->created_at))) . '</td>'
            . '<td>' . $h($site->domain ?? $a->website_id) . '</td><td>' . $h($a->period) . '</td><td><strong>' . (int) $a->level . '%</strong></td>'
            . '<td>' . $h(_enhance_cdn_gb((int) $a->bytes) . ' / ' . _enhance_cdn_gb((int) $a->limit_bytes)) . '</td>'
            . '<td><form method="post" action="' . $h($action) . '" style="margin:0;"><input type="hidden" name="enhance_importer_action" value="cdn_ack">' . $token
            . '<input type="hidden" name="alert_id" value="' . (int) $a->id . '"><button type="submit" class="btn btn-default btn-xs">' . $t('cdnset.ack') . '</button></form></td></tr>';
    }
    $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>' . $t('cdnset.open_alerts') . '</strong></div>'
        . ($list === '' ? '<div class="panel-body">' . $t('cdnset.no_alerts') . '</div>'
            : '<table class="table"><thead><tr><th>' . $t('cdnset.date') . '</th><th>' . $t('imp.col_domain') . '</th><th>' . $t('cdnset.period') . '</th><th>' . $t('cdnset.level') . '</th><th>' . $t('cdnset.usage') . '</th><th></th></tr></thead><tbody>' . $list . '</tbody></table>')
        . '</div>';
    return $html;
}
