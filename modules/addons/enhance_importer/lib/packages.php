<?php
/**
 * Enhance Importer - Enhance Plans tab: import Enhance plans as WHMCS products.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

function enhance_importer_packages_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, $currencies, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if (enhance_importer_valid_post('import_packages')) {
        $feedback = enhance_importer_handle_import($api, $selectedServerId, $selectedGroupId, $selectedServerGroupId, $currencies);
    }
    $response = $api->getPlans();
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'packages');
    if (EnhanceApi::isError($response)) {
        echo enhance_importer_alert('danger', enhance_importer_t('imp.plans_failed', ['detail' => EnhanceApi::errorSummary($response)]));
        return;
    }
    $plans = $response['items'] ?? [];
    echo $plans
        ? enhance_importer_plan_table($moduleLink, $plans, $selectedServerId, $selectedGroupId, $selectedServerGroupId)
        : enhance_importer_alert('warning', enhance_importer_t('imp.no_plans'));
}

function enhance_importer_handle_import(EnhanceApi $api, int $serverId, int $groupId, int $serverGroupId, $currencies): string
{
    $selected = $_POST['plans'] ?? [];
    if (!is_array($selected) || count($selected) === 0) return enhance_importer_alert('warning', enhance_importer_t('imp.no_plan_selected'));
    if ($groupId <= 0) return enhance_importer_alert('danger', enhance_importer_t('imp.no_group'));

    $names          = $_POST['product_name'] ?? [];
    $monthly        = $_POST['monthly'] ?? [];
    $annually       = $_POST['annually'] ?? [];
    $hiddenDefault  = !empty($_POST['hidden']);
    $skipExisting   = !empty($_POST['skip_existing']);
    $updateExisting = !empty($_POST['update_existing']);

    $plansResponse = $api->getPlans();
    if (EnhanceApi::isError($plansResponse)) {
        return enhance_importer_alert('danger', enhance_importer_t('imp.plans_failed', ['detail' => EnhanceApi::errorSummary($plansResponse)]));
    }
    $plansById = [];
    foreach ($plansResponse['items'] as $plan) {
        if (isset($plan['id'])) $plansById[(string) $plan['id']] = $plan;
    }

    $messages = [];
    foreach ($selected as $planIdRaw) {
        $planId = (string) $planIdRaw;
        if (!isset($plansById[$planId])) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.plan_not_found', ['plan' => $planId]));
            continue;
        }
        $plan = $plansById[$planId];
        $productName = trim((string) ($names[$planId] ?? ($plan['name'] ?? ''))) ?: EnhanceLang::t('imp.default_product_name', ['plan' => $planId]);
        $existing = Capsule::table('tblproducts')->where('servertype', 'enhance')->where('configoption1', $planId)->first();

        if ($existing && $skipExisting && !$updateExisting) {
            enhance_importer_save_map($serverId, $planId, (int) $existing->id, $productName, $plan);
            $messages[] = enhance_importer_alert('info', enhance_importer_t('imp.product_skipped', ['name' => $productName]));
            continue;
        }
        if ($existing && $updateExisting) {
            Capsule::table('tblproducts')->where('id', (int) $existing->id)->update([
                'name'          => $productName,
                'gid'           => $groupId,
                'servertype'    => 'enhance',
                'configoption1' => $planId,
                'servergroup'   => $serverGroupId,
                'hidden'        => $hiddenDefault ? 1 : 0,
                'description'   => enhance_importer_plan_description($plan),
            ]);
            enhance_importer_save_map($serverId, $planId, (int) $existing->id, $productName, $plan);
            $messages[] = enhance_importer_alert('success', enhance_importer_t('imp.product_updated', ['name' => $productName]));
            continue;
        }

        $postData = [
            'type'              => 'hostingaccount',
            'gid'               => $groupId,
            'name'              => $productName,
            'paytype'           => 'recurring',
            'hidden'            => $hiddenDefault,
            'showdomainoptions' => true,
            'tax'               => true,
            'autosetup'         => 'payment',
            'module'            => 'enhance',
            'servergroupid'     => $serverGroupId,
            'configoption1'     => $planId,
            'configoption2'     => 'soft',
            'description'       => enhance_importer_plan_description($plan),
            'pricing'           => enhance_importer_build_pricing($currencies, (string) ($monthly[$planId] ?? '0'), (string) ($annually[$planId] ?? '-1')),
        ];
        $result = localAPI('AddProduct', $postData);
        if (($result['result'] ?? '') !== 'success') {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.product_failed', ['name' => $productName, 'detail' => $result['message'] ?? json_encode($result)]));
            continue;
        }
        $pid = (int) ($result['pid'] ?? 0);
        if (!$pid) {
            $pid = (int) Capsule::table('tblproducts')->where('servertype', 'enhance')->where('configoption1', $planId)->orderBy('id', 'desc')->value('id');
        }
        if ($pid) {
            Capsule::table('tblproducts')->where('id', $pid)->update(['servertype' => 'enhance', 'configoption1' => $planId, 'configoption2' => 'soft', 'servergroup' => $serverGroupId]);
            enhance_importer_save_map($serverId, $planId, $pid, $productName, $plan);
        }
        $messages[] = enhance_importer_alert('success', enhance_importer_t('imp.product_imported', ['name' => $productName, 'pid' => $pid ?: '?']));
    }
    return implode('', $messages);
}

function enhance_importer_build_pricing($currencies, string $monthly, string $annually): array
{
    $monthlyValue = is_numeric(str_replace(',', '.', $monthly)) ? (float) str_replace(',', '.', $monthly) : 0.00;
    $annualValue  = is_numeric(str_replace(',', '.', $annually)) ? (float) str_replace(',', '.', $annually) : -1.00;
    $pricing = [];
    foreach ($currencies as $currency) {
        $pricing[(int) $currency->id] = [
            'monthly' => $monthlyValue, 'msetupfee' => 0.00,
            'quarterly' => -1.00, 'qsetupfee' => 0.00,
            'semiannually' => -1.00, 'ssetupfee' => 0.00,
            'annually' => $annualValue, 'asetupfee' => 0.00,
            'biennially' => -1.00, 'bsetupfee' => 0.00,
            'triennially' => -1.00, 'tsetupfee' => 0.00,
        ];
    }
    return $pricing;
}

function enhance_importer_save_map(int $serverId, string $planId, int $productId, string $name, array $plan): void
{
    $now = date('Y-m-d H:i:s');
    $key = ['server_id' => $serverId, 'enhance_plan_id' => $planId];
    $data = ['whmcs_product_id' => $productId, 'plan_name' => $name, 'plan_snapshot' => json_encode($plan), 'updated_at' => $now];
    if (Capsule::table('mod_enhance_package_map')->where($key)->exists()) {
        Capsule::table('mod_enhance_package_map')->where($key)->update($data);
    } else {
        Capsule::table('mod_enhance_package_map')->insert($key + $data + ['created_at' => $now]);
    }
}

function enhance_importer_plan_table(string $moduleLink, array $plans, int $serverId, int $groupId, int $serverGroupId): string
{
    $rows = '';
    foreach ($plans as $plan) {
        $id = (string) ($plan['id'] ?? '');
        if ($id === '') continue;
        $name = (string) ($plan['name'] ?? EnhanceLang::t('imp.default_product_name', ['plan' => $id]));
        $existing = Capsule::table('tblproducts')->where('servertype', 'enhance')->where('configoption1', $id)->first();
        $mapped = Capsule::table('mod_enhance_package_map')->where('server_id', $serverId)->where('enhance_plan_id', $id)->first();
        $status = $existing
            ? '<span class="label label-info">' . enhance_importer_t('imp.exists_product', ['pid' => (int) $existing->id]) . '</span>'
            : '<span class="label label-success">' . enhance_importer_t('imp.new') . '</span>';
        if ($mapped && !$existing) {
            $status .= ' <span class="label label-warning">' . enhance_importer_t('imp.old_map', ['pid' => (int) $mapped->whmcs_product_id]) . '</span>';
        }
        $limits   = enhance_importer_h(enhance_importer_summary($plan));
        $safeId   = enhance_importer_h($id);
        $safeName = enhance_importer_h($name);
        $lId      = enhance_importer_t('imp.enhance_id');
        $rows .= <<<HTML
<tr>
    <td style="width:35px;"><input type="checkbox" name="plans[]" value="{$safeId}"></td>
    <td><strong>{$safeName}</strong><br><small>{$lId}: {$safeId}</small></td>
    <td style="max-width:420px;white-space:normal;">{$limits}</td><td>{$status}</td>
    <td><input type="text" class="form-control input-sm" name="product_name[{$safeId}]" value="{$safeName}"></td>
    <td style="width:100px;"><input type="text" class="form-control input-sm" name="monthly[{$safeId}]" value="0.00"></td>
    <td style="width:100px;"><input type="text" class="form-control input-sm" name="annually[{$safeId}]" value="-1"></td>
</tr>
HTML;
    }
    $action = enhance_importer_h($moduleLink . '&tab=packages&server_id=' . $serverId . '&gid=' . $groupId . '&servergroupid=' . $serverGroupId);
    $tokenField = enhance_importer_token_field();
    $t = fn($k) => enhance_importer_t($k);
    return <<<HTML
<form method="post" action="{$action}">
<input type="hidden" name="enhance_importer_action" value="import_packages">{$tokenField}
<div class="panel panel-default"><div class="panel-heading"><strong>{$t('imp.plans_found')}</strong></div><div class="panel-body">
<label><input type="checkbox" onclick="jQuery('input[name=&quot;plans[]&quot;]').prop('checked', this.checked);"> {$t('imp.select_all')}</label>
<span style="margin-left:20px;"><label><input type="checkbox" name="hidden" value="1" checked> {$t('imp.opt_hidden')}</label></span>
<span style="margin-left:20px;"><label><input type="checkbox" name="skip_existing" value="1" checked> {$t('imp.opt_skip')}</label></span>
<span style="margin-left:20px;"><label><input type="checkbox" name="update_existing" value="1"> {$t('imp.opt_update')}</label></span>
<p class="help-block" style="margin-top:8px;">{$t('imp.price_help')}</p></div>
<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th></th><th>{$t('imp.col_plan')}</th><th>{$t('imp.col_summary')}</th><th>{$t('imp.col_status')}</th><th>{$t('imp.col_name')}</th><th>{$t('imp.col_monthly')}</th><th>{$t('imp.col_annual')}</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div class="panel-footer"><button type="submit" class="btn btn-success">{$t('imp.import_plans')}</button></div></div></form>
HTML;
}

function enhance_importer_summary(array $plan): string
{
    $labels = [
        'websites' => 'res_websites', 'stagingWebsites' => 'res_staging', 'pageViews' => 'res_pageviews',
        'transfer' => 'res_transfer', 'processes' => 'res_processes', 'mailboxes' => 'res_mailboxes',
        'forwarders' => 'res_forwarders', 'mysqlDbs' => 'res_databases', 'ftpUsers' => 'res_ftp',
        'domainAliases' => 'res_aliases', 'additionalDomains' => 'res_additional_domains',
        'dnsRecords' => 'res_dns', 'diskspace' => 'res_disk', 'disk' => 'res_disk', 'storage' => 'res_storage',
        'emails' => 'res_emails', 'databases' => 'res_databases',
    ];
    $label = fn(string $name) => isset($labels[$name]) ? EnhanceLang::t('imp.' . $labels[$name]) : $name;

    $parts = [];
    $resources = [];
    foreach (['resources', 'resourceLimits', 'limits', 'quotas'] as $key) {
        if (!empty($plan[$key]) && is_array($plan[$key])) { $resources = $plan[$key]; break; }
    }
    foreach ($resources as $key => $value) {
        $name  = is_array($value) ? (string) ($value['name'] ?? $key) : (string) $key;
        $total = is_array($value) ? ($value['total'] ?? $value['limit'] ?? $value['value'] ?? null) : $value;
        // In Enhance a resource without "total" is unlimited.
        $parts[] = $label($name) . ': ' . enhance_importer_format_limit($name, $total);
    }
    foreach ($labels as $key => $unused) {
        foreach ([$key, $key . 'Limit', $key . '_limit'] as $candidate) {
            if (array_key_exists($candidate, $plan) && $plan[$candidate] !== null && $plan[$candidate] !== '' && !is_array($plan[$candidate])) {
                $parts[] = $label($key) . ': ' . enhance_importer_format_limit($key, $plan[$candidate]);
                break;
            }
        }
    }
    $parts = array_values(array_unique($parts));
    return $parts ? implode(' | ', array_slice($parts, 0, 12)) : EnhanceLang::t('imp.no_limits');
}

function enhance_importer_format_limit(string $name, $value): string
{
    if ($value === null || $value === '') return EnhanceLang::t('imp.unlimited');
    if (is_bool($value)) return EnhanceLang::t($value ? 'imp.yes' : 'imp.no');
    if (!is_numeric($value)) return (string) $value;
    $num = (float) $value;
    if ($num < 0) return EnhanceLang::t('imp.unlimited');
    $dec = EnhanceLang::t('fmt.decimal');
    $trim = fn(string $n) => rtrim(rtrim($n, '0'), $dec);
    if (in_array($name, ['diskspace', 'disk', 'storage', 'transfer', 'bandwidth'], true)) {
        if ($num >= 1073741824) return $trim(number_format($num / 1073741824, 2, $dec, '')) . ' GB';
        if ($num >= 1048576) return $trim(number_format($num / 1048576, 2, $dec, '')) . ' MB';
    }
    return (string) (int) $num;
}

function enhance_importer_plan_description(array $plan): string
{
    return EnhanceLang::t('imp.product_description') . "\n" . enhance_importer_summary($plan);
}

function enhance_importer_find_product_for_plan(int $serverId, string $planId): int
{
    if ($planId === '') return 0;
    $mapped = Capsule::table('mod_enhance_package_map')->where('server_id', $serverId)->where('enhance_plan_id', $planId)->value('whmcs_product_id');
    if ($mapped && Capsule::table('tblproducts')->where('id', (int) $mapped)->exists()) return (int) $mapped;
    return (int) Capsule::table('tblproducts')->where('servertype', 'enhance')->where('configoption1', $planId)->value('id');
}
