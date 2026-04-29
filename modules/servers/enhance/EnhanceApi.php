<?php
/**
 * Enhance API wrapper for WHMCS
 *
 * Credentials are injected per-instance from WHMCS server $params so that
 * multiple Enhance servers can coexist in the same WHMCS installation.
 * Credentials are injected per instance — multiple servers are supported.
 */

require_once __DIR__ . '/config.php';

use WHMCS\Database\Capsule;

class EnhanceApi
{
    // Custom field / product field names
    const FIELD_CLIENT_ORG_ID    = 'EnhanceOrgId';
    const FIELD_SUBSCRIPTION_ID  = 'SUBSCRIPTION_ID';
    const EMAIL_TEMPLATE_NAME    = 'Enhance Panel Access';

    private string $host;
    private string $masterOrgId;
    private string $apiKey;
    public  bool   $debug;

    /** ID of the "EnhanceOrgId" row in tblcustomfields */
    public int $clientOrgFieldId;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(string $host, string $masterOrgId, string $apiKey)
    {
        $this->host        = rtrim($host, '/');
        $this->masterOrgId = $masterOrgId;
        $this->apiKey      = $apiKey;
        $this->debug       = defined('ENHANCE_DEBUG') && ENHANCE_DEBUG;

        $this->clientOrgFieldId = $this->resolveClientOrgField();
        $this->ensureEmailTemplate();
    }

    // -------------------------------------------------------------------------
    // Schema bootstrapping (lazy, cheap)
    // -------------------------------------------------------------------------

    private function resolveClientOrgField(): int
    {
        $row = Capsule::table('tblcustomfields')
            ->where('fieldname', self::FIELD_CLIENT_ORG_ID)
            ->where('type', 'client')
            ->value('id');

        if (is_numeric($row)) {
            return (int) $row;
        }

        Capsule::table('tblcustomfields')->insert([
            'fieldname'   => self::FIELD_CLIENT_ORG_ID,
            'fieldtype'   => 'text',
            'adminonly'   => 'on',
            'description' => 'Enhance organisation UUID / Enhance organisation UUID',
            'type'        => 'client',
        ]);

        return (int) Capsule::connection()->getPdo()->lastInsertId();
    }

    private function ensureEmailTemplate(): void
    {
        $exists = Capsule::table('tblemailtemplates')
            ->where('type', 'general')
            ->where('name', self::EMAIL_TEMPLATE_NAME)
            ->exists();

        if ($exists) return;

        Capsule::table('tblemailtemplates')->insert([
            'type'     => 'general',
            'name'     => self::EMAIL_TEMPLATE_NAME,
            'subject'  => 'Access to Your Hosting Panel / Acesso ao Painel de Hospedagem',
            'message'  => '<p>Hello {$client_name},</p>'
                        . '<p><strong>Panel / Painel:</strong> https://{$urlPainel}<br>'
                        . '<strong>Email:</strong> {$email}<br>'
                        . '<strong>Password / Senha:</strong> {$password}</p>'
                        . '<p>Please change your password on first login. / Por favor altere a password no primeiro acesso.</p>',
            'custom'   => '1',
            'disabled' => '0',
        ]);
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    public function send(string $method, string $path, array $body = []): array
    {
        $url = "https://{$this->host}/api{$path}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
                "Authorization: Bearer {$this->apiKey}",
            ],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->debug) {
            $this->writeLog(['method' => $method, 'url' => $url, 'status' => $httpCode, 'response' => $raw]);
        }

        logModuleCall('enhance', "{$method} {$path}", $body, $raw, $raw, []);

        if ($curlErr) {
            return ['code' => 'curl_error', 'message' => $curlErr, '_httpCode' => $httpCode];
        }

        $decoded = json_decode((string) $raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if ($httpCode >= 400 && empty($decoded['code'])) {
                $decoded['code'] = 'http_' . $httpCode;
            }
            $decoded['_httpCode'] = $httpCode;
            return $decoded;
        }

        if ($httpCode >= 400) {
            return ['code' => 'http_' . $httpCode, 'message' => trim((string) $raw), '_httpCode' => $httpCode];
        }

        return ['_raw' => trim((string) $raw), '_httpCode' => $httpCode];
    }
    private function writeLog(array $entry): void
    {
        $dir = __DIR__ . '/logs/' . date('d-m-Y');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents("{$dir}/enhance.log", '[' . date('H:i:s') . '] ' . json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // -------------------------------------------------------------------------
    // Password generator
    // -------------------------------------------------------------------------

    public function generatePassword(int $length = 20): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }

    // =========================================================================
    // API calls
    // =========================================================================

    // Licence
    public function getLicense(): array { return $this->send('GET', '/licence'); }

    // Orgs
    public function getOrg(string $orgId): array    { return $this->send('GET', "/orgs/{$orgId}"); }
    public function deleteOrg(string $orgId): array { return $this->send('DELETE', "/orgs/{$orgId}"); }

    public function setOrgSuspended(string $orgId, bool $suspended): array
    {
        return $this->send('PATCH', "/orgs/{$orgId}", ['isSuspended' => $suspended]);
    }

    public function updateOrgName(string $orgId, string $name): array
    {
        return $this->send('PATCH', "/orgs/{$orgId}", ['name' => $name]);
    }

    // Customers
    public function getCustomers(int $limit = 500): array
    {
        return $this->send('GET', "/orgs/{$this->masterOrgId}/customers?limit={$limit}");
    }

    public function getCustomerSubscriptions(string $orgId, int $limit = 250): array
    {
        return $this->send('GET', "/orgs/{$this->masterOrgId}/customers/{$orgId}/subscriptions?limit={$limit}");
    }

    /**
     * Creates customer org + login + owner link + welcome email.
     * Rolls back the org if login creation fails.
     * PT: Cria org + login + owner + email. Rollback da org se o login falhar.
     */
    public function createCustomerOrg(int $whmcsClientId, string $name, string $email, string $company): array
    {
        $orgName = ($company !== '') ? $company : $name;
        $org     = $this->send('POST', "/orgs/{$this->masterOrgId}/customers", ['name' => $orgName]);

        if (empty($org['id'])) {
            return ['error' => true, 'message' => 'Could not create organisation: ' . ($org['detail'] ?? json_encode($org))];
        }

        $orgId   = $org['id'];
        $newPass = $this->generatePassword();
        $login   = $this->createLogin($orgId, $email, $newPass, $name);

        if (empty($login['id'])) {
            $this->deleteOrg($orgId);
            return ['error' => true, 'message' => 'Could not create login (org rolled back): ' . ($login['detail'] ?? json_encode($login))];
        }

        $this->linkMemberAsOwner($orgId, $login['id']);

        // Persist in WHMCS / Guardar no WHMCS
        Capsule::table('tblcustomfieldsvalues')
            ->where(['fieldid' => $this->clientOrgFieldId, 'relid' => $whmcsClientId])
            ->delete();
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => $this->clientOrgFieldId,
            'relid'   => $whmcsClientId,
            'value'   => $orgId,
        ]);

        localAPI('SendEmail', [
            'messagename' => self::EMAIL_TEMPLATE_NAME,
            'id'          => $whmcsClientId,
            'customvars'  => base64_encode(serialize(['email' => $email, 'password' => $newPass, 'urlPainel' => $this->host])),
        ]);

        return ['error' => false, 'orgId' => $orgId];
    }

    /**
     * Ensures the WHMCS client has a valid Enhance org and an owner member.
     */
    public function syncCustomerFromWhmcs(int $whmcsClientId, string $name, string $email, string $company = ''): array
    {
        $orgName = trim($company) !== '' ? trim($company) : trim($name);
        if ($orgName === '') {
            $orgName = $email;
        }

        $orgId = $this->getClientOrgId($whmcsClientId);
        $created = false;
        $changed = [];

        if ($orgId) {
            $org = $this->getOrg($orgId);
            if (!empty($org['code'])) {
                $orgId = null;
                $changed[] = 'stored_org_missing';
            } elseif (($org['name'] ?? '') !== $orgName) {
                $resp = $this->updateOrgName($orgId, $orgName);
                if (empty($resp['code'])) {
                    $changed[] = 'org_name_updated';
                }
            }
        }

        if (!$orgId) {
            $match = $this->findCustomerOrgByEmailOrName($email, $orgName);
            if ($match) {
                $orgId = $match['id'];
                $this->saveClientOrgId($whmcsClientId, $orgId);
                $changed[] = 'org_relinked';
            }
        }

        if (!$orgId) {
            $result = $this->createCustomerOrg($whmcsClientId, $name, $email, $company);
            if (!empty($result['error'])) {
                return ['success' => false, 'message' => $result['message'] ?? 'Could not create organisation'];
            }
            $orgId = $result['orgId'];
            $created = true;
            $changed[] = 'org_created';
        }

        $owner = $this->findOwnerMember($orgId);
        if (!$owner) {
            $loginResult = $this->createLogin($orgId, $email, $this->generatePassword(), $name ?: $email);
            if (!empty($loginResult['id'])) {
                $member = $this->linkMemberAsOwner($orgId, $loginResult['id']);
                if (empty($member['code'])) {
                    $changed[] = 'owner_created';
                }
            } else {
                $byEmail = $this->findLoginByEmail($email);
                if (!empty($byEmail['id'])) {
                    $member = $this->linkMemberAsOwner($orgId, $byEmail['id']);
                    if (empty($member['code'])) {
                        $changed[] = 'existing_login_linked_as_owner';
                    }
                }
            }
        }

        return ['success' => true, 'orgId' => $orgId, 'created' => $created, 'changed' => $changed];
    }

    public function findCustomerOrgByEmailOrName(string $email, string $name): ?array
    {
        $customers = $this->getCustomers();
        foreach ($customers['items'] ?? [] as $customer) {
            if (strcasecmp((string)($customer['ownerEmail'] ?? ''), $email) === 0) {
                return $customer;
            }
            if ($name !== '' && strcasecmp((string)($customer['name'] ?? ''), $name) === 0) {
                return $customer;
            }
        }
        return null;
    }

    // Subscriptions
    public function getSubscription(string $orgId, string $subId): array
    {
        return $this->send('GET', "/orgs/{$orgId}/subscriptions/{$subId}");
    }

    public function createSubscription(string $orgId, int $planId): array
    {
        return $this->send('POST', "/orgs/{$this->masterOrgId}/customers/{$orgId}/subscriptions", ['planId' => $planId]);
    }

    public function updateSubscriptionPlan(string $orgId, string $subId, int $planId): array
    {
        return $this->send('PATCH', "/orgs/{$orgId}/subscriptions/{$subId}", ['planId' => $planId]);
    }

    public function setSubscriptionSuspended(string $orgId, string $subId, bool $suspended): array
    {
        return $this->send('PATCH', "/orgs/{$orgId}/subscriptions/{$subId}", ['isSuspended' => $suspended]);
    }

    public function deleteSubscription(string $orgId, string $subId, bool $force = false): array
    {
        return $this->send('DELETE', "/orgs/{$orgId}/subscriptions/{$subId}?force=" . ($force ? 'true' : 'false'));
    }

    // Plans
    public function getPlans(): array
    {
        return $this->send('GET', "/orgs/{$this->masterOrgId}/plans");
    }

    // Logins & Members
    public function createLogin(string $orgId, string $email, string $password, string $name): array
    {
        return $this->send('POST', "/logins?orgId={$orgId}", ['email' => $email, 'password' => $password, 'name' => $name]);
    }

    public function linkMemberAsOwner(string $orgId, string $loginId): array
    {
        return $this->send('POST', "/orgs/{$orgId}/members", ['loginId' => $loginId, 'roles' => ['Owner']]);
    }

    public function getMembers(string $orgId): array
    {
        return $this->send('GET', "/orgs/{$orgId}/members?limit=250");
    }

    public function findOwnerMember(string $orgId): ?array
    {
        $members = $this->getMembers($orgId);
        foreach ($members['items'] ?? [] as $m) {
            if (in_array('Owner', $m['roles'] ?? [], true)) {
                return $m;
            }
        }
        return null;
    }

    public function getLogins(int $limit = 500): array
    {
        return $this->send('GET', "/logins?limit={$limit}");
    }

    public function findLoginByEmail(string $email): ?array
    {
        $logins = $this->getLogins();
        foreach ($logins['items'] ?? [] as $login) {
            if (strcasecmp((string)($login['email'] ?? ''), $email) === 0) {
                return $login;
            }
        }
        return null;
    }

    public function triggerPasswordRecovery(string $email): array
    {
        return $this->send('PUT', '/login/password-recovery', ['email' => $email]);
    }

    /**
     * Returns a one-time SSO URL for the org owner.
     * Tries ssoToken first, falls back to session-link endpoint.
     * PT: URL SSO one-time para o owner. Tenta ssoToken primeiro, fallback para session link.
     */
    public function getOwnerSsoUrl(string $orgId): ?string
    {
        $members = $this->getMembers($orgId);
        $ownerId = null;

        foreach ($members['items'] ?? [] as $m) {
            if (in_array('Owner', $m['roles'] ?? [], true)) {
                $ownerId = $m['id'];
                break;
            }
        }

        if (!$ownerId) return null;

        // Preferred: ssoToken
        $token = $this->send('GET', "/orgs/{$orgId}/members/{$ownerId}/ssoToken");
        if (!empty($token['ssoToken'])) {
            return "https://{$this->host}/login?ssoToken=" . urlencode($token['ssoToken']);
        }

        // Fallback: session link
        $link = $this->send('GET', "/orgs/{$orgId}/members/{$ownerId}/login");
        if (!empty($link['_raw'])) return trim($link['_raw'], '"');
        if (!empty($link['url']))  return $link['url'];

        return null;
    }

    // Websites
    public function getWebsite(string $orgId, string $websiteId): array
    {
        return $this->send('GET', "/orgs/{$orgId}/websites/{$websiteId}");
    }

    public function listWebsitesBySubscription(string $orgId, string $subId): array
    {
        return $this->send('GET', "/orgs/{$orgId}/websites?subscriptionId={$subId}&limit=500");
    }

    public function listWebsites(string $orgId, int $limit = 500): array
    {
        return $this->send('GET', "/orgs/{$orgId}/websites?limit={$limit}");
    }

    public function listWebsiteDomains(string $orgId, string $websiteId, int $limit = 500): array
    {
        return $this->send('GET', "/orgs/{$orgId}/websites/{$websiteId}/domains?limit={$limit}");
    }

    public function createWebsite(string $orgId, string $subId, string $domain): array
    {
        return $this->send('POST', "/orgs/{$orgId}/websites?kind=normal", ['domain' => $domain, 'subscriptionId' => $subId]);
    }

    public function deleteWebsite(string $orgId, string $websiteId): array
    {
        return $this->send('DELETE', "/orgs/{$orgId}/websites/{$websiteId}");
    }

    // Emails
    public function getEmails(string $orgId): array
    {
        return $this->send('GET', "/orgs/{$orgId}/emails");
    }

    // =========================================================================
    // WHMCS custom field helpers
    // =========================================================================

    public function saveClientOrgId(int $clientId, string $orgId): void
    {
        Capsule::table('tblcustomfieldsvalues')
            ->where(['fieldid' => $this->clientOrgFieldId, 'relid' => $clientId])
            ->delete();
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => $this->clientOrgFieldId,
            'relid'   => $clientId,
            'value'   => $orgId,
        ]);
    }

    public function getClientOrgId(int $clientId): ?string
    {
        $val = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $this->clientOrgFieldId)
            ->where('relid', $clientId)
            ->value('value');

        return ($val !== null && $val !== '') ? $val : null;
    }

    public function getServiceSubscriptionId(int $packageId, int $serviceId): ?string
    {
        $fieldId = Capsule::table('tblcustomfields')
            ->where('relid', $packageId)
            ->where('fieldname', self::FIELD_SUBSCRIPTION_ID)
            ->value('id');

        if (!$fieldId) return null;

        $val = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->value('value');

        return ($val !== null && $val !== '') ? $val : null;
    }

    public function saveServiceSubscriptionId(int $packageId, int $serviceId, string $subId): void
    {
        $fieldId = Capsule::table('tblcustomfields')
            ->where('relid', $packageId)
            ->where('fieldname', self::FIELD_SUBSCRIPTION_ID)
            ->value('id');

        if (!$fieldId) {
            Capsule::table('tblcustomfields')->insert([
                'type'      => 'product',
                'relid'     => $packageId,
                'fieldname' => self::FIELD_SUBSCRIPTION_ID,
                'fieldtype' => 'text',
                'adminonly' => 'on',
            ]);
            $fieldId = (int) Capsule::connection()->getPdo()->lastInsertId();
        }

        Capsule::table('tblcustomfieldsvalues')
            ->where(['fieldid' => $fieldId, 'relid' => $serviceId])
            ->delete();
        Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $subId]);
    }

    public function clearServiceSubscriptionId(int $packageId, int $serviceId): void
    {
        $fieldId = Capsule::table('tblcustomfields')
            ->where('relid', $packageId)
            ->where('fieldname', self::FIELD_SUBSCRIPTION_ID)
            ->value('id');

        if ($fieldId) {
            Capsule::table('tblcustomfieldsvalues')
                ->where(['fieldid' => $fieldId, 'relid' => $serviceId])
                ->delete();
        }
    }
}
