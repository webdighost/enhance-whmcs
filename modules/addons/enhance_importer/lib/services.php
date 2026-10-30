<?php
/**
 * Enhance Importer - Existing Services tab: import Enhance subscriptions as WHMCS services (paged, under the per-client lock).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

function enhance_importer_services_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if (enhance_importer_valid_post('import_services')) {
        $feedback = enhance_importer_handle_service_import($api, $selectedServerId);
    }
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'services');
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    $page = max(1, (int) ($_REQUEST['p'] ?? 1));
    $scan = enhance_importer_scan_services($api, $selectedServerId, $page);
    echo enhance_importer_services_table($moduleLink, $scan['rows'], $selectedServerId, $selectedGroupId, $selectedServerGroupId, $scan);
}

/** WHMCS clients (with a linked organisation) analysed per page of the Existing Services tab. */
if (!defined('ENHANCE_IMPORTER_CLIENTS_PER_PAGE')) define('ENHANCE_IMPORTER_CLIENTS_PER_PAGE', 20);

/**
 * Analyses one page of linked clients, so large installations never hit the
 * PHP time limit: per client, 1 organisation + 1 subscriptions + 1 websites call.
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function enhance_importer_scan_services(EnhanceApi $api, int $serverId, int $page = 1): array
{
    $rows = [];

    // Conservative import: the key is always the Enhance organisation linked to
    // the WHMCS client on this server (client profile > Enhance widget). The
    // WHMCS client email may differ from the Enhance owner email.
    $mappedClients = Capsule::table(EnhanceStore::T_CLIENTS . ' as m')
        ->join('tblclients as c', 'c.id', '=', 'm.client_id')
        ->where('m.server_id', $api->getServerId())
        ->where('c.status', '!=', 'Closed')
        ->select('c.id', 'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'm.org_id as enhance_org_id')
        ->orderBy('c.id');
    $total = (clone $mappedClients)->count();
    $pages = max(1, (int) ceil($total / ENHANCE_IMPORTER_CLIENTS_PER_PAGE));
    $page  = min(max(1, $page), $pages);
    $mappedClients = $mappedClients->skip(($page - 1) * ENHANCE_IMPORTER_CLIENTS_PER_PAGE)->take(ENHANCE_IMPORTER_CLIENTS_PER_PAGE)->get();

    $errorRow = function ($client, string $orgId, array $org, string $error) {
        return [
            'org' => $org + ['id' => $orgId], 'subscription' => [], 'orgId' => $orgId, 'subId' => '', 'planId' => '',
            'email' => (string) ($org['ownerEmail'] ?? ''), 'client' => $client, 'productId' => 0, 'serviceId' => 0,
            'domainOptions' => [], 'error' => $error,
        ];
    };

    foreach ($mappedClients as $client) {
        $orgId = trim((string) $client->enhance_org_id);
        if ($orgId === '') continue;

        $org = $api->getOrg($orgId);
        if (EnhanceApi::isError($org)) {
            $rows[] = $errorRow($client, $orgId, ['name' => EnhanceLang::t('imp.org_not_found')], EnhanceApi::errorSummary($org));
            continue;
        }
        if (strtolower((string) ($org['parentId'] ?? '')) !== $api->getMasterOrgId()) {
            $rows[] = $errorRow($client, $orgId, $org, EnhanceLang::t('imp.org_other_master'));
            continue;
        }

        $subs = $api->getCustomerSubscriptions($orgId);
        if (EnhanceApi::isError($subs)) {
            $rows[] = $errorRow($client, $orgId, $org, EnhanceApi::errorSummary($subs));
            continue;
        }

        // One websites call per organisation, grouped by subscription locally.
        $websitesBySub = [];
        $websites = $api->listWebsites($orgId);
        if (!EnhanceApi::isError($websites)) {
            foreach ($websites['items'] as $website) {
                if (($website['status'] ?? '') === 'deleted') continue;
                $websitesBySub[(string) ($website['subscriptionId'] ?? '')][] = $website;
            }
        }

        foreach ($subs['items'] as $sub) {
            $subId = (string) ($sub['id'] ?? '');
            if ($subId === '' || ($sub['status'] ?? '') === 'deleted') continue;

            $planId = (string) ($sub['planId'] ?? ($sub['plan']['id'] ?? ''));
            $rows[] = [
                'org'           => $org + ['id' => $orgId],
                'subscription'  => $sub,
                'orgId'         => $orgId,
                'subId'         => $subId,
                'planId'        => $planId,
                'email'         => (string) ($org['ownerEmail'] ?? ''),
                'client'        => $client,
                'productId'     => enhance_importer_find_product_for_plan($serverId, $planId),
                'serviceId'     => $api->findServiceBySubscriptionId($subId),
                'domainOptions' => enhance_importer_domain_options_for_subscription($sub, $websitesBySub[$subId] ?? []),
                'error'         => '',
            ];
        }
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/**
 * Domains of a subscription: from the subscription itself and from its
 * websites (primary domain, aliases and subdomains are included in the
 * website listing, so no extra API call per website is needed).
 */
function enhance_importer_domain_options_for_subscription(array $sub, array $websites): array
{
    $domains = [];
    enhance_importer_collect_domains_from_array($sub, $domains);
    foreach ($websites as $website) {
        enhance_importer_collect_domains_from_array($website, $domains);
    }
    return enhance_importer_clean_sort_domains($domains);
}

function enhance_importer_collect_domains_from_array(array $source, array &$domains): void
{
    foreach (['primaryDomain', 'domain', 'domainName', 'websiteDomain', 'hostname', 'fqdn'] as $key) {
        if (!empty($source[$key]) && is_scalar($source[$key])) {
            $domain = enhance_importer_normalize_domain((string) $source[$key]);
            if ($domain !== '') $domains[] = $domain;
        }
    }
    foreach (['website', 'primaryWebsite', 'domain', 'primaryDomain'] as $key) {
        if (!empty($source[$key]) && is_array($source[$key])) {
            enhance_importer_collect_domains_from_array($source[$key], $domains);
        }
    }
    foreach (['websites', 'domains', 'domainAliases', 'aliases', 'addonDomains', 'subdomains'] as $key) {
        if (!empty($source[$key]) && is_array($source[$key])) {
            foreach ($source[$key] as $item) {
                if (is_array($item)) {
                    enhance_importer_collect_domains_from_array($item, $domains);
                } elseif (is_scalar($item)) {
                    $domain = enhance_importer_normalize_domain((string) $item);
                    if ($domain !== '') $domains[] = $domain;
                }
            }
        }
    }
}

function enhance_importer_clean_sort_domains(array $domains): array
{
    $clean = [];
    foreach ($domains as $domain) {
        $domain = enhance_importer_normalize_domain((string) $domain);
        if ($domain !== '' && !isset($clean[$domain])) $clean[$domain] = $domain;
    }
    $values = array_values($clean);
    usort($values, function ($a, $b) {
        $scoreA = enhance_importer_domain_score($a);
        $scoreB = enhance_importer_domain_score($b);
        return $scoreA === $scoreB ? strcmp($a, $b) : $scoreB <=> $scoreA;
    });
    return $values;
}

function enhance_importer_domain_score(string $domain): int
{
    $score = 0;
    $root = enhance_importer_is_probable_root_domain($domain);
    if ($root) $score += 100;
    if (preg_match('/^(mta-sts|autoconfig|autodiscover|mail|smtp|imap|pop|webmail|cpanel|whm|ftp|status|old|dev|staging|test)\./i', $domain)) $score -= 80;
    if (!$root && substr_count($domain, '.') >= 2) $score -= 25;
    return $score;
}

function enhance_importer_is_probable_root_domain(string $domain): bool
{
    $parts = explode('.', strtolower($domain));
    $count = count($parts);
    if ($count === 2) return true;
    $secondLevel = ['com.pt', 'net.pt', 'org.pt', 'edu.pt', 'gov.pt', 'nome.pt', 'publ.pt', 'co.uk', 'org.uk', 'com.br', 'net.br', 'com.es', 'com.au'];
    return $count === 3 && in_array(implode('.', array_slice($parts, -2)), $secondLevel, true);
}

function enhance_importer_normalize_domain(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $value = (string) preg_replace('#^https?://#i', '', $value);
    $value = (string) preg_replace('#/.*$#', '', $value);
    $value = (string) preg_replace('#:\d+$#', '', $value);
    $value = trim($value, " \t\n\r\0\x0B.");
    if ($value === '' || strpos($value, '.') === false || !preg_match('/^[a-z0-9.-]+$/i', $value)) return '';
    return strtolower($value);
}

function enhance_importer_subscription_domain(array $sub): string
{
    $domains = [];
    enhance_importer_collect_domains_from_array($sub, $domains);
    return enhance_importer_clean_sort_domains($domains)[0] ?? '';
}

function enhance_importer_services_table(string $moduleLink, array $rows, int $serverId, int $groupId, int $serverGroupId, array $scan = []): string
{
    $page  = (int) ($scan['page'] ?? 1);
    $pages = (int) ($scan['pages'] ?? 1);
    $base  = $moduleLink . '&tab=services&server_id=' . $serverId . '&gid=' . $groupId . '&servergroupid=' . $serverGroupId;
    $pager = '';
    if ($pages > 1) {
        $pager = '<nav><ul class="pagination" style="margin:10px 0;">';
        for ($i = 1; $i <= $pages; $i++) {
            $pager .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . enhance_importer_h($base . '&p=' . $i) . '">' . $i . '</a></li>';
        }
        $pager .= '</ul></nav><p class="text-muted">' . enhance_importer_t('imp.page_info', ['page' => $page, 'pages' => $pages, 'total' => (int) ($scan['total'] ?? 0)]) . '</p>';
    }
    if (!$rows) return $pager . enhance_importer_alert('warning', enhance_importer_t('imp.no_services'));

    $cycleValues = ['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'Triennially'];
    $htmlRows = '';
    foreach ($rows as $r) {
        $orgName    = enhance_importer_h($r['org']['name'] ?? '');
        $ownerEmail = enhance_importer_h($r['email'] ?? '');
        $orgIdSafe  = enhance_importer_h($r['orgId']);
        $subName    = enhance_importer_h($r['subscription']['friendlyName'] ?? $r['subscription']['name'] ?? $r['subId'] ?? '');
        $planName   = enhance_importer_h($r['subscription']['planName'] ?? $r['subscription']['plan']['name'] ?? $r['planId'] ?? '');
        $client     = $r['client'];
        $clientName = trim((string) ($client->firstname ?? '') . ' ' . (string) ($client->lastname ?? ''));
        $clientText = '#' . (int) $client->id . ' - ' . enhance_importer_h($clientName ?: (string) $client->email)
            . '<br><small>' . enhance_importer_h($client->email) . '</small><br><span class="label label-success">' . enhance_importer_t('imp.linked_org') . '</span>';

        if ($r['productId']) {
            $productName = (string) Capsule::table('tblproducts')->where('id', (int) $r['productId'])->value('name');
            $productText = enhance_importer_h($productName !== '' ? $productName : EnhanceLang::t('imp.product_n', ['pid' => (int) $r['productId']]))
                . '<br><small>' . enhance_importer_t('imp.product_id', ['pid' => (int) $r['productId']]) . '</small>';
        } else {
            $productText = '<span class="label label-warning">' . enhance_importer_t('imp.plan_not_imported') . '</span>';
        }

        if (!empty($r['error'])) {
            $status   = '<span class="label label-danger">' . enhance_importer_t('imp.status_error') . '</span><br><small>' . enhance_importer_h($r['error']) . '</small>';
            $disabled = ' disabled';
        } else {
            $status = $r['serviceId']
                ? '<span class="label label-info">' . enhance_importer_t('imp.status_imported', ['service' => (int) $r['serviceId']]) . '</span>'
                : '<span class="label label-success">' . enhance_importer_t('imp.status_ready') . '</span>';
            $disabled = (!$r['productId'] || $r['serviceId'] || empty($r['subId'])) ? ' disabled' : '';
        }

        $value      = enhance_importer_h((int) $client->id . '|' . $r['orgId'] . '|' . $r['subId'] . '|' . $r['planId']);
        $subIdSafe  = enhance_importer_h($r['subId']);
        $planIdSafe = enhance_importer_h($r['planId']);

        $cycleSelect = '<select class="form-control input-sm" name="billingcycle[' . $subIdSafe . ']">';
        foreach ($cycleValues as $cycle) {
            $cycleSelect .= '<option value="' . $cycle . '"' . ($cycle === 'Monthly' ? ' selected' : '') . '>' . enhance_importer_t('imp.cycle_' . strtolower(str_replace('-', '', $cycle))) . '</option>';
        }
        $cycleSelect .= '</select><small>' . enhance_importer_t('imp.cycle_help') . '</small>';
        $cycleSelect .= '<input type="date" class="form-control input-sm" style="margin-top:6px;" name="nextduedate[' . $subIdSafe . ']"' . ($disabled ? '' : ' required') . '><small>' . enhance_importer_t('imp.due_help') . '</small>';

        $domainOptions = $r['domainOptions'] ?? [];
        if (count($domainOptions) > 1) {
            $domainInput = '<select class="form-control input-sm" name="domain[' . $subIdSafe . ']">';
            foreach ($domainOptions as $domainOption) {
                $safe = enhance_importer_h($domainOption);
                $domainInput .= '<option value="' . $safe . '">' . $safe . '</option>';
            }
            $domainInput .= '</select><small>' . enhance_importer_t('imp.domain_choose') . '</small>';
        } elseif (count($domainOptions) === 1) {
            $domainInput = '<input type="text" class="form-control input-sm" name="domain[' . $subIdSafe . ']" value="' . enhance_importer_h($domainOptions[0]) . '"><small>' . enhance_importer_t('imp.domain_detected') . '</small>';
        } else {
            $fallback = enhance_importer_h(enhance_importer_subscription_domain($r['subscription']));
            $domainInput = '<input type="text" class="form-control input-sm" name="domain[' . $subIdSafe . ']" value="' . $fallback . '" placeholder="example.com"><small>' . enhance_importer_t('imp.domain_not_detected') . '</small>';
        }

        $lOrgId = enhance_importer_t('imp.org_id');
        $lOwner = enhance_importer_t('imp.enhance_owner');
        $lId    = enhance_importer_t('imp.id');
        $lPlan  = enhance_importer_t('imp.plan_id');
        $htmlRows .= "<tr><td><input type=\"checkbox\" name=\"services[]\" value=\"{$value}\"{$disabled}></td>"
            . "<td><strong>{$orgName}</strong><br><small>{$lOrgId}: {$orgIdSafe}</small><br><small>{$lOwner}: {$ownerEmail}</small></td>"
            . "<td>{$subName}<br><small>{$lId}: {$subIdSafe}</small></td>"
            . "<td>{$planName}<br><small>{$lPlan}: {$planIdSafe}</small></td>"
            . "<td>{$clientText}</td><td>{$productText}</td><td>{$domainInput}</td><td>{$cycleSelect}</td><td>{$status}</td></tr>";
    }

    $action = enhance_importer_h($base . '&p=' . $page);
    $tokenField = enhance_importer_token_field();
    $t = fn($k) => enhance_importer_t($k);
    return $pager . <<<HTML
<form method="post" action="{$action}"><input type="hidden" name="enhance_importer_action" value="import_services">{$tokenField}
<div class="panel panel-default"><div class="panel-heading"><strong>{$t('imp.services_found')}</strong></div><div class="panel-body">
<p>{$t('imp.services_help')}</p>
<label><input type="checkbox" onclick="jQuery('input[name=&quot;services[]&quot;]:not(:disabled)').prop('checked', this.checked);"> {$t('imp.select_all_services')}</label></div>
<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th></th><th>{$t('imp.col_org')}</th><th>{$t('imp.col_subscription')}</th><th>{$t('imp.col_plan_short')}</th><th>{$t('imp.col_client')}</th><th>{$t('imp.col_product')}</th><th>{$t('imp.col_domain')}</th><th>{$t('imp.col_cycle')}</th><th>{$t('imp.col_status')}</th></tr></thead><tbody>{$htmlRows}</tbody></table></div>
<div class="panel-footer"><button type="submit" class="btn btn-success">{$t('imp.import_services')}</button></div></div></form>
HTML;
}

function enhance_importer_payment_method(int $clientId): string
{
    $active  = Capsule::table('tblpaymentgateways')->where('setting', 'name')->orderBy('order')->pluck('gateway')->all();
    $default = (string) Capsule::table('tblclients')->where('id', $clientId)->value('defaultgateway');
    if ($default !== '' && in_array($default, $active, true)) return $default;
    return (string) ($active[0] ?? '');
}

/**
 * Imports one subscription. Runs under the same per-client lock as Create, so
 * a double submit or a concurrent Create can never link a subscription twice.
 * @return string[] alert HTML
 */
function enhance_importer_import_one(EnhanceApi $api, int $serverId, string $raw, array $domains, array $cycles, array $dues): array
{
    $messages = [];
    $allowedCycles = ['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'Triennially'];
    [$clientId, $orgId, $subId, $planId] = array_pad(explode('|', (string) $raw, 4), 4, '');
    $clientId = (int) $clientId;
    $orgId    = strtolower(trim($orgId));
    if ($clientId <= 0 || !EnhanceStore::isUuid($orgId) || !ctype_digit($subId) || $planId === '') {
        $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.invalid_data', ['data' => $raw]));
        return $messages;
    }
    return EnhanceStore::withLock(EnhanceStore::clientLockKey($api->getServerId(), $clientId), function () use ($api, $serverId, $clientId, $orgId, $subId, $planId, $domains, $cycles, $dues, $allowedCycles, $raw) {
        $messages = [];
        if ($existing = $api->findServiceBySubscriptionId($subId)) {
            $messages[] = enhance_importer_alert('info', enhance_importer_t('imp.already_imported', ['sub' => $subId, 'service' => $existing]));
            return $messages;
        }

        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.client_not_found', ['client' => $clientId]));
            return $messages;
        }

        // Safety: the selected client must really own this organisation on this server.
        $storedOrgId = strtolower(trim((string) EnhanceStore::clientOrg($clientId, $api->getServerId())));
        if ($storedOrgId !== $orgId) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.org_mismatch', ['client' => $clientId]));
            return $messages;
        }

        // Re-validate the subscription against Enhance at import time.
        $sub = $api->getSubscription($orgId, $subId);
        if (EnhanceApi::isError($sub) || ($sub['status'] ?? '') === 'deleted' || strtolower((string) ($sub['subscriberId'] ?? $orgId)) !== $orgId) {
            $detail = EnhanceApi::isError($sub) ? EnhanceApi::errorSummary($sub) : EnhanceLang::t('imp.sub_deleted_or_other');
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.sub_invalid', ['sub' => $subId, 'detail' => $detail]));
            return $messages;
        }

        $pid = enhance_importer_find_product_for_plan($serverId, $planId);
        if (!$pid) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.product_for_plan_missing', ['plan' => $planId]));
            return $messages;
        }

        $domain = strtolower(trim((string) ($domains[$subId] ?? '')));
        $cycle  = (string) ($cycles[$subId] ?? 'Monthly');
        if (!in_array($cycle, $allowedCycles, true)) $cycle = 'Monthly';
        $due = (string) ($dues[$subId] ?? '');
        $dueDate = DateTime::createFromFormat('!Y-m-d', $due);
        if (!$dueDate || $dueDate->format('Y-m-d') !== $due) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.due_missing', ['sub' => $subId]));
            return $messages;
        }

        $gateway = enhance_importer_payment_method($clientId);
        if ($gateway === '') {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.no_gateway'));
            return $messages;
        }

        $order = localAPI('AddOrder', [
            'clientid'       => $clientId,
            'pid'            => [$pid],
            'domain'         => [$domain],
            'billingcycle'   => [strtolower(str_replace('-', '', $cycle))],
            'paymentmethod'  => $gateway,
            'noinvoice'      => true,
            'noinvoiceemail' => true,
            'noemail'        => true,
        ]);
        if (($order['result'] ?? '') !== 'success') {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.order_failed', ['client' => $clientId, 'detail' => $order['message'] ?? json_encode($order)]));
            return $messages;
        }

        // AddOrder returns serviceids as a comma-separated string.
        $serviceIds = array_values(array_filter(array_map('intval', explode(',', (string) ($order['serviceids'] ?? '')))));
        $serviceId  = (int) ($serviceIds[0] ?? 0);
        if (!$serviceId) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.order_no_service', ['order' => (int) ($order['orderid'] ?? 0)]));
            return $messages;
        }

        $accept = localAPI('AcceptOrder', ['orderid' => (int) $order['orderid'], 'serverid' => $serverId, 'autosetup' => false, 'sendemail' => false]);
        if (($accept['result'] ?? '') !== 'success') {
            $messages[] = enhance_importer_alert('warning', enhance_importer_t('imp.order_not_accepted', ['order' => (int) $order['orderid'], 'detail' => $accept['message'] ?? '']));
        }

        try {
            $api->saveServiceSubscriptionId($pid, $serviceId, $subId);
        } catch (Throwable $e) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_t('imp.link_failed', ['service' => $serviceId, 'detail' => $e->getMessage()]));
            return $messages;
        }

        $suspended = !empty($sub['isSuspended']) || !empty($sub['suspendedBy']);
        $update = localAPI('UpdateClientProduct', [
            'serviceid'   => $serviceId,
            'serverid'    => $serverId,
            'domain'      => $domain,
            'status'      => $suspended ? 'Suspended' : 'Active',
            'nextduedate' => $due,
        ]);
        if (($update['result'] ?? '') !== 'success') {
            $messages[] = enhance_importer_alert('warning', enhance_importer_t('imp.update_failed', ['service' => $serviceId, 'detail' => $update['message'] ?? '']));
        }
        enhance_importer_save_service_log($serverId, $orgId, $subId, $clientId, $serviceId, $pid, 'imported', ['domain' => $domain, 'nextduedate' => $due]);
        $messages[] = enhance_importer_alert('success', enhance_importer_t('imp.service_imported', ['service' => $serviceId, 'client' => $clientId, 'sub' => $subId, 'due' => $due]));
        return $messages;
    });
}

function enhance_importer_handle_service_import(EnhanceApi $api, int $serverId): string
{
    $selected = $_POST['services'] ?? [];
    if (!is_array($selected) || !$selected) return enhance_importer_alert('warning', enhance_importer_t('imp.no_service_selected'));

    $messages = [];
    $domains  = $_POST['domain'] ?? [];
    $cycles   = $_POST['billingcycle'] ?? [];
    $dues     = $_POST['nextduedate'] ?? [];

    foreach ($selected as $raw) {
        try {
            $messages = array_merge($messages, enhance_importer_import_one($api, $serverId, (string) $raw, (array) $domains, (array) $cycles, (array) $dues));
        } catch (Throwable $e) {
            $messages[] = enhance_importer_alert('danger', enhance_importer_h($e->getMessage()));
        }
    }
    return implode('', $messages);
}

function enhance_importer_save_service_log(int $serverId, string $orgId, string $subId, int $clientId, int $serviceId, int $productId, string $status, array $snapshot): void
{
    $now  = date('Y-m-d H:i:s');
    $key  = ['server_id' => $serverId, 'enhance_subscription_id' => $subId];
    $data = ['enhance_org_id' => $orgId, 'client_id' => $clientId, 'service_id' => $serviceId, 'product_id' => $productId, 'status' => $status, 'snapshot' => json_encode($snapshot), 'updated_at' => $now];
    if (Capsule::table('mod_enhance_service_import_log')->where($key)->exists()) {
        Capsule::table('mod_enhance_service_import_log')->where($key)->update($data);
    } else {
        Capsule::table('mod_enhance_service_import_log')->insert($key + $data + ['created_at' => $now]);
    }
}
