<?php
/**
 * Enhance WHMCS Module - configuration (v1.4.0)
 *
 * API calls are recorded in Utilities > Logs > Module Log when module logging
 * is enabled in WHMCS. Tokens and generated passwords are always masked.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/**
 * Debug mode (reserved; API calls are written to the WHMCS Module Log).
 */
if (!defined('ENHANCE_DEBUG')) define('ENHANCE_DEBUG', false);

/**
 * Interface language.
 *
 * Leave empty to follow WHMCS: the administrator's language in the admin area
 * and the visitor's language in the client area. Set to 'english' or
 * 'portuguese-pt' to force one language everywhere.
 */
if (!defined('ENHANCE_LANGUAGE')) define('ENHANCE_LANGUAGE', '');

/**
 * Client area colours.
 *
 * Leave both values empty so the client area card follows the colours of the
 * active WHMCS theme (detected from the theme's primary button). Set a hex
 * colour (e.g. '#17b8b0') only to force a specific colour. Without a
 * secondary colour, the gradient uses a darker shade of the primary colour.
 */
if (!defined('ENHANCE_COLOR_PRIMARY')) define('ENHANCE_COLOR_PRIMARY', '');
if (!defined('ENHANCE_COLOR_SECONDARY')) define('ENHANCE_COLOR_SECONDARY', '');
