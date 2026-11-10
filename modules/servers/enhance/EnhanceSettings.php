<?php
/**
 * Enhance WHMCS Module - settings (v1.5.0)
 *
 * Settings are edited in Addons > Enhance Manager > Settings and stored in the
 * table mod_enhance_settings. A constant defined in config.php (see the list in
 * that file) overrides the stored value and is shown as locked in the addon.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/config.php';

use WHMCS\Database\Capsule;

class EnhanceSettings
{
    const TABLE = 'mod_enhance_settings';

    /**
     * Definition of every setting: type, default and the config.php constant
     * that may override it.
     */
    const DEFINITIONS = [
        // Appearance
        'color_primary'      => ['type' => 'color', 'default' => '',      'constant' => 'ENHANCE_COLOR_PRIMARY'],
        'color_secondary'    => ['type' => 'color', 'default' => '',      'constant' => 'ENHANCE_COLOR_SECONDARY'],
        'language'           => ['type' => 'enum',  'default' => '',      'constant' => 'ENHANCE_LANGUAGE', 'options' => ['', 'english', 'portuguese-pt']],
        // Client area sections
        'show_usage'         => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_USAGE'],
        'show_ssl'           => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_SSL'],
        'show_webmail'       => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_WEBMAIL'],
        'show_phpmyadmin'    => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_PHPMYADMIN'],
        'show_wordpress'     => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_WORDPRESS'],
        'show_backups'       => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_BACKUPS'],
        'show_backup_size'   => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_BACKUP_SIZE'],
        'show_features'      => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_FEATURES'],
        // Listing and performance
        'websites_per_page'  => ['type' => 'int',   'default' => 5,       'constant' => 'ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE', 'min' => 1, 'max' => 50],
        'backups_shown'      => ['type' => 'int',   'default' => 5,       'constant' => 'ENHANCE_CLIENTAREA_BACKUPS_SHOWN', 'min' => 1, 'max' => 30],
        'cache_ttl'          => ['type' => 'int',   'default' => 300,     'constant' => 'ENHANCE_CLIENTAREA_CACHE_TTL', 'min' => 0, 'max' => 3600],
        // Backup restore (per product option + global switch)
        'restore_enabled'    => ['type' => 'bool',  'default' => true,    'constant' => 'ENHANCE_CLIENTAREA_RESTORE'],
        // CDN (monitoring of CDN pull zones, see EnhanceCdn.php)
        'cdn_enabled'        => ['type' => 'bool',    'default' => false, 'constant' => 'ENHANCE_CDN_ENABLED'],
        'cdn_name'           => ['type' => 'text',    'default' => 'CDN', 'constant' => 'ENHANCE_CDN_NAME', 'max' => 40],
        'cdn_api_key'        => ['type' => 'secret',  'default' => '',    'constant' => 'ENHANCE_CDN_API_KEY'],
        'cdn_sync_hours'     => ['type' => 'int',     'default' => 24,    'constant' => 'ENHANCE_CDN_SYNC_HOURS', 'min' => 1, 'max' => 168],
        'cdn_show_inactive'  => ['type' => 'bool',    'default' => true,  'constant' => 'ENHANCE_CDN_SHOW_INACTIVE'],
        'cdn_alerts'         => ['type' => 'bool',    'default' => true,  'constant' => 'ENHANCE_CDN_ALERTS'],
        'cdn_warn_pct'       => ['type' => 'int',     'default' => 80,    'constant' => 'ENHANCE_CDN_WARN_PCT', 'min' => 1, 'max' => 100],
        'cdn_crit_pct'       => ['type' => 'int',     'default' => 100,   'constant' => 'ENHANCE_CDN_CRIT_PCT', 'min' => 1, 'max' => 1000],
        'cdn_retention_months' => ['type' => 'int',   'default' => 24,    'constant' => 'ENHANCE_CDN_RETENTION_MONTHS', 'min' => 3, 'max' => 120],
        'cdn_debug'          => ['type' => 'bool',    'default' => false, 'constant' => 'ENHANCE_CDN_DEBUG'],
        // CDN plans: name, monthly traffic (GB) and annual price (EUR, without VAT)
        'cdn_plan_business_name'  => ['type' => 'text',    'default' => 'Business', 'constant' => 'ENHANCE_CDN_PLAN_BUSINESS_NAME', 'max' => 40],
        'cdn_plan_standard_name'  => ['type' => 'text',    'default' => 'Standard', 'constant' => 'ENHANCE_CDN_PLAN_STANDARD_NAME', 'max' => 40],
        'cdn_plan_volume_name'    => ['type' => 'text',    'default' => 'Volume',   'constant' => 'ENHANCE_CDN_PLAN_VOLUME_NAME', 'max' => 40],
        'cdn_plan_business_gb'    => ['type' => 'int',     'default' => 10,  'constant' => 'ENHANCE_CDN_PLAN_BUSINESS_GB', 'min' => 1, 'max' => 1000000],
        'cdn_plan_standard_gb'    => ['type' => 'int',     'default' => 25,  'constant' => 'ENHANCE_CDN_PLAN_STANDARD_GB', 'min' => 1, 'max' => 1000000],
        'cdn_plan_volume_gb'      => ['type' => 'int',     'default' => 75,  'constant' => 'ENHANCE_CDN_PLAN_VOLUME_GB', 'min' => 1, 'max' => 1000000],
        'cdn_plan_business_price' => ['type' => 'decimal', 'default' => 0.0,  'constant' => 'ENHANCE_CDN_PLAN_BUSINESS_PRICE', 'min' => 0, 'max' => 100000],
        'cdn_plan_standard_price' => ['type' => 'decimal', 'default' => 25.0, 'constant' => 'ENHANCE_CDN_PLAN_STANDARD_PRICE', 'min' => 0, 'max' => 100000],
        'cdn_plan_volume_price'   => ['type' => 'decimal', 'default' => 50.0, 'constant' => 'ENHANCE_CDN_PLAN_VOLUME_PRICE', 'min' => 0, 'max' => 100000],
    ];

    /** CDN plans (internal keys), in display order. */
    const CDN_PLANS = ['business', 'standard', 'volume'];

    /** @var array<string, string>|null */
    private static ?array $stored = null;

    public static function get(string $key)
    {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            throw new InvalidArgumentException('Unknown Enhance setting: ' . $key);
        }
        if (defined($def['constant'])) {
            return self::cast($key, constant($def['constant']));
        }
        $stored = self::stored();
        if (!array_key_exists($key, $stored)) return $def['default'];
        if ($def['type'] === 'secret') {
            return self::decryptSecret($stored[$key]);
        }
        return self::cast($key, $stored[$key]);
    }

    /** All settings with their value and whether config.php locks them. */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $value = self::get($key);
            $row = ['value' => $value, 'locked' => defined($def['constant']), 'constant' => $def['constant']] + $def;
            if ($def['type'] === 'secret') {
                // A secret never leaves the server: only whether it is set and its last 4 characters.
                $row['value'] = '';
                $row['configured'] = $value !== '';
                $row['hint'] = $value !== '' ? substr((string) $value, -4) : '';
            }
            $out[$key] = $row;
        }
        return $out;
    }

    /**
     * Validates and stores the given values (locked settings are ignored).
     * @return string[] keys rejected as invalid
     */
    public static function save(array $input, ?array $only = null): array
    {
        self::ensureTable();
        $invalid = [];
        foreach (self::DEFINITIONS as $key => $def) {
            // Each form saves only its own settings (an unposted checkbox would otherwise turn others off).
            if ($only !== null && !in_array($key, $only, true)) continue;
            if (defined($def['constant'])) continue;
            // Unchecked checkboxes are not posted: a missing bool means false.
            if (!array_key_exists($key, $input) && $def['type'] !== 'bool') continue;
            $raw = $input[$key] ?? '';
            if ($def['type'] === 'secret') {
                // An empty field keeps the stored secret (it is never shown again).
                if (trim((string) $raw) === '') continue;
                $value = self::validate($key, $raw);
                if ($value === null) {
                    $invalid[] = $key;
                    continue;
                }
                self::store($key, self::encryptSecret($value));
                continue;
            }
            $value = self::validate($key, $raw);
            if ($value === null) {
                $invalid[] = $key;
                continue;
            }
            self::store($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        self::$stored = null;
        return $invalid;
    }

    /** Setting keys of one form: those starting with $prefix, or all others when $exclude is true. */
    public static function keys(string $prefix, bool $exclude = false): array
    {
        return array_values(array_filter(array_keys(self::DEFINITIONS), fn($k) => (strpos($k, $prefix) === 0) !== $exclude));
    }

    /** Removes a stored secret (the "remove key" action). */
    public static function clearSecret(string $key): void
    {
        if ((self::DEFINITIONS[$key]['type'] ?? '') !== 'secret' || defined(self::DEFINITIONS[$key]['constant'])) return;
        self::ensureTable();
        Capsule::table(self::TABLE)->where('setting', $key)->delete();
        self::$stored = null;
    }

    private static function store(string $key, string $value): void
    {
        if (Capsule::table(self::TABLE)->where('setting', $key)->exists()) {
            Capsule::table(self::TABLE)->where('setting', $key)->update(['value' => $value]);
        } else {
            Capsule::table(self::TABLE)->insert(['setting' => $key, 'value' => $value]);
        }
    }

    /** Secrets are stored with the WHMCS encryption (cc_encryption_hash); never in plain text. */
    private static function encryptSecret(string $plain): string
    {
        if (!function_exists('encrypt')) {
            throw new RuntimeException('WHMCS encryption is not available: the secret was not saved.');
        }
        return 'enc:' . encrypt($plain);
    }

    private static function decryptSecret(string $stored): string
    {
        if (strpos($stored, 'enc:') !== 0 || !function_exists('decrypt')) return '';
        return (string) decrypt(substr($stored, 4));
    }

    /** Restores every stored setting to its default. */
    public static function reset(?array $only = null): void
    {
        self::ensureTable();
        $query = Capsule::table(self::TABLE);
        if ($only !== null) $query->whereIn('setting', $only);
        $query->delete();
        self::$stored = null;
    }

    public static function ensureTable(): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, function ($t) {
                $t->string('setting', 64)->primary();
                $t->text('value')->nullable();
            });
        }
    }

    /** Valid value of $raw for $key, or null when invalid. */
    public static function validate(string $key, $raw)
    {
        $def = self::DEFINITIONS[$key];
        switch ($def['type']) {
            case 'bool':
                return in_array(strtolower(trim((string) $raw)), ['1', 'on', 'yes', 'true'], true);
            case 'int':
                $raw = trim((string) $raw);
                if (!preg_match('/^\d+$/', $raw)) return null;
                return max($def['min'], min($def['max'], (int) $raw));
            case 'color':
                $raw = trim((string) $raw);
                return ($raw === '' || preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $raw)) ? strtolower($raw) : null;
            case 'enum':
                $raw = trim((string) $raw);
                return in_array($raw, $def['options'], true) ? $raw : null;
            case 'decimal':
                $raw = str_replace(',', '.', trim((string) $raw));
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) return null;
                return max((float) $def['min'], min((float) $def['max'], round((float) $raw, 2)));
            case 'text':
                // Short labels: no control characters or markup; empty is invalid (the default applies).
                $raw = trim((string) preg_replace('/[\x00-\x1f\x7f<>]/u', '', (string) $raw));
                return ($raw !== '' && mb_strlen($raw) <= ($def['max'] ?? 100)) ? $raw : null;
            case 'secret':
                // API keys: printable, no spaces (spaces and line breaks from copy & paste are removed).
                $raw = (string) preg_replace('/\s+/', '', (string) $raw);
                return ($raw !== '' && strlen($raw) <= 200 && preg_match('/^[\x21-\x7e]+$/', $raw)) ? $raw : null;
        }
        return null;
    }

    /** For tests: forget the values read from the database. */
    public static function flush(): void
    {
        self::$stored = null;
    }

    private static function cast(string $key, $value)
    {
        if (self::DEFINITIONS[$key]['type'] === 'secret') {
            return (string) (self::validate($key, $value) ?? '');
        }
        $valid = self::validate($key, is_bool($value) ? ($value ? '1' : '0') : $value);
        return $valid === null ? self::DEFINITIONS[$key]['default'] : $valid;
    }

    private static function stored(): array
    {
        if (self::$stored === null) {
            self::$stored = [];
            try {
                if (Capsule::schema()->hasTable(self::TABLE)) {
                    foreach (Capsule::table(self::TABLE)->get() as $row) {
                        self::$stored[(string) $row->setting] = (string) $row->value;
                    }
                }
            } catch (Throwable $e) {
                // Without a database (or before the table exists) the defaults apply.
            }
        }
        return self::$stored;
    }
}
