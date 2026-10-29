<?php
/**
 * Enhance WHMCS Server Module v1.4.0
 *
 * Supports: account creation, suspension, termination, plan change, SSO,
 * admin services tab, dynamic plan dropdown, connection test, usage update.
 *
 * WHMCS server configuration (System Settings > Servers):
 *   Hostname    = Enhance control panel domain, without https:// (e.g. cp.example.com)
 *   Username    = UUID of the master organisation (Enhance > Settings > Organisation ID)
 *   Access Hash = Enhance API access token with the Super Admin role
 *                 (the Password field may be used instead; leave Access Hash empty then)
 *   Secure      = ticked (Enhance only answers over HTTPS)
 *
 * All texts are translated (English / European Portuguese), see lang/.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceSync.php';

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

// =============================================================================
// Helpers
// =============================================================================

function _enhance_api(array $params): EnhanceApi
{
    return EnhanceApi::fromParams($params);
}

function _enhance_api_error(array $resp): ?string
{
    return EnhanceApi::isError($resp) ? EnhanceApi::errorSummary($resp) : null;
}

function _enhance_client_name(array $client): string
{
    $name = trim((string) ($client['fullname'] ?? ''));
    if ($name !== '') return $name;
    return trim(trim((string) ($client['firstname'] ?? '')) . ' ' . trim((string) ($client['lastname'] ?? '')));
}

function _enhance_client_id(array $params): int
{
    return (int) ($params['clientsdetails']['userid'] ?? $params['clientsdetails']['id'] ?? $params['userid'] ?? 0);
}

/**
 * Creates or relinks the customer organisation. Only used by CreateAccount,
 * because it may create organisations/logins and send the access email.
 */
function _enhance_sync_customer(EnhanceApi $api, array $params): array
{
    $client = $params['clientsdetails'] ?? [];
    return $api->syncCustomerFromWhmcs(
        _enhance_client_id($params),
        _enhance_client_name($client),
        trim((string) ($client['email'] ?? '')),
        (string) ($client['companyname'] ?? '')
    );
}

/**
 * Read-only resolution of the organisation and subscription of a service.
 * Never creates remote resources and never clears a stored association.
 *
 * @param bool $allowAutodetect Link the subscription automatically when the
 *                              customer has exactly one unmapped active one.
 *                              Must be false for destructive actions.
 */
function _enhance_resolve_subscription(EnhanceApi $api, array $params, bool $allowAutodetect = true): array
{
    $clientId  = _enhance_client_id($params);
    $packageId = (int) $params['packageid'];
    $serviceId = (int) $params['serviceid'];

    $orgId = $api->getClientOrgId($clientId);
    if (!$orgId) {
        throw new Exception(EnhanceLang::t('svc.no_org', ['client' => $clientId]));
    }
    $orgId = strtolower(trim($orgId));

    $subId = $api->getServiceSubscriptionId($packageId, $serviceId);

    if ($subId) {
        $sub = $api->getSubscription($orgId, $subId);
        if (EnhanceApi::isNotFound($sub)) {
            throw new Exception(EnhanceLang::t('svc.sub_missing', ['sub' => $subId, 'org' => $orgId]));
        }
        if ($err = _enhance_api_error($sub)) {
            throw new Exception(EnhanceLang::t('svc.sub_read_failed', ['sub' => $subId, 'detail' => $err]));
        }
        _enhance_assert_subscription($api, $sub, $orgId);
        return ['orgId' => $orgId, 'subId' => (string) $subId, 'subscription' => $sub];
    }

    if (!$allowAutodetect) {
        throw new Exception(EnhanceLang::t('svc.no_sub_destructive'));
    }

    $subs = $api->getCustomerSubscriptions($orgId);
    if ($err = _enhance_api_error($subs)) {
        throw new Exception(EnhanceLang::t('sub.list_failed', ['detail' => $err]));
    }

    $candidates = [];
    foreach ($subs['items'] ?? [] as $item) {
        if (empty($item['id']) || ($item['status'] ?? '') === 'deleted') continue;
        $mappedTo = $api->findServiceBySubscriptionId((string) $item['id']);
        if ($mappedTo && $mappedTo !== $serviceId) continue;
        $candidates[] = $item;
    }

    $planId = (int) ($params['configoption1'] ?? 0);
    if (count($candidates) > 1 && $planId > 0) {
        $candidates = array_values(array_filter($candidates, fn($i) => (int) ($i['planId'] ?? 0) === $planId));
    }

    if (count($candidates) !== 1) {
        throw new Exception(EnhanceLang::t('svc.sub_ambiguous', ['count' => count($candidates)]));
    }

    $subId = (string) $candidates[0]['id'];
    $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);
    return ['orgId' => $orgId, 'subId' => $subId, 'subscription' => $candidates[0]];
}

function _enhance_assert_subscription(EnhanceApi $api, array $sub, string $orgId): void
{
    if (isset($sub['subscriberId']) && strtolower((string) $sub['subscriberId']) !== $orgId) {
        throw new Exception(EnhanceLang::t('svc.sub_other_org', ['org' => $sub['subscriberId']]));
    }
    if (isset($sub['vendorId']) && strtolower((string) $sub['vendorId']) !== $api->getMasterOrgId()) {
        throw new Exception(EnhanceLang::t('svc.sub_other_vendor', ['org' => $sub['vendorId']]));
    }
}

function _enhance_subscription_is_suspended(array $sub): ?bool
{
    if (array_key_exists('isSuspended', $sub)) return (bool) $sub['isSuspended'];
    if (array_key_exists('suspendedBy', $sub)) return !empty($sub['suspendedBy']);
    return null;
}

/**
 * Records the result of a module action for the admin service tab and returns
 * it unchanged ("success" or the error message shown by WHMCS).
 */
function _enhance_track(array $params, string $action, string $result): string
{
    EnhanceStore::recordEvent((int) ($params['serviceid'] ?? 0), $action, $result === 'success', $result === 'success' ? '' : $result);
    return $result;
}

/** WHMCS TransientData cache (shared across web nodes); silently disabled when unavailable. */
function _enhance_cache_get(string $key, int $ttl, callable $fetch)
{
    if ($ttl <= 0) {
        return $fetch(); // cache disabled in the settings
    }
    $store = class_exists('WHMCS\\TransientData') ? \WHMCS\TransientData::getInstance() : null;
    $key = 'enhance_' . md5($key);
    if ($store) {
        $cached = $store->retrieve($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) return $decoded;
        }
    }
    $data = $fetch();
    if ($store && is_array($data) && !empty($data['available'])) {
        $store->store($key, json_encode($data), $ttl);
    }
    return $data;
}

function _enhance_cache_forget(string $key): void
{
    if (class_exists('WHMCS\\TransientData')) {
        \WHMCS\TransientData::getInstance()->delete('enhance_' . md5($key));
    }
}

/**
 * Client area cache generation of a service: every cached overview, page and
 * search of the service includes it in its key, so changing it forgets them all.
 */
function _enhance_clientarea_gen(int $serviceId): string
{
    if (!class_exists('WHMCS\\TransientData')) return '0';
    $store = \WHMCS\TransientData::getInstance();
    $gen = $store->retrieve('enhance_ca_gen_' . $serviceId);
    if (!is_string($gen) || $gen === '') {
        $gen = bin2hex(random_bytes(4));
        $store->store('enhance_ca_gen_' . $serviceId, $gen, 30 * 86400);
    }
    return $gen;
}

/** Forgets the client area data of a service (all languages, pages and searches). */
function _enhance_forget_clientarea(int $serviceId): void
{
    if (class_exists('WHMCS\\TransientData')) {
        \WHMCS\TransientData::getInstance()->store('enhance_ca_gen_' . $serviceId, bin2hex(random_bytes(4)), 30 * 86400);
    }
}

/** Accepts only #rgb / #rrggbb, so config values can never inject CSS. */
function _enhance_hex_color(string $value): string
{
    $value = trim($value);
    return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : '';
}

/** Enhance phpVersion enum (e.g. "php84") -> "8.4". */
function _enhance_php_label(string $value): string
{
    if (preg_match('/^php(\d)(\d+)$/i', $value, $m)) return $m[1] . '.' . $m[2];
    return $value;
}

function _enhance_panel_url(array $params): string
{
    $host = EnhanceApi::normaliseHost((string) (($params['serverhostname'] ?? '') ?: ($params['serverip'] ?? '')));
    return $host === '' ? '' : 'https://' . $host;
}

/** Bytes formatted with the separators of the current language. */
function _enhance_format_bytes(?int $bytes): string
{
    if ($bytes === null) return '';
    $dec = EnhanceLang::t('fmt.decimal');
    $th  = EnhanceLang::t('fmt.thousands');
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, $dec, $th) . ' GB';
    return number_format($bytes / 1048576, 0, $dec, $th) . ' MB';
}

/** Precise size for small values (backups): B, KB, MB or GB, never rounded down to 0. */
function _enhance_format_size(int $bytes): string
{
    $dec = EnhanceLang::t('fmt.decimal');
    $th  = EnhanceLang::t('fmt.thousands');
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, $dec, $th) . ' GB';
    if ($bytes >= 10485760)   return number_format($bytes / 1048576, 0, $dec, $th) . ' MB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 1, $dec, $th) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 0, $dec, $th) . ' KB';
    return $bytes . ' B';
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
        'DefaultNonSSLPort'        => '443',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => EnhanceLang::t('meta.sso_label'),
        'AdminSingleSignOnLabel'   => EnhanceLang::t('meta.admin_sso_label'),
    ];
}

// =============================================================================
// Config options
// =============================================================================

function enhance_ConfigOptions(): array
{
    return [
        'plan' => [
            'FriendlyName' => EnhanceLang::t('cfg.plan'),
            'Type'         => 'text',
            'Size'         => '25',
            'Loader'       => 'enhance_LoadPlans',
            'SimpleMode'   => true,
            'Description'  => EnhanceLang::t('cfg.plan_desc'),
        ],
        'termination' => [
            'FriendlyName' => EnhanceLang::t('cfg.termination'),
            'Type'         => 'dropdown',
            'Options'      => [
                'soft' => EnhanceLang::t('cfg.termination_soft'),
                'hard' => EnhanceLang::t('cfg.termination_hard'),
            ],
            'Description'  => EnhanceLang::t('cfg.termination_desc'),
            'Default'      => 'soft',
            'SimpleMode'   => true,
        ],
        'server_group' => [
            'FriendlyName' => EnhanceLang::t('cfg.server_group'),
            'Type'         => 'text',
            'Size'         => '36',
            'Loader'       => 'enhance_LoadServerGroups',
            'SimpleMode'   => true,
            'Description'  => EnhanceLang::t('cfg.server_group_desc'),
        ],
        'allow_restore' => [
            'FriendlyName' => EnhanceLang::t('cfg.allow_restore'),
            'Type'         => 'yesno',
            'Description'  => EnhanceLang::t('cfg.allow_restore_desc'),
            'SimpleMode'   => true,
        ],
    ];
}

/** Loader for configoption3: '' = the plan's default server group. */
function enhance_LoadServerGroups(array $params): array
{
    $result = _enhance_api($params)->getServerGroups();
    if ($err = _enhance_api_error($result)) {
        throw new Exception(EnhanceLang::t('cfg.groups_failed', ['detail' => $err]));
    }
    $out = ['' => EnhanceLang::t('cfg.server_group_default')];
    foreach ((array) ($result['items'] ?? []) as $group) {
        if (empty($group['id'])) continue;
        $out[(string) $group['id']] = ($group['name'] ?? $group['id']) . ' (' . (int) ($group['serverCount'] ?? 0) . ')';
    }
    return $out;
}

/**
 * WHMCS Loader: key = value stored in configoption1, value = label.
 * Must throw on failure so WHMCS shows the error instead of an empty list.
 */
function enhance_LoadPlans(array $params): array
{
    $result = _enhance_api($params)->getPlans();
    if ($err = _enhance_api_error($result)) {
        throw new Exception(EnhanceLang::t('cfg.plans_failed', ['detail' => $err]));
    }
    $out = [];
    foreach ($result['items'] ?? [] as $plan) {
        if (!isset($plan['id'])) continue;
        $out[(string) $plan['id']] = $plan['id'] . ' - ' . ($plan['name'] ?? '');
    }
    return $out;
}

// =============================================================================
// Test connection
// =============================================================================

function enhance_TestConnection(array $params): array
{
    try {
        return _enhance_api($params)->testConnection();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// =============================================================================
// Create account
// =============================================================================

function enhance_CreateAccount(array $params): string
{
    return _enhance_track($params, 'CreateAccount', _enhance_run_CreateAccount($params));
}

function _enhance_run_CreateAccount(array $params): string
{
    try {
        $api = _enhance_api($params);
        // One provisioning at a time per client and server: the organisation,
        // login and subscription checks below are "read, then create".
        return EnhanceStore::withLock(
            EnhanceStore::clientLockKey($api->getServerId(), _enhance_client_id($params)),
            fn() => _enhance_create_account($api, $params)
        );
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function _enhance_create_account(EnhanceApi $api, array $params): string
{
    try {
        $packageId = (int) $params['packageid'];
        $serviceId = (int) $params['serviceid'];
        $planId    = (int) ($params['configoption1'] ?? 0);
        $domain    = strtolower(trim((string) ($params['domain'] ?? '')));

        if ($planId <= 0) {
            return EnhanceLang::t('svc.plan_missing');
        }
        if ($api->getServiceSubscriptionId($packageId, $serviceId) !== null) {
            return EnhanceLang::t('svc.already_linked');
        }

        $sync = _enhance_sync_customer($api, $params);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            return $sync['message'] ?? EnhanceLang::t('svc.sync_failed');
        }
        $orgId = (string) $sync['orgId'];

        // Idempotency: reuse the subscription created for this service by an earlier, interrupted attempt.
        $sub = $api->findSubscriptionForService($orgId, $serviceId);
        if ($sub === null) {
            $sub = $api->createSubscription($orgId, $planId, EnhanceApi::friendlyNameForService($serviceId));
            if (EnhanceApi::isError($sub) || empty($sub['id'])) {
                return EnhanceLang::t('svc.sub_create_failed', ['detail' => _enhance_api_error($sub) ?? json_encode($sub)]);
            }
        } elseif ((int) ($sub['planId'] ?? 0) !== $planId) {
            return EnhanceLang::t('svc.sub_other_plan', ['sub' => $sub['id'], 'plan' => (int) ($sub['planId'] ?? 0)]);
        }

        $subId = (string) $sub['id'];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);

        if ($domain !== '') {
            $websiteId = null;
            $sites = $api->listWebsitesBySubscription($orgId, $subId);
            foreach ($sites['items'] ?? [] as $site) {
                if (strtolower((string) ($site['domain']['domain'] ?? '')) === $domain && (int) ($site['subscriptionId'] ?? 0) === (int) $subId) {
                    $websiteId = (string) $site['id'];
                    break;
                }
            }
            if ($websiteId === null) {
                $serverGroup = trim((string) ($params['configoption3'] ?? ''));
                $site = $api->createWebsite($orgId, $subId, $domain, EnhanceStore::isUuid($serverGroup) ? strtolower($serverGroup) : '');
                if (EnhanceApi::isError($site)) {
                    $rollback = $api->deleteSubscription($orgId, $subId, true);
                    if (EnhanceApi::isError($rollback)) {
                        return EnhanceLang::t('svc.website_failed_kept', ['detail' => _enhance_api_error($site), 'sub' => $subId, 'rollback' => _enhance_api_error($rollback)]);
                    }
                    $api->clearServiceSubscriptionId($packageId, $serviceId);
                    return EnhanceLang::t('svc.website_failed', ['detail' => _enhance_api_error($site)]);
                }
                $websiteId = isset($site['id']) ? (string) $site['id'] : null;
            }
            if ($websiteId) {
                $api->saveServiceWebsiteId($serviceId, $websiteId);
            }
        }

        _enhance_store_login_username($api, $serviceId, $orgId);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Shows the Enhance login (the organisation owner's email) as the service
 * username, so support knows which email the client uses in the panel.
 */
function _enhance_store_login_username(EnhanceApi $api, int $serviceId, string $orgId): void
{
    $org = $api->getOrg($orgId);
    $email = EnhanceApi::isError($org) ? '' : trim((string) ($org['ownerEmail'] ?? ''));
    if ($email !== '') {
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['username' => $email]);
    }
}

// =============================================================================
// Suspend / Unsuspend
// =============================================================================

function enhance_SuspendAccount(array $params): string
{
    return _enhance_track($params, 'SuspendAccount', _enhance_run_SuspendAccount($params));
}

function _enhance_run_SuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);

        if (_enhance_subscription_is_suspended($resolved['subscription']) === true) {
            return 'success';
        }

        _enhance_forget_clientarea((int) $params['serviceid']);
        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], true);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return EnhanceLang::t('svc.suspend_unverified', ['detail' => $err]);

        if (_enhance_subscription_is_suspended($verify) === false) {
            return EnhanceLang::t('svc.suspend_not_applied');
        }

        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function enhance_UnsuspendAccount(array $params): string
{
    return _enhance_track($params, 'UnsuspendAccount', _enhance_run_UnsuspendAccount($params));
}

function _enhance_run_UnsuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $org      = $api->getOrg($resolved['orgId']);

        // An organisation-level suspension is also lifted.
        if (!EnhanceApi::isError($org) && !empty($org['suspendedBy'])) {
            $orgResp = $api->setOrgSuspended($resolved['orgId'], false);
            if ($err = _enhance_api_error($orgResp)) return EnhanceLang::t('svc.org_unsuspend_failed', ['detail' => $err]);
        }

        if (_enhance_subscription_is_suspended($resolved['subscription']) === false) {
            return 'success';
        }

        _enhance_forget_clientarea((int) $params['serviceid']);
        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], false);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return EnhanceLang::t('svc.unsuspend_unverified', ['detail' => $err]);

        if (_enhance_subscription_is_suspended($verify) === true) {
            return EnhanceLang::t('svc.unsuspend_not_applied');
        }

        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Terminate
// =============================================================================

function enhance_TerminateAccount(array $params): string
{
    return _enhance_track($params, 'TerminateAccount', _enhance_run_TerminateAccount($params));
}

function _enhance_run_TerminateAccount(array $params): string
{
    try {
        $api        = _enhance_api($params);
        $packageId  = (int) $params['packageid'];
        $serviceId  = (int) $params['serviceid'];
        $hardDelete = (($params['configoption2'] ?? 'soft') === 'hard');

        // Destructive action: only the explicitly stored subscription is used.
        $resolved = _enhance_resolve_subscription($api, $params, false);
        $orgId    = $resolved['orgId'];
        $subId    = $resolved['subId'];

        if (($resolved['subscription']['status'] ?? '') === 'deleted' && !$hardDelete) {
            $api->clearServiceSubscriptionId($packageId, $serviceId);
            return 'success';
        }

        if ($hardDelete) {
            $sites = $api->listWebsitesBySubscription($orgId, $subId);
            if ($err = _enhance_api_error($sites)) return $err;
            foreach ($sites['items'] ?? [] as $site) {
                if ((string) ($site['subscriptionId'] ?? '') !== '' && (int) $site['subscriptionId'] !== (int) $subId) continue;
                $del = $api->deleteWebsite($orgId, (string) $site['id']);
                if ($err = _enhance_api_error($del)) return EnhanceLang::t('svc.website_delete_failed', ['website' => $site['id'] ?? '', 'detail' => $err]);
            }
        }

        $resp = $api->deleteSubscription($orgId, $subId, $hardDelete);
        if ($err = _enhance_api_error($resp)) return $err;

        $api->clearServiceSubscriptionId($packageId, $serviceId);
        _enhance_forget_clientarea($serviceId);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Change package
// =============================================================================

function enhance_ChangePackage(array $params): string
{
    return _enhance_track($params, 'ChangePackage', _enhance_run_ChangePackage($params));
}

function _enhance_run_ChangePackage(array $params): string
{
    try {
        $newPlanId = (int) ($params['configoption1'] ?? 0);
        if ($newPlanId <= 0) {
            return EnhanceLang::t('svc.plan_missing_target');
        }
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);

        if ((int) ($resolved['subscription']['planId'] ?? 0) === $newPlanId) {
            return 'success';
        }

        _enhance_forget_clientarea((int) $params['serviceid']);
        $resp = $api->updateSubscriptionPlan($resolved['orgId'], $resolved['subId'], $newPlanId);
        return ($err = _enhance_api_error($resp)) ? $err : 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// SSO
// =============================================================================

function enhance_ServiceSingleSignOn(array $params): array
{
    try {
        $api   = _enhance_api($params);
        $orgId = $api->getClientOrgId(_enhance_client_id($params));
        if (!$orgId) {
            throw new Exception(EnhanceLang::t('sso.not_linked'));
        }

        $sso = $api->ownerSsoUrl(strtolower(trim($orgId)));
        $isAdmin = !empty($_SESSION['adminid']);
        if ($sso['url']) {
            // The same function serves the admin "log in" button: record who entered as the client.
            if ($isAdmin && function_exists('logActivity')) {
                logActivity(EnhanceLang::t('log.admin_sso', ['admin' => (int) $_SESSION['adminid'], 'service' => (int) $params['serviceid']]), _enhance_client_id($params));
            }
            return ['success' => true, 'redirectTo' => $sso['url']];
        }

        if (function_exists('logActivity')) {
            logActivity(EnhanceLang::t('log.sso_failed', ['service' => (int) $params['serviceid'], 'detail' => $sso['error']]));
        }
        // Admins see the technical reason; clients a neutral message.
        throw new Exception($isAdmin ? EnhanceLang::t('sso.failed', ['detail' => $sso['error']]) : EnhanceLang::t('sso.unavailable'));
    } catch (Throwable $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function enhance_AdminSingleSignOn(array $params): array
{
    // Server-level admin access opens the configured Enhance panel directly.
    $url = _enhance_panel_url($params);
    if ($url === '') {
        return ['success' => false, 'errorMsg' => EnhanceLang::t('sso.no_hostname')];
    }
    return ['success' => true, 'redirectTo' => $url];
}

function enhance_AdminLink(array $params): string
{
    $url = _enhance_panel_url($params);
    if ($url === '') return '';
    return '<a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
        . htmlspecialchars(EnhanceLang::t('admin.login_to_panel'), ENT_QUOTES, 'UTF-8') . '</a>';
}

// =============================================================================
// Client area
// =============================================================================

function enhance_ClientArea(array $params): array
{
    $serviceId = (int) $params['serviceid'];
    $systemUrl = rtrim((string) Setting::getValue('SystemURL'), '/');
    $pageUrl   = $systemUrl . '/clientarea.php';
    $baseUrl   = $pageUrl . '?action=productdetails&id=' . $serviceId;
    $ssoUrl    = $baseUrl . '&dosinglesignon=1';
    $language  = EnhanceLang::current();
    $perPage   = (int) EnhanceSettings::get('websites_per_page');
    $ttl       = (int) EnhanceSettings::get('cache_ttl');
    $showUsage = (bool) EnhanceSettings::get('show_usage');
    $anyTool   = EnhanceSettings::get('show_ssl') || EnhanceSettings::get('show_webmail') || EnhanceSettings::get('show_phpmyadmin')
        || EnhanceSettings::get('show_wordpress') || EnhanceSettings::get('show_backups');
    $search    = mb_substr(trim((string) ($_GET['enhsearch'] ?? '')), 0, 100);
    $page      = max(1, (int) ($_GET['enhpage'] ?? 1));

    $overview = ['websites' => [], 'disk' => null, 'bandwidth' => null, 'available' => false];
    $sites = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'links' => [], 'search' => $search, 'searchable' => false];
    try {
        $gen = _enhance_clientarea_gen($serviceId) . _enhance_clientarea_settings_key();
        // 1. Usage and the list of ALL websites: 1 call for the list, whatever its size.
        //    Cached per language because sizes are formatted for the reader.
        $overview = _enhance_cache_get('clientarea_' . $serviceId . '_' . $language . '_' . $gen, $ttl, function () use ($params, $showUsage) {
            $empty = ['websites' => [], 'disk' => null, 'bandwidth' => null, 'available' => false];
            $api   = _enhance_api($params);
            $orgId = $api->getClientOrgId(_enhance_client_id($params));
            $subId = $api->getServiceSubscriptionId((int) $params['packageid'], (int) $params['serviceid']);
            if (!$orgId || !$subId) return $empty;

            $sub = $api->getSubscription($orgId, $subId);
            if (EnhanceApi::isError($sub)) return $empty;

            $websites = [];
            $list = $api->listWebsitesBySubscription($orgId, $subId);
            foreach ($list['items'] ?? [] as $site) {
                if (($site['kind'] ?? 'normal') !== 'normal' || ($site['status'] ?? '') === 'deleted') continue;
                if ((int) ($site['subscriptionId'] ?? 0) !== (int) $subId) continue;
                $websites[] = [
                    'id'     => (string) ($site['id'] ?? ''),
                    'domain' => (string) ($site['domain']['domain'] ?? ''),
                    'active' => ($site['status'] ?? '') === 'active' && empty($site['suspendedBy']),
                    'php'    => _enhance_php_label((string) ($site['phpVersion'] ?? '')),
                ];
            }
            usort($websites, fn($a, $b) => strcmp($a['domain'], $b['domain']));

            $meter = function (?int $usage, ?int $total): ?array {
                if ($usage === null) return null;
                $limited = $total !== null && $total > 0;
                $percent = $limited ? min(100, (int) round($usage * 100 / $total)) : 0;
                return [
                    'used'    => _enhance_format_bytes($usage),
                    'limit'   => $limited ? _enhance_format_bytes($total) : '',
                    'limited' => $limited,
                    'percent' => $percent,
                    'high'    => $limited && $percent >= 85,
                ];
            };

            $disk      = EnhanceApi::resourceUsage($sub, 'diskspace');
            $transfer  = EnhanceApi::resourceUsage($sub, 'transfer');
            $bandwidth = $showUsage ? $api->getSubscriptionBandwidth($orgId, $subId) : null;
            if (!$showUsage) {
                $disk = $transfer = ['usage' => null, 'total' => null];
            }

            return [
                'websites'  => $websites,
                'disk'      => $meter($disk['usage'], $disk['total']),
                'bandwidth' => $meter($bandwidth ?? $transfer['usage'], $transfer['total']),
                'plan'      => (string) ($sub['planName'] ?? ''),
                'suspended' => (bool) _enhance_subscription_is_suspended($sub),
                'available' => true,
            ];
        });

        // 2. Search and pagination over every website.
        $all = (array) ($overview['websites'] ?? []);
        $matching = $search === '' ? $all : array_values(array_filter($all, fn($w) => mb_stripos($w['domain'], $search) !== false));
        $pages = max(1, (int) ceil(count($matching) / $perPage));
        $page  = min($page, $pages);
        $items = array_slice($matching, ($page - 1) * $perPage, $perPage);

        // 3. Tools (5 API calls per website) only for the websites of this page.
        $toolSites = [];
        foreach ($items as $w) {
            if ($w['active'] && $w['id'] !== '') $toolSites[$w['id']] = $w['domain'];
        }
        $tools = [];
        if ($toolSites && $anyTool && empty($overview['suspended'])) {
            $key = 'clientarea_tools_' . $serviceId . '_' . $language . '_' . $gen . '_' . md5(implode(',', array_keys($toolSites)));
            $tools = _enhance_cache_get($key, $ttl, function () use ($params, $toolSites) {
                $api   = _enhance_api($params);
                $orgId = (string) $api->getClientOrgId(_enhance_client_id($params));
                $out = ['available' => true];
                foreach ($toolSites as $id => $domain) {
                    $out[$id] = _enhance_website_tools($api, $orgId, ['id' => $id, 'domain' => ['domain' => $domain]], $params);
                }
                return $out;
            });
        }
        foreach ($items as &$w) {
            $w['tools'] = $tools[$w['id']] ?? null;
        }
        unset($w);

        $query = $search !== '' ? '&enhsearch=' . rawurlencode($search) : '';
        $sites = [
            'items'      => $items,
            'total'      => count($all),
            'page'       => $page,
            'pages'      => $pages,
            'links'      => _enhance_pager_links($page, $pages, fn($n) => $baseUrl . '&enhpage=' . $n . $query),
            'search'     => $search,
            'searchable' => count($all) > $perPage || $search !== '',
        ];
    } catch (Throwable $e) {
        // The client area must always render; details are in the module log.
    }

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => [
            'ssoUrl'         => $ssoUrl,
            'pageUrl'        => $pageUrl,
            'serviceId'      => $serviceId,
            'sites'          => $sites,
            'allowRestore'   => _enhance_restore_allowed($params) && _enhance_client_may_use_tools($params, true),
            'actionUrl'      => $baseUrl . '&modop=custom&a=',
            'csrfToken'      => function_exists('generate_token') ? (string) generate_token('plain') : '',
            'domain'         => $params['domain'],
            'enhance'        => $overview,
            'enhanceLang'    => EnhanceLang::group('ca.'),
            // Empty = follow the active theme colours (detected in the template).
            'colorPrimary'   => _enhance_hex_color((string) EnhanceSettings::get('color_primary')),
            'colorSecondary' => _enhance_hex_color((string) EnhanceSettings::get('color_secondary')),
            'showFeatures'   => (bool) EnhanceSettings::get('show_features'),
        ],
    ];
}

/**
 * Page links: first, last, and two pages around the current one, with gaps
 * (e.g. 1 ... 8 9 [10] 11 12 ... 200).
 */
function _enhance_pager_links(int $page, int $pages, callable $url): array
{
    $links = [];
    $last = 0;
    for ($n = 1; $n <= $pages; $n++) {
        if ($n !== 1 && $n !== $pages && abs($n - $page) > 2) continue;
        if ($last && $n - $last > 1) $links[] = ['gap' => true];
        $links[] = ['label' => (string) $n, 'url' => $url($n), 'current' => $n === $page, 'gap' => false];
        $last = $n;
    }
    return $links;
}

// =============================================================================
// Client area tools: webmail, phpMyAdmin, WordPress, SSL and backups
// =============================================================================

/** Date of an Enhance ISO timestamp in the reader's format. */
function _enhance_format_date(string $iso, bool $withTime = false): string
{
    $ts = strtotime($iso);
    return $ts ? date(EnhanceLang::t('fmt.date') . ($withTime ? ' H:i' : ''), $ts) : '';
}

/**
 * Data for the tools of one website. Only names and IDs are cached: the login
 * links are created when the client clicks, by the functions below.
 */
function _enhance_website_tools(EnhanceApi $api, string $orgId, array $site, array $params): array
{
    $websiteId = (string) $site['id'];
    $primary   = strtolower((string) ($site['domain']['domain'] ?? ''));
    $tools = ['ssl' => null, 'ssl_known' => false, 'mailboxes' => [], 'databases' => [], 'wordpress' => [], 'backups' => []];

    // Only the tools enabled in Addons > Enhance Manager > Settings call the API.
    $ssl = EnhanceSettings::get('show_ssl') ? $api->getWebsiteSsl($orgId, $websiteId) : ['items' => [], 'code' => 'disabled'];
    // "No certificate" is only stated when Enhance answered; an API error shows nothing.
    $tools['ssl_known'] = !EnhanceApi::isError($ssl);
    foreach ((array) ($ssl['items'] ?? []) as $mapping) {
        if (empty($mapping['cert']['expires'])) continue;
        if ($tools['ssl'] !== null && strtolower((string) ($mapping['domain'] ?? '')) !== $primary) continue;
        $expires = strtotime((string) $mapping['cert']['expires']);
        $days = $expires ? (int) floor(($expires - time()) / 86400) : null;
        $tools['ssl'] = [
            'domain'  => (string) ($mapping['domain'] ?? ''),
            'issuer'  => (string) ($mapping['cert']['issuer'] ?? ''),
            'expires' => _enhance_format_date((string) $mapping['cert']['expires']),
            'days'    => $days,
            'warn'    => $days !== null && $days <= 14,
            'expired' => $days !== null && $days < 0,
        ];
    }

    $emails = EnhanceSettings::get('show_webmail') ? $api->listWebsiteEmails($orgId, $websiteId) : [];
    foreach ((array) ($emails['items'] ?? []) as $email) {
        if (empty($email['hasMailbox']) || (isset($email['ssoAvailable']) && !$email['ssoAvailable'])) continue;
        $tools['mailboxes'][] = (string) $email['address'];
    }

    $dbs = EnhanceSettings::get('show_phpmyadmin') ? $api->listWebsiteDatabases($orgId, $websiteId) : [];
    foreach ((array) ($dbs['items'] ?? []) as $db) {
        if (!empty($db['name'])) $tools['databases'][] = (string) $db['name'];
    }

    $apps = EnhanceSettings::get('show_wordpress') ? $api->listWebsiteApps($orgId, $websiteId) : [];
    foreach ((array) ($apps['items'] ?? []) as $app) {
        if (($app['app'] ?? '') === 'wordpress' && !empty($app['id'])) {
            $tools['wordpress'][] = ['id' => (string) $app['id'], 'path' => _enhance_app_subfolder((string) ($app['path'] ?? ''))];
        }
    }

    $backups = EnhanceSettings::get('show_backups') ? $api->listWebsiteBackups($orgId, $websiteId) : [];
    $items = (array) ($backups['items'] ?? []);
    usort($items, fn($a, $b) => strcmp((string) ($b['startedAt'] ?? ''), (string) ($a['startedAt'] ?? '')));
    foreach (array_slice($items, 0, (int) EnhanceSettings::get('backups_shown')) as $backup) {
        $ok = ($backup['homeDirStatus'] ?? '') === 'successful';
        // Enhance backups are incremental snapshots: "size" may only be what changed since the
        // previous one. The backed up content is the sum of its components; "size" is the fallback.
        $components = (int) ($backup['filesSize'] ?? 0) + (int) ($backup['mysqlDbsSize'] ?? 0) + (int) ($backup['emailsSize'] ?? 0);
        $size = $components > 0 ? $components : (int) ($backup['size'] ?? 0);
        // An incremental backup with nothing to copy (0 bytes) is not a failure for the client:
        // only backups that wrote data and report a failed component are shown as failed.
        $failed = $size > 0 && in_array('failed', [$backup['homeDirStatus'] ?? '', $backup['mysqlDbsStatus'] ?? '', $backup['emailsStatus'] ?? ''], true);
        $tools['backups'][] = [
            'id'      => (int) ($backup['id'] ?? 0),
            'date'    => _enhance_format_date((string) ($backup['finishedAt'] ?? $backup['startedAt'] ?? ''), true),
            'size'    => $size > 0 && EnhanceSettings::get('show_backup_size') ? _enhance_format_size($size) : '',
            'kind'    => EnhanceLang::t('ca.backup_kind_' . (in_array($backup['kind'] ?? '', ['manual', 'automatic', 'archive'], true) ? $backup['kind'] : 'automatic')),
            'ok'      => $ok,
            'failed'  => $failed,
            'pending' => empty($backup['finishedAt']),
        ];
    }
    return $tools;
}

/**
 * Subfolder of a website app for display: '' when installed in the website root.
 * Enhance omits the path for root installs (API) but some panels return the
 * document root itself ("public_html"), which is not a subfolder either.
 */
function _enhance_app_subfolder(string $path): string
{
    $path = trim($path, '/');
    if ($path === 'public_html' || strpos($path, 'public_html/') === 0) {
        $path = trim(substr($path, strlen('public_html')), '/');
    }
    return $path === '' ? '' : '/' . $path;
}

/** Restore needs the product option and the global switch in the settings (and backups shown). */
function _enhance_restore_allowed(array $params): bool
{
    return EnhanceSettings::get('restore_enabled') && EnhanceSettings::get('show_backups')
        && in_array(strtolower((string) ($params['configoption4'] ?? '')), ['on', 'yes', '1'], true);
}

/** Settings that change what the client area shows: part of every client area cache key. */
function _enhance_clientarea_settings_key(): string
{
    $keys = ['show_usage', 'show_ssl', 'show_webmail', 'show_phpmyadmin', 'show_wordpress', 'show_backups', 'show_backup_size', 'backups_shown'];
    $values = [];
    foreach ($keys as $k) $values[] = EnhanceSettings::get($k);
    return substr(md5(json_encode($values)), 0, 8);
}

/**
 * The logged-in user may use the tools of this service: admins, the account
 * owner, and (except for restores) users with the "productsso" permission.
 */
function _enhance_client_may_use_tools(array $params, bool $ownerOnly = false): bool
{
    if (!empty($_SESSION['adminid'])) return true;
    $clientId = _enhance_client_id($params);
    if (!class_exists('WHMCS\\Authentication\\CurrentUser')) {
        return (int) ($_SESSION['uid'] ?? 0) === $clientId; // WHMCS 7
    }
    try {
        $auth = new \WHMCS\Authentication\CurrentUser();
        $user = $auth->user();
        $client = $auth->client();
        if (!$user || !$client || (int) $client->id !== $clientId) return false;
        $link = Capsule::table('tblusers_clients')->where('auth_user_id', $user->id)->where('client_id', $clientId)->first();
        if (!$link) return false;
        if ((int) $link->owner === 1) return true;
        return !$ownerOnly && in_array('productsso', array_map('trim', explode(',', (string) $link->permissions)), true);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validates that the website belongs to this service's subscription.
 * @return array{0:EnhanceApi,1:string,2:array} api, organisation, website
 */
function _enhance_tool_context(array $params, bool $ownerOnly = false): array
{
    if (!_enhance_client_may_use_tools($params, $ownerOnly)) {
        throw new Exception(EnhanceLang::t('ca.no_permission'));
    }
    $websiteId = strtolower(trim((string) ($_REQUEST['website'] ?? '')));
    if (!EnhanceStore::isUuid($websiteId)) {
        throw new Exception(EnhanceLang::t('ca.invalid_request'));
    }
    $api = _enhance_api($params);
    $resolved = _enhance_resolve_subscription($api, $params, false);
    if (_enhance_subscription_is_suspended($resolved['subscription'])) {
        throw new Exception(EnhanceLang::t('ca.suspended'));
    }
    $site = $api->getWebsite($resolved['orgId'], $websiteId);
    if (EnhanceApi::isError($site) || (string) ($site['subscriptionId'] ?? '') !== (string) $resolved['subId'] || ($site['status'] ?? '') === 'deleted') {
        throw new Exception(EnhanceLang::t('ca.invalid_request'));
    }
    return [$api, $resolved['orgId'], $site];
}

/** Sends the browser to an SSO link (never returns on success). */
function _enhance_redirect(array $sso, int $serviceId, string $action): string
{
    if (!empty($sso['url'])) {
        header('Location: ' . $sso['url'], true, 302);
        exit;
    }
    if (function_exists('logActivity')) {
        logActivity(EnhanceLang::t('log.tool_failed', ['action' => $action, 'service' => $serviceId, 'detail' => $sso['error'] ?? '']));
    }
    return EnhanceLang::t('ca.tool_unavailable');
}

function enhance_ClientAreaAllowedFunctions(): array
{
    return [
        'Webmail'        => 'WebmailSso',
        'phpMyAdmin'     => 'PhpMyAdminSso',
        'WordPress'      => 'WordpressSso',
        'Restore backup' => 'RestoreBackup',
    ];
}

function enhance_WebmailSso(array $params)
{
    try {
        [$api, $orgId, $site] = _enhance_tool_context($params);
        $email = strtolower(trim((string) ($_REQUEST['email'] ?? '')));
        $known = [];
        foreach ((array) ($api->listWebsiteEmails($orgId, (string) $site['id'])['items'] ?? []) as $item) {
            $known[] = strtolower((string) ($item['address'] ?? ''));
        }
        if ($email === '' || !in_array($email, $known, true)) {
            return EnhanceLang::t('ca.invalid_request');
        }
        return _enhance_redirect($api->webmailSsoUrl($orgId, (string) $site['id'], $email), (int) $params['serviceid'], 'webmail');
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function enhance_PhpMyAdminSso(array $params)
{
    try {
        [$api, $orgId, $site] = _enhance_tool_context($params);
        $db = (string) ($_REQUEST['db'] ?? '');
        $known = array_column((array) ($api->listWebsiteDatabases($orgId, (string) $site['id'])['items'] ?? []), 'name');
        if ($db === '' || !in_array($db, $known, true)) {
            return EnhanceLang::t('ca.invalid_request');
        }
        return _enhance_redirect($api->phpMyAdminSsoUrl($orgId, (string) $site['id'], $db), (int) $params['serviceid'], 'phpmyadmin');
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function enhance_WordpressSso(array $params)
{
    try {
        [$api, $orgId, $site] = _enhance_tool_context($params);
        $appId = strtolower(trim((string) ($_REQUEST['app'] ?? '')));
        $app = null;
        foreach ((array) ($api->listWebsiteApps($orgId, (string) $site['id'])['items'] ?? []) as $item) {
            if (strtolower((string) ($item['id'] ?? '')) === $appId && ($item['app'] ?? '') === 'wordpress') {
                $app = $item;
                break;
            }
        }
        if (!$app) {
            return EnhanceLang::t('ca.invalid_request');
        }
        $default = isset($app['defaultWpUserId']) ? (int) $app['defaultWpUserId'] : null;
        return _enhance_redirect($api->wordpressSsoUrl($orgId, (string) $site['id'], $appId, $default), (int) $params['serviceid'], 'wordpress');
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/** POST only, CSRF-protected, account owner only, and only when enabled in the product. */
function enhance_RestoreBackup(array $params): string
{
    return _enhance_track($params, 'RestoreBackup', _enhance_run_RestoreBackup($params));
}

function _enhance_run_RestoreBackup(array $params): string
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return EnhanceLang::t('ca.invalid_request');
        }
        $tokenOk = false;
        try { $tokenOk = function_exists('check_token') && check_token('WHMCS.default', (string) ($_POST['token'] ?? '')); } catch (Throwable $e) { $tokenOk = false; }
        if (!$tokenOk) {
            return EnhanceLang::t('widget.bad_token');
        }
        if (!_enhance_restore_allowed($params)) {
            return EnhanceLang::t('ca.restore_disabled');
        }
        if (empty($_POST['confirm'])) {
            return EnhanceLang::t('ca.restore_confirm_required');
        }
        [$api, $orgId, $site] = _enhance_tool_context($params, true);
        $backupId = (int) ($_POST['backup'] ?? 0);
        $valid = false;
        foreach ((array) ($api->listWebsiteBackups($orgId, (string) $site['id'])['items'] ?? []) as $backup) {
            if ((int) ($backup['id'] ?? 0) === $backupId && ($backup['homeDirStatus'] ?? '') === 'successful') {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return EnhanceLang::t('ca.invalid_request');
        }
        $resp = $api->restoreWebsiteBackup($orgId, (string) $site['id'], $backupId, !empty($_POST['include_emails']));
        if ($err = _enhance_api_error($resp)) {
            return EnhanceLang::t('ca.restore_failed', ['detail' => $err]);
        }
        if (function_exists('logActivity')) {
            logActivity(EnhanceLang::t('log.restore', ['service' => (int) $params['serviceid'], 'backup' => $backupId, 'domain' => $site['domain']['domain'] ?? '']), _enhance_client_id($params));
        }
        _enhance_forget_clientarea((int) $params['serviceid']);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Usage statistics (WHMCS cron: "Update Usage Statistics")
// =============================================================================

function enhance_UsageUpdate(array $params)
{
    try {
        $result = enhance_update_server_usage(_enhance_api($params));
        return $result['errors'] > 0
            ? EnhanceLang::t('svc.usage_partial', ['ok' => $result['checked'] - $result['errors'], 'total' => $result['checked']])
            : 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Admin custom buttons (service page > Module Commands)
// =============================================================================

function enhance_AdminCustomButtonArray(): array
{
    return [
        EnhanceLang::t('btn.sync_state')     => 'SyncState',
        EnhanceLang::t('btn.refresh_usage')  => 'RefreshUsage',
        EnhanceLang::t('btn.resend_access')  => 'ResendAccess',
    ];
}

function enhance_SyncState(array $params): string
{
    return _enhance_track($params, 'SyncState', _enhance_run_SyncState($params));
}

function _enhance_run_SyncState(array $params): string
{
    try {
        $api       = _enhance_api($params);
        $resolved  = _enhance_resolve_subscription($api, $params);
        $sub       = $resolved['subscription'];
        $serviceId = (int) $params['serviceid'];

        if (($sub['status'] ?? '') === 'deleted') {
            return EnhanceLang::t('svc.sub_deleted_remote', ['sub' => $resolved['subId']]);
        }
        $current = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
        $target  = _enhance_subscription_is_suspended($sub) ? 'Suspended' : 'Active';
        if (in_array($current, ['Active', 'Suspended'], true) && $current !== $target) {
            $result = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => $target]);
            if (($result['result'] ?? '') !== 'success') {
                return EnhanceLang::t('svc.status_update_failed', ['detail' => $result['message'] ?? '']);
            }
        }
        enhance_store_service_usage($api, $serviceId, $resolved['orgId'], $resolved['subId'], $sub);
        _enhance_forget_clientarea($serviceId);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function enhance_RefreshUsage(array $params): string
{
    return _enhance_track($params, 'RefreshUsage', _enhance_run_RefreshUsage($params));
}

function _enhance_run_RefreshUsage(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $calc     = $api->calculateResourceUsage($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($calc)) return EnhanceLang::t('svc.recalc_failed', ['detail' => $err]);
        if (!enhance_store_service_usage($api, (int) $params['serviceid'], $resolved['orgId'], $resolved['subId'])) {
            return EnhanceLang::t('svc.usage_read_failed');
        }
        _enhance_forget_clientarea((int) $params['serviceid']);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function enhance_ResendAccess(array $params): string
{
    return _enhance_track($params, 'ResendAccess', _enhance_run_ResendAccess($params));
}

function _enhance_run_ResendAccess(array $params): string
{
    try {
        $api   = _enhance_api($params);
        $orgId = $api->getClientOrgId(_enhance_client_id($params));
        if (!$orgId) return EnhanceLang::t('svc.no_org_short');

        $org = $api->getOrg($orgId);
        if ($err = _enhance_api_error($org)) return $err;
        $email = (string) ($org['ownerEmail'] ?? '');
        if ($email === '') return EnhanceLang::t('svc.no_owner_email');

        $sent = $api->sendAccessEmail(_enhance_client_id($params), $email, true);
        if (!$sent['recovery']) return EnhanceLang::t('svc.recovery_failed', ['detail' => $sent['message']]);
        if (!$sent['email']) return EnhanceLang::t('svc.email_failed', ['detail' => $sent['message']]);
        return 'success';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Admin services tab
// =============================================================================

function enhance_AdminServicesTabFields(array $params): array
{
    $fields = _enhance_admin_tab_main($params);
    try {
        $fields += _enhance_admin_tab_maintenance($params);
    } catch (Throwable $e) {
        $fields[EnhanceLang::t('tab.link')] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    return $fields;
}

/**
 * Last action result and the subscription link selector. The selector is part
 * of the service form: its value is applied by enhance_AdminServicesTabFieldsSave().
 */
function _enhance_admin_tab_maintenance(array $params): array
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $serviceId = (int) $params['serviceid'];
    $out = [EnhanceLang::t('tab.module_version') => 'v' . $h(EnhanceApi::VERSION)];

    $event = EnhanceStore::lastEvent($serviceId);
    if ($event) {
        $when = $event->created_at ? date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $event->created_at)) : '';
        $status = $event->success
            ? '<span class="label label-success">' . $h(EnhanceLang::t('tab.ok')) . '</span>'
            : '<span class="label label-danger">' . $h(EnhanceLang::t('tab.failed')) . '</span>';
        $html = $status . ' ' . $h($event->action) . ' &middot; ' . $h($when);
        if ($event->last_error) {
            $errWhen = $event->last_error_at ? date(EnhanceLang::t('fmt.date') . ' H:i', strtotime((string) $event->last_error_at)) : '';
            $html .= '<div style="margin-top:6px;color:#b91c1c;white-space:pre-wrap;">' . $h(EnhanceLang::t('tab.last_error', ['date' => $errWhen])) . ' ' . $h($event->last_error) . '</div>';
        }
        $out[EnhanceLang::t('tab.last_action')] = $html;
    }

    $api   = _enhance_api($params);
    $orgId = $api->getClientOrgId(_enhance_client_id($params));
    if (!$orgId) {
        $out[EnhanceLang::t('tab.link')] = $h(EnhanceLang::t('tab.link_no_org'));
        return $out;
    }
    $current = $api->getServiceSubscriptionId((int) $params['packageid'], $serviceId);
    $subs = $api->getCustomerSubscriptions($orgId);
    if ($err = _enhance_api_error($subs)) {
        $out[EnhanceLang::t('tab.link')] = $h(EnhanceLang::t('tab.error', ['detail' => $err]));
        return $out;
    }
    $options = '<option value="">' . $h(EnhanceLang::t($current ? 'tab.link_keep' : 'tab.link_choose')) . '</option>';
    foreach ($subs['items'] as $sub) {
        $id = (string) ($sub['id'] ?? '');
        if ($id === '' || ($sub['status'] ?? '') === 'deleted' || $id === (string) $current) continue;
        $usedBy = $api->findServiceBySubscriptionId($id);
        $label = '#' . $id . ' - ' . ($sub['planName'] ?? '') . (($sub['friendlyName'] ?? '') !== '' ? ' - ' . $sub['friendlyName'] : '')
            . (!empty($sub['suspendedBy']) ? ' (' . EnhanceLang::t('widget.status_suspended') . ')' : '');
        if ($usedBy && $usedBy !== $serviceId) {
            $options .= '<option value="" disabled>' . $h($label . ' - ' . EnhanceLang::t('tab.link_used_by', ['service' => $usedBy])) . '</option>';
        } else {
            $options .= '<option value="' . $h($id) . '">' . $h($label) . '</option>';
        }
    }
    if ($current) {
        $options .= '<option value="unlink">' . $h(EnhanceLang::t('tab.link_remove')) . '</option>';
    }
    $out[EnhanceLang::t('tab.link')] = '<select name="enhance_link_subscription" class="form-control select-inline" style="max-width:520px;">' . $options . '</select>'
        . '<div class="help-block" style="margin:4px 0 0;">' . $h(EnhanceLang::t('tab.link_help')) . '</div>';
    return $out;
}

/** Applies the subscription chosen in the service tab when the service is saved. */
function enhance_AdminServicesTabFieldsSave(array $params): void
{
    $choice = trim((string) ($_POST['enhance_link_subscription'] ?? ''));
    if ($choice === '') return;
    $serviceId = (int) $params['serviceid'];
    try {
        $api = _enhance_api($params);
        $clientId = _enhance_client_id($params);
        $message = EnhanceStore::withLock(EnhanceStore::clientLockKey($api->getServerId(), $clientId), function () use ($api, $params, $choice, $serviceId, $clientId) {
            if ($choice === 'unlink') {
                $api->clearServiceSubscriptionId((int) $params['packageid'], $serviceId);
                return EnhanceLang::t('tab.link_removed');
            }
            if (!ctype_digit($choice)) {
                throw new Exception(EnhanceLang::t('ca.invalid_request'));
            }
            $orgId = $api->getClientOrgId($clientId);
            if (!$orgId) {
                throw new Exception(EnhanceLang::t('tab.link_no_org'));
            }
            $orgId = strtolower($orgId);
            $sub = $api->getSubscription($orgId, $choice);
            if ($err = _enhance_api_error($sub)) {
                throw new Exception(EnhanceLang::t('svc.sub_read_failed', ['sub' => $choice, 'detail' => $err]));
            }
            _enhance_assert_subscription($api, $sub, $orgId);
            if (($sub['status'] ?? '') === 'deleted') {
                throw new Exception(EnhanceLang::t('svc.sub_deleted_remote', ['sub' => $choice]));
            }
            $usedBy = $api->findServiceBySubscriptionId($choice);
            if ($usedBy && $usedBy !== $serviceId) {
                throw new Exception(EnhanceLang::t('store.sub_taken', ['sub' => $choice, 'service' => $usedBy]));
            }
            $api->saveServiceSubscriptionId((int) $params['packageid'], $serviceId, $choice);
            EnhanceStore::setWebsite($serviceId, null); // the website belonged to the previous subscription
            return EnhanceLang::t('tab.link_saved', ['sub' => $choice]);
        });
        _enhance_forget_clientarea($serviceId);
        EnhanceStore::recordEvent($serviceId, 'LinkSubscription', true, '');
        if (function_exists('logActivity')) logActivity('Enhance: ' . $message . ' (#' . $serviceId . ')', $clientId);
    } catch (Throwable $e) {
        EnhanceStore::recordEvent($serviceId, 'LinkSubscription', false, $e->getMessage());
    }
}

function _enhance_admin_tab_main(array $params): array
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    try {
        $api   = _enhance_api($params);
        $orgId = $api->getClientOrgId(_enhance_client_id($params));
        $subId = $api->getServiceSubscriptionId((int) $params['packageid'], (int) $params['serviceid']);

        $fields = [
            EnhanceLang::t('tab.org')          => $h($orgId ?: '-'),
            EnhanceLang::t('tab.subscription') => $h($subId ?: '-'),
            EnhanceLang::t('tab.server')       => '#' . (int) ($params['serverid'] ?? 0) . ' ' . $h($api->getHost()),
        ];
        if (!$orgId || !$subId) return $fields;
        $orgId = strtolower(trim($orgId));

        $sites = $api->listWebsitesBySubscription($orgId, $subId);
        if ($err = _enhance_api_error($sites)) {
            $fields[EnhanceLang::t('tab.website')] = $h(EnhanceLang::t('tab.error', ['detail' => $err]));
            return $fields;
        }

        $site = null;
        $storedSite = $api->getServiceWebsiteId((int) $params['serviceid']);
        $wanted = strtolower(trim((string) ($params['domain'] ?? '')));
        foreach ($sites['items'] ?? [] as $candidate) {
            if ($storedSite && (string) ($candidate['id'] ?? '') === $storedSite) {
                $site = $candidate;
                break;
            }
            if (($candidate['kind'] ?? 'normal') !== 'normal' || ($candidate['status'] ?? '') === 'deleted') continue;
            if ((int) ($candidate['subscriptionId'] ?? 0) !== (int) $subId) continue;
            $site = $site ?? $candidate;
            if ($wanted !== '' && strtolower((string) ($candidate['domain']['domain'] ?? '')) === $wanted) {
                $site = $candidate;
                break;
            }
        }
        if ($site && !$storedSite && !empty($site['id'])) {
            $api->saveServiceWebsiteId((int) $params['serviceid'], (string) $site['id']);
        }
        if (!$site) {
            $fields[EnhanceLang::t('tab.website')] = $h(EnhanceLang::t('tab.no_websites'));
            return $fields;
        }

        $color  = preg_replace('/[^0-9a-f]/i', '', (string) ($site['colorCode'] ?? '999999')) ?: '999999';
        $status = $h($site['status'] ?? 'unknown');
        $domain = $h($site['domain']['domain'] ?? $params['domain']);
        $ipv4   = $h($site['serverIps'][0]['ip'] ?? 'N/A');
        $ipv6   = $h($site['appServerIpv6'] ?? 'N/A');
        $php    = $h(_enhance_php_label((string) ($site['phpVersion'] ?? 'N/A')));
        $mysql  = $h($site['canUse']['mysqlKind'] ?? 'N/A');
        $server = $h($site['appServerName'] ?? '');
        $lServer = $h(EnhanceLang::t('tab.app_server'));

        $fields[EnhanceLang::t('tab.website')] = <<<HTML
<div style="background:#f2f5ff;border-radius:8px;padding:16px;margin:8px 0;">
    <h4 style="margin:0 0 6px;">{$domain}
        <span style="color:#{$color};font-size:.85em;margin-left:8px;">
            <i class="fa fa-circle"></i> {$status}
        </span>
    </h4>
    <div style="display:flex;flex-wrap:wrap;gap:14px;background:#fff;padding:8px 12px;border-radius:5px;margin-top:8px;">
        <span><strong>{$lServer}:</strong> {$server}</span>
        <span><strong>IPv4:</strong> {$ipv4}</span>
        <span><strong>IPv6:</strong> {$ipv6}</span>
        <span><strong>PHP:</strong> {$php}</span>
        <span><strong>MySQL:</strong> {$mysql}</span>
    </div>
</div>
HTML;
        return $fields;
    } catch (Throwable $e) {
        return ['Enhance' => $h($e->getMessage())];
    }
}
