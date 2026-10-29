<?php
/**
 * Enhance WHMCS Module - translations
 *
 * Every user-facing string of the server module, the importer addon and the
 * hooks goes through EnhanceLang::t(). Dictionaries live in ./lang:
 *   lang/english.php       (source language, always complete)
 *   lang/portuguese-pt.php (European Portuguese)
 *
 * Language selection, in order:
 *   1. the language chosen in Addons > Enhance Manager > Settings (or forced
 *      with ENHANCE_LANGUAGE in config.php), when not "automatic";
 *   2. admin area: the language of the logged-in administrator;
 *   3. client area: the language chosen by the visitor / client;
 *   4. the WHMCS default language (System Settings > General > Localisation).
 * Any "portuguese*" language uses the European Portuguese dictionary; any
 * language without a dictionary falls back to English.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceSettings.php';

use WHMCS\Database\Capsule;

class EnhanceLang
{
    const FALLBACK = 'english';

    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];
    private static ?string $current = null;

    /**
     * Translates $key, replacing ":name" placeholders with $vars['name'].
     */
    public static function t(string $key, array $vars = [], ?string $language = null): string
    {
        $language = $language ? self::normalise($language) : self::current();
        $text = self::dictionary($language)[$key] ?? self::dictionary(self::FALLBACK)[$key] ?? $key;
        if ($vars) {
            $pairs = [];
            foreach ($vars as $name => $value) {
                $pairs[':' . $name] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
            }
            // Longest placeholders first, so ":org" never breaks ":orgId".
            uksort($pairs, fn($a, $b) => strlen($b) <=> strlen($a));
            $text = strtr($text, $pairs);
        }
        return $text;
    }

    /** All strings of a language whose key starts with $prefix (used by templates). */
    public static function group(string $prefix, ?string $language = null): array
    {
        $language = $language ? self::normalise($language) : self::current();
        $out = [];
        foreach (self::dictionary(self::FALLBACK) as $key => $text) {
            if (strpos($key, $prefix) === 0) {
                $out[substr($key, strlen($prefix))] = self::dictionary($language)[$key] ?? $text;
            }
        }
        return $out;
    }

    /** Dictionary name for the current request (e.g. "english", "portuguese-pt"). */
    public static function current(): string
    {
        if (self::$current !== null) return self::$current;

        $wanted = '';
        $forced = '';
        try {
            $forced = (string) EnhanceSettings::get('language');
        } catch (Throwable $e) {
            $forced = '';
        }
        if ($forced !== '') {
            $wanted = $forced;
        } elseif (defined('ADMINAREA') || !empty($_SESSION['adminid'])) {
            $wanted = self::adminLanguage();
        } else {
            $wanted = self::clientLanguage();
        }
        if ($wanted === '') {
            $wanted = self::systemLanguage();
        }

        return self::$current = self::normalise($wanted);
    }

    /** Language for a specific WHMCS client (emails, cron messages about a client). */
    public static function forClient(int $clientId): string
    {
        try {
            $lang = (string) Capsule::table('tblclients')->where('id', $clientId)->value('language');
        } catch (Throwable $e) {
            $lang = '';
        }
        return self::normalise($lang !== '' ? $lang : self::systemLanguage());
    }

    public static function normalise(string $language): string
    {
        $language = strtolower(trim($language));
        if (strpos($language, 'portuguese') === 0 || in_array($language, ['pt', 'pt-pt', 'pt_pt', 'pt-br', 'pt_br'], true)) {
            return 'portuguese-pt';
        }
        $language = preg_replace('/[^a-z0-9_-]/', '', $language);
        return ($language !== '' && is_file(__DIR__ . '/lang/' . $language . '.php')) ? $language : self::FALLBACK;
    }

    /** For tests and long-running cron loops. */
    public static function reset(?string $language = null): void
    {
        self::$current = $language !== null ? self::normalise($language) : null;
    }

    // -------------------------------------------------------------------------

    private static function dictionary(string $language): array
    {
        if (!isset(self::$dictionaries[$language])) {
            $file = __DIR__ . '/lang/' . $language . '.php';
            $data = is_file($file) ? include $file : [];
            self::$dictionaries[$language] = is_array($data) ? $data : [];
        }
        return self::$dictionaries[$language];
    }

    private static function adminLanguage(): string
    {
        try {
            if (class_exists('AdminLang') && method_exists('AdminLang', 'getName')) {
                $name = (string) \AdminLang::getName();
                if ($name !== '') return $name;
            }
        } catch (Throwable $e) {
            // Fall through to the database lookup.
        }
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId > 0) {
            try {
                return (string) Capsule::table('tbladmins')->where('id', $adminId)->value('language');
            } catch (Throwable $e) {
                return '';
            }
        }
        return '';
    }

    private static function clientLanguage(): string
    {
        try {
            if (class_exists('Lang') && method_exists('Lang', 'getName')) {
                $name = (string) \Lang::getName();
                if ($name !== '') return $name;
            }
        } catch (Throwable $e) {
            // Fall through to the session.
        }
        if (!empty($_SESSION['Language'])) {
            return (string) $_SESSION['Language'];
        }
        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($clientId > 0) {
            try {
                return (string) Capsule::table('tblclients')->where('id', $clientId)->value('language');
            } catch (Throwable $e) {
                return '';
            }
        }
        return '';
    }

    private static function systemLanguage(): string
    {
        try {
            return (string) Capsule::table('tblconfiguration')->where('setting', 'Language')->value('value');
        } catch (Throwable $e) {
            return self::FALLBACK;
        }
    }
}

if (!function_exists('enhance_t')) {
    /** Shorthand for EnhanceLang::t(). */
    function enhance_t(string $key, array $vars = [], ?string $language = null): string
    {
        return EnhanceLang::t($key, $vars, $language);
    }
}
