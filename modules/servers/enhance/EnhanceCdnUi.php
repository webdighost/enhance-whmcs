<?php
/**
 * WDH-CDN - admin service tab block, its save handler and client area data (v1.4.0)
 *
 * The CDN is configured per Enhance website by the admin, in the service tab:
 * the fields are part of the WHMCS service form (CSRF and permissions by WHMCS)
 * and are applied by enhance_AdminServicesTabFieldsSave(). The client area reads
 * only local data synchronised by the cron: no provider call on page views.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceCdnSync.php';

/** The websites of the service, from Enhance: id => domain (also used to validate posted ids). */
function _enhance_cdn_service_websites(EnhanceApi $api, array $params): array
{
    $orgId = $api->getClientOrgId(_enhance_client_id($params));
    $subId = $api->getServiceSubscriptionId((int) $params['packageid'], (int) $params['serviceid']);
    if (!$orgId || !$subId) return [];
    $sites = $api->listWebsitesBySubscription(strtolower($orgId), $subId);
    if (EnhanceApi::isError($sites)) {
        throw new Exception(EnhanceLang::t('tab.error', ['detail' => EnhanceApi::errorSummary($sites)]));
    }
    $out = [];
    foreach ($sites['items'] as $site) {
        if (($site['kind'] ?? 'normal') !== 'normal' || ($site['status'] ?? '') === 'deleted') continue;
        if ((string) ($site['subscriptionId'] ?? '') !== (string) $subId || empty($site['id'])) continue;
        $out[strtolower((string) $site['id'])] = (string) ($site['domain']['domain'] ?? '');
    }
    asort($out);
    return $out;
}

/** Plan options with their monthly traffic and annual price, for selects and labels. */
function _enhance_cdn_plans(): array
{
    $plans = [];
    foreach (EnhanceSettings::CDN_PLANS as $plan) {
        $price = (float) EnhanceSettings::get('cdn_plan_' . $plan . '_price');
        $plans[$plan] = [
            'name'  => EnhanceLang::t('cdn.plan_' . $plan),
            'gb'    => (int) EnhanceSettings::get('cdn_plan_' . $plan . '_gb'),
            'price' => $price,
            'label' => EnhanceLang::t('cdn.plan_label', [
                'name'  => EnhanceLang::t('cdn.plan_' . $plan),
                'gb'    => (int) EnhanceSettings::get('cdn_plan_' . $plan . '_gb'),
                'price' => $price > 0 ? number_format($price, 2, EnhanceLang::t('fmt.decimal'), EnhanceLang::t('fmt.thousands')) . ' €' : EnhanceLang::t('cdn.free'),
            ]),
        ];
    }
    return $plans;
}

// =============================================================================
// Admin service tab
// =============================================================================

function _enhance_cdn_admin_fields(array $params): array
{
    if (!EnhanceSettings::get('cdn_enabled')) return [];
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $api = _enhance_api($params);
    $websites = _enhance_cdn_service_websites($api, $params);
    if (!$websites) {
        return [EnhanceLang::t('cdn.title') => $h(EnhanceLang::t('cdn.no_websites'))];
    }
    $stored = EnhanceCdnStore::sitesForService((int) $params['serviceid']);
    $plans = _enhance_cdn_plans();
    $keyMissing = !EnhanceCdn::isConfigured();
    $fmtDate = fn($d) => $d ? date(EnhanceLang::t('fmt.date'), strtotime((string) $d)) : '-';
    $fmtTime = fn($d) => $d ? date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $d)) : '-';

    $html = '<div class="enhance-cdn-admin">';
    if ($keyMissing) {
        $html .= '<div class="alert alert-warning" style="margin:0 0 8px;">' . $h(EnhanceLang::t('cdn.admin_no_key')) . '</div>';
    }
    foreach ($websites as $id => $domain) {
        $site = $stored[$id] ?? null;
        $n = 'enhance_cdn[' . $h($id) . ']';
        $active = $site && $site->active;
        $planOptions = '';
        foreach ($plans as $key => $plan) {
            $planOptions .= '<option value="' . $h($key) . '"' . (($site->plan ?? 'business') === $key ? ' selected' : '') . '>' . $h($plan['label']) . '</option>';
        }
        // Websites without the CDN stay collapsed so services with many sites remain readable.
        $title = '<i class="fas fa-globe"></i> ' . $h($domain)
            . ' <span class="label ' . ($active ? 'label-success' : 'label-default') . '">' . $h(EnhanceLang::t($active ? 'cdn.active' : 'cdn.inactive')) . '</span>';
        $html .= '<div style="border:1px solid #dde3ea;border-radius:8px;padding:' . ($site ? '12px 14px' : '8px 14px') . ';margin:0 0 ' . ($site ? '10px' : '6px') . ';">'
            . ($site
                ? '<h4 style="margin:0 0 8px;font-size:15px;">' . $title . '</h4>'
                : '<details><summary style="cursor:pointer;font-size:14px;font-weight:600;">' . $title . '</summary><div style="margin-top:8px;">')
            . '<input type="hidden" name="' . $n . '[present]" value="1">'
            . '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">'
            . '<label style="font-weight:normal;margin:0 8px 6px 0;"><input type="checkbox" name="' . $n . '[active]" value="1"' . ($active ? ' checked' : '') . '> ' . $h(EnhanceLang::t('cdn.field_active')) . '</label>'
            . '<div><small>' . $h(EnhanceLang::t('cdn.field_host')) . '</small><input type="text" class="form-control input-sm" style="width:230px;" name="' . $n . '[host]" value="' . $h($site->cdn_host ?? '') . '" placeholder="' . $h(EnhanceLang::t('cdn.field_host_ph')) . '"></div>'
            . '<div><small>' . $h(EnhanceLang::t('cdn.field_zone')) . '</small><input type="number" min="1" class="form-control input-sm" style="width:120px;" name="' . $n . '[zone]" value="' . $h($site->zone_id ?? '') . '"></div>'
            . '<div><small>' . $h(EnhanceLang::t('cdn.field_plan')) . '</small><select class="form-control input-sm" name="' . $n . '[plan]">' . $planOptions . '</select></div>'
            . '<div><small>' . $h(EnhanceLang::t('cdn.field_limit')) . '</small><input type="number" min="1" class="form-control input-sm" style="width:110px;" name="' . $n . '[limit]" value="' . $h($site->limit_gb ?? '') . '" placeholder="' . $h(EnhanceLang::t('cdn.field_limit_ph')) . '"></div>'
            . '<div><small>' . $h(EnhanceLang::t('cdn.field_activated')) . '</small><input type="date" class="form-control input-sm" name="' . $n . '[activated]" value="' . $h($site->activated_at ?? '') . '"></div>'
            . '</div>'
            . '<div style="margin-top:6px;">'
            . '<label style="font-weight:normal;margin-right:14px;"><input type="checkbox" name="' . $n . '[sync]" value="1"> ' . $h(EnhanceLang::t('cdn.field_sync_now')) . '</label>'
            . '<label style="font-weight:normal;"><input type="checkbox" name="' . $n . '[confirm_shared]" value="1"> ' . $h(EnhanceLang::t('cdn.field_confirm_shared')) . '</label>'
            . '</div>'
            . ($site ? '' : '</div></details>');

        if ($site) {
            $usage = EnhanceCdnStore::usage($site, gmdate('Y-m-d'));
            $info = [];
            if ($usage['has_data']) {
                $pct = (int) $usage['month_percent'];
                $info[] = '<strong>' . $h(EnhanceLang::t('cdn.month_usage', ['month' => $usage['month']])) . ':</strong> '
                    . $h(_enhance_cdn_gb($usage['month_bytes']) . ' / ' . _enhance_cdn_gb($usage['limit_bytes'])) . ' (' . $pct . '%)'
                    . '<div style="height:6px;background:#eef2f6;border-radius:4px;max-width:340px;margin-top:3px;"><div style="height:6px;border-radius:4px;width:' . min(100, $pct) . '%;background:' . ($pct >= 100 ? '#c0392b' : ($pct >= (int) EnhanceSettings::get('cdn_warn_pct') ? '#e0802b' : '#2e8b57')) . ';"></div></div>';
                if ($usage['annuity']) {
                    $info[] = '<strong>' . $h(EnhanceLang::t('cdn.annuity', ['from' => $fmtDate($usage['annuity'][0]), 'to' => $fmtDate($usage['annuity'][1])])) . ':</strong> '
                        . $h(_enhance_cdn_gb((int) $usage['year_bytes']) . ' / ' . _enhance_cdn_gb($usage['year_limit'])) . ' (' . (int) $usage['year_percent'] . '%)';
                }
            } else {
                $info[] = $h(EnhanceLang::t('cdn.no_data'));
            }
            $info[] = '<strong>' . $h(EnhanceLang::t('cdn.last_sync')) . ':</strong> ' . $h($fmtTime($site->last_sync_at))
                . ' · ' . $h(EnhanceLang::t('cdn.sync_status_' . (in_array($site->last_sync_status, ['ok', 'error', 'never'], true) ? $site->last_sync_status : 'never')));
            if ($site->last_error) {
                $info[] = '<span style="color:#b91c1c;">' . $h($site->last_error) . '</span>';
            }
            if ($site->zone_type) {
                $type = $h(EnhanceLang::t('cdn.zone_type_' . $site->zone_type));
                $mismatch = ($site->plan === 'volume') !== ($site->zone_type === 'volume');
                $info[] = '<strong>' . $h(EnhanceLang::t('cdn.provider')) . ':</strong> ' . $h(EnhanceCdn::PROVIDER) . ' · ' . $type
                    . ($site->zone_enabled === 0 || $site->zone_enabled === '0' ? ' · <span style="color:#b91c1c;">' . $h(EnhanceLang::t('cdn.zone_disabled')) . '</span>' : '')
                    . ($site->provider_month_charges !== null ? ' · ' . $h(EnhanceLang::t('cdn.provider_charges', ['month' => $site->provider_month, 'amount' => number_format((float) $site->provider_month_charges, 2, EnhanceLang::t('fmt.decimal'), EnhanceLang::t('fmt.thousands'))])) : '')
                    . ($mismatch ? '<br><span style="color:#b45309;">' . $h(EnhanceLang::t('cdn.type_mismatch')) . '</span>' : '');
            }
            $alerts = EnhanceCdnStore::alerts($id, false, 5);
            if ($alerts) {
                $list = [];
                foreach ($alerts as $a) {
                    $list[] = $h($fmtTime($a->created_at) . ' · ' . $a->period . ' · ' . $a->level . '%') . ($a->acknowledged_at ? '' : ' <span class="label label-warning">' . $h(EnhanceLang::t('cdn.alert_open')) . '</span>');
                }
                $info[] = '<strong>' . $h(EnhanceLang::t('cdn.alerts')) . ':</strong><br>' . implode('<br>', $list);
            }
            $history = EnhanceCdnStore::monthly($id, 12);
            if ($history) {
                $rows = '';
                foreach ($history as $m) {
                    $rows .= '<tr><td style="padding:2px 10px 2px 0;">' . $h($m['month']) . '</td><td style="text-align:right;">' . $h(_enhance_cdn_gb($m['bytes'])) . '</td></tr>';
                }
                $info[] = '<details><summary style="cursor:pointer;">' . $h(EnhanceLang::t('cdn.history')) . '</summary><table style="font-size:12px;margin-top:4px;">' . $rows . '</table></details>';
            }
            $html .= '<div style="margin-top:8px;font-size:12px;line-height:1.7;">' . implode('<br>', $info) . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '<div class="help-block" style="margin:0;">' . $h(EnhanceLang::t('cdn.admin_help')) . '</div></div>';
    return [EnhanceLang::t('cdn.title') => $html];
}

/**
 * Applies the CDN fields of the service form. Every website id must belong to the
 * service (checked against Enhance); errors are recorded as the last module action.
 */
function _enhance_cdn_admin_save(array $params): void
{
    $posted = $_POST['enhance_cdn'] ?? null;
    if (!is_array($posted) || !EnhanceSettings::get('cdn_enabled')) return;
    $serviceId = (int) $params['serviceid'];
    $clientId = _enhance_client_id($params);
    $errors = [];
    $saved = 0;
    try {
        $api = _enhance_api($params);
        $websites = _enhance_cdn_service_websites($api, $params);
        $cdn = EnhanceCdn::isConfigured() ? EnhanceCdn::fromSettings() : null;
        foreach ($posted as $websiteId => $in) {
            $websiteId = strtolower((string) $websiteId);
            if (!is_array($in) || !isset($websites[$websiteId])) {
                $errors[] = EnhanceLang::t('cdn.err_not_service_website');
                continue;
            }
            $domain = $websites[$websiteId];
            $site = EnhanceCdnStore::site($websiteId);
            $active = !empty($in['active']);
            $host = EnhanceCdn::normaliseHost((string) ($in['host'] ?? ''));
            $zoneRaw = trim((string) ($in['zone'] ?? ''));
            $zone = preg_match('/^[1-9]\d{0,17}$/', $zoneRaw) ? (int) $zoneRaw : null;
            $plan = in_array($in['plan'] ?? '', EnhanceSettings::CDN_PLANS, true) ? (string) $in['plan'] : 'business';
            $limitRaw = trim((string) ($in['limit'] ?? ''));
            $limit = $limitRaw === '' ? null : (preg_match('/^\d{1,7}$/', $limitRaw) && (int) $limitRaw > 0 ? (int) $limitRaw : false);
            $activated = trim((string) ($in['activated'] ?? ''));
            $activatedOk = $activated === '' || (bool) DateTime::createFromFormat('!Y-m-d', $activated);

            // Nothing typed for a website without CDN: nothing to store.
            if (!$site && !$active && $zone === null && $host === '') continue;

            if ($active && $zone === null) { $errors[] = EnhanceLang::t('cdn.err_zone_required', ['domain' => $domain]); continue; }
            if ($zoneRaw !== '' && $zone === null) { $errors[] = EnhanceLang::t('cdn.err_zone_invalid', ['domain' => $domain]); continue; }
            if (($in['host'] ?? '') !== '' && $host === '') { $errors[] = EnhanceLang::t('cdn.err_host_invalid', ['domain' => $domain]); continue; }
            if ($active && $host === '') { $errors[] = EnhanceLang::t('cdn.err_host_required', ['domain' => $domain]); continue; }
            if ($limit === false) { $errors[] = EnhanceLang::t('cdn.err_limit_invalid', ['domain' => $domain]); continue; }
            if (!$activatedOk) { $errors[] = EnhanceLang::t('cdn.err_date_invalid', ['domain' => $domain]); continue; }

            $changedZone = !$site || (int) $site->zone_id !== (int) $zone || (string) $site->cdn_host !== $host || (!$site->active && $active);
            if ($active && $zone !== null) {
                // Another client's website on the same pull zone needs an explicit confirmation.
                foreach (EnhanceCdnStore::zoneUsedBy($zone, $websiteId) as $other) {
                    if ((int) $other->client_id !== $clientId && empty($in['confirm_shared'])) {
                        $errors[] = EnhanceLang::t('cdn.err_zone_shared', ['domain' => $domain, 'zone' => $zone, 'other' => $other->domain, 'client' => (int) $other->client_id]);
                        continue 2;
                    }
                }
                // Validate the association with the provider when the zone or the address changes.
                if ($changedZone && $cdn) {
                    $pz = $cdn->getPullZone($zone);
                    if (EnhanceCdn::isError($pz)) {
                        $errors[] = EnhanceLang::t('cdn.err_zone_check', ['domain' => $domain, 'detail' => EnhanceCdn::errorSummary($pz)]);
                        continue;
                    }
                    if (!in_array($host, $pz['hostnames'], true)) {
                        $errors[] = EnhanceLang::t('cdn.err_host_not_in_zone', ['host' => $host, 'zone' => $zone]) . ' (' . implode(', ', $pz['hostnames']) . ')';
                        continue;
                    }
                }
            }

            $today = date('Y-m-d');
            $data = [
                'service_id' => $serviceId,
                'client_id'  => $clientId,
                'server_id'  => (int) ($params['serverid'] ?? 0),
                'domain'     => $domain,
                'active'     => $active,
                'zone_id'    => $zone,
                'cdn_host'   => $host,
                'plan'       => $plan,
                'limit_gb'   => $limit,
            ];
            if ($activated !== '') {
                $data['activated_at'] = $activated;
            } elseif ($active && (!$site || !$site->activated_at)) {
                $data['activated_at'] = $today;
            }
            if ($active) {
                $data['deactivated_at'] = null;
            } elseif ($site && $site->active) {
                $data['deactivated_at'] = $today;
            }
            if ($changedZone) {
                // A new zone starts its own back-fill; the history of the previous zone is kept.
                $data['last_success_at'] = null;
                $data['last_sync_status'] = 'never';
                $data['last_error'] = $cdn ? null : EnhanceLang::t('cdn.not_validated');
            }
            EnhanceCdnStore::saveSite($websiteId, $data);
            $saved++;

            if ($active && $cdn && !empty($in['sync'])) {
                $r = EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($websiteId));
                if (!$r['ok']) $errors[] = $domain . ': ' . $r['error'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    if ($errors || $saved) {
        EnhanceStore::recordEvent($serviceId, 'CdnSettings', !$errors, implode("\n", $errors));
        _enhance_forget_clientarea($serviceId);
    }
}

// =============================================================================
// Client area (local data only)
// =============================================================================

/**
 * CDN data of the websites shown, keyed by website id. Only websites of this
 * service are looked up (the ids come from the service's own website list).
 */
function _enhance_cdn_client_data(int $serviceId, array $websiteIds): array
{
    if (!EnhanceSettings::get('cdn_enabled') || !$websiteIds) return [];
    $stored = EnhanceCdnStore::sitesForService($serviceId);
    $showInactive = (bool) EnhanceSettings::get('cdn_show_inactive');
    $fmtDate = fn($d) => $d ? date(EnhanceLang::t('fmt.date'), strtotime((string) $d)) : '';
    $out = [];
    foreach ($websiteIds as $id) {
        $site = $stored[strtolower($id)] ?? null;
        if (!$site || !$site->active) {
            if ($showInactive) $out[$id] = ['active' => false];
            continue;
        }
        $usage = EnhanceCdnStore::usage($site, gmdate('Y-m-d'));
        $pct = $usage['month_percent'];
        $out[$id] = [
            'active'      => true,
            'has_data'    => $usage['has_data'],
            'month_used'  => _enhance_cdn_gb($usage['month_bytes']),
            'month_limit' => $usage['limit_bytes'] > 0 ? _enhance_cdn_gb($usage['limit_bytes']) : '',
            'percent'     => $pct === null ? null : min(100, $pct),
            'level'       => $pct === null ? '' : ($pct >= (int) EnhanceSettings::get('cdn_crit_pct') ? 'crit' : ($pct >= (int) EnhanceSettings::get('cdn_warn_pct') ? 'warn' : '')),
            'year_used'   => $usage['annuity'] ? _enhance_cdn_gb((int) $usage['year_bytes']) : '',
            'year_limit'  => $usage['annuity'] ? _enhance_cdn_gb($usage['year_limit']) : '',
            'year_from'   => $usage['annuity'] ? $fmtDate($usage['annuity'][0]) : '',
            'year_to'     => $usage['annuity'] ? $fmtDate($usage['annuity'][1]) : '',
            // Last sync failed: the values shown are the last known ones, with their date.
            'stale'       => $site->last_sync_status === 'error' && $usage['has_data'],
            'updated'     => $site->last_success_at ? date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $site->last_success_at)) : '',
        ];
    }
    return $out;
}
