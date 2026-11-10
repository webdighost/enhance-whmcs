<?php
/**
 * CDN - read-only client of the CDN provider API (bunny.net), v1.5.1
 *
 * Monitoring only: this class sends GET requests and never creates, changes,
 * suspends or deletes anything at the provider. Checked against the provider's
 * OpenAPI specification (https://core-api-public-docs.b-cdn.net/docs/v3/public.json):
 *   GET /pullzone?page=1&perPage=1    connection test
 *   GET /pullzone/{id}                zone check (hostnames, state, type, month charges)
 *   GET /statistics?pullZone=...      traffic per day (BandwidthUsedChart, bytes)
 *
 * Security:
 *   - the account key (header "AccessKey") is read from the encrypted settings and
 *     masked in every log;
 *   - zones are always requested with includeCertificate=false: with true, the
 *     provider returns the SSL certificates *and their private keys*;
 *   - responses are never logged in full, only a short summary;
 *   - HTTPS only, fixed host, no redirects, timeouts, controlled retries on 429/5xx.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceSettings.php';
require_once __DIR__ . '/EnhanceLang.php';

class EnhanceCdn
{
    const PROVIDER = 'bunny.net';
    const BASE_URL = 'https://api.bunny.net';
    const MODULE   = 'enhance_cdn';
    /** Bytes in one GB for limits and display (binary, as the disk usage of the module). */
    const GB = 1073741824;

    private string $key;
    private string $base;

    public function __construct(string $key, string $base = self::BASE_URL)
    {
        $key = (string) preg_replace('/\s+/', '', $key);
        if ($key === '') {
            throw new RuntimeException(EnhanceLang::t('cdn.err_no_key'));
        }
        if (stripos($base, 'https://') !== 0) {
            throw new RuntimeException('HTTPS is required for the CDN API.');
        }
        $this->key  = $key;
        $this->base = rtrim($base, '/');
    }

    /** Client built from the settings (the key is decrypted only here, in memory). */
    public static function fromSettings(): self
    {
        // ENHANCE_CDN_API_BASE only exists for the test suite (mock API); it must be HTTPS too.
        $base = defined('ENHANCE_CDN_API_BASE') ? (string) ENHANCE_CDN_API_BASE : self::BASE_URL;
        return new self((string) EnhanceSettings::get('cdn_api_key'), $base);
    }

    public static function isConfigured(): bool
    {
        return (bool) EnhanceSettings::get('cdn_enabled') && (string) EnhanceSettings::get('cdn_api_key') !== '';
    }

    // -------------------------------------------------------------------------
    // API calls (GET only)
    // -------------------------------------------------------------------------

    /** Validates the key without changing anything. */
    public function testConnection(): array
    {
        $resp = $this->get('/pullzone', ['page' => 1, 'perPage' => 1, 'includeCertificate' => 'false']);
        return self::isError($resp)
            ? ['success' => false, 'error' => self::errorSummary($resp)]
            : ['success' => true, 'error' => ''];
    }

    /**
     * The zone, reduced to what the module needs (never certificates).
     * @return array{id:int,name:string,enabled:bool,suspended:bool,type:string,hostnames:string[],month_bytes:?int,month_charges:?float}|array error
     */
    public function getPullZone(int $zoneId): array
    {
        $resp = $this->get('/pullzone/' . $zoneId, ['includeCertificate' => 'false']);
        if (self::isError($resp)) return $resp;
        $hostnames = [];
        foreach ((array) ($resp['Hostnames'] ?? []) as $h) {
            if (!empty($h['Value'])) $hostnames[] = strtolower((string) $h['Value']);
        }
        return [
            'id'            => (int) ($resp['Id'] ?? $zoneId),
            'name'          => (string) ($resp['Name'] ?? ''),
            'enabled'       => (bool) ($resp['Enabled'] ?? false),
            'suspended'     => (bool) ($resp['Suspended'] ?? false),
            // 0 = Premium (standard network), 1 = Volume network
            'type'          => ((int) ($resp['Type'] ?? 0)) === 1 ? 'volume' : 'premium',
            'hostnames'     => $hostnames,
            'month_bytes'   => isset($resp['MonthlyBandwidthUsed']) ? (int) $resp['MonthlyBandwidthUsed'] : null,
            'month_charges' => isset($resp['MonthlyCharges']) ? (float) $resp['MonthlyCharges'] : null,
            '_httpCode'     => 200,
        ];
    }

    /**
     * Traffic per day (UTC dates), in bytes, between two dates (inclusive).
     * @return array{days: array<string,int>, requests: array<string,int>}|array error
     */
    public function dailyTraffic(int $zoneId, string $from, string $to): array
    {
        $resp = $this->get('/statistics', [
            'pullZone'           => $zoneId,
            'dateFrom'           => $from . 'T00:00:00Z',
            'dateTo'             => $to . 'T23:59:59Z',
            'hourly'             => 'false',
            // The charts are only returned when asked for (both default to false).
            'loadBandwidthUsed'  => 'true',
            'loadRequestsServed' => 'true',
        ]);
        if (self::isError($resp)) return $resp;
        if (!isset($resp['BandwidthUsedChart']) || !is_array($resp['BandwidthUsedChart'])) {
            return ['code' => 'invalid_response', 'detail' => EnhanceLang::t('cdn.err_invalid_stats'), '_httpCode' => 0];
        }
        $days = [];
        foreach ($resp['BandwidthUsedChart'] as $date => $bytes) {
            $day = substr((string) $date, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
            $days[$day] = ($days[$day] ?? 0) + (int) round((float) $bytes);
        }
        $requests = [];
        foreach ((array) ($resp['RequestsServedChart'] ?? []) as $date => $count) {
            $day = substr((string) $date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $requests[$day] = ($requests[$day] ?? 0) + (int) round((float) $count);
        }
        return ['days' => $days, 'requests' => $requests, '_httpCode' => 200];
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    private function get(string $path, array $query = []): array
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
        $attempts = 0;
        do {
            $attempts++;
            $resp = $this->request($url, $path);
            $status = (int) ($resp['_httpCode'] ?? 0);
            $retry = ($status === 429 || $status >= 500 || ($resp['code'] ?? '') === 'curl_error') && $attempts < 3;
            if ($retry) {
                // Respect Retry-After when the provider sends it (capped), otherwise 1 s then 2 s.
                $wait = min(10, max(1, (int) ($resp['_retryAfter'] ?? $attempts)));
                sleep($wait);
            }
        } while ($retry);
        return $resp;
    }

    private function request(string $url, string $path): array
    {
        $headers = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'AccessKey: ' . $this->key, 'User-Agent: WHMCS-Enhance-Module/' . (class_exists('EnhanceApi') ? EnhanceApi::VERSION : '')],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (stripos($line, 'retry-after:') === 0) $headers['retry-after'] = trim(substr($line, 12));
                return strlen($line);
            },
        ]);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';

        $decoded = json_decode($raw, true);
        $result = $errno
            ? ['code' => 'curl_error', 'detail' => 'cURL ' . $errno . ': ' . $error, '_httpCode' => 0]
            : ($status >= 200 && $status < 300 && is_array($decoded)
                ? $decoded + ['_httpCode' => $status]
                : ['code' => 'http_' . $status, 'detail' => is_array($decoded) ? (string) ($decoded['Message'] ?? $decoded['message'] ?? '') : '', '_httpCode' => $status]);
        if (isset($headers['retry-after']) && ctype_digit($headers['retry-after'])) {
            $result['_retryAfter'] = (int) $headers['retry-after'];
        }

        if (function_exists('logModuleCall')) {
            // Summary only: status and size, never the body (zones can contain sensitive data).
            $summary = ['http' => $status, 'bytes' => strlen($raw)];
            if (isset($result['code'])) $summary['error'] = $result['code'] . ' ' . mb_substr((string) ($result['detail'] ?? ''), 0, 200);
            if (EnhanceSettings::get('cdn_debug') && isset($decoded['BandwidthUsedChart'])) {
                $summary['days'] = count((array) $decoded['BandwidthUsedChart']);
            }
            logModuleCall(self::MODULE, 'GET ' . $path, preg_replace('/^[^?]*\??/', '', $url), $summary, null, [$this->key]);
        }
        return $result;
    }

    public static function isError(array $resp): bool
    {
        return !empty($resp['code']) || (int) ($resp['_httpCode'] ?? 0) < 200 || (int) ($resp['_httpCode'] ?? 0) >= 300;
    }

    /** Technical summary for the admin (never shown to clients). */
    public static function errorSummary(array $resp): string
    {
        $status = (int) ($resp['_httpCode'] ?? 0);
        $hint = '';
        if ($status === 401 || $status === 403) $hint = ' ' . EnhanceLang::t('cdn.err_key');
        if ($status === 404) $hint = ' ' . EnhanceLang::t('cdn.err_zone_not_found');
        if ($status === 429) $hint = ' ' . EnhanceLang::t('cdn.err_rate');
        return trim(($status ? 'HTTP ' . $status . ' ' : '') . '[' . ($resp['code'] ?? 'error') . '] ' . ($resp['detail'] ?? '') . $hint);
    }

    /** CDN address (https://host or host) reduced to its hostname, or '' when invalid. */
    public static function normaliseHost(string $address): string
    {
        $address = strtolower(trim($address));
        $address = (string) preg_replace('#^https?://#', '', $address);
        $address = (string) preg_replace('#[/?\#].*$#', '', $address);
        $address = rtrim($address, '.');
        return (strlen($address) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $address)) ? $address : '';
    }
}
