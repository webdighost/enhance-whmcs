<?php
/**
 * CDN - statistics synchronisation and admin alerts (v1.5.1)
 *
 * Run by the WHMCS cron (includes/hooks/enhance_cdn.php) at the interval set in
 * Addons > Enhance Manager > CDN, and on demand by the admin.
 *
 * Per active website: check the pull zone (state, hostnames, type, month charges),
 * fetch the traffic per day and store it (re-syncing the last 3 days to pick up
 * late corrections by the provider; a first sync back-fills the annuity), then
 * raise the admin alerts. A failure keeps the last stored values, is recorded on
 * the website and never changes the CDN state configured by the admin.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceCdnStore.php';
require_once __DIR__ . '/EnhanceStore.php';

use WHMCS\Database\Capsule;

class EnhanceCdnSync
{
    const LAST_RUN = 'EnhanceCdnLastSync';
    /** Days re-read on every sync, so late corrections by the provider are applied. */
    const RESYNC_DAYS = 3;
    /** Longest back-fill on a first sync. */
    const MAX_BACKFILL_DAYS = 400;
    /** Days per statistics request. */
    const CHUNK_DAYS = 31;

    /** Synchronises one website. @return array{ok:bool,error:string,days:int} */
    public static function syncSite(EnhanceCdn $api, object $site, ?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $zoneId = (int) $site->zone_id;
        $update = ['last_sync_at' => date('Y-m-d H:i:s')];
        $warnings = [];

        $zone = $api->getPullZone($zoneId);
        if (EnhanceCdn::isError($zone)) {
            EnhanceCdnStore::saveSite($site->website_id, $update + ['last_sync_status' => 'error', 'last_error' => EnhanceCdn::errorSummary($zone)]);
            return ['ok' => false, 'error' => EnhanceCdn::errorSummary($zone), 'days' => 0];
        }
        $update += [
            'zone_type'              => $zone['type'],
            'zone_enabled'           => $zone['enabled'] && !$zone['suspended'],
            'provider_month_charges' => $zone['month_charges'],
            'provider_month'         => gmdate('Y-m'),
        ];
        if ($site->cdn_host !== '' && !in_array($site->cdn_host, $zone['hostnames'], true)) {
            $warnings[] = EnhanceLang::t('cdn.err_host_not_in_zone', ['host' => $site->cdn_host, 'zone' => $zoneId]);
        }

        // Range: the last days, from the last success if older, or a back-fill on the first sync.
        $from = (new DateTimeImmutable($today))->modify('-' . self::RESYNC_DAYS . ' days');
        if ($site->last_success_at) {
            $last = (new DateTimeImmutable(substr((string) $site->last_success_at, 0, 10)))->modify('-1 day');
            if ($last < $from) $from = $last;
        } else {
            $annuity = EnhanceCdnStore::annuity($site->activated_at, $today);
            if ($annuity) $from = new DateTimeImmutable(min($annuity[0], $from->format('Y-m-d')));
        }
        $oldest = (new DateTimeImmutable($today))->modify('-' . self::MAX_BACKFILL_DAYS . ' days');
        if ($from < $oldest) $from = $oldest;
        // Traffic before the activation is not part of the service.
        if ($site->activated_at && $from->format('Y-m-d') < $site->activated_at) {
            $from = new DateTimeImmutable($site->activated_at);
        }

        $stored = 0;
        $end = new DateTimeImmutable($today);
        for ($chunk = $from; $chunk <= $end; $chunk = $chunk->modify('+' . self::CHUNK_DAYS . ' days')) {
            $chunkEnd = min($end, $chunk->modify('+' . (self::CHUNK_DAYS - 1) . ' days'));
            $stats = $api->dailyTraffic($zoneId, $chunk->format('Y-m-d'), $chunkEnd->format('Y-m-d'));
            if (EnhanceCdn::isError($stats)) {
                // Days stored before the failure are kept; the next run starts from the last success.
                EnhanceCdnStore::saveSite($site->website_id, $update + ['last_sync_status' => 'error', 'last_error' => EnhanceCdn::errorSummary($stats)]);
                return ['ok' => false, 'error' => EnhanceCdn::errorSummary($stats), 'days' => $stored];
            }
            foreach ($stats['days'] as $day => $bytes) {
                if ($day < $chunk->format('Y-m-d') || $day > $chunkEnd->format('Y-m-d')) continue;
                if ($site->activated_at && $day < $site->activated_at) continue;
                EnhanceCdnStore::putDay($site->website_id, $zoneId, $day, $bytes, $stats['requests'][$day] ?? 0);
                $stored++;
            }
        }

        EnhanceCdnStore::saveSite($site->website_id, $update + [
            'last_success_at'  => date('Y-m-d H:i:s'),
            'last_sync_status' => $warnings ? 'error' : 'ok',
            'last_error'       => $warnings ? implode(' ', $warnings) : null,
        ]);
        self::checkAlerts(EnhanceCdnStore::site($site->website_id), $today);
        return ['ok' => !$warnings, 'error' => implode(' ', $warnings), 'days' => $stored];
    }

    /** Admin alerts for the calendar month and the annuity, once per level and period. */
    public static function checkAlerts(object $site, ?string $today = null): array
    {
        if (!EnhanceSettings::get('cdn_alerts')) return [];
        $usage = EnhanceCdnStore::usage($site, $today ?? gmdate('Y-m-d'));
        $levels = array_unique([(int) EnhanceSettings::get('cdn_warn_pct'), (int) EnhanceSettings::get('cdn_crit_pct')]);
        sort($levels);
        $raised = [];
        $checks = [['month:' . $usage['month'], $usage['month_bytes'], $usage['limit_bytes'], 'cdn.alert_month']];
        if ($usage['annuity']) {
            $checks[] = ['year:' . $usage['annuity'][0], (int) $usage['year_bytes'], $usage['year_limit'], 'cdn.alert_year'];
        }
        foreach ($checks as [$period, $bytes, $limit, $text]) {
            if ($limit <= 0) continue;
            foreach ($levels as $level) {
                if ($bytes * 100 < $limit * $level) continue;
                if (EnhanceCdnStore::raiseAlert($site, $period, $level, $bytes, $limit)) {
                    $raised[] = $period . '@' . $level;
                    if (function_exists('logActivity')) {
                        logActivity(EnhanceLang::t($text, [
                            'level' => $level, 'domain' => $site->domain, 'service' => (int) $site->service_id,
                            'used' => _enhance_cdn_gb($bytes), 'limit' => _enhance_cdn_gb($limit),
                        ]));
                    }
                }
            }
        }
        return $raised;
    }

    /** Synchronises every active website (cron or "sync now"). */
    public static function syncAll(?string $today = null): array
    {
        $result = ['sites' => 0, 'ok' => 0, 'errors' => 0, 'purged' => 0];
        if (!EnhanceCdn::isConfigured()) return $result;
        return EnhanceStore::withLock('cdn-sync', function () use ($today, $result) {
            $api = EnhanceCdn::fromSettings();
            foreach (EnhanceCdnStore::activeSites() as $site) {
                $result['sites']++;
                try {
                    $r = self::syncSite($api, $site, $today);
                } catch (Throwable $e) {
                    EnhanceCdnStore::saveSite($site->website_id, ['last_sync_at' => date('Y-m-d H:i:s'), 'last_sync_status' => 'error', 'last_error' => $e->getMessage()]);
                    $r = ['ok' => false];
                }
                $r['ok'] ? $result['ok']++ : $result['errors']++;
            }
            $result['purged'] = EnhanceCdnStore::purge((int) EnhanceSettings::get('cdn_retention_months'));
            self::markRun();
            if (function_exists('logActivity')) {
                logActivity(EnhanceLang::t('cdn.log_sync', $result));
            }
            return $result;
        }, 1);
    }

    /** True when the interval set in the settings has passed since the last run. */
    public static function isDue(?int $now = null): bool
    {
        $last = (int) Capsule::table('tblconfiguration')->where('setting', self::LAST_RUN)->value('value');
        return ($now ?? time()) - $last >= (int) EnhanceSettings::get('cdn_sync_hours') * 3600 - 300;
    }

    public static function lastRun(): int
    {
        return (int) Capsule::table('tblconfiguration')->where('setting', self::LAST_RUN)->value('value');
    }

    private static function markRun(): void
    {
        $now = (string) time();
        if (Capsule::table('tblconfiguration')->where('setting', self::LAST_RUN)->exists()) {
            Capsule::table('tblconfiguration')->where('setting', self::LAST_RUN)->update(['value' => $now]);
        } else {
            Capsule::table('tblconfiguration')->insert(['setting' => self::LAST_RUN, 'value' => $now]);
        }
    }
}

if (!function_exists('_enhance_cdn_gb')) {
    /** Bytes as GB in the reader's format (2 decimals below 10 GB, 1 below 100 GB). */
    function _enhance_cdn_gb(?int $bytes): string
    {
        $gb = ((int) $bytes) / EnhanceCdn::GB;
        $decimals = $gb < 10 ? 2 : ($gb < 100 ? 1 : 0);
        return number_format($gb, $decimals, EnhanceLang::t('fmt.decimal'), EnhanceLang::t('fmt.thousands')) . ' GB';
    }
}
