<?php
/**
 * Enhance WHMCS Module - configuration overrides (v1.4.0)
 *
 * The client area and language settings are managed in
 *   Addons > Enhance Manager > Settings
 * and stored in the WHMCS database, so module updates never overwrite them.
 *
 * To force a setting (for example on several WHMCS installations managed from
 * the same files), uncomment its line below. A forced setting is shown as locked
 * in the Settings tab.
 *
 * API calls are recorded in Utilities > Logs > Module Log when module logging
 * is enabled in WHMCS. Tokens and generated passwords are always masked.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/** Debug mode (reserved; API calls are written to the WHMCS Module Log). */
if (!defined('ENHANCE_DEBUG')) define('ENHANCE_DEBUG', false);

// Appearance ('' = follow the active WHMCS theme / follow WHMCS for the language)
// define('ENHANCE_COLOR_PRIMARY', '#17b8b0');
// define('ENHANCE_COLOR_SECONDARY', '');
// define('ENHANCE_LANGUAGE', 'portuguese-pt');            // '', 'english' or 'portuguese-pt'

// Client area sections (true = shown)
// define('ENHANCE_CLIENTAREA_USAGE', true);              // disk and traffic meters
// define('ENHANCE_CLIENTAREA_SSL', true);
// define('ENHANCE_CLIENTAREA_WEBMAIL', true);
// define('ENHANCE_CLIENTAREA_PHPMYADMIN', true);
// define('ENHANCE_CLIENTAREA_WORDPRESS', true);
// define('ENHANCE_CLIENTAREA_BACKUPS', true);
// define('ENHANCE_CLIENTAREA_BACKUP_SIZE', true);
// define('ENHANCE_CLIENTAREA_FEATURES', true);           // the three information cards and the note

// Listing and performance
// define('ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE', 5);     // 1-50
// define('ENHANCE_CLIENTAREA_BACKUPS_SHOWN', 5);         // 1-30
// define('ENHANCE_CLIENTAREA_CACHE_TTL', 300);           // seconds, 0-3600 (0 = no cache)

// Backup restore by clients (also needs the option in each product)
// define('ENHANCE_CLIENTAREA_RESTORE', true);
