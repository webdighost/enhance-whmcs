<?php
/**
 * Enhance WHMCS Module - settings (v1.4.0)
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
    ];

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
        return array_key_exists($key, $stored) ? self::cast($key, $stored[$key]) : $def['default'];
    }

    /** All settings with their value and whether config.php locks them. */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[$key] = ['value' => self::get($key), 'locked' => defined($def['constant']), 'constant' => $def['constant']] + $def;
        }
        return $out;
    }

    /**
     * Validates and stores the given values (locked settings are ignored).
     * @return string[] keys rejected as invalid
     */
    public static function save(array $input): array
    {
        self::ensureTable();
        $invalid = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if (defined($def['constant'])) continue;
            // Unchecked checkboxes are not posted: a missing bool means false.
            if (!array_key_exists($key, $input) && $def['type'] !== 'bool') continue;
            $raw = $input[$key] ?? '';
            $value = self::validate($key, $raw);
            if ($value === null) {
                $invalid[] = $key;
                continue;
            }
            $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (Capsule::table(self::TABLE)->where('setting', $key)->exists()) {
                Capsule::table(self::TABLE)->where('setting', $key)->update(['value' => $stored]);
            } else {
                Capsule::table(self::TABLE)->insert(['setting' => $key, 'value' => $stored]);
            }
        }
        self::$stored = null;
        return $invalid;
    }

    /** Restores every stored setting to its default. */
    public static function reset(): void
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->delete();
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
