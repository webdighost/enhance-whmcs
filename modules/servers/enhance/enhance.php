<?php
/**
 * Enhance WHMCS Server Module v1.0.0
 *
 * Supports: account creation, suspension, termination, plan change, SSO,
 * admin services tab, dynamic plan dropdown, test connection.
 *
 * Credentials are read per-request from WHMCS server $params — multiple
 * Enhance servers are fully supported.
 * Credentials are read from $params per request — multiple servers are supported.
 */

require_once __DIR__ . '/EnhanceApi.php';

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// =============================================================================
// Helpers
// =============================================================================

function _enhance_api(array $params): EnhanceApi
{
    return new EnhanceApi(
        $params['serverhostname'],
        $params['serverusername'],   // orgId in WHMCS server "Username"
        $params['serveraccesshash']  // token in WHMCS server "Access Hash"
    );
}

function _enhance_api_error(array $resp): ?string
{
    if (!empty($resp['code'])) {
        return "[{$resp['code']}] " . ($resp['detail'] ?? $resp['message'] ?? '');
    }
    return null;
}

function _enhance_client_name(array $client): string
{
    $name = trim((string)($client["fullname"] ?? ""));
    if ($name !== "") return $name;
    $first = trim((string)($client["firstname"] ?? ""));
    $last  = trim((string)($client["lastname"] ?? ""));
    return trim($first . " " . $last);
}

function _enhance_sync_customer(EnhanceApi $api, array $params): array
{
    $client = $params["clientsdetails"];
    return $api->syncCustomerFromWhmcs(
        (int) $client["id"],
        _enhance_client_name($client),
        (string) $client["email"],
        (string) ($client["companyname"] ?? "")
    );
}

function _enhance_resolve_subscription(EnhanceApi $api, array $params): array
{
    $sync = _enhance_sync_customer($api, $params);
    if (empty($sync["success"]) || empty($sync["orgId"])) {
        throw new Exception($sync["message"] ?? "Could not sync customer organisation.");
    }

    $orgId     = (string) $sync["orgId"];
    $packageId = (int) $params["packageid"];
    $serviceId = (int) $params["serviceid"];
    $subId     = $api->getServiceSubscriptionId($packageId, $serviceId);

    if ($subId) {
        $sub = $api->getSubscription($orgId, $subId);
        if (empty($sub["code"])) {
            return ["orgId" => $orgId, "subId" => $subId, "subscription" => $sub, "synced" => $sync];
        }
        $api->clearServiceSubscriptionId($packageId, $serviceId);
        $subId = null;
    }

    $subs = $api->getCustomerSubscriptions($orgId);
    if ($err = _enhance_api_error($subs)) {
        throw new Exception("Could not list customer subscriptions: " . $err);
    }

    $items = $subs["items"] ?? [];
    if (count($items) === 1 && !empty($items[0]["id"])) {
        $subId = (string) $items[0]["id"];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);
        return ["orgId" => $orgId, "subId" => $subId, "subscription" => $items[0], "synced" => $sync];
    }

    $planId = (int) ($params["configoption1"] ?? 0);
    foreach ($items as $item) {
        $itemPlanId = (int) ($item["planId"] ?? ($item["plan"]["id"] ?? 0));
        if ($planId > 0 && $itemPlanId === $planId && !empty($item["id"])) {
            $subId = (string) $item["id"];
            $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);
            return ["orgId" => $orgId, "subId" => $subId, "subscription" => $item, "synced" => $sync];
        }
    }

    throw new Exception("Subscription not found or ambiguous.");
}

function _enhance_subscription_is_suspended(array $sub): ?bool
{
    if (array_key_exists("isSuspended", $sub)) return (bool) $sub["isSuspended"];
    if (array_key_exists("suspended", $sub)) return (bool) $sub["suspended"];
    if (!empty($sub["suspendedBy"])) return true;
    if (($sub["status"] ?? "") === "suspended") return true;
    return null;
}

// =============================================================================
// Metadata
// =============================================================================

function enhance_MetaData(): array
{
    return [
        'DisplayName'              => 'Enhance',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => 'Login to Panel',
        'AdminSingleSignOnLabel'   => 'Login to Panel',
    ];
}

// =============================================================================
// Config options
// =============================================================================

function enhance_ConfigOptions(): array
{
    return [
        'plano' => [
            'FriendlyName' => 'Hosting Package',
            'Type'         => 'text',
            'Size'         => '255',
            'Loader'       => 'enhance_LoadPlans',
            'YespleMode'   => true,
        ],
        'hardDelete' => [
            'FriendlyName' => 'Termination Mode',
            'Type'         => 'dropdown',
            'Options'      => 'soft,hard',
            'Description'  => 'soft = keep data | hard = delete all websites + subscription permanently',
            'Default'      => 'soft',
        ],
    ];
}

function enhance_LoadPlans(array $params): array
{
    try {
        $result = _enhance_api($params)->getPlans();
        $out    = [];
        foreach ($result['items'] ?? [] as $plan) {
            $out[$plan['id']] = "{$plan['id']} — {$plan['name']}";
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// =============================================================================
// Test connection
// =============================================================================

function enhance_TestConnection(array $params): array
{
    try {
        $resp = _enhance_api($params)->getLicense();

        if (($resp['code'] ?? '') === 'unauthorized') {
            return ['success' => false, 'error' => 'Not authenticated — check credentials.'];
        }

        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// =============================================================================
// Create account
// =============================================================================

function enhance_CreateAccount(array $params): string
{
    try {
        $api       = _enhance_api($params);
        $client    = $params['clientsdetails'];
        $packageId = (int) $params['packageid'];
        $serviceId = (int) $params['serviceid'];
        $planId    = (int) $params['configoption1'];
        $domain    = $params['domain'];

        if ($api->getServiceSubscriptionId($packageId, $serviceId) !== null) {
            return 'Already provisioned — SUBSCRIPTION_ID is already set.';
        }

        $sync = _enhance_sync_customer($api, $params);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            return $sync['message'] ?? 'Could not sync customer organisation.';
        }
        $orgId = $sync['orgId'];

        $sub = $api->createSubscription($orgId, $planId);
        if (empty($sub['id'])) {
            return 'Failed to create subscription: ' . (_enhance_api_error($sub) ?? json_encode($sub));
        }

        $subId = $sub['id'];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);

        if ($domain !== '') {
            $site = $api->createWebsite($orgId, $subId, $domain);
            if (!empty($site['code'])) {
                $api->deleteSubscription($orgId, $subId, true);
                $api->clearServiceSubscriptionId($packageId, $serviceId);
                return 'Website creation failed (subscription rolled back): ' . _enhance_api_error($site);
            }
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Suspend / Unsuspend
// =============================================================================

function enhance_SuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $current  = _enhance_subscription_is_suspended($resolved['subscription']);

        if ($current === true) {
            return 'success';
        }

        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], true);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return 'Suspension sent but verification failed: ' . $err;

        $verified = _enhance_subscription_is_suspended($verify);
        if ($verified === false) {
            return 'Suspension request accepted, but Enhance still reports the subscription as active.';
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function enhance_UnsuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $org      = $api->getOrg($resolved['orgId']);

        if (empty($org['code']) && (!empty($org['suspendedBy']) || (($org['status'] ?? '') === 'suspended'))) {
            $orgResp = $api->setOrgSuspended($resolved['orgId'], false);
            if ($err = _enhance_api_error($orgResp)) return 'Could not reactivate organisation: ' . $err;
        }

        $current = _enhance_subscription_is_suspended($resolved['subscription']);
        if ($current === false) {
            return 'success';
        }

        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], false);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return 'Unsuspension sent but verification failed: ' . $err;

        $verified = _enhance_subscription_is_suspended($verify);
        if ($verified === true) {
            return 'Unsuspension request accepted, but Enhance still reports the subscription as suspended.';
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Terminate
// =============================================================================

function enhance_TerminateAccount(array $params): string
{
    try {
        $api        = _enhance_api($params);
        $packageId  = (int) $params['packageid'];
        $serviceId  = (int) $params['serviceid'];
        $resolved   = _enhance_resolve_subscription($api, $params);
        $orgId      = $resolved['orgId'];
        $subId      = $resolved['subId'];
        $hardDelete = (($params['configoption2'] ?? 'soft') === 'hard');

        if ($hardDelete) {
            $sites = $api->listWebsitesBySubscription($orgId, $subId);
            if ($err = _enhance_api_error($sites)) return $err;
            foreach ($sites['items'] ?? [] as $site) {
                $api->deleteWebsite($orgId, $site['id']);
            }
        }

        $resp = $api->deleteSubscription($orgId, $subId, $hardDelete);
        if ($err = _enhance_api_error($resp)) return $err;

        $api->clearServiceSubscriptionId($packageId, $serviceId);
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Change package
// =============================================================================

function enhance_ChangePackage(array $params): string
{
    try {
        $api       = _enhance_api($params);
        $resolved  = _enhance_resolve_subscription($api, $params);
        $orgId     = $resolved['orgId'];
        $subId     = $resolved['subId'];
        $newPlanId = (int) $params['configoption1'];

        $resp = $api->updateSubscriptionPlan($orgId, $subId, $newPlanId);
        return ($err = _enhance_api_error($resp)) ? $err : 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// SSO
// =============================================================================

function enhance_ServiceSingleSignOn(array $params): array
{
    try {
        $api  = _enhance_api($params);
        $sync = _enhance_sync_customer($api, $params);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            throw new Exception($sync['message'] ?? 'Organisation not found.');
        }
        $orgId = $sync['orgId'];

        $url = $api->getOwnerSsoUrl($orgId);
        if ($url) return ['success' => true, 'redirectTo' => $url];

        throw new Exception('Could not generate SSO link.');
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function enhance_AdminSingleSignOn(array $params): array
{
    try {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            throw new Exception('Server hostname is empty.');
        }

        // Server-level admin access in WHMCS is not the same as service/client SSO.
        // For Enhance, the admin button should open the configured Enhance panel directly.
        // PT: O acesso admin abre diretamente o painel Enhance configurado no servidor.
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }

        return [
            'success'    => true,
            'redirectTo' => rtrim($host, '/'),
        ];
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function enhance_AdminLink(array $params): string
{
    $host = trim((string) ($params['serverhostname'] ?? ''));
    if ($host === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $host)) {
        $host = 'https://' . $host;
    }

    $url = htmlspecialchars(rtrim($host, '/'), ENT_QUOTES, 'UTF-8');

    return '<a class="btn btn-default" href="' . $url . '" target="_blank" rel="noopener noreferrer">Login to Panel</a>';
}

// =============================================================================
// Client area
// =============================================================================

function enhance_ClientArea(array $params): array
{
    $systemUrl = Setting::getValue('SystemURL');
    $ssoUrl    = "{$systemUrl}/clientarea.php?action=productdetails&id={$params['serviceid']}&dosinglesignon=1";

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => [
            'ssoUrl' => $ssoUrl,
            'domain' => $params['domain'],
        ],
    ];
}

// =============================================================================
// Admin services tab
// =============================================================================

function enhance_AdminServicesTabFields(array $params): array
{
    try {
        $api       = _enhance_api($params);
        $orgId     = $api->getClientOrgId((int) $params['clientsdetails']['id']);
        $websiteId = $params['customfields']['WEBSITE_ID'] ?? '';

        if (!$orgId || !$websiteId) return [];

        $site = $api->getWebsite($orgId, $websiteId);
        if (!isset($site['id'])) return [];

        $color   = htmlspecialchars($site['colorCode']                   ?? '999999', ENT_QUOTES);
        $status  = htmlspecialchars($site['status']                      ?? 'unknown', ENT_QUOTES);
        $domain  = htmlspecialchars($site['domain']['domain']            ?? $params['domain'], ENT_QUOTES);
        $ipv4    = htmlspecialchars($site['serverIps'][0]['ip']          ?? 'N/A', ENT_QUOTES);
        $ipv6    = htmlspecialchars($site['appServerIpv6']               ?? 'N/A', ENT_QUOTES);
        $php     = htmlspecialchars($site['phpVersion']                  ?? 'N/A', ENT_QUOTES);
        $mysql   = htmlspecialchars($site['canUse']['mysqlKind']         ?? 'N/A', ENT_QUOTES);

        $html = <<<HTML
<div style="background:#f2f5ff;border-radius:8px;padding:16px;margin:8px 0;">
    <h4 style="margin:0 0 6px;">{$domain}
        <span style="color:#{$color};font-size:.85em;margin-left:8px;">
            <i class="fa fa-circle"></i> {$status}
        </span>
    </h4>
    <div style="display:flex;flex-wrap:wrap;gap:14px;background:#fff;padding:8px 12px;border-radius:5px;margin-top:8px;">
        <span><strong>IPv4:</strong> {$ipv4}</span>
        <span><strong>IPv6:</strong> {$ipv6}</span>
        <span><img width="18" src="../modules/servers/enhance/templates/imagens/icones/php.png" style="vertical-align:middle;margin-right:3px;"> {$php}</span>
        <span><img width="18" src="../modules/servers/enhance/templates/imagens/icones/mysql.png" style="vertical-align:middle;margin-right:3px;"> {$mysql}</span>
    </div>
</div>
HTML;

        return ['Website / Website' => $html];
    } catch (Exception $e) {
        return [];
    }
}
