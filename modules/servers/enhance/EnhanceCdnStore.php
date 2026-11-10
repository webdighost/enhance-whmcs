<?php
/**
 * CDN - local storage (v1.5.0)
 *
 *   mod_enhance_cdn_sites   CDN configuration per Enhance website (manual, by the admin)
 *   mod_enhance_cdn_daily   traffic per website, pull zone and UTC day (unique: re-syncs replace, never add)
 *   mod_enhance_cdn_alerts  admin alerts (unique per website, period and level: raised once)
 *
 * Every period (calendar month, annuity, history) is computed from the daily
 * rows when shown, so nothing is recalculated retroactively, a pull zone change
 * keeps the history, and repeated synchronisations cannot duplicate traffic.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/EnhanceSettings.php';
require_once __DIR__ . '/EnhanceCdn.php';

use WHMCS\Database\Capsule;

class EnhanceCdnStore
{
    const T_SITES  = 'mod_enhance_cdn_sites';
    const T_DAILY  = 'mod_enhance_cdn_daily';
    const T_ALERTS = 'mod_enhance_cdn_alerts';

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::T_SITES)) {
            $schema->create(self::T_SITES, function ($t) {
                $t->string('website_id', 36)->primary();
                $t->integer('service_id');
                $t->integer('client_id');
                $t->integer('server_id')->default(0);
                $t->string('domain', 253)->default('');
                $t->boolean('active')->default(false);
                $t->bigInteger('zone_id')->nullable();
                $t->string('cdn_host', 253)->default('');
                $t->string('plan', 16)->default('business');
                $t->integer('limit_gb')->nullable();          // null = the plan's monthly traffic
                $t->date('activated_at')->nullable();         // start of the annuity
                $t->date('deactivated_at')->nullable();
                $t->string('zone_type', 16)->nullable();      // premium / volume, as reported by the provider
                $t->boolean('zone_enabled')->nullable();
                $t->decimal('provider_month_charges', 12, 4)->nullable();
                $t->string('provider_month', 7)->nullable();  // Y-m of provider_month_charges
                $t->timestamp('last_sync_at')->nullable();
                $t->timestamp('last_success_at')->nullable();
                $t->string('last_sync_status', 16)->default('never');  // never / ok / error
                $t->text('last_error')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->index(['service_id'], 'enh_cdn_service');
                $t->index(['zone_id'], 'enh_cdn_zone');
            });
        }
        if (!$schema->hasTable(self::T_DAILY)) {
            $schema->create(self::T_DAILY, function ($t) {
                $t->increments('id');
                $t->string('website_id', 36);
                $t->bigInteger('zone_id');
                $t->date('day');
                $t->bigInteger('bytes')->default(0);
                $t->bigInteger('requests')->default(0);
                $t->timestamp('updated_at')->nullable();
                $t->unique(['website_id', 'zone_id', 'day'], 'enh_cdn_daily_key');
                $t->index(['website_id', 'day'], 'enh_cdn_daily_site');
            });
        }
        if (!$schema->hasTable(self::T_ALERTS)) {
            $schema->create(self::T_ALERTS, function ($t) {
                $t->increments('id');
                $t->string('website_id', 36);
                $t->integer('service_id');
                $t->string('period', 24);          // "month:2026-11" or "year:2026-03-05"
                $t->integer('level');              // percentage that was reached (e.g. 80, 100)
                $t->bigInteger('bytes');
                $t->bigInteger('limit_bytes');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('acknowledged_at')->nullable();
                $t->unique(['website_id', 'period', 'level'], 'enh_cdn_alert_key');
            });
        }
        self::$ready = true;
    }

    // -------------------------------------------------------------------------
    // Websites
    // -------------------------------------------------------------------------

    public static function site(string $websiteId): ?object
    {
        self::ensure();
        return Capsule::table(self::T_SITES)->where('website_id', strtolower($websiteId))->first();
    }

    /** @return array<string, object> keyed by website id */
    public static function sitesForService(int $serviceId): array
    {
        self::ensure();
        $out = [];
        foreach (Capsule::table(self::T_SITES)->where('service_id', $serviceId)->get() as $row) {
            $out[(string) $row->website_id] = $row;
        }
        return $out;
    }

    public static function activeSites(): array
    {
        self::ensure();
        return Capsule::table(self::T_SITES)->where('active', 1)->whereNotNull('zone_id')->orderBy('service_id')->get()->all();
    }

    public static function allSites(): array
    {
        self::ensure();
        return Capsule::table(self::T_SITES)->orderBy('active', 'desc')->orderBy('domain')->get()->all();
    }

    /** Other websites using the same pull zone (to warn before sharing it). */
    public static function zoneUsedBy(int $zoneId, string $exceptWebsite): array
    {
        self::ensure();
        return Capsule::table(self::T_SITES)->where('zone_id', $zoneId)->where('website_id', '!=', strtolower($exceptWebsite))->get()->all();
    }

    public static function saveSite(string $websiteId, array $data): void
    {
        self::ensure();
        $websiteId = strtolower($websiteId);
        $now = date('Y-m-d H:i:s');
        $data['updated_at'] = $now;
        if (Capsule::table(self::T_SITES)->where('website_id', $websiteId)->exists()) {
            Capsule::table(self::T_SITES)->where('website_id', $websiteId)->update($data);
        } else {
            Capsule::table(self::T_SITES)->insert(['website_id' => $websiteId, 'created_at' => $now] + $data);
        }
    }

    // -------------------------------------------------------------------------
    // Traffic
    // -------------------------------------------------------------------------

    /** Stores the traffic of one day; a repeated sync replaces the value (idempotent). */
    public static function putDay(string $websiteId, int $zoneId, string $day, int $bytes, int $requests): void
    {
        self::ensure();
        $key = ['website_id' => strtolower($websiteId), 'zone_id' => $zoneId, 'day' => $day];
        $data = ['bytes' => max(0, $bytes), 'requests' => max(0, $requests), 'updated_at' => date('Y-m-d H:i:s')];
        if (Capsule::table(self::T_DAILY)->where($key)->exists()) {
            Capsule::table(self::T_DAILY)->where($key)->update($data);
        } else {
            Capsule::table(self::T_DAILY)->insert($key + $data);
        }
    }

    /** Traffic of a website between two days (inclusive), all pull zones it ever used. */
    public static function bytesBetween(string $websiteId, string $from, string $to): int
    {
        self::ensure();
        return (int) Capsule::table(self::T_DAILY)->where('website_id', strtolower($websiteId))
            ->where('day', '>=', $from)->where('day', '<=', $to)->sum('bytes');
    }

    public static function hasData(string $websiteId): bool
    {
        self::ensure();
        return Capsule::table(self::T_DAILY)->where('website_id', strtolower($websiteId))->exists();
    }

    /** Traffic per calendar month, newest first: [['month' => 'Y-m', 'bytes' => n], ...] */
    public static function monthly(string $websiteId, int $months = 12): array
    {
        self::ensure();
        $first = date('Y-m-01', strtotime('first day of -' . max(0, $months - 1) . ' months'));
        $rows = Capsule::table(self::T_DAILY)->where('website_id', strtolower($websiteId))->where('day', '>=', $first)->get(['day', 'bytes']);
        $out = [];
        foreach ($rows as $r) {
            $m = substr((string) $r->day, 0, 7);
            $out[$m] = ($out[$m] ?? 0) + (int) $r->bytes;
        }
        krsort($out);
        $list = [];
        foreach ($out as $m => $b) $list[] = ['month' => $m, 'bytes' => $b];
        return $list;
    }

    /** Deletes daily rows older than the retention (alerts and configuration are kept). */
    public static function purge(int $months): int
    {
        self::ensure();
        $limit = date('Y-m-01', strtotime('first day of -' . max(1, $months) . ' months'));
        return Capsule::table(self::T_DAILY)->where('day', '<', $limit)->delete();
    }

    // -------------------------------------------------------------------------
    // Periods and usage
    // -------------------------------------------------------------------------

    /** Monthly traffic included in the website's plan (or its own limit), in bytes. */
    public static function monthlyLimitBytes(object $site): int
    {
        $gb = $site->limit_gb !== null && (int) $site->limit_gb > 0
            ? (int) $site->limit_gb
            : (int) EnhanceSettings::get('cdn_plan_' . (in_array($site->plan, EnhanceSettings::CDN_PLANS, true) ? $site->plan : 'business') . '_gb');
        return $gb * EnhanceCdn::GB;
    }

    /**
     * Annuity containing $today: from the activation date's anniversary to the day before the next one.
     * @return array{0:string,1:string}|null
     */
    public static function annuity(?string $activatedAt, ?string $today = null): ?array
    {
        if (!$activatedAt) return null;
        $today = $today ?? date('Y-m-d');
        $start = new DateTimeImmutable($activatedAt);
        $now = new DateTimeImmutable($today);
        if ($start > $now) return [$start->format('Y-m-d'), $start->modify('+1 year -1 day')->format('Y-m-d')];
        $years = (int) $start->diff($now)->y;
        $periodStart = $start->modify('+' . $years . ' years');
        return [$periodStart->format('Y-m-d'), $periodStart->modify('+1 year -1 day')->format('Y-m-d')];
    }

    /** Everything shown about a website's consumption (admin and client area). */
    public static function usage(object $site, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $monthStart = substr($today, 0, 7) . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $limit = self::monthlyLimitBytes($site);
        $month = self::bytesBetween($site->website_id, $monthStart, $monthEnd);
        $annuity = self::annuity($site->activated_at, $today);
        $year = $annuity ? self::bytesBetween($site->website_id, $annuity[0], $annuity[1]) : null;
        return [
            'month'          => substr($today, 0, 7),
            'month_from'     => $monthStart,
            'month_to'       => $monthEnd,
            'month_bytes'    => $month,
            'limit_bytes'    => $limit,
            'month_percent'  => $limit > 0 ? (int) floor($month * 100 / $limit) : null,
            'annuity'        => $annuity,
            'year_bytes'     => $year,
            'year_limit'     => $limit * 12,
            'year_percent'   => $annuity && $limit > 0 ? (int) floor($year * 100 / ($limit * 12)) : null,
            'has_data'       => self::hasData($site->website_id),
        ];
    }

    // -------------------------------------------------------------------------
    // Alerts (administration only)
    // -------------------------------------------------------------------------

    /** Records an alert once; returns false when it already existed. */
    public static function raiseAlert(object $site, string $period, int $level, int $bytes, int $limit): bool
    {
        self::ensure();
        $key = ['website_id' => $site->website_id, 'period' => $period, 'level' => $level];
        if (Capsule::table(self::T_ALERTS)->where($key)->exists()) return false;
        Capsule::table(self::T_ALERTS)->insert($key + [
            'service_id' => (int) $site->service_id, 'bytes' => $bytes, 'limit_bytes' => $limit, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public static function alerts(?string $websiteId = null, bool $openOnly = false, int $limit = 50): array
    {
        self::ensure();
        $q = Capsule::table(self::T_ALERTS)->orderBy('id', 'desc')->limit($limit);
        if ($websiteId !== null) $q->where('website_id', strtolower($websiteId));
        if ($openOnly) $q->whereNull('acknowledged_at');
        return $q->get()->all();
    }

    public static function acknowledge(int $alertId): void
    {
        self::ensure();
        Capsule::table(self::T_ALERTS)->where('id', $alertId)->update(['acknowledged_at' => date('Y-m-d H:i:s')]);
    }
}
