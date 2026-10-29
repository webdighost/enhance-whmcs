<?php
/**
 * Enhance API wrapper for WHMCS (v1.4.0)
 *
 * Credentials are injected per instance from the WHMCS server, so several
 * Enhance servers can coexist in the same WHMCS installation:
 *   Hostname    = Enhance control panel domain (e.g. cp.example.com)
 *   Username    = UUID of the master (reseller/vendor) organisation
 *   Access Hash = Enhance API access token  (or the Password field, see below)
 *
 * Authentication: every request sends "Authorization: Bearer <token>" to
 * https://<hostname>/api/... The token is read from the Access Hash field
 * and, when that field is empty, from the (encrypted) Password field.
 * Values are normalised before use, which fixes the most common causes of
 * HTTP 401 with tokens pasted into WHMCS: HTML entities added by WHMCS when
 * saving the form, line breaks/spaces from copy & paste and a "Bearer " prefix.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/EnhanceSettings.php';
require_once __DIR__ . '/EnhanceLang.php';
require_once __DIR__ . '/EnhanceStore.php';

use WHMCS\Database\Capsule;

class EnhanceApi
{
    const VERSION                = '1.4.0';
    const MODULE                 = 'enhance';
    const FIELD_CLIENT_ORG_ID    = 'EnhanceOrgId';
    const FIELD_SUBSCRIPTION_ID  = 'SUBSCRIPTION_ID';
    const EMAIL_TEMPLATE_NAME    = EnhanceStore::EMAIL_TEMPLATE;
    const FRIENDLY_NAME_PREFIX   = 'WHMCS #';

    private string $host;
    private string $masterOrgId;
    private string $apiKey;
    private string $tokenSource;
    private string $alternativeKey = '';
    private int    $serverId = 0;
    public  bool   $debug;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param string $tokenSource "accesshash" or "password" (used in diagnostics only)
     */
    public function __construct(string $host, string $masterOrgId, string $apiKey, int $serverId = 0, string $tokenSource = 'accesshash')
    {
        $host        = self::normaliseHost($host);
        $masterOrgId = self::normaliseOrgId($masterOrgId);
        $apiKey      = self::normaliseToken($apiKey);

        if ($host === '' || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$/i', $host)) {
            throw new Exception(EnhanceLang::t('api.host_invalid'));
        }
        if (!EnhanceStore::isUuid($masterOrgId)) {
            throw new Exception(EnhanceLang::t('api.org_invalid'));
        }
        if ($apiKey === '') {
            throw new Exception(EnhanceLang::t('api.token_missing'));
        }

        $this->host        = $host;
        $this->masterOrgId = $masterOrgId;
        $this->apiKey      = $apiKey;
        $this->tokenSource = $tokenSource;
        $this->debug       = defined('ENHANCE_DEBUG') && ENHANCE_DEBUG;
        $this->serverId    = $serverId > 0 ? $serverId : $this->resolveServerId();

        EnhanceStore::ensure();
    }

    /** Builds the client from the $params WHMCS passes to module functions. */
    public static function fromParams(array $params): self
    {
        $host = (string) ($params['serverhostname'] ?? '');
        if (trim($host) === '') {
            $host = (string) ($params['serverip'] ?? '');
        }
        [$token, $source, $alternative] = self::pickToken(
            (string) ($params['serveraccesshash'] ?? ''),
            (string) ($params['serverpassword'] ?? '')
        );
        $api = new self($host, (string) ($params['serverusername'] ?? ''), $token, (int) ($params['serverid'] ?? 0), $source);
        $api->alternativeKey = $alternative;
        return $api;
    }

    /** Builds the client from a tblservers row (hooks, cron, addon). */
    public static function fromServerRow(object $row): self
    {
        $host = (string) ($row->hostname ?? '');
        if (trim($host) === '') {
            $host = (string) ($row->ipaddress ?? '');
        }
        [$token, $source, $alternative] = self::pickToken(
            (string) ($row->accesshash ?? ''),
            self::decryptPassword((string) ($row->password ?? ''))
        );
        $api = new self($host, (string) ($row->username ?? ''), $token, (int) ($row->id ?? 0), $source);
        $api->alternativeKey = $alternative;
        return $api;
    }

    public static function fromServerId(int $serverId): self
    {
        $row = Capsule::table('tblservers')->where('id', $serverId)->where('type', self::MODULE)->first();
        if (!$row) {
            throw new Exception(EnhanceLang::t('api.server_not_found', ['id' => $serverId]));
        }
        return self::fromServerRow($row);
    }

    /**
     * Access Hash wins when filled in (1.x behaviour); Password is the fallback.
     * @return array{0:string,1:string,2:string} token, source field, other field's token
     */
    private static function pickToken(string $accessHash, string $password): array
    {
        $accessHash = self::normaliseToken($accessHash);
        $password   = self::normaliseToken($password);
        if ($accessHash !== '') {
            return [$accessHash, 'accesshash', $password !== $accessHash ? $password : ''];
        }
        return [$password, 'password', ''];
    }

    private static function decryptPassword(string $encrypted): string
    {
        if ($encrypted === '') return '';
        if (function_exists('decrypt')) {
            try {
                $plain = decrypt($encrypted);
                if (is_string($plain) && $plain !== '') return $plain;
            } catch (Throwable $e) {
                // Fall back to the local API.
            }
        }
        if (function_exists('localAPI')) {
            $result = localAPI('DecryptPassword', ['password2' => $encrypted]);
            if (($result['result'] ?? '') === 'success') return (string) ($result['password'] ?? '');
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Input normalisation
    // -------------------------------------------------------------------------

    public static function normaliseHost(string $host): string
    {
        $host = trim(html_entity_decode($host, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host); // scheme
        $host = (string) preg_replace('#[/?\#].*$#s', '', $host);               // path, query, fragment
        $host = (string) preg_replace('#^[^@]*@#', '', $host);                  // user:pass@
        return strtolower(rtrim($host, '.'));
    }

    public static function normaliseOrgId(string $orgId): string
    {
        return strtolower(trim(html_entity_decode($orgId, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\"'{}"));
    }

    /**
     * WHMCS stores server fields HTML-encoded ("&" becomes "&amp;"); tokens are
     * also often pasted with line breaks, quotes or a "Bearer " prefix.
     */
    public static function normaliseToken(string $token): string
    {
        $token = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $token = (string) preg_replace('/^\s*(?:authorization\s*:\s*)?bearer\s+/i', '', $token);
        $token = (string) preg_replace('/\s+/u', '', $token);
        return trim($token, "\"'");
    }

    /** WHMCS server id matching this hostname and master organisation. */
    private function resolveServerId(): int
    {
        try {
            $candidates = Capsule::table('tblservers')->where('type', self::MODULE)->orderBy('id')->get();
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($candidates as $row) {
            if (self::normaliseHost((string) $row->hostname) === $this->host && self::normaliseOrgId((string) $row->username) === $this->masterOrgId) {
                return (int) $row->id;
            }
        }
        return 0;
    }

    public function getServerId(): int { return $this->serverId; }
    public function getHost(): string { return $this->host; }
    public function getMasterOrgId(): string { return $this->masterOrgId; }
    public function getPanelUrl(): string { return 'https://' . $this->host; }

    /** ID of the client custom field "EnhanceOrgId" (created when missing). */
    public function clientOrgFieldId(): int
    {
        return EnhanceStore::clientOrgFieldId();
    }

    // -------------------------------------------------------------------------
    // Access email
    // -------------------------------------------------------------------------

    /**
     * Sends the panel access email (never a password) and, for new logins,
     * asks Enhance to send its own password-setting link.
     */
    public function sendAccessEmail(int $whmcsClientId, string $email, bool $newLogin): array
    {
        EnhanceStore::ensureEmailTemplate();
        $recovery = $newLogin ? $this->triggerPasswordRecovery($email) : ['_httpCode' => 200];
        $mail = function_exists('localAPI') ? localAPI('SendEmail', [
            'messagename' => self::EMAIL_TEMPLATE_NAME,
            'id'          => $whmcsClientId,
            'customvars'  => base64_encode(serialize([
                'panel_url'   => $this->getPanelUrl(),
                'login_email' => $email,
                'new_login'   => $newLogin ? 1 : 0,
            ])),
        ]) : ['result' => 'success'];

        return [
            'recovery' => !self::isError($recovery),
            'email'    => ($mail['result'] ?? '') === 'success',
            'message'  => self::isError($recovery) ? 'Enhance: ' . self::errorSummary($recovery) : (string) ($mail['message'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    /**
     * @param array    $body Request body; an empty array sends no body.
     * @param string[] $mask Values (e.g. passwords) replaced by *** in the module log.
     */
    public function send(string $method, string $path, array $body = [], array $mask = [], ?string $apiKey = null): array
    {
        $method = strtoupper($method);
        $url = 'https://' . $this->host . '/api' . $path;
        $apiKey = $apiKey ?? $this->apiKey;

        $headers = [
            'Accept: application/json',
            'User-Agent: WHMCS-Enhance-Module/' . self::VERSION,
        ];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            // Never follow redirects: curl would drop or leak the Authorization header.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        $payload = '';
        if ($body !== []) {
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $payload;
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            // Explicit empty body, so a "Content-Length: 0" header is sent.
            $options[CURLOPT_POSTFIELDS] = '';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw         = curl_exec($ch);
        $curlErrNo   = curl_errno($ch);
        $curlErr     = curl_error($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirect    = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';

        if (function_exists('logModuleCall')) {
            $route = explode('?', $path)[0];
            $isSso = (bool) preg_match('#/sso$#', $route);
            $mask[] = $apiKey;
            logModuleCall(
                self::MODULE,
                $method . ' ' . $path,
                $body === [] ? '' : $payload,
                ['http' => $httpCode, 'body' => $isSso && $httpCode < 300 ? '[SSO link omitted]' : mb_substr($raw, 0, 4000)],
                null,
                array_values(array_filter($mask, 'strlen'))
            );
        }

        if ($curlErrNo) {
            return ['code' => 'curl_error', 'detail' => self::curlHint($curlErrNo, $curlErr), '_httpCode' => 0];
        }

        if ($httpCode >= 300 && $httpCode < 400) {
            // "_location" lets the few endpoints that answer with a redirect (Roundcube SSO) use it.
            return ['code' => 'redirect', 'detail' => EnhanceLang::t('api.redirect', ['location' => $redirect ?: '?']), '_httpCode' => $httpCode, '_location' => $redirect];
        }

        $trimmed = trim($raw);
        $decoded = $trimmed === '' ? null : json_decode($trimmed, true);
        $isJson  = $trimmed !== '' && json_last_error() === JSON_ERROR_NONE;

        if ($isJson && is_array($decoded)) {
            if ($httpCode >= 400 && empty($decoded['code'])) {
                $decoded['code'] = 'http_' . $httpCode;
            }
            $decoded['_httpCode'] = $httpCode;
            return $decoded;
        }

        if ($httpCode >= 400) {
            $detail = $isJson && is_scalar($decoded) ? (string) $decoded : trim((string) preg_replace('/\s+/', ' ', strip_tags($raw)));
            return ['code' => 'http_' . $httpCode, 'detail' => mb_substr($detail, 0, 300), '_httpCode' => $httpCode];
        }

        // 2xx with an HTML page: the hostname is a website / proxy, not the Enhance panel.
        if (!$isJson && stripos($contentType, 'text/html') !== false) {
            return ['code' => 'not_enhance_api', 'detail' => EnhanceLang::t('api.not_api', ['host' => $this->host]), '_httpCode' => $httpCode];
        }

        // Scalar JSON (e.g. an SSO link as a JSON string, bandwidth as a number) or plain text.
        if ($isJson && is_scalar($decoded)) {
            return ['_raw' => is_bool($decoded) ? ($decoded ? 'true' : 'false') : (string) $decoded, '_httpCode' => $httpCode];
        }
        return ['_raw' => $trimmed, '_httpCode' => $httpCode];
    }

    private static function curlHint(int $errno, string $error): string
    {
        $hint = '';
        if (in_array($errno, [6], true)) {
            $hint = EnhanceLang::t('api.curl_dns');
        } elseif (in_array($errno, [7, 28], true)) {
            $hint = EnhanceLang::t('api.curl_connect');
        } elseif (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
            $hint = EnhanceLang::t('api.curl_ssl');
        }
        return trim('cURL ' . $errno . ': ' . $error . ' ' . $hint);
    }

    public static function isError(array $response): bool
    {
        if (!empty($response['code'])) return true;
        if (!array_key_exists('_httpCode', $response)) return false;
        $status = (int) $response['_httpCode'];
        return $status === 0 || $status >= 300;
    }

    public static function isNotFound(array $response): bool
    {
        return (int) ($response['_httpCode'] ?? 0) === 404 || ($response['code'] ?? '') === 'not_found';
    }

    public static function errorSummary(array $response): string
    {
        $status = (int) ($response['_httpCode'] ?? 0);
        $code   = (string) ($response['code'] ?? 'unknown');
        $detail = $response['detail'] ?? $response['message'] ?? '';
        if (!is_scalar($detail)) {
            $detail = json_encode($detail);
        }
        $hint = '';
        if ($status === 401) $hint = ' ' . EnhanceLang::t('api.hint_401');
        if ($status === 403) $hint = ' ' . EnhanceLang::t('api.hint_403');
        if ($status === 404) $hint = ' ' . EnhanceLang::t('api.hint_404');
        if ($status === 429) $hint = ' ' . EnhanceLang::t('api.hint_429');
        $prefix = $status > 0 ? 'HTTP ' . $status . ' ' : '';
        return trim($prefix . '[' . $code . '] ' . (string) $detail . $hint);
    }

    /**
     * Fetches every page of an Enhance listing endpoint ({items, total}).
     * Returns ['items' => [...], 'total' => n] or the API error array.
     */
    public function listAll(string $path, int $pageSize = 100): array
    {
        $items = [];
        $offset = 0;
        $sep = strpos($path, '?') === false ? '?' : '&';
        do {
            $page = $this->send('GET', "{$path}{$sep}limit={$pageSize}&offset={$offset}");
            if (self::isError($page)) {
                return $page;
            }
            if (!isset($page['items']) || !is_array($page['items'])) {
                return ['code' => 'invalid_listing', 'detail' => EnhanceLang::t('api.invalid_listing', ['path' => explode('?', $path)[0]]), '_httpCode' => (int) ($page['_httpCode'] ?? 0)];
            }
            $items = array_merge($items, $page['items']);
            $offset += count($page['items']);
            $total = (int) ($page['total'] ?? $offset);
            if (!$page['items'] || $offset > 50000) {
                break;
            }
        } while ($offset < $total);

        return ['items' => $items, 'total' => count($items), '_httpCode' => 200];
    }

    // -------------------------------------------------------------------------
    // Connection test / diagnostics
    // -------------------------------------------------------------------------

    /**
     * Validates hostname, token and master organisation, step by step, and
     * explains exactly which part of the configuration is wrong.
     */
    public function testConnection(): array
    {
        // 1. GET /version is public (sent without the token): proves the hostname
        //    is an Enhance panel before the token is blamed for anything.
        $version = $this->send('GET', '/version', [], [], '');
        $versionOk = !self::isError($version) && preg_match('/^\d+\.\d+/', (string) ($version['_raw'] ?? ''));
        $hostWrong = in_array($version['code'] ?? '', ['curl_error', 'redirect', 'not_enhance_api'], true)
            || (int) ($version['_httpCode'] ?? 0) === 404
            || (!self::isError($version) && !$versionOk);
        if ($hostWrong) {
            $detail = self::isError($version) ? self::errorSummary($version) : mb_substr((string) json_encode($version), 0, 200);
            return ['success' => false, 'error' => EnhanceLang::t('test.not_enhance', ['host' => $this->host, 'detail' => $detail])];
        }

        // 2. The master organisation, read with the token.
        $org = $this->getOrg($this->masterOrgId);

        if (self::isError($org) && (int) ($org['_httpCode'] ?? 0) === 401) {
            // The other credential field may hold the right token.
            if ($this->alternativeKey !== '') {
                $retry = $this->send('GET', '/orgs/' . $this->masterOrgId, [], [$this->alternativeKey], $this->alternativeKey);
                if (!self::isError($retry)) {
                    return ['success' => false, 'error' => EnhanceLang::t('test.token_in_other_field', [
                        'used'  => EnhanceLang::t('field.' . $this->tokenSource),
                        'other' => EnhanceLang::t('field.' . ($this->tokenSource === 'accesshash' ? 'password' : 'accesshash')),
                    ])];
                }
            }
            return ['success' => false, 'error' => EnhanceLang::t('test.unauthorised', [
                'field'  => EnhanceLang::t('field.' . $this->tokenSource),
                'length' => strlen($this->apiKey),
                // Enhance lists each token by its first five characters ("firstFive").
                'first'  => mb_substr($this->apiKey, 0, 5),
                'detail' => self::errorSummary($org),
            ])];
        }
        if (self::isError($org) && (int) ($org['_httpCode'] ?? 0) === 403) {
            return ['success' => false, 'error' => EnhanceLang::t('test.forbidden', ['detail' => self::errorSummary($org)])];
        }
        if (self::isNotFound($org)) {
            return ['success' => false, 'error' => EnhanceLang::t('test.org_not_found', ['org' => $this->masterOrgId, 'detail' => self::errorSummary($org)])];
        }
        if (self::isError($org)) {
            return ['success' => false, 'error' => 'Enhance: ' . self::errorSummary($org)];
        }
        if (strtolower((string) ($org['id'] ?? '')) !== $this->masterOrgId) {
            return ['success' => false, 'error' => EnhanceLang::t('test.org_mismatch')];
        }

        $plans = $this->getPlans();
        if (self::isError($plans)) {
            return ['success' => false, 'error' => EnhanceLang::t('test.plans_failed', ['detail' => self::errorSummary($plans)])];
        }

        $customers = $this->send('GET', '/orgs/' . $this->masterOrgId . '/customers?limit=1&offset=0');
        if (self::isError($customers)) {
            return ['success' => false, 'error' => EnhanceLang::t('test.customers_failed', ['detail' => self::errorSummary($customers)])];
        }

        return ['success' => true, 'error' => ''];
    }

    // -------------------------------------------------------------------------
    // Password generator
    // -------------------------------------------------------------------------

    public function generatePassword(int $length = 24): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#%*-_'];
        $all  = implode('', $sets);
        $pass = '';
        foreach ($sets as $set) {
            $pass .= $set[random_int(0, strlen($set) - 1)];
        }
        for ($i = strlen($pass); $i < $length; $i++) {
            $pass .= $all[random_int(0, strlen($all) - 1)];
        }
        $chars = str_split($pass);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    // =========================================================================
    // API calls
    // =========================================================================

    // Orgs
    public function getOrg(string $orgId): array
    {
        return $this->send('GET', '/orgs/' . rawurlencode($orgId));
    }

    public function setOrgSuspended(string $orgId, bool $suspended): array
    {
        return $this->send('PATCH', '/orgs/' . rawurlencode($orgId), ['isSuspended' => $suspended]);
    }

    public function updateOrgName(string $orgId, string $name): array
    {
        return $this->send('PATCH', '/orgs/' . rawurlencode($orgId), ['name' => $name]);
    }

    // Customers
    public function getCustomers(): array
    {
        return $this->listAll("/orgs/{$this->masterOrgId}/customers");
    }

    /**
     * First $limit customers whose name contains $term (case insensitive), or
     * the customer with that exact organisation UUID.
     */
    public function searchCustomers(string $term, int $limit = 25): array
    {
        $term = trim($term);
        if (EnhanceStore::isUuid($term)) {
            $org = $this->getOrg(strtolower($term));
            if (self::isNotFound($org)) return ['items' => [], 'total' => 0, '_httpCode' => 200];
            if (self::isError($org)) return $org;
            $isCustomer = strtolower((string) ($org['parentId'] ?? '')) === $this->masterOrgId;
            return ['items' => $isCustomer ? [$org] : [], 'total' => $isCustomer ? 1 : 0, '_httpCode' => 200];
        }
        return $this->send('GET', "/orgs/{$this->masterOrgId}/customers?search=" . rawurlencode($term) . '&limit=' . $limit . '&offset=0&sortBy=name&sortOrder=asc');
    }

    public function getCustomerSubscriptions(string $orgId): array
    {
        return $this->listAll("/orgs/{$this->masterOrgId}/customers/" . rawurlencode($orgId) . '/subscriptions');
    }

    /**
     * Creates the customer org and persists its id before login provisioning,
     * so a failure in the next steps resumes on the same org.
     */
    public function createCustomerOrg(int $whmcsClientId, string $orgName): array
    {
        $org = $this->send('POST', "/orgs/{$this->masterOrgId}/customers", ['name' => $orgName]);
        if (self::isError($org) || empty($org['id'])) {
            return ['error' => true, 'message' => EnhanceLang::t('org.create_failed', ['detail' => self::isError($org) ? self::errorSummary($org) : json_encode($org)])];
        }

        $orgId = strtolower((string) $org['id']);
        try {
            $this->saveClientOrgId($whmcsClientId, $orgId);
        } catch (Exception $e) {
            return ['error' => true, 'message' => EnhanceLang::t('org.created_not_saved', ['org' => $orgId, 'detail' => $e->getMessage()])];
        }
        return ['error' => false, 'orgId' => $orgId];
    }

    private static function organisationName(string $name, string $email, string $company): string
    {
        $orgName = trim($company) !== '' ? trim($company) : trim($name);
        return $orgName !== '' ? $orgName : $email;
    }

    /**
     * Ensures the WHMCS client has a valid Enhance org and an owner member.
     * May create the organisation, a login and send the access email.
     */
    public function syncCustomerFromWhmcs(int $whmcsClientId, string $name, string $email, string $company = ''): array
    {
        $vendor = $this->getOrg($this->masterOrgId);
        if (self::isError($vendor) || strtolower((string) ($vendor['id'] ?? '')) !== $this->masterOrgId) {
            $detail = self::isError($vendor) ? ' ' . self::errorSummary($vendor) : '';
            return ['success' => false, 'message' => EnhanceLang::t('org.master_not_found') . $detail];
        }

        $orgName = self::organisationName($name, $email, $company);
        $orgId   = $this->getClientOrgId($whmcsClientId);
        $created = false;
        $changed = [];

        if ($orgId) {
            $org = $this->getOrg($orgId);
            if (self::isNotFound($org)) {
                $orgId = null;
                $changed[] = 'stored_org_missing';
            } elseif (self::isError($org) || empty($org['id'])) {
                return ['success' => false, 'message' => EnhanceLang::t('org.validate_failed') . (self::isError($org) ? ' ' . self::errorSummary($org) : '')];
            } elseif (strtolower((string) ($org['parentId'] ?? '')) !== $this->masterOrgId) {
                return ['success' => false, 'message' => EnhanceLang::t('org.wrong_parent')];
            } elseif (($org['name'] ?? '') !== $orgName) {
                if (!self::isError($this->updateOrgName($orgId, $orgName))) {
                    $changed[] = 'org_name_updated';
                }
            }
        }

        if (!$orgId) {
            $match = $this->findCustomerOrgByEmail($email);
            if (!empty($match['error'])) {
                return ['success' => false, 'message' => $match['message']];
            }
            if ($match) {
                $orgId = strtolower((string) $match['id']);
                try {
                    $this->saveClientOrgId($whmcsClientId, $orgId);
                } catch (Exception $e) {
                    return ['success' => false, 'message' => 'Enhance: ' . $e->getMessage() . ' ' . EnhanceLang::t('org.none_created')];
                }
                $changed[] = 'org_relinked';
            }
        }

        if (!$orgId) {
            $result = $this->createCustomerOrg($whmcsClientId, $orgName);
            if (!empty($result['error'])) {
                return ['success' => false, 'message' => $result['message']];
            }
            $orgId = $result['orgId'];
            $created = true;
            $changed[] = 'org_created';
        }

        $members = $this->getMembers($orgId);
        if (self::isError($members)) {
            return ['success' => false, 'message' => EnhanceLang::t('org.members_failed', ['org' => $orgId, 'detail' => self::errorSummary($members)])];
        }
        $owner = null;
        foreach ($members['items'] as $member) {
            if (in_array('Owner', (array) ($member['roles'] ?? []), true)) {
                $owner = $member;
                break;
            }
        }
        if (!$owner) {
            $orgInfo = $this->getOrg($orgId);
            if (!self::isError($orgInfo) && !empty($orgInfo['ownerId'])) {
                $owner = ['id' => $orgInfo['ownerId']];
            }
        }
        if (!$owner) {
            if ($email === '') {
                return ['success' => false, 'message' => EnhanceLang::t('org.no_email', ['org' => $orgId])];
            }
            $matching = $this->findLoginsByEmail($email);
            if (isset($matching['code'])) {
                return ['success' => false, 'message' => EnhanceLang::t('org.logins_failed', ['org' => $orgId, 'detail' => self::errorSummary($matching)])];
            }
            if (count($matching) > 1) {
                return ['success' => false, 'message' => EnhanceLang::t('org.many_logins', ['org' => $orgId])];
            }
            $newLogin = false;
            $login = $matching[0] ?? null;
            if (!$login) {
                $password = $this->generatePassword();
                $login = $this->createLogin($orgId, $email, $password, $name !== '' ? $name : $email);
                if ((int) ($login['_httpCode'] ?? 0) === 409) {
                    // Enhance already has this login (e.g. created meanwhile, or listed under
                    // another realm): reuse it when exactly one login has this email.
                    $existing = $this->findLoginsByEmail($email, true);
                    if (!isset($existing['code']) && count($existing) === 1) {
                        $login = $existing[0];
                    } elseif (!isset($existing['code']) && count($existing) > 1) {
                        return ['success' => false, 'message' => EnhanceLang::t('org.many_logins', ['org' => $orgId])];
                    }
                } elseif (!self::isError($login) && !empty($login['id'])) {
                    $newLogin = true;
                    self::rememberCreatedLogin((string) $login['id']);
                }
                if (self::isError($login) || empty($login['id'])) {
                    return ['success' => false, 'message' => EnhanceLang::t('org.login_failed', ['org' => $orgId, 'detail' => self::errorSummary($login)])];
                }
            }
            if (!$newLogin && self::wasCreatedByModule((string) ($login['id'] ?? ''))) {
                // Created by an earlier, interrupted attempt: the client never set a password.
                $newLogin = true;
            }
            $linked = $this->linkMemberAsOwner($orgId, (string) $login['id']);
            if (self::isError($linked)) {
                return ['success' => false, 'message' => EnhanceLang::t('org.owner_failed', ['org' => $orgId, 'detail' => self::errorSummary($linked)])];
            }
            $changed[] = $newLogin ? 'owner_created' : 'existing_login_linked_as_owner';
            // The password is never emailed: Enhance sends its own link to set it.
            $this->sendAccessEmail($whmcsClientId, $email, $newLogin);
        }

        return ['success' => true, 'orgId' => $orgId, 'created' => $created, 'changed' => $changed];
    }

    /**
     * Profile edits only rename the linked organisation: they never create
     * organisations, logins or owners, and never send emails.
     */
    public function updateCustomerOrgName(int $whmcsClientId, string $name, string $email, string $company = ''): array
    {
        $orgId = $this->getClientOrgId($whmcsClientId);
        if (!$orgId) return ['success' => true, 'changed' => []];

        $org = $this->getOrg($orgId);
        if (self::isError($org)) return ['success' => false, 'message' => self::errorSummary($org)];
        if (strtolower((string) ($org['parentId'] ?? '')) !== $this->masterOrgId) {
            return ['success' => false, 'message' => EnhanceLang::t('org.wrong_parent')];
        }
        $orgName = self::organisationName($name, $email, $company);
        if (($org['name'] ?? '') === $orgName) return ['success' => true, 'changed' => []];

        $resp = $this->updateOrgName($orgId, $orgName);
        return self::isError($resp) ? ['success' => false, 'message' => self::errorSummary($resp)] : ['success' => true, 'changed' => ['org_name_updated']];
    }

    /**
     * Only a verified owner email can link an existing org (a name is not an identity).
     * Returns the customer, null, or ['error' => true, 'message' => ...].
     */
    public function findCustomerOrgByEmail(string $email): ?array
    {
        if ($email === '') return null;
        $customers = $this->getCustomers();
        if (self::isError($customers)) {
            return ['error' => true, 'message' => EnhanceLang::t('org.customers_failed') . ' ' . self::errorSummary($customers)];
        }
        $matches = [];
        foreach ($customers['items'] as $customer) {
            if (empty($customer['id']) || strcasecmp((string) ($customer['ownerEmail'] ?? ''), $email) !== 0) continue;
            $org = isset($customer['parentId']) ? $customer : $this->getOrg((string) $customer['id']);
            if (strtolower((string) ($org['parentId'] ?? '')) === $this->masterOrgId && ($org['status'] ?? '') !== 'deleted') {
                $matches[] = $customer;
            }
        }
        if (count($matches) > 1) {
            return ['error' => true, 'message' => EnhanceLang::t('org.many_orgs_email')];
        }
        return $matches[0] ?? null;
    }

    // Subscriptions
    public function getSubscription(string $orgId, string $subId): array
    {
        return $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId));
    }

    public function createSubscription(string $orgId, int $planId, string $friendlyName = ''): array
    {
        $body = ['planId' => $planId];
        if ($friendlyName !== '') $body['friendlyName'] = $friendlyName;
        return $this->send('POST', "/orgs/{$this->masterOrgId}/customers/" . rawurlencode($orgId) . '/subscriptions', $body);
    }

    public static function friendlyNameForService(int $serviceId): string
    {
        return self::FRIENDLY_NAME_PREFIX . $serviceId;
    }

    /**
     * Active subscription created earlier for this WHMCS service (friendlyName
     * "WHMCS #<serviceid>"). Makes CreateAccount idempotent.
     */
    public function findSubscriptionForService(string $orgId, int $serviceId): ?array
    {
        $subs = $this->getCustomerSubscriptions($orgId);
        if (self::isError($subs)) {
            throw new Exception(EnhanceLang::t('sub.list_failed', ['detail' => self::errorSummary($subs)]));
        }
        $name = self::friendlyNameForService($serviceId);
        foreach ($subs['items'] as $sub) {
            if (($sub['friendlyName'] ?? '') === $name && ($sub['status'] ?? '') !== 'deleted' && !empty($sub['id'])) {
                return $sub;
            }
        }
        return null;
    }

    /** Current month transfer in bytes, or null when unavailable. */
    public function getSubscriptionBandwidth(string $orgId, string $subId): ?int
    {
        $resp = $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId) . '/bandwidth');
        if (self::isError($resp)) return null;
        foreach (['_raw', 'total', 'bytes', 'usage', 'value'] as $key) {
            if (isset($resp[$key]) && is_numeric($resp[$key])) return (int) $resp[$key];
        }
        return null;
    }

    public function calculateResourceUsage(string $orgId, string $subId): array
    {
        return $this->send('PUT', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId) . '/calculate-resource-usage');
    }

    /**
     * Extracts usage and limit (bytes) of a resource from a subscription.
     * A missing total means unlimited.
     */
    public static function resourceUsage(array $subscription, string $name): array
    {
        foreach ((array) ($subscription['resources'] ?? []) as $res) {
            if (($res['name'] ?? '') === $name) {
                return ['usage' => isset($res['usage']) ? (int) $res['usage'] : null, 'total' => isset($res['total']) ? (int) $res['total'] : null];
            }
        }
        return ['usage' => null, 'total' => null];
    }

    public function updateSubscriptionPlan(string $orgId, string $subId, int $planId): array
    {
        return $this->send('PATCH', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId), ['planId' => $planId]);
    }

    public function setSubscriptionSuspended(string $orgId, string $subId, bool $suspended): array
    {
        return $this->send('PATCH', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId), ['isSuspended' => $suspended]);
    }

    public function deleteSubscription(string $orgId, string $subId, bool $force = false): array
    {
        return $this->send('DELETE', '/orgs/' . rawurlencode($orgId) . '/subscriptions/' . rawurlencode($subId) . '?force=' . ($force ? 'true' : 'false'));
    }

    // Plans
    public function getPlans(): array
    {
        return $this->listAll("/orgs/{$this->masterOrgId}/plans");
    }

    // Logins & members
    public function createLogin(string $orgId, string $email, string $password, string $name): array
    {
        return $this->send('POST', '/logins?orgId=' . rawurlencode($orgId), ['email' => $email, 'password' => $password, 'name' => $name], [$password]);
    }

    /**
     * Makes the login the owner of the organisation.
     *
     * Enhance only lets an access token create an owner by creating the member
     * with the Owner role (as the official Enhance module does):
     *   - PUT /orgs/{org_id}/owner answers 403 to access tokens;
     *   - PUT /orgs/{org_id}/members/{id} does not accept the Owner role.
     * So: an existing member is first promoted with PUT .../owner; when that is
     * refused and the organisation has no owner yet, the member (a SuperAdmin
     * left by an interrupted attempt) is deleted and recreated with the Owner
     * role. The login is kept. If the recreation fails, the SuperAdmin member is
     * restored. Success is only reported after GET /orgs/{org_id} shows the owner.
     */
    public function linkMemberAsOwner(string $orgId, string $loginId): array
    {
        $org = rawurlencode($orgId);
        $orgInfo = $this->getOrg($orgId);
        if (self::isError($orgInfo)) {
            return $orgInfo;
        }
        $currentOwner = (string) ($orgInfo['ownerId'] ?? '');

        $member = $this->findMemberByLogin($orgId, $loginId);
        if (isset($member['code'])) {
            return $member;
        }
        if ($member !== null && strcasecmp($currentOwner, (string) $member['id']) === 0) {
            return ['id' => (string) $member['id'], '_httpCode' => 200];
        }
        // Never replace an existing owner automatically.
        if ($currentOwner !== '') {
            return ['code' => 'owner_exists', 'detail' => EnhanceLang::t('org.owner_refused', ['detail' => 'ownerId ' . $currentOwner]), '_httpCode' => 0];
        }

        if ($member === null) {
            $created = $this->createOwnerMember($orgId, $loginId);
            if (self::isError($created)) {
                return $created;
            }
            $member = ['id' => (string) $created['id'], 'roles' => $created['roles']];
            if ($created['roles'] === ['Owner'] && $this->ownerIs($orgId, (string) $created['id'])) {
                return ['id' => (string) $created['id'], '_httpCode' => 200];
            }
        }
        $memberId = (string) $member['id'];

        $owner = $this->send('PUT', "/orgs/{$org}/owner", ['memberId' => $memberId]);
        if (self::isError($owner)) {
            $refused = in_array((int) ($owner['_httpCode'] ?? 0), [400, 403], true);
            $onlySuperAdmin = array_values((array) ($member['roles'] ?? [])) === ['SuperAdmin'];
            if (!$refused || !$onlySuperAdmin) {
                return ['code' => $owner['code'] ?? 'owner_refused', 'detail' => EnhanceLang::t('org.owner_refused', ['detail' => self::errorSummary($owner)]), '_httpCode' => 0];
            }

            $deleted = $this->send('DELETE', "/orgs/{$org}/members/" . rawurlencode($memberId));
            if (self::isError($deleted)) {
                return ['code' => 'owner_refused', 'detail' => EnhanceLang::t('org.owner_refused', ['detail' => self::errorSummary($owner)]) . ' ' . self::errorSummary($deleted), '_httpCode' => 0];
            }
            $created = $this->send('POST', "/orgs/{$org}/members", ['loginId' => $loginId, 'roles' => ['Owner']]);
            if (self::isError($created) || empty($created['id'])) {
                $this->send('POST', "/orgs/{$org}/members", ['loginId' => $loginId, 'roles' => ['SuperAdmin']]);
                return ['code' => 'owner_refused', 'detail' => EnhanceLang::t('org.owner_recreate_failed', ['detail' => self::errorSummary($created)]), '_httpCode' => 0];
            }
            $memberId = (string) $created['id'];
        }

        if (!$this->ownerIs($orgId, $memberId)) {
            return ['code' => 'owner_not_set', 'detail' => EnhanceLang::t('org.owner_not_confirmed', ['member' => $memberId]), '_httpCode' => 0];
        }
        return ['id' => $memberId, '_httpCode' => 200];
    }

    /** Member of $orgId linked to $loginId, null, or an API error array. */
    private function findMemberByLogin(string $orgId, string $loginId): ?array
    {
        $members = $this->getMembers($orgId);
        if (self::isError($members)) {
            return $members + ['code' => 'members_failed'];
        }
        foreach ($members['items'] as $m) {
            if (strcasecmp((string) ($m['loginId'] ?? ''), $loginId) === 0 && !empty($m['id'])) {
                return $m;
            }
        }
        return null;
    }

    /** Creates the member with the Owner role; falls back to SuperAdmin when refused. */
    private function createOwnerMember(string $orgId, string $loginId): array
    {
        $org = rawurlencode($orgId);
        foreach ([['Owner'], ['SuperAdmin']] as $roles) {
            $created = $this->send('POST', "/orgs/{$org}/members", ['loginId' => $loginId, 'roles' => $roles]);
            if (!self::isError($created) && !empty($created['id'])) {
                return $created + ['roles' => $roles];
            }
            if (!in_array((int) ($created['_httpCode'] ?? 0), [400, 403, 422], true)) {
                break;
            }
        }
        return self::isError($created) ? $created : ['code' => 'member_not_created', 'detail' => EnhanceLang::t('org.no_member_id'), '_httpCode' => (int) ($created['_httpCode'] ?? 0)];
    }

    /** True when GET /orgs/{org_id} reports $memberId as the owner. */
    private function ownerIs(string $orgId, string $memberId): bool
    {
        $org = $this->getOrg($orgId);
        return !self::isError($org) && strcasecmp((string) ($org['ownerId'] ?? ''), $memberId) === 0;
    }

    /**
     * Logins created by this module are remembered for 30 days, so a retry after
     * a failure still sends the password-setting link (the generated password is
     * never shown to anyone).
     */
    private static function rememberCreatedLogin(string $loginId): void
    {
        if (class_exists('WHMCS\\TransientData')) {
            \WHMCS\TransientData::getInstance()->store('enhance_created_login_' . md5(strtolower($loginId)), '1', 30 * 86400);
        }
    }

    private static function wasCreatedByModule(string $loginId): bool
    {
        if (!class_exists('WHMCS\\TransientData')) return false;
        return (string) \WHMCS\TransientData::getInstance()->retrieve('enhance_created_login_' . md5(strtolower($loginId))) === '1';
    }

    public function getMembers(string $orgId): array
    {
        return $this->listAll('/orgs/' . rawurlencode($orgId) . '/members');
    }

    public function findOwnerMember(string $orgId): ?array
    {
        $org = $this->getOrg($orgId);
        if (!self::isError($org) && !empty($org['ownerId'])) {
            $member = $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/members/' . rawurlencode((string) $org['ownerId']));
            if (!self::isError($member) && !empty($member['id'])) {
                return $member;
            }
        }
        $members = $this->getMembers($orgId);
        foreach ($members['items'] ?? [] as $m) {
            if (in_array('Owner', (array) ($m['roles'] ?? []), true)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Logins of a realm (realm ids are org ids). The customers of the master
     * organisation log in to its realm, so lookups are limited to it: logins of
     * other resellers' realms with the same email are not mixed in.
     * Without $realmId, every login of the control panel is returned.
     */
    public function getLogins(?string $realmId = null): array
    {
        return $this->listAll('/logins' . ($realmId !== null ? '?realmId=' . rawurlencode($realmId) : ''));
    }

    /**
     * Logins with $email: first in the master organisation's realm, then, only
     * when nothing is found there, in the whole panel.
     * @return array list of logins, or an API error array (with "code")
     */
    public function findLoginsByEmail(string $email, bool $wholePanel = false): array
    {
        $logins = $this->getLogins($wholePanel ? null : $this->masterOrgId);
        if (self::isError($logins)) {
            return $logins;
        }
        $matching = [];
        foreach ($logins['items'] as $login) {
            if (!empty($login['id']) && strcasecmp(trim((string) ($login['email'] ?? '')), $email) === 0) {
                $matching[] = $login;
            }
        }
        return $matching;
    }

    /**
     * Asks Enhance to email its own password-setting link to $email
     * (PUT /login/password-recovery, "startPasswordRecovery"). Enhance answers
     * 200 even when no login has this email. POST on the same path finishes a
     * recovery with the emailed secret and must not be used here.
     */
    public function triggerPasswordRecovery(string $email): array
    {
        return $this->send('PUT', '/login/password-recovery', ['email' => $email]);
    }

    /**
     * One-time SSO URL for the organisation owner:
     * GET /orgs/{orgId}/members/{memberId}/sso
     * @return array{url:?string,error:string}
     */
    public function ownerSsoUrl(string $orgId): array
    {
        $owner = $this->findOwnerMember($orgId);
        if (!$owner || empty($owner['id'])) {
            return ['url' => null, 'error' => EnhanceLang::t('sso.no_owner')];
        }
        // Only an explicit "isActive: false" blocks SSO (older versions omit the field).
        if (array_key_exists('isActive', $owner) && $owner['isActive'] === false) {
            return ['url' => null, 'error' => EnhanceLang::t('sso.owner_inactive')];
        }

        $link = $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/members/' . rawurlencode((string) $owner['id']) . '/sso');
        if (self::isError($link)) {
            return ['url' => null, 'error' => EnhanceLang::t('sso.failed', ['detail' => self::errorSummary($link)])];
        }

        $url = '';
        foreach (['_raw', 'url', 'loginUrl', 'link'] as $key) {
            if (!empty($link[$key]) && is_string($link[$key])) {
                $url = trim($link[$key], " \t\n\r\0\x0B\"");
                break;
            }
        }
        if (stripos($url, 'https://') !== 0) {
            return ['url' => null, 'error' => EnhanceLang::t('sso.invalid_url')];
        }
        return ['url' => $url, 'error' => ''];
    }

    /** @deprecated 1.2.x compatibility; use ownerSsoUrl(). */
    public function getOwnerSsoUrl(string $orgId): ?string
    {
        return $this->ownerSsoUrl($orgId)['url'];
    }

    // Websites
    public function getWebsite(string $orgId, string $websiteId): array
    {
        return $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode($websiteId));
    }

    public function listWebsitesBySubscription(string $orgId, string $subId): array
    {
        return $this->listAll('/orgs/' . rawurlencode($orgId) . '/websites?subscriptionId=' . rawurlencode($subId));
    }

    public function listWebsites(string $orgId): array
    {
        return $this->listAll('/orgs/' . rawurlencode($orgId) . '/websites');
    }

    public function listWebsiteDomains(string $orgId, string $websiteId): array
    {
        return $this->listAll('/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode($websiteId) . '/domains');
    }

    public function createWebsite(string $orgId, string $subId, string $domain, string $serverGroupId = ''): array
    {
        if (!ctype_digit($subId) || (int) $subId < 1) {
            return ['code' => 'invalid_subscription_id', 'detail' => EnhanceLang::t('sub.invalid_id'), '_httpCode' => 0];
        }
        $body = ['domain' => $domain, 'subscriptionId' => (int) $subId];
        if ($serverGroupId !== '') {
            $body['serverGroupId'] = $serverGroupId;
        }
        return $this->send('POST', '/orgs/' . rawurlencode($orgId) . '/websites?kind=normal', $body);
    }

    public function deleteWebsite(string $orgId, string $websiteId): array
    {
        return $this->send('DELETE', '/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode($websiteId));
    }

    /** Server groups of the cluster (GET /servers/groups, MO admins only). */
    public function getServerGroups(): array
    {
        return $this->send('GET', '/servers/groups');
    }

    // -------------------------------------------------------------------------
    // Website tools for the client area (all scoped to one website)
    // -------------------------------------------------------------------------

    private function websitePath(string $orgId, string $websiteId): string
    {
        return '/orgs/' . rawurlencode($orgId) . '/websites/' . rawurlencode($websiteId);
    }

    /** Domains of the website with their SSL certificate (cn, issued, expires, issuer). */
    public function getWebsiteSsl(string $orgId, string $websiteId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/domains?withSsl=true');
    }

    public function listWebsiteEmails(string $orgId, string $websiteId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/emails?sortBy=address&sortOrder=asc');
    }

    public function listWebsiteDatabases(string $orgId, string $websiteId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/mysql-dbs');
    }

    public function listWebsiteApps(string $orgId, string $websiteId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/apps');
    }

    public function listWebsiteBackups(string $orgId, string $websiteId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/backups');
    }

    /** Restores files and databases (and optionally all mailboxes) from a backup. */
    public function restoreWebsiteBackup(string $orgId, string $websiteId, int $backupId, bool $includeEmails): array
    {
        $options = ['restoreFiles' => true];
        if ($includeEmails) {
            $options['restoreAllEmails'] = true;
        }
        return $this->send('PUT', $this->websitePath($orgId, $websiteId) . '/backups/' . $backupId, $options);
    }

    public function getRestoreStatus(string $orgId, string $websiteId, int $backupId): array
    {
        return $this->send('GET', $this->websitePath($orgId, $websiteId) . '/backups/' . $backupId . '/restore_status');
    }

    /** Roundcube (webmail) login link for one mailbox of the website. */
    public function webmailSsoUrl(string $orgId, string $websiteId, string $email): array
    {
        return self::ssoResult($this->send('GET', $this->websitePath($orgId, $websiteId) . '/emails/' . rawurlencode($email) . '/sso'));
    }

    /** phpMyAdmin login link for one database of the website. */
    public function phpMyAdminSsoUrl(string $orgId, string $websiteId, string $database): array
    {
        return self::ssoResult($this->send('GET', $this->websitePath($orgId, $websiteId) . '/mysql-dbs/' . rawurlencode($database) . '/sso'));
    }

    /**
     * WordPress admin login link: the app's default WordPress user, otherwise the
     * first administrator, otherwise the first user.
     */
    public function wordpressSsoUrl(string $orgId, string $websiteId, string $appId, ?int $defaultUserId = null): array
    {
        $base = $this->websitePath($orgId, $websiteId) . '/apps/' . rawurlencode($appId) . '/wordpress/users';
        $userId = $defaultUserId;
        if ($userId === null) {
            $users = $this->send('GET', $base);
            if (self::isError($users)) {
                return ['url' => null, 'error' => self::errorSummary($users)];
            }
            $first = null;
            foreach ((array) ($users['items'] ?? []) as $user) {
                if (!isset($user['id'])) continue;
                $first = $first ?? (int) $user['id'];
                if (strtolower((string) ($user['role'] ?? '')) === 'administrator') {
                    $userId = (int) $user['id'];
                    break;
                }
            }
            $userId = $userId ?? $first;
        }
        if ($userId === null) {
            return ['url' => null, 'error' => EnhanceLang::t('sso.no_wp_user')];
        }
        return self::ssoResult($this->send('GET', $base . '/' . $userId . '/sso'));
    }

    /**
     * Normalises an SSO answer: a JSON string, a {url} object or a redirect.
     * @return array{url:?string,error:string}
     */
    private static function ssoResult(array $resp): array
    {
        $url = '';
        if (($resp['code'] ?? '') === 'redirect' && !empty($resp['_location'])) {
            $url = (string) $resp['_location'];
        } elseif (!self::isError($resp)) {
            foreach (['_raw', 'url', 'link'] as $key) {
                if (!empty($resp[$key]) && is_string($resp[$key])) {
                    $url = trim($resp[$key], " \t\n\r\0\x0B\"");
                    break;
                }
            }
        } else {
            return ['url' => null, 'error' => self::errorSummary($resp)];
        }
        return stripos($url, 'https://') === 0
            ? ['url' => $url, 'error' => '']
            : ['url' => null, 'error' => EnhanceLang::t('sso.invalid_url')];
    }

    // Emails
    public function getEmails(string $orgId): array
    {
        return $this->send('GET', '/orgs/' . rawurlencode($orgId) . '/emails?limit=1&offset=0');
    }

    // =========================================================================
    // Association storage (see EnhanceStore)
    // =========================================================================

    public function saveClientOrgId(int $clientId, string $orgId): void
    {
        EnhanceStore::setClientOrg($clientId, $this->requireServerId(), $orgId);
    }

    public function clearClientOrgId(int $clientId): void
    {
        EnhanceStore::clearClientOrg($clientId, $this->requireServerId());
    }

    public function getClientOrgId(int $clientId): ?string
    {
        return $this->serverId > 0 ? EnhanceStore::clientOrg($clientId, $this->serverId) : null;
    }

    /** $packageId is kept for compatibility; the association no longer depends on the product. */
    public function getServiceSubscriptionId(int $packageId, int $serviceId): ?string
    {
        $row = EnhanceStore::service($serviceId);
        if (!$row || $row->subscription_id === null || $row->subscription_id === '') return null;
        if ($this->serverId > 0 && (int) $row->server_id !== $this->serverId) {
            throw new Exception(EnhanceLang::t('store.service_other_server', ['service' => $serviceId, 'server' => (int) $row->server_id]));
        }
        return (string) $row->subscription_id;
    }

    public function getServiceWebsiteId(int $serviceId): ?string
    {
        $row = EnhanceStore::service($serviceId);
        return ($row && $row->website_id) ? (string) $row->website_id : null;
    }

    public function findServiceBySubscriptionId(string $subId): int
    {
        return $subId === '' ? 0 : EnhanceStore::serviceBySubscription($this->requireServerId(), $subId);
    }

    public function saveServiceSubscriptionId(int $packageId, int $serviceId, $subId, ?string $websiteId = null): void
    {
        $serverId = $this->requireServerId();
        $clientId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
        $orgId = $clientId ? EnhanceStore::clientOrg($clientId, $serverId) : null;
        EnhanceStore::setService($serviceId, $serverId, $orgId, (string) $subId, $websiteId);
    }

    public function saveServiceWebsiteId(int $serviceId, ?string $websiteId): void
    {
        EnhanceStore::setWebsite($serviceId, $websiteId);
    }

    public function clearServiceSubscriptionId(int $packageId, int $serviceId): void
    {
        EnhanceStore::clearService($serviceId);
    }

    private function requireServerId(): int
    {
        if ($this->serverId <= 0) {
            throw new Exception(EnhanceLang::t('api.server_unknown'));
        }
        return $this->serverId;
    }
}
