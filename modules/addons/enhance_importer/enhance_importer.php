<?php
/**
 * Enhance Package + Service Importer for WHMCS
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

require_once dirname(__FILE__, 3) . '/servers/enhance/EnhanceApi.php';

function enhance_importer_config(): array
{
    return [
        'name'        => 'Enhance Package Importer',
        'description' => 'Import selected Enhance plans/packages and existing customer services into WHMCS.',
        'version'     => '1.0.0',
        'author'      => 'WebDig.DEV',
        'language'    => 'english',
        'fields'      => [],
    ];
}

function enhance_importer_activate(): array
{
    try {
        enhance_importer_ensure_schema();
        if (!Capsule::schema()->hasTable('mod_enhance_package_map')) {
            Capsule::schema()->create('mod_enhance_package_map', function ($table) {
                $table->increments('id');
                $table->integer('server_id')->default(0);
                $table->string('enhance_plan_id', 191);
                $table->integer('whmcs_product_id')->default(0);
                $table->string('plan_name', 191)->nullable();
                $table->text('plan_snapshot')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['server_id', 'enhance_plan_id'], 'server_plan_unique');
            });
        }
        if (!Capsule::schema()->hasTable('mod_enhance_service_import_log')) {
            Capsule::schema()->create('mod_enhance_service_import_log', function ($table) {
                $table->increments('id');
                $table->integer('server_id')->default(0);
                $table->string('enhance_org_id', 191);
                $table->string('enhance_subscription_id', 191);
                $table->integer('client_id')->default(0);
                $table->integer('service_id')->default(0);
                $table->integer('product_id')->default(0);
                $table->string('status', 64)->nullable();
                $table->text('snapshot')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['server_id', 'enhance_subscription_id'], 'server_subscription_unique');
            });
        }
        return ['status' => 'success', 'description' => 'Enhance Importer activated.'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => $e->getMessage()];
    }
}


function enhance_importer_ensure_schema(): void
{
    if (!Capsule::schema()->hasTable('mod_enhance_package_map')) {
        Capsule::schema()->create('mod_enhance_package_map', function ($table) {
            $table->increments('id');
            $table->integer('server_id')->default(0);
            $table->string('enhance_plan_id', 191);
            $table->integer('whmcs_product_id')->default(0);
            $table->string('plan_name', 191)->nullable();
            $table->text('plan_snapshot')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['server_id', 'enhance_plan_id'], 'server_plan_unique');
        });
    }
    if (!Capsule::schema()->hasTable('mod_enhance_service_import_log')) {
        Capsule::schema()->create('mod_enhance_service_import_log', function ($table) {
            $table->increments('id');
            $table->integer('server_id')->default(0);
            $table->string('enhance_org_id', 191);
            $table->string('enhance_subscription_id', 191);
            $table->integer('client_id')->default(0);
            $table->integer('service_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->string('status', 64)->nullable();
            $table->text('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['server_id', 'enhance_subscription_id'], 'server_subscription_unique');
        });
    }
}

function enhance_importer_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Enhance Importer deactivated. Mapping tables were preserved.'];
}

function enhance_importer_output(array $vars): void
{
    try { enhance_importer_ensure_schema(); } catch (Exception $e) { echo '<div class="alert alert-danger">Error preparing importer tables: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'; return; }

    $moduleLink = $vars['modulelink'];
    $tab = $_REQUEST['tab'] ?? 'packages';

    $servers = Capsule::table('tblservers')->where('type', 'enhance')->where('disabled', '0')->orderBy('name')->get();
    $groups = Capsule::table('tblproductgroups')->orderBy('order')->orderBy('name')->get();
    $serverGroups = Capsule::table('tblservergroups')->orderBy('name')->get();
    $currencies = Capsule::table('tblcurrencies')->orderBy('default', 'desc')->orderBy('id')->get();

    $selectedServerId = (int)($_REQUEST['server_id'] ?? ($servers[0]->id ?? 0));
    $selectedGroupId = (int)($_REQUEST['gid'] ?? ($groups[0]->id ?? 0));
    $selectedServerGroupId = (int)($_REQUEST['servergroupid'] ?? 0);

    echo '<div class="container-fluid">';
    echo '<h2>Enhance Importer</h2>';
    echo '<p>Import Enhance packages and existing customer services into WHMCS with preview and manual selection.</p>';
    echo enhance_importer_tabs($moduleLink, $tab, $selectedServerId, $selectedGroupId, $selectedServerGroupId);

    if ($servers->isEmpty()) {
        echo enhance_importer_alert('danger', 'No active Enhance server is configured in WHMCS.');
        echo '</div>'; return;
    }

    $server = $servers->firstWhere('id', $selectedServerId) ?: $servers->first();
    $selectedServerId = (int)$server->id;

    try {
        $api = new EnhanceApi((string)$server->hostname, (string)$server->username, (string)$server->accesshash);
        if ($tab === 'services') {
            enhance_importer_services_page($moduleLink, $api, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        } elseif ($tab === 'sync') {
            enhance_importer_sync_page($moduleLink, $api, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        } else {
            enhance_importer_packages_page($moduleLink, $api, $servers, $groups, $serverGroups, $currencies, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        }
    } catch (Exception $e) {
        echo enhance_importer_alert('danger', 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    echo '</div>';
}

function enhance_importer_tabs(string $moduleLink, string $active, int $serverId, int $gid, int $sgid): string
{
    $base = $moduleLink . '&server_id=' . $serverId . '&gid=' . $gid . '&servergroupid=' . $sgid;
    $tabs = [
        'packages' => 'Enhance Packages',
        'services' => 'Existing Services',
        'sync' => 'Daily Synchronization',
    ];
    $html = '<ul class="nav nav-tabs" style="margin:15px 0;">';
    foreach ($tabs as $key => $label) {
        $class = $active === $key ? ' class="active"' : '';
        $html .= '<li' . $class . '><a href="' . htmlspecialchars($base . '&tab=' . $key, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul>';
}

function enhance_importer_packages_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, $currencies, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['enhance_importer_action'] ?? '') === 'import_packages') {
        $feedback = enhance_importer_handle_import($api, $selectedServerId, $selectedGroupId, $selectedServerGroupId, $currencies);
    }
    $response = $api->getPlans();
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'packages');
    if (!empty($response['code'])) {
        echo enhance_importer_alert('danger', 'Error fetching Enhance packages: ' . htmlspecialchars($response['message'] ?? $response['detail'] ?? $response['code'], ENT_QUOTES, 'UTF-8'));
        return;
    }
    $plans = $response['items'] ?? [];
    echo $plans ? enhance_importer_plan_table($moduleLink, $plans, $selectedServerId, $selectedGroupId, $selectedServerGroupId) : enhance_importer_alert('warning', 'No Enhance packages/plans were found for this server.');
}

function enhance_importer_services_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['enhance_importer_action'] ?? '') === 'import_services') {
        $feedback = enhance_importer_handle_service_import($api, $selectedServerId);
    }
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'services');
    $rows = enhance_importer_scan_services($api, $selectedServerId);
    echo enhance_importer_services_table($moduleLink, $rows, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
}

function enhance_importer_sync_page(string $moduleLink, EnhanceApi $api, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId): void
{
    $feedback = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['enhance_importer_action'] ?? '') === 'run_daily_sync') {
        $result = enhance_importer_run_daily_sync($selectedServerId, $api);
        $feedback = enhance_importer_alert('success', 'Synchronization completed. Services checked: ' . (int)$result['checked'] . '. Updated: ' . (int)$result['updated'] . '.');
    }
    echo $feedback;
    echo enhance_importer_filters($moduleLink, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId, 'sync');
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Daily Synchronization</strong></div><div class="panel-body">';
    echo '<p>Daily synchronization is executed by the WHMCS cron through the included hook at <code>/includes/hooks/enhance_importer_daily_sync.php</code>. For safety, this routine only synchronizes services that have already been imported/mapped and does not create new services automatically.</p>';
    echo '<form method="post" action="' . htmlspecialchars($moduleLink . '&tab=sync&server_id=' . $selectedServerId . '&gid=' . $selectedGroupId . '&servergroupid=' . $selectedServerGroupId, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="enhance_importer_action" value="run_daily_sync"><button class="btn btn-primary" type="submit">Run synchronization now</button></form>';
    echo '</div></div>';
}

function enhance_importer_handle_import(EnhanceApi $api, int $serverId, int $groupId, int $serverGroupId, $currencies): string
{
    $selected = $_POST['plans'] ?? [];
    if (!is_array($selected) || count($selected) === 0) return enhance_importer_alert('warning', 'No package selected for import.');

    $names = $_POST['product_name'] ?? [];
    $monthly = $_POST['monthly'] ?? [];
    $annually = $_POST['annually'] ?? [];
    $hiddenDefault = !empty($_POST['hidden']);
    $skipExisting = !empty($_POST['skip_existing']);
    $updateExisting = !empty($_POST['update_existing']);

    $plansResponse = $api->getPlans();
    $plansById = [];
    foreach (($plansResponse['items'] ?? []) as $plan) if (isset($plan['id'])) $plansById[(string)$plan['id']] = $plan;

    $messages = [];
    foreach ($selected as $planIdRaw) {
        $planId = (string)$planIdRaw;
        if (!isset($plansById[$planId])) { $messages[] = enhance_importer_alert('danger', 'Plan not found in Enhance: ' . htmlspecialchars($planId, ENT_QUOTES, 'UTF-8')); continue; }
        $plan = $plansById[$planId];
        $productName = trim((string)($names[$planId] ?? ($plan['name'] ?? 'Enhance Plan ' . $planId))) ?: 'Enhance Plan ' . $planId;
        $existing = Capsule::table('tblproducts')->where('servertype', 'enhance')->where('configoption1', $planId)->first();
        if ($existing && $skipExisting) { enhance_importer_save_map($serverId, $planId, (int)$existing->id, $productName, $plan); $messages[] = enhance_importer_alert('info', htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ' already existed and was skipped.'); continue; }
        if ($existing && $updateExisting) {
            Capsule::table('tblproducts')->where('id', (int)$existing->id)->update(['name'=>$productName,'gid'=>$groupId,'servertype'=>'enhance','configoption1'=>$planId,'configoption2'=>'soft','servergroup'=>$serverGroupId,'hidden'=>$hiddenDefault?1:0,'description'=>enhance_importer_plan_description($plan)]);
            enhance_importer_save_map($serverId, $planId, (int)$existing->id, $productName, $plan);
            $messages[] = enhance_importer_alert('success', htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ' updated in WHMCS.'); continue;
        }
        $postData = ['type'=>'hostingaccount','gid'=>$groupId,'name'=>$productName,'paytype'=>'recurring','hidden'=>$hiddenDefault,'showdomainoptions'=>true,'tax'=>true,'autosetup'=>'payment','module'=>'enhance','servergroupid'=>$serverGroupId,'configoption1'=>$planId,'configoption2'=>'soft','description'=>enhance_importer_plan_description($plan),'pricing'=>enhance_importer_build_pricing($currencies, (string)($monthly[$planId] ?? '0'), (string)($annually[$planId] ?? '-1'))];
        $result = localAPI('AddProduct', $postData);
        if (($result['result'] ?? '') !== 'success') { $messages[] = enhance_importer_alert('danger', 'Error importing ' . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($result['message'] ?? json_encode($result), ENT_QUOTES, 'UTF-8')); continue; }
        $pid = (int)($result['pid'] ?? 0);
        if (!$pid) $pid = (int) Capsule::table('tblproducts')->where('servertype','enhance')->where('configoption1',$planId)->orderBy('id','desc')->value('id');
        if ($pid) { Capsule::table('tblproducts')->where('id',$pid)->update(['servertype'=>'enhance','configoption1'=>$planId,'configoption2'=>'soft','servergroup'=>$serverGroupId]); enhance_importer_save_map($serverId,$planId,$pid,$productName,$plan); }
        $messages[] = enhance_importer_alert('success', htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . ' imported as WHMCS product' . ($pid ? ' #' . $pid : '') . '.');
    }
    return implode('', $messages);
}

function enhance_importer_build_pricing($currencies, string $monthly, string $annually): array
{
    $monthlyValue = is_numeric(str_replace(',', '.', $monthly)) ? (float)str_replace(',', '.', $monthly) : 0.00;
    $annualValue = is_numeric(str_replace(',', '.', $annually)) ? (float)str_replace(',', '.', $annually) : -1.00;
    $pricing = [];
    foreach ($currencies as $currency) {
        $pricing[(int)$currency->id] = ['monthly'=>$monthlyValue,'msetupfee'=>0.00,'quarterly'=>-1.00,'qsetupfee'=>0.00,'semiannually'=>-1.00,'ssetupfee'=>0.00,'annually'=>$annualValue,'asetupfee'=>0.00,'biennially'=>-1.00,'bsetupfee'=>0.00,'triennially'=>-1.00,'tsetupfee'=>0.00];
    }
    return $pricing;
}

function enhance_importer_save_map(int $serverId, string $planId, int $productId, string $name, array $plan): void
{
    $exists = Capsule::table('mod_enhance_package_map')->where('server_id',$serverId)->where('enhance_plan_id',$planId)->exists();
    $data = ['whmcs_product_id'=>$productId,'plan_name'=>$name,'plan_snapshot'=>json_encode($plan),'updated_at'=>date('Y-m-d H:i:s')];
    if ($exists) Capsule::table('mod_enhance_package_map')->where('server_id',$serverId)->where('enhance_plan_id',$planId)->update($data);
    else { $data['server_id']=$serverId; $data['enhance_plan_id']=$planId; $data['created_at']=date('Y-m-d H:i:s'); Capsule::table('mod_enhance_package_map')->insert($data); }
}

function enhance_importer_filters(string $moduleLink, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId, string $tab='packages'): string
{
    $serverOptions = ''; foreach ($servers as $server) { $sel=((int)$server->id===$selectedServerId)?' selected':''; $label=htmlspecialchars(($server->name?:$server->hostname).' — '.$server->hostname,ENT_QUOTES,'UTF-8'); $serverOptions.='<option value="'.(int)$server->id.'"'.$sel.'>'.$label.'</option>'; }
    $groupOptions = ''; foreach ($groups as $group) { $sel=((int)$group->id===$selectedGroupId)?' selected':''; $groupOptions.='<option value="'.(int)$group->id.'"'.$sel.'>'.htmlspecialchars($group->name,ENT_QUOTES,'UTF-8').'</option>'; }
    $serverGroupOptions='<option value="0">No server group / automatic</option>'; foreach ($serverGroups as $sg) { $sel=((int)$sg->id===$selectedServerGroupId)?' selected':''; $serverGroupOptions.='<option value="'.(int)$sg->id.'"'.$sel.'>'.htmlspecialchars($sg->name,ENT_QUOTES,'UTF-8').'</option>'; }
    return <<<HTML
<form method="get" action="{$moduleLink}" class="panel panel-default" style="padding:15px;margin:15px 0;">
    <input type="hidden" name="module" value="enhance_importer"><input type="hidden" name="tab" value="{$tab}">
    <div class="row">
        <div class="col-md-4"><label>Enhance Server</label><select name="server_id" class="form-control">{$serverOptions}</select></div>
        <div class="col-md-4"><label>WHMCS Product Group</label><select name="gid" class="form-control">{$groupOptions}</select></div>
        <div class="col-md-3"><label>WHMCS Server Group</label><select name="servergroupid" class="form-control">{$serverGroupOptions}</select></div>
        <div class="col-md-1" style="padding-top:24px;"><button class="btn btn-primary" type="submit">Search</button></div>
    </div>
</form>
HTML;
}

function enhance_importer_plan_table(string $moduleLink, array $plans, int $serverId, int $groupId, int $serverGroupId): string
{
    $rows = '';
    foreach ($plans as $plan) {
        $id = (string)($plan['id'] ?? ''); if ($id==='') continue;
        $name = (string)($plan['name'] ?? ('Plan '.$id));
        $existing = Capsule::table('tblproducts')->where('servertype','enhance')->where('configoption1',$id)->first();
        $mapped = Capsule::table('mod_enhance_package_map')->where('server_id',$serverId)->where('enhance_plan_id',$id)->first();
        $status = $existing ? '<span class="label label-info">Already exists: product #'.(int)$existing->id.'</span>' : '<span class="label label-success">New</span>';
        if ($mapped && !$existing) $status .= ' <span class="label label-warning">Old map #'.(int)$mapped->whmcs_product_id.'</span>';
        $limits = htmlspecialchars(enhance_importer_summary($plan), ENT_QUOTES, 'UTF-8');
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); $safeName=htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $rows .= <<<HTML
<tr>
    <td style="width:35px;"><input type="checkbox" name="plans[]" value="{$safeId}"></td>
    <td><strong>{$safeName}</strong><br><small>Enhance ID: {$safeId}</small></td>
    <td style="max-width:420px;white-space:normal;">{$limits}</td><td>{$status}</td>
    <td><input type="text" class="form-control input-sm" name="product_name[{$safeId}]" value="{$safeName}"></td>
    <td style="width:100px;"><input type="text" class="form-control input-sm" name="monthly[{$safeId}]" value="0.00"></td>
    <td style="width:100px;"><input type="text" class="form-control input-sm" name="annually[{$safeId}]" value="-1"></td>
</tr>
HTML;
    }
    $action = htmlspecialchars($moduleLink.'&tab=packages&server_id='.$serverId.'&gid='.$groupId.'&servergroupid='.$serverGroupId,ENT_QUOTES,'UTF-8');
    return <<<HTML
<form method="post" action="{$action}">
<input type="hidden" name="enhance_importer_action" value="import_packages">
<div class="panel panel-default"><div class="panel-heading"><strong>Packages found in Enhance</strong></div><div class="panel-body">
<label><input type="checkbox" onclick="jQuery('input[name=&quot;plans[]&quot;]').prop('checked', this.checked);"> Select all</label>
<span style="margin-left:20px;"><label><input type="checkbox" name="hidden" value="1" checked> Create hidden products for review</label></span>
<span style="margin-left:20px;"><label><input type="checkbox" name="skip_existing" value="1" checked> Do not duplicate existing products</label></span>
<span style="margin-left:20px;"><label><input type="checkbox" name="update_existing" value="1"> Update selected existing products</label></span></div>
<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th></th><th>Enhance Package</th><th>Technical Summary</th><th>Status</th><th>WHMCS Name</th><th>Monthly</th><th>Annual</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div class="panel-footer"><button type="submit" class="btn btn-success">Import selected packages</button></div></div></form>
HTML;
}

function enhance_importer_summary(array $plan): string
{
    $labels = ['websites'=>'Websites','stagingWebsites'=>'Staging','pageViews'=>'Page views','transfer'=>'Traffic','processes'=>'Processes','mailboxes'=>'Mailboxes','forwarders'=>'Forwarders','mysqlDbs'=>'Databases','ftpUsers'=>'FTP Users','domainAliases'=>'Domain aliases','additionalDomains'=>'Additional domains','dnsRecords'=>'DNS records','diskspace'=>'Disk space','disk'=>'Disk','storage'=>'Storage','emails'=>'Emails','databases'=>'Databases'];
    $parts = [];
    $resources = [];
    foreach (['resources','resourceLimits','limits','quotas'] as $key) {
        if (!empty($plan[$key]) && is_array($plan[$key])) { $resources = $plan[$key]; break; }
    }
    if ($resources) {
        foreach ($resources as $key => $value) {
            $name = is_array($value) ? (string)($value['name'] ?? $key) : (string)$key;
            $total = is_array($value) ? ($value['total'] ?? $value['limit'] ?? $value['value'] ?? null) : $value;
            if ($total === null || $total === '') continue;
            $label = $labels[$name] ?? $name;
            $parts[] = $label . ': ' . enhance_importer_format_limit($name, $total);
        }
    }
    foreach ($labels as $key => $label) {
        foreach ([$key, $key.'Limit', $key.'_limit'] as $candidate) {
            if (array_key_exists($candidate, $plan) && $plan[$candidate] !== null && $plan[$candidate] !== '') {
                $parts[] = $label . ': ' . enhance_importer_format_limit($key, $plan[$candidate]);
                break;
            }
        }
    }
    $parts = array_values(array_unique($parts));
    return $parts ? implode(' | ', array_slice($parts, 0, 10)) : 'No readable technical limits returned by the API.';
}

function enhance_importer_format_limit(string $name, $value): string
{
    if ($value === null || $value === '') return 'Unlimited';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (!is_numeric($value)) return (string)$value;
    $num = (float)$value;
    if ($num < 0) return 'Unlimited';
    if (in_array($name, ['diskspace','disk','storage'], true)) {
        if ($num >= 1073741824) return rtrim(rtrim(number_format($num/1073741824,2,'.',''), '0'), '.') . ' GB';
        if ($num >= 1048576) return rtrim(rtrim(number_format($num/1048576,2,'.',''), '0'), '.') . ' MB';
    }
    if (in_array($name, ['transfer','bandwidth'], true)) {
        if ($num >= 1073741824) return rtrim(rtrim(number_format($num/1073741824,2,'.',''), '0'), '.') . ' GB';
        if ($num >= 1048576) return rtrim(rtrim(number_format($num/1048576,2,'.',''), '0'), '.') . ' MB';
    }
    return (string)(int)$num;
}

function enhance_importer_plan_description(array $plan): string { return "Imported from Enhance.\n" . enhance_importer_summary($plan); }

function enhance_importer_scan_services(EnhanceApi $api, int $serverId): array
{
    $rows = [];

    // Conservative import: the primary key is always the EnhanceOrgId stored on the WHMCS client.
    // The WHMCS client email may be different from the Enhance Owner email.
    $clientOrgFieldId = (int) Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->where('fieldname', EnhanceApi::FIELD_CLIENT_ORG_ID)
        ->value('id');

    if (!$clientOrgFieldId) {
        return [];
    }

    $mappedClients = Capsule::table('tblcustomfieldsvalues as cfv')
        ->join('tblclients as c', 'c.id', '=', 'cfv.relid')
        ->where('cfv.fieldid', $clientOrgFieldId)
        ->where('cfv.value', '!=', '')
        ->where('c.status', '!=', 'Closed')
        ->select('c.*', 'cfv.value as enhance_org_id')
        ->orderBy('c.id')
        ->get();

    foreach ($mappedClients as $client) {
        $orgId = trim((string) $client->enhance_org_id);
        if ($orgId === '') continue;

        $org = $api->getOrg($orgId);
        if (!empty($org['code'])) {
            $rows[] = [
                'org' => ['id' => $orgId, 'name' => 'Organisation not found in Enhance'],
                'subscription' => [],
                'orgId' => $orgId,
                'subId' => '',
                'planId' => '',
                'email' => '',
                'client' => $client,
                'productId' => 0,
                'serviceId' => 0,
                'matchMode' => 'EnhanceOrgId',
                'matchConfidence' => 'erro',
                'error' => $org['message'] ?? $org['detail'] ?? $org['code'],
            ];
            continue;
        }

        $subs = $api->getCustomerSubscriptions($orgId, 500);
        if (!empty($subs['code'])) {
            $rows[] = [
                'org' => $org + ['id' => $orgId],
                'subscription' => [],
                'orgId' => $orgId,
                'subId' => '',
                'planId' => '',
                'email' => (string)($org['ownerEmail'] ?? $org['email'] ?? ''),
                'client' => $client,
                'productId' => 0,
                'serviceId' => 0,
                'matchMode' => 'EnhanceOrgId',
                'matchConfidence' => 'erro',
                'error' => $subs['message'] ?? $subs['detail'] ?? $subs['code'],
            ];
            continue;
        }

        foreach (($subs['items'] ?? []) as $sub) {
            $subId = (string)($sub['id'] ?? $sub['subscriptionId'] ?? '');
            if ($subId === '') continue;

            $planId = (string)($sub['planId'] ?? ($sub['plan']['id'] ?? $sub['packageId'] ?? $sub['package']['id'] ?? ''));
            $productId = enhance_importer_find_product_for_plan($serverId, $planId);
            $serviceId = enhance_importer_find_service_by_subscription($subId);
            $ownerEmail = (string)($org['ownerEmail'] ?? $org['email'] ?? $sub['ownerEmail'] ?? '');
            $domainOptions = enhance_importer_domain_options_for_subscription($api, $orgId, $subId, $sub);

            $rows[] = [
                'org' => $org + ['id' => $orgId],
                'subscription' => $sub,
                'orgId' => $orgId,
                'subId' => $subId,
                'planId' => $planId,
                'email' => $ownerEmail,
                'client' => $client,
                'productId' => $productId,
                'serviceId' => $serviceId,
                'matchMode' => 'EnhanceOrgId',
                'matchConfidence' => '100%',
                'domainOptions' => $domainOptions ?? [],
                'error' => '',
            ];
        }
    }

    return $rows;
}

function enhance_importer_find_product_for_plan(int $serverId, string $planId): int
{
    if ($planId === '') return 0;
    $mapped = Capsule::table('mod_enhance_package_map')->where('server_id',$serverId)->where('enhance_plan_id',$planId)->value('whmcs_product_id');
    if ($mapped) return (int)$mapped;
    return (int)Capsule::table('tblproducts')->where('servertype','enhance')->where('configoption1',$planId)->value('id');
}

function enhance_importer_find_service_by_subscription(string $subId): int
{
    if ($subId === '') return 0;
    $fieldIds = Capsule::table('tblcustomfields')->where('type','product')->where('fieldname', EnhanceApi::FIELD_SUBSCRIPTION_ID)->pluck('id')->all();
    if (!$fieldIds) return 0;
    return (int)Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid',$fieldIds)->where('value',$subId)->value('relid');
}

function enhance_importer_domain_options_for_subscription(EnhanceApi $api, string $orgId, string $subId, array $sub): array
{
    $domains = [];

    // 1) Data already returned by the subscription payload.
    enhance_importer_collect_domains_from_array($sub, $domains);

    // 2) Websites associated with the subscription.
    $websites = [];
    if ($orgId !== '' && $subId !== '') {
        $bySubscription = $api->listWebsitesBySubscription($orgId, $subId);
        if (empty($bySubscription['code'])) {
            $websites = array_merge($websites, $bySubscription['items'] ?? []);
        }
    }

    // 3) Fallback: all websites from the organisation. This is useful when the API does not return
    // all domains through the subscriptionId filter, or when the domain is stored as
    // alias/addon noutro endpoint.
    if ($orgId !== '') {
        $allWebsites = $api->listWebsites($orgId, 500);
        if (empty($allWebsites['code'])) {
            foreach (($allWebsites['items'] ?? []) as $website) {
                $websiteSubId = (string)($website['subscriptionId'] ?? $website['subscription']['id'] ?? '');
                if ($subId === '' || $websiteSubId === '' || $websiteSubId === $subId) {
                    $websites[] = $website;
                }
            }
        }
    }

    $seenWebsiteIds = [];
    foreach ($websites as $website) {
        enhance_importer_collect_domains_from_array($website, $domains);
        $websiteId = (string)($website['id'] ?? $website['websiteId'] ?? '');
        if ($orgId !== '' && $websiteId !== '' && empty($seenWebsiteIds[$websiteId])) {
            $seenWebsiteIds[$websiteId] = true;
            $websiteDomains = $api->listWebsiteDomains($orgId, $websiteId, 500);
            if (empty($websiteDomains['code'])) {
                foreach (($websiteDomains['items'] ?? []) as $domainRow) {
                    if (is_array($domainRow)) {
                        enhance_importer_collect_domains_from_array($domainRow, $domains);
                    } elseif (is_scalar($domainRow)) {
                        $domains[] = (string)$domainRow;
                    }
                }
            }
        }
    }

    return enhance_importer_clean_sort_domains($domains);
}

function enhance_importer_collect_domains_from_array(array $source, array &$domains): void
{
    foreach (['primaryDomain', 'primary_domain', 'domain', 'domainName', 'domain_name', 'websiteDomain', 'hostname', 'fqdn', 'name'] as $key) {
        if (!empty($source[$key]) && is_scalar($source[$key])) {
            $domain = enhance_importer_normalize_domain((string)$source[$key]);
            if ($domain !== '') $domains[] = $domain;
        }
    }

    foreach (['website', 'primaryWebsite', 'site', 'domain', 'primaryDomain'] as $key) {
        if (!empty($source[$key]) && is_array($source[$key])) {
            enhance_importer_collect_domains_from_array($source[$key], $domains);
        }
    }

    foreach (['websites', 'domains', 'domainMappings', 'domain_mappings', 'websiteDomains', 'domainAliases', 'aliases', 'addonDomains', 'subDomains', 'subdomains'] as $key) {
        if (!empty($source[$key]) && is_array($source[$key])) {
            foreach ($source[$key] as $item) {
                if (is_array($item)) {
                    enhance_importer_collect_domains_from_array($item, $domains);
                } elseif (is_scalar($item)) {
                    $domain = enhance_importer_normalize_domain((string)$item);
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
        $domain = enhance_importer_normalize_domain((string)$domain);
        if ($domain === '') continue;
        $key = strtolower($domain);
        if (!isset($clean[$key])) $clean[$key] = $domain;
    }

    $values = array_values($clean);
    usort($values, function ($a, $b) {
        $scoreA = enhance_importer_domain_score($a);
        $scoreB = enhance_importer_domain_score($b);
        if ($scoreA === $scoreB) return strcmp($a, $b);
        return $scoreB <=> $scoreA;
    });

    return $values;
}

function enhance_importer_domain_score(string $domain): int
{
    $score = 0;
    if (enhance_importer_is_probable_root_domain($domain)) $score += 100;
    if (preg_match('/^(mta-sts|autoconfig|autodiscover|mail|smtp|imap|pop|webmail|cpanel|whm|ftp|status|old|dev|staging|test)\./i', $domain)) $score -= 80;
    if (substr_count($domain, '.') >= 2 && !enhance_importer_is_probable_root_domain($domain)) $score -= 25;
    return $score;
}

function enhance_importer_is_probable_root_domain(string $domain): bool
{
    $parts = explode('.', strtolower($domain));
    $count = count($parts);
    if ($count === 2) return true;

    $twoLevelPt = ['com.pt','net.pt','org.pt','edu.pt','gov.pt','nome.pt','publ.pt'];
    $suffix = implode('.', array_slice($parts, -2));
    if ($count === 3 && in_array($suffix, $twoLevelPt, true)) return true;

    return false;
}

function enhance_importer_normalize_domain(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = preg_replace('#:\\d+$#', '', $value);
    $value = trim($value, " \t\n\r\0\x0B.");

    if ($value === '' || strpos($value, ' ') !== false || strpos($value, '.') === false) return '';
    if (!preg_match('/^[a-z0-9.-]+$/i', $value)) return '';

    return strtolower($value);
}

function enhance_importer_subscription_domain(array $sub): string
{
    $domains = [];
    enhance_importer_collect_domains_from_array($sub, $domains);
    $domains = enhance_importer_clean_sort_domains($domains);
    return $domains[0] ?? '';
}

function enhance_importer_services_table(string $moduleLink, array $rows, int $serverId, int $groupId, int $serverGroupId): string
{
    if (!$rows) return enhance_importer_alert('warning', 'No services were found for WHMCS clients with EnhanceOrgId populated. Confirm that the EnhanceOrgId client field is populated for the clients you want to import.');
    $htmlRows='';
    foreach ($rows as $r) {
        $orgName = htmlspecialchars((string)($r['org']['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ownerEmail = htmlspecialchars((string)($r['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $orgIdSafe = htmlspecialchars((string)$r['orgId'], ENT_QUOTES, 'UTF-8');
        $subName = htmlspecialchars((string)($r['subscription']['name'] ?? $r['subId'] ?? ''), ENT_QUOTES, 'UTF-8');
        $planName = htmlspecialchars((string)($r['subscription']['planName'] ?? $r['subscription']['plan']['name'] ?? $r['planId'] ?? ''), ENT_QUOTES, 'UTF-8');
        $clientName = trim((string)($r['client']->firstname ?? '') . ' ' . (string)($r['client']->lastname ?? ''));
        $clientEmail = htmlspecialchars((string)($r['client']->email ?? ''), ENT_QUOTES, 'UTF-8');
        $clientText = $r['client'] ? '#'.(int)$r['client']->id.' — '.htmlspecialchars($clientName ?: (string)$r['client']->email, ENT_QUOTES, 'UTF-8').'<br><small>'.$clientEmail.'</small><br><span class="label label-success">EnhanceOrgId 100%</span>' : '<span class="label label-danger">Client not found</span>';
        if ($r['productId']) {
            $product = Capsule::table('tblproducts')->where('id', (int)$r['productId'])->first();
            $productName = $product ? (string)$product->name : '';
            $productText = htmlspecialchars($productName !== '' ? $productName : ('Product #' . (int)$r['productId']), ENT_QUOTES, 'UTF-8') . '<br><small>Product ID: ' . (int)$r['productId'] . '</small>';
        } else {
            $productText = '<span class="label label-warning">Plan not imported</span>';
        }
        if (!empty($r['error'])) {
            $status = '<span class="label label-danger">Error</span><br><small>'.htmlspecialchars((string)$r['error'], ENT_QUOTES, 'UTF-8').'</small>';
            $disabled = ' disabled';
        } else {
            $status = $r['serviceId'] ? '<span class="label label-info">Already imported: service #'.(int)$r['serviceId'].'</span>' : '<span class="label label-success">Ready to import</span>';
            $disabled = (!$r['client'] || !$r['productId'] || $r['serviceId'] || empty($r['subId'])) ? ' disabled' : '';
        }
        $clientId = $r['client'] ? (int)$r['client']->id : 0;
        $value = htmlspecialchars($clientId.'|'.$r['orgId'].'|'.$r['subId'].'|'.$r['planId'], ENT_QUOTES, 'UTF-8');
        $subIdSafe = htmlspecialchars((string)$r['subId'], ENT_QUOTES, 'UTF-8');
        $planIdSafe = htmlspecialchars((string)$r['planId'], ENT_QUOTES, 'UTF-8');
        $domainOptions = $r['domainOptions'] ?? [];
        if (count($domainOptions) > 1) {
            $domainInput = '<select class="form-control input-sm" name="domain[' . $subIdSafe . ']">';
            foreach ($domainOptions as $domainOption) {
                $safeDomainOption = htmlspecialchars((string)$domainOption, ENT_QUOTES, 'UTF-8');
                $domainInput .= '<option value="' . $safeDomainOption . '">' . $safeDomainOption . '</option>';
            }
            $domainInput .= '</select><small>Choose the primary domain</small>';
        } elseif (count($domainOptions) === 1) {
            $domain = htmlspecialchars((string)$domainOptions[0], ENT_QUOTES, 'UTF-8');
            $domainInput = '<input type="text" class="form-control input-sm" name="domain[' . $subIdSafe . ']" value="' . $domain . '"><small>Detected automatically</small>';
        } else {
            $fallbackDomain = htmlspecialchars(enhance_importer_subscription_domain($r['subscription']), ENT_QUOTES, 'UTF-8');
            $domainInput = '<input type="text" class="form-control input-sm" name="domain[' . $subIdSafe . ']" value="' . $fallbackDomain . '" placeholder="example.com"><small>Not detected in Enhance</small>';
        }
        $htmlRows .= "<tr><td><input type=\"checkbox\" name=\"services[]\" value=\"{$value}\"{$disabled}></td><td><strong>{$orgName}</strong><br><small>Org ID: {$orgIdSafe}</small><br><small>Enhance Owner: {$ownerEmail}</small></td><td>{$subName}<br><small>ID: {$subIdSafe}</small></td><td>{$planName}<br><small>Plan ID: {$planIdSafe}</small></td><td>{$clientText}</td><td>{$productText}</td><td>{$domainInput}</td><td>{$status}</td></tr>";
    }
    $action = htmlspecialchars($moduleLink.'&tab=services&server_id='.$serverId.'&gid='.$groupId.'&servergroupid='.$serverGroupId,ENT_QUOTES,'UTF-8');
    return <<<HTML
<form method="post" action="{$action}"><input type="hidden" name="enhance_importer_action" value="import_services">
<div class="panel panel-default"><div class="panel-heading"><strong>Existing Services in Enhance</strong></div><div class="panel-body">
<p>This import matches services using the <strong>EnhanceOrgId</strong> stored on the WHMCS client. The Enhance Owner email is informational only and may be different from the WHMCS client email.</p>
<label><input type="checkbox" onclick="jQuery('input[name=&quot;services[]&quot;]:not(:disabled)').prop('checked', this.checked);"> Select all importable services</label></div>
<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th></th><th>Enhance Organisation</th><th>Subscription</th><th>Plan</th><th>WHMCS Client</th><th>WHMCS Product</th><th>Domain</th><th>Status</th></tr></thead><tbody>{$htmlRows}</tbody></table></div>
<div class="panel-footer"><button type="submit" class="btn btn-success">Import selected services</button></div></div></form>
HTML;
}

function enhance_importer_handle_service_import(EnhanceApi $api, int $serverId): string
{
    $selected = $_POST['services'] ?? [];
    if (!is_array($selected) || !$selected) return enhance_importer_alert('warning', 'No service selected for import.');
    $messages=[]; $domains = $_POST['domain'] ?? [];
    foreach ($selected as $raw) {
        [$clientId,$orgId,$subId,$planId] = array_pad(explode('|', (string)$raw, 4), 4, '');
        $clientId = (int)$clientId;
        if ($clientId <= 0 || $orgId==='' || $subId==='' || $planId==='') { $messages[] = enhance_importer_alert('danger', 'Invalid data: '.htmlspecialchars((string)$raw,ENT_QUOTES,'UTF-8')); continue; }
        if (enhance_importer_find_service_by_subscription($subId)) { $messages[] = enhance_importer_alert('info', 'Subscription already imported: '.htmlspecialchars($subId,ENT_QUOTES,'UTF-8')); continue; }

        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) { $messages[] = enhance_importer_alert('danger', 'WHMCS client not found: #'.(int)$clientId); continue; }

        // Safety validation: does the selected client really have this EnhanceOrgId?
        $clientOrgFieldId = (int) Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', EnhanceApi::FIELD_CLIENT_ORG_ID)
            ->value('id');
        $storedOrgId = $clientOrgFieldId ? (string) Capsule::table('tblcustomfieldsvalues')->where('fieldid', $clientOrgFieldId)->where('relid', $clientId)->value('value') : '';
        if (trim($storedOrgId) !== trim($orgId)) {
            $messages[] = enhance_importer_alert('danger', 'The EnhanceOrgId for client #'.(int)$clientId.' does not match the selected organisation. Import blocked for safety.');
            continue;
        }

        $pid = enhance_importer_find_product_for_plan($serverId,$planId);
        if (!$pid) { $messages[] = enhance_importer_alert('danger', 'WHMCS product not found for Enhance plan '.htmlspecialchars($planId,ENT_QUOTES,'UTF-8')); continue; }
        $domain = trim((string)($domains[$subId] ?? ''));
        $order = localAPI('AddOrder', ['clientid'=>$clientId,'pid'=>[$pid],'domain'=>[$domain],'billingcycle'=>['Monthly'],'paymentmethod'=>'banktransfer','noinvoice'=>true,'noemail'=>true]);
        if (($order['result']??'') !== 'success') { $messages[] = enhance_importer_alert('danger', 'Error creating order for client #'.(int)$clientId.': '.htmlspecialchars($order['message']??json_encode($order),ENT_QUOTES,'UTF-8')); continue; }
        if (!empty($order['orderid'])) localAPI('AcceptOrder', ['orderid'=>(int)$order['orderid'],'autosetup'=>false,'sendemail'=>false]);
        $serviceId = 0;
        if (!empty($order['serviceids']) && is_array($order['serviceids'])) $serviceId = (int)reset($order['serviceids']);
        if (!$serviceId) $serviceId = (int)Capsule::table('tblhosting')->where('userid',$clientId)->where('packageid',$pid)->orderBy('id','desc')->value('id');
        if (!$serviceId) { $messages[] = enhance_importer_alert('danger', 'Order created, but the created service could not be identified.'); continue; }
        $api->saveServiceSubscriptionId($pid,$serviceId,$subId);
        Capsule::table('tblhosting')->where('id',$serviceId)->update(['server'=>$serverId,'domain'=>$domain,'domainstatus'=>'Active']);
        enhance_importer_save_service_log($serverId,$orgId,$subId,$clientId,$serviceId,$pid,'imported',['domain'=>$domain]);
        $messages[] = enhance_importer_alert('success', 'Service #'.(int)$serviceId.' imported for client #'.(int)$clientId.' by EnhanceOrgId.');
    }
    return implode('', $messages);
}

function enhance_importer_save_service_log(int $serverId, string $orgId, string $subId, int $clientId, int $serviceId, int $productId, string $status, array $snapshot): void
{
    enhance_importer_ensure_schema();
    $exists = Capsule::table('mod_enhance_service_import_log')->where('server_id',$serverId)->where('enhance_subscription_id',$subId)->exists();
    $data=['enhance_org_id'=>$orgId,'client_id'=>$clientId,'service_id'=>$serviceId,'product_id'=>$productId,'status'=>$status,'snapshot'=>json_encode($snapshot),'updated_at'=>date('Y-m-d H:i:s')];
    if ($exists) Capsule::table('mod_enhance_service_import_log')->where('server_id',$serverId)->where('enhance_subscription_id',$subId)->update($data);
    else { $data['server_id']=$serverId; $data['enhance_subscription_id']=$subId; $data['created_at']=date('Y-m-d H:i:s'); Capsule::table('mod_enhance_service_import_log')->insert($data); }
}

function enhance_importer_run_daily_sync(int $serverId, EnhanceApi $api): array
{
    enhance_importer_ensure_schema();
    $checked=0; $updated=0;
    $logs = Capsule::table('mod_enhance_service_import_log')->where('server_id',$serverId)->get();
    foreach ($logs as $log) {
        $checked++;
        $sub = $api->getSubscription((string)$log->enhance_org_id, (string)$log->enhance_subscription_id);
        if (!empty($sub['code'])) continue;
        $newStatus = !empty($sub['isSuspended']) || !empty($sub['suspendedBy']) ? 'Suspended' : 'Active';
        $current = Capsule::table('tblhosting')->where('id',(int)$log->service_id)->value('domainstatus');
        if ($current && $current !== $newStatus) { Capsule::table('tblhosting')->where('id',(int)$log->service_id)->update(['domainstatus'=>$newStatus]); $updated++; }
        enhance_importer_save_service_log($serverId,(string)$log->enhance_org_id,(string)$log->enhance_subscription_id,(int)$log->client_id,(int)$log->service_id,(int)$log->product_id,$newStatus,$sub);
    }
    return ['checked'=>$checked,'updated'=>$updated];
}

function enhance_importer_alert(string $type, string $message): string { return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . $message . '</div>'; }
