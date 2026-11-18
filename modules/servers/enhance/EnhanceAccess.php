<?php
/**
 * Enhance WHMCS Module - who may use the panel login and tools of a client (v1.6.0)
 *
 * Shared by the server module and the client area hooks, which run on pages
 * where the server module is not loaded.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

class EnhanceAccess
{
    /** Client account selected by the logged-in user (0 when nobody is logged in). */
    public static function currentClientId(): int
    {
        if (class_exists('WHMCS\\Authentication\\CurrentUser')) {
            try {
                $client = (new \WHMCS\Authentication\CurrentUser())->client();
                return $client ? (int) $client->id : 0;
            } catch (Throwable $e) {
                return 0;
            }
        }
        return (int) ($_SESSION['uid'] ?? 0); // WHMCS 7
    }

    /**
     * The logged-in user may log in to the panel / use the tools of this client:
     * admins, the account owner, and (unless $ownerOnly) users with the WHMCS
     * "productsso" (Single Sign-On) permission.
     */
    public static function userMayUse(int $clientId, bool $ownerOnly = false): bool
    {
        if (!empty($_SESSION['adminid'])) return true;
        if ($clientId <= 0) return false;
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
}
