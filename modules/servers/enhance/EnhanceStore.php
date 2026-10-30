<?php
/**
 * Enhance WHMCS Module - association storage (v1.3.0)
 *
 * Replaces the legacy custom fields as the source of truth:
 *   mod_enhance_clients  : WHMCS client  <-> Enhance organisation, per server
 *   mod_enhance_services : WHMCS service <-> Enhance subscription/website, per server
 *
 * The client custom field "EnhanceOrgId" is still written for display in the
 * admin client profile, but it is no longer read as the primary source.
 * The per-product "SUBSCRIPTION_ID" field is only read once, for migration.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceLang.php';

use WHMCS\Database\Capsule;

class EnhanceStore
{
    const VERSION        = '1.4.0';
    const SETTING        = 'EnhanceModuleStoreVersion';
    const T_CLIENTS      = 'mod_enhance_clients';
    const T_SERVICES     = 'mod_enhance_services';
    const T_EVENTS       = 'mod_enhance_service_events';
    const LEGACY_ORG     = 'EnhanceOrgId';
    const LEGACY_SUB     = 'SUBSCRIPTION_ID';

    /** Client fields holding the organisation: this module 1.x and the official Enhance module. */
    const LEGACY_ORG_FIELDS = ['EnhanceOrgId', 'enhOrgId'];
    /** Product fields holding the subscription: this module 1.x and the official module (>= 1.1 and < 1.1). */
    const LEGACY_SUB_FIELDS = ['SUBSCRIPTION_ID', 'enhanceSubscriptionId', 'subscriptionId'];
    const EMAIL_TEMPLATE = 'Enhance Panel Access';

    private static bool $ready = false;
    private static ?int $orgFieldId = null;

    // -------------------------------------------------------------------------
    // Schema and migration
    // -------------------------------------------------------------------------

    public static function ensure(): void
    {
        if (self::$ready) return;

        $schema = Capsule::schema();
        if (!$schema->hasTable(self::T_CLIENTS)) {
            $schema->create(self::T_CLIENTS, function ($t) {
                $t->increments('id');
                $t->integer('client_id');
                $t->integer('server_id');
                $t->string('org_id', 36);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['client_id', 'server_id'], 'enh_client_server');
                $t->unique(['server_id', 'org_id'], 'enh_server_org');
            });
        }
        if (!$schema->hasTable(self::T_SERVICES)) {
            $schema->create(self::T_SERVICES, function ($t) {
                $t->integer('service_id')->primary();
                $t->integer('server_id');
                $t->string('org_id', 36)->nullable();
                $t->string('subscription_id', 32)->nullable();
                $t->string('website_id', 36)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['server_id', 'subscription_id'], 'enh_server_sub');
            });
        }

        if (!$schema->hasTable(self::T_EVENTS)) {
            // Last result of each module action per service (shown in the admin service tab).
            $schema->create(self::T_EVENTS, function ($t) {
                $t->integer('service_id')->primary();
                $t->string('action', 64);
                $t->boolean('success')->default(false);
                $t->text('message')->nullable();
                $t->integer('admin_id')->default(0);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('last_error_at')->nullable();
                $t->text('last_error')->nullable();
            });
        }

        // Mark ready before migrating: the migration itself reads through the public API.
        self::$ready = true;

        $done = Capsule::table('tblconfiguration')->where('setting', self::SETTING)->value('value');
        if ($done !== self::VERSION) {
            self::clientOrgFieldId();
            self::ensureEmailTemplate();
            if (self::migrateLegacy()) {
                if ($done === null) {
                    Capsule::table('tblconfiguration')->insert(['setting' => self::SETTING, 'value' => self::VERSION]);
                } else {
                    Capsule::table('tblconfiguration')->where('setting', self::SETTING)->update(['value' => self::VERSION]);
                }
            }
        }
    }

    /**
     * Copies the custom field values of this module 1.0.x/1.1.x and of the official
     * Enhance module (enhOrgId, enhanceSubscriptionId, subscriptionId) into the new tables.
     * Returns false when no Enhance server exists yet (migration is retried later).
     */
    private static function migrateLegacy(): bool
    {
        $defaultServer = (int) Capsule::table('tblservers')->where('type', 'enhance')->orderBy('id')->value('id');
        if (!$defaultServer) return false;

        $now = date('Y-m-d H:i:s');

        // Clients
        $orgFields = self::legacyFieldIds('client', self::LEGACY_ORG_FIELDS);
        if ($orgFields) {
            $rows = Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid', $orgFields)->where('value', '!=', '')->get();
            foreach ($rows as $row) {
                $clientId = (int) $row->relid;
                $orgId = strtolower(trim((string) $row->value));
                if (!self::isUuid($orgId)) continue;

                $servers = Capsule::table('tblhosting as h')
                    ->join('tblservers as s', 's.id', '=', 'h.server')
                    ->where('h.userid', $clientId)->where('s.type', 'enhance')
                    ->distinct()->pluck('h.server')->all();
                $serverId = (int) ($servers[0] ?? $defaultServer);
                if (count($servers) > 1) {
                    self::log(EnhanceLang::t('log.migration_multi_server', ['client' => $clientId, 'server' => $serverId]));
                }

                if (Capsule::table(self::T_CLIENTS)->where(['client_id' => $clientId, 'server_id' => $serverId])->exists()) continue;
                if (Capsule::table(self::T_CLIENTS)->where(['server_id' => $serverId, 'org_id' => $orgId])->exists()) {
                    self::log(EnhanceLang::t('log.migration_org_dup', ['org' => $orgId, 'client' => $clientId]));
                    continue;
                }
                Capsule::table(self::T_CLIENTS)->insert(['client_id' => $clientId, 'server_id' => $serverId, 'org_id' => $orgId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Services
        $subFields = self::legacyFieldIds('product', self::LEGACY_SUB_FIELDS);
        if ($subFields) {
            $rows = Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid', $subFields)->where('value', '!=', '')->get();
            foreach ($rows as $row) {
                self::adoptLegacyService((int) $row->relid, (string) $row->value);
            }
        }

        return true;
    }

    /**
     * IDs of legacy custom fields named $names (also "name|Label" display names).
     * Product fields only count on products using this module, so a generic name
     * such as "subscriptionId" of another module is never read.
     */
    private static function legacyFieldIds(string $type, array $names): array
    {
        $query = Capsule::table('tblcustomfields as f')
            ->where('f.type', $type)
            ->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('f.fieldname', $name)->orWhere('f.fieldname', 'like', $name . '|%');
                }
            });
        if ($type === 'product') {
            $query->join('tblproducts as p', 'p.id', '=', 'f.relid')->where('p.servertype', 'enhance');
        }
        return array_map('intval', $query->pluck('f.id')->all());
    }

    private static function adoptLegacyService(int $serviceId, string $subId): ?object
    {
        $subId = trim($subId);
        if ($serviceId <= 0 || !ctype_digit($subId)) return null;
        if ($existing = Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->first()) return $existing;

        $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$hosting || !(int) $hosting->server) {
            self::log(EnhanceLang::t('log.migration_no_server', ['service' => $serviceId, 'sub' => $subId]));
            return null;
        }
        $serverId = (int) $hosting->server;
        if (Capsule::table(self::T_SERVICES)->where(['server_id' => $serverId, 'subscription_id' => $subId])->exists()) {
            self::log(EnhanceLang::t('log.migration_sub_dup', ['sub' => $subId, 'service' => $serviceId]));
            return null;
        }
        $orgId = self::clientOrg((int) $hosting->userid, $serverId);
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::T_SERVICES)->insert(['service_id' => $serviceId, 'server_id' => $serverId, 'org_id' => $orgId, 'subscription_id' => $subId, 'created_at' => $now, 'updated_at' => $now]);
        return Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->first();
    }

    // -------------------------------------------------------------------------
    // Clients
    // -------------------------------------------------------------------------

    public static function clientOrg(int $clientId, int $serverId): ?string
    {
        self::ensure();
        $org = Capsule::table(self::T_CLIENTS)->where(['client_id' => $clientId, 'server_id' => $serverId])->value('org_id');
        return $org ? (string) $org : null;
    }

    public static function clientByOrg(int $serverId, string $orgId): int
    {
        self::ensure();
        return (int) Capsule::table(self::T_CLIENTS)->where(['server_id' => $serverId, 'org_id' => strtolower($orgId)])->value('client_id');
    }

    /** @throws Exception when the organisation already belongs to another client on this server. */
    public static function setClientOrg(int $clientId, int $serverId, string $orgId): void
    {
        self::ensure();
        $orgId = strtolower(trim($orgId));
        if (!self::isUuid($orgId)) throw new Exception(EnhanceLang::t('store.org_uuid_invalid'));
        if ($serverId <= 0) throw new Exception(EnhanceLang::t('store.server_unknown'));

        $owner = self::clientByOrg($serverId, $orgId);
        if ($owner && $owner !== $clientId) {
            throw new Exception(EnhanceLang::t('store.org_taken', ['org' => $orgId, 'client' => $owner]));
        }

        $now = date('Y-m-d H:i:s');
        $key = ['client_id' => $clientId, 'server_id' => $serverId];
        if (Capsule::table(self::T_CLIENTS)->where($key)->exists()) {
            Capsule::table(self::T_CLIENTS)->where($key)->update(['org_id' => $orgId, 'updated_at' => $now]);
        } else {
            Capsule::table(self::T_CLIENTS)->insert($key + ['org_id' => $orgId, 'created_at' => $now, 'updated_at' => $now]);
        }
        self::writeLegacyOrgField($clientId, $orgId);
    }

    public static function clearClientOrg(int $clientId, int $serverId): void
    {
        self::ensure();
        Capsule::table(self::T_CLIENTS)->where(['client_id' => $clientId, 'server_id' => $serverId])->delete();
        $remaining = Capsule::table(self::T_CLIENTS)->where('client_id', $clientId)->value('org_id');
        self::writeLegacyOrgField($clientId, $remaining ? (string) $remaining : '');
    }

    /** Display-only copy in the client custom field "EnhanceOrgId". */
    private static function writeLegacyOrgField(int $clientId, string $orgId): void
    {
        $fieldId = self::clientOrgFieldId();
        if (!$fieldId) return;
        Capsule::table('tblcustomfieldsvalues')->where(['fieldid' => $fieldId, 'relid' => $clientId])->delete();
        if ($orgId !== '') {
            Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $clientId, 'value' => $orgId]);
        }
    }

    // -------------------------------------------------------------------------
    // Services
    // -------------------------------------------------------------------------

    public static function service(int $serviceId): ?object
    {
        self::ensure();
        $row = Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->first();
        if ($row) return $row;

        // Late fallback: a legacy value created after the migration ran.
        $subFields = self::legacyFieldIds('product', self::LEGACY_SUB_FIELDS);
        if (!$subFields) return null;
        $legacy = Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid', $subFields)->where('relid', $serviceId)->where('value', '!=', '')->value('value');
        return $legacy ? self::adoptLegacyService($serviceId, (string) $legacy) : null;
    }

    public static function serviceBySubscription(int $serverId, string $subId): int
    {
        self::ensure();
        return (int) Capsule::table(self::T_SERVICES)->where(['server_id' => $serverId, 'subscription_id' => $subId])->value('service_id');
    }

    /** @throws Exception when the subscription is already linked to another service. */
    public static function setService(int $serviceId, int $serverId, ?string $orgId, string $subId, ?string $websiteId = null): void
    {
        self::ensure();
        if ($serverId <= 0) throw new Exception(EnhanceLang::t('store.server_unknown'));
        $other = self::serviceBySubscription($serverId, $subId);
        if ($other && $other !== $serviceId) {
            throw new Exception(EnhanceLang::t('store.sub_taken', ['sub' => $subId, 'service' => $other]));
        }

        $now = date('Y-m-d H:i:s');
        $data = ['server_id' => $serverId, 'org_id' => $orgId ? strtolower($orgId) : null, 'subscription_id' => $subId, 'updated_at' => $now];
        if ($websiteId !== null) $data['website_id'] = $websiteId;

        if (Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->exists()) {
            Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->update($data);
        } else {
            Capsule::table(self::T_SERVICES)->insert(['service_id' => $serviceId, 'created_at' => $now] + $data);
        }
    }

    public static function setWebsite(int $serviceId, ?string $websiteId): void
    {
        self::ensure();
        Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->update(['website_id' => $websiteId, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function clearService(int $serviceId): void
    {
        self::ensure();
        Capsule::table(self::T_SERVICES)->where('service_id', $serviceId)->delete();
        // Remove the legacy value too, so the late fallback cannot re-adopt it.
        $subFields = self::legacyFieldIds('product', self::LEGACY_SUB_FIELDS);
        if ($subFields) {
            Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid', $subFields)->where('relid', $serviceId)->delete();
        }
    }

    /** Linked services of a server whose WHMCS status is in $statuses. */
    public static function servicesForServer(int $serverId, array $statuses = ['Active', 'Suspended']): array
    {
        self::ensure();
        return Capsule::table(self::T_SERVICES . ' as m')
            ->join('tblhosting as h', 'h.id', '=', 'm.service_id')
            ->where('m.server_id', $serverId)
            ->whereNotNull('m.subscription_id')
            ->whereIn('h.domainstatus', $statuses)
            ->select('m.service_id', 'm.server_id', 'm.org_id', 'm.subscription_id', 'm.website_id', 'h.userid', 'h.domainstatus', 'h.domain')
            ->get()->all();
    }

    // -------------------------------------------------------------------------
    // Last action per service
    // -------------------------------------------------------------------------

    /** Records the result of a module action; the last error is kept until a newer one. */
    public static function recordEvent(int $serviceId, string $action, bool $success, string $message = ''): void
    {
        if ($serviceId <= 0) return;
        try {
            self::ensure();
            $now = date('Y-m-d H:i:s');
            $data = [
                'action'     => mb_substr($action, 0, 64),
                'success'    => $success,
                'message'    => mb_substr($message, 0, 2000),
                'admin_id'   => (int) ($_SESSION['adminid'] ?? 0),
                'created_at' => $now,
            ];
            if (!$success) {
                $data['last_error'] = $data['message'];
                $data['last_error_at'] = $now;
            }
            if (Capsule::table(self::T_EVENTS)->where('service_id', $serviceId)->exists()) {
                Capsule::table(self::T_EVENTS)->where('service_id', $serviceId)->update($data);
            } else {
                Capsule::table(self::T_EVENTS)->insert(['service_id' => $serviceId] + $data);
            }
        } catch (Throwable $e) {
            // Recording must never break the action itself.
        }
    }

    public static function lastEvent(int $serviceId): ?object
    {
        self::ensure();
        return Capsule::table(self::T_EVENTS)->where('service_id', $serviceId)->first();
    }

    // -------------------------------------------------------------------------
    // Client custom field and email template
    // -------------------------------------------------------------------------

    /** ID of the admin-only client custom field "EnhanceOrgId" (created when missing). */
    public static function clientOrgFieldId(): int
    {
        if (self::$orgFieldId !== null) return self::$orgFieldId;

        $id = Capsule::table('tblcustomfields')->where('type', 'client')->where('fieldname', self::LEGACY_ORG)->value('id');
        if (!is_numeric($id)) {
            $id = Capsule::table('tblcustomfields')->insertGetId([
                'type'        => 'client',
                'relid'       => 0,
                'fieldname'   => self::LEGACY_ORG,
                'fieldtype'   => 'text',
                'description' => 'Enhance organisation UUID (managed by the Enhance module)',
                'fieldoptions'=> '',
                'regexpr'     => '',
                'adminonly'   => 'on',
                'required'    => '',
                'showorder'   => '',
                'showinvoice' => '',
                'sortorder'   => 0,
            ]);
        }
        return self::$orgFieldId = (int) $id;
    }

    /**
     * Creates the "Enhance Panel Access" general email template (English master)
     * and its European Portuguese translation, when missing.
     * Merge fields: {$panel_url}, {$login_email}, {$new_login}.
     */
    public static function ensureEmailTemplate(): void
    {
        $name = self::EMAIL_TEMPLATE;
        $templates = [
            ''              => 'english',
            'portuguese-pt' => 'portuguese-pt',
        ];
        foreach ($templates as $language => $dictionary) {
            $query = Capsule::table('tblemailtemplates')->where('type', 'general')->where('name', $name);
            $language === '' ? $query->where(function ($q) { $q->where('language', '')->orWhereNull('language'); }) : $query->where('language', $language);
            if ($query->exists()) continue;

            Capsule::table('tblemailtemplates')->insert([
                'type'     => 'general',
                'name'     => $name,
                'subject'  => EnhanceLang::t('email.subject', [], $dictionary),
                'message'  => EnhanceLang::t('email.body', [], $dictionary),
                'language' => $language,
                'custom'   => 1,
                'disabled' => 0,
                'plaintext'=> 0,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Runs $work while holding a named MySQL lock (GET_LOCK), so two requests for
     * the same client never provision at the same time (double click, automatic
     * setup racing a manual Create, cron). The lock is released when $work ends,
     * also on exceptions, and by MySQL itself if the PHP process dies.
     * On other database drivers (tests) $work simply runs.
     *
     * @throws Exception when the lock is still held after $timeout seconds.
     */
    public static function withLock(string $key, callable $work, int $timeout = 15)
    {
        $connection = Capsule::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $work();
        }
        $name = 'enhance:' . md5($key); // MySQL lock names are limited to 64 characters
        $row = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
        if (!$row || (int) $row->acquired !== 1) {
            throw new Exception(EnhanceLang::t('store.locked'));
        }
        try {
            return $work();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }

    public static function clientLockKey(int $serverId, int $clientId): string
    {
        return 'client:' . $serverId . ':' . $clientId;
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/', strtolower($value));
    }

    private static function log(string $message): void
    {
        if (function_exists('logActivity')) logActivity('Enhance: ' . $message);
    }
}
