<?php
// WDH-CDN: settings (encrypted key), provider API client, admin association and validation,
// idempotent daily sync, alerts, history, failures, client area data and template.
require __DIR__ . '/support/setting_stub.php';
define('ENHANCE_CDN_API_BASE', 'https://localhost:18443/bunny');
$GLOBALS['ENHANCE_ROOT'] = $argv[1];
require __DIR__ . '/support/whmcs_boot.php';
require $argv[1] . '/modules/addons/enhance_importer/enhance_importer.php';
use WHMCS\Database\Capsule;

$fails = 0;
$check = function ($n, $c, $x = '') use (&$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "$n" . ($c ? '' : " :: $x") . "\n"; if (!$c) $fails++; };
$ctx = stream_context_create(['ssl' => ['cafile' => getenv('ENHANCE_TEST_CA')], 'http' => ['header' => "Authorization: Bearer tok_GOOD&abc\r\n"]]);
$bunny = fn(string $q) => file_get_contents('https://localhost:18443/bunny/__control?' . $q, false, $ctx);
$log = fn() => json_decode(file_get_contents('https://localhost:18443/api/__log', false, $ctx), true);
EnhanceLang::reset('portuguese-pt');
EnhanceSettings::reset();
$p = $GLOBALS['PARAMS'];
$WEB = 'abababab-0000-4000-8000-000000000001';   // acme.pt, website of service 50
$WEB2 = 'abababab-0000-4000-8000-000000000101';  // site01.pt, same service
$TODAY = gmdate('Y-m-d');

// --- 1. Settings: encrypted key, never exposed; each tab saves only its own settings
EnhanceSettings::save(['cdn_enabled' => '1', 'cdn_api_key' => " bunny_GOOD_key \n", 'cdn_show_inactive' => '1', 'cdn_alerts' => '1', 'cdn_warn_pct' => '80', 'cdn_crit_pct' => '100'], EnhanceSettings::keys('cdn_'));
$raw = (string) Capsule::table('mod_enhance_settings')->where('setting', 'cdn_api_key')->value('value');
$all = EnhanceSettings::all();
$check('API key stored encrypted, decrypted only on the server', strpos($raw, 'enc:') === 0 && strpos($raw, 'bunny_GOOD_key') === false && EnhanceSettings::get('cdn_api_key') === 'bunny_GOOD_key', $raw);
$check('the key is never returned to the forms (only "configured" and the last 4 characters)', $all['cdn_api_key']['value'] === '' && $all['cdn_api_key']['configured'] && $all['cdn_api_key']['hint'] === '_key');
EnhanceSettings::save(['cdn_api_key' => ''], ['cdn_api_key']);
$check('an empty key field keeps the stored key', EnhanceSettings::get('cdn_api_key') === 'bunny_GOOD_key');
EnhanceSettings::save(['websites_per_page' => '5'], EnhanceSettings::keys('cdn_', true));
$check('saving the Settings tab does not touch the CDN settings', EnhanceSettings::get('cdn_enabled') === true);
$check('plan defaults: Business 10 GB free, Standard 25 GB 25 EUR, Volume 75 GB 50 EUR', EnhanceSettings::get('cdn_plan_business_gb') === 10 && EnhanceSettings::get('cdn_plan_standard_gb') === 25 && EnhanceSettings::get('cdn_plan_volume_gb') === 75 && EnhanceSettings::get('cdn_plan_standard_price') === 25.0 && EnhanceSettings::get('cdn_plan_volume_price') === 50.0 && EnhanceSettings::get('cdn_plan_business_price') === 0.0);

// --- 2. Provider API client
$cdn = EnhanceCdn::fromSettings();
$check('test connection with the right key', $cdn->testConnection()['success'] === true);
$bad = (new EnhanceCdn('wrong_key', ENHANCE_CDN_API_BASE))->testConnection();
$check('wrong key: HTTP 401 explained', !$bad['success'] && strpos($bad['error'], 'HTTP 401') !== false && strpos($bad['error'], 'recusada') !== false, $bad['error']);
$GLOBALS['modlog'] = [];
$mark = count($log());
$zone = $cdn->getPullZone(1001);
$zoneCalls = array_values(array_filter(array_slice($log(), $mark), fn($r) => strpos($r[1], '/bunny/pullzone/') === 0));
$check('zones always requested with includeCertificate=false', $zoneCalls && strpos($zoneCalls[0][1], 'includeCertificate=false') !== false, json_encode($zoneCalls));
$check('zone reduced to what is needed (no certificate or private key)', $zone['hostnames'] === ['zone1001.b-cdn.net', 'antifraude.wdh-cdn.eu'] && $zone['type'] === 'premium' && strpos(json_encode($zone), 'PRIVATE') === false, json_encode($zone));
$logged = json_encode($GLOBALS['modlog']);
$check('module log: key masked, no response body', strpos($logged, 'Hostnames') === false && in_array('bunny_GOOD_key', $GLOBALS['modlog'][0][5] ?? [], true), $logged);
$check('unknown zone: 404 explained', strpos(EnhanceCdn::errorSummary($cdn->getPullZone(4242)), 'Pull Zone não encontrada') !== false);
$mark = count($log());
$stats = $cdn->dailyTraffic(1001, '2026-11-01', '2026-11-03');
$statCall = array_values(array_filter(array_slice($log(), $mark), fn($r) => strpos($r[1], '/bunny/statistics') === 0));
$check('statistics ask for the traffic chart (loadBandwidthUsed=true) and return bytes per day', strpos($statCall[0][1] ?? '', 'loadBandwidthUsed=true') !== false && count($stats['days']) === 3 && $stats['days']['2026-11-01'] === 536870912, json_encode($stats));
$bunny('ratelimit=1');
$t0 = microtime(true);
$retry = $cdn->getPullZone(1001);
$check('HTTP 429: waits for Retry-After and retries', !EnhanceCdn::isError($retry) && microtime(true) - $t0 >= 0.9);
$check('CDN address: hostname only, invalid values refused (no URL is ever requested)', EnhanceCdn::normaliseHost('https://Antifraude.WDH-CDN.eu/path?x') === 'antifraude.wdh-cdn.eu' && EnhanceCdn::normaliseHost('http://127.0.0.1') === '' && EnhanceCdn::normaliseHost('file:///etc/passwd') === '' && EnhanceCdn::normaliseHost('bad host.eu') === '');

// --- 3. Admin: association per website (service tab save), validations
$_SESSION = ['adminid' => 1];
$save = function (array $cdnPost) use ($p) { $_POST = ['enhance_cdn' => $cdnPost]; enhance_AdminServicesTabFieldsSave($p); $_POST = []; return EnhanceStore::lastEvent(50); };
$e = $save([$WEB => ['present' => 1, 'active' => 1, 'host' => 'wrong.wdh-cdn.eu', 'zone' => '1001', 'plan' => 'standard']]);
$check('address that is not a hostname of the zone is refused', !$e->success && strpos((string) $e->last_error, 'não é um hostname da Pull Zone 1001') !== false && EnhanceCdnStore::site($WEB) === null, (string) $e->last_error);
$e = $save([$WEB => ['present' => 1, 'active' => 1, 'host' => '', 'zone' => '', 'plan' => 'standard']]);
$check('activation without zone or address refused', !$e->success && strpos((string) $e->last_error, 'Pull Zone ID') !== false);
$e = $save(['ffffffff-0000-4000-8000-000000000000' => ['present' => 1, 'active' => 1, 'host' => 'x.wdh-cdn.eu', 'zone' => '1001']]);
$check('website id that does not belong to the service is refused', !$e->success && strpos((string) $e->last_error, 'não pertence a este serviço') !== false);
$e = $save([$WEB => ['present' => 1, 'active' => 1, 'host' => 'https://antifraude.wdh-cdn.eu', 'zone' => '1001', 'plan' => 'standard', 'activated' => date('Y-m-01')],
            $WEB2 => ['present' => 1, 'plan' => 'business']]);
$site = EnhanceCdnStore::site($WEB);
$check('valid association saved (address normalised, plan, activation date); untouched website not stored', $e->success && $site && $site->active && $site->cdn_host === 'antifraude.wdh-cdn.eu' && (int) $site->zone_id === 1001 && $site->plan === 'standard' && $site->activated_at === date('Y-m-01') && EnhanceCdnStore::site($WEB2) === null, json_encode([$e, $site]));
// Shared zone with another client's website needs confirmation
EnhanceCdnStore::saveSite('cccccccc-0000-4000-8000-000000000001', ['service_id' => 77, 'client_id' => 33, 'domain' => 'outro.pt', 'active' => true, 'zone_id' => 1002, 'cdn_host' => 'other.wdh-cdn.eu', 'plan' => 'volume']);
$e = $save([$WEB2 => ['present' => 1, 'active' => 1, 'host' => 'other.wdh-cdn.eu', 'zone' => '1002', 'plan' => 'volume']]);
$check('zone used by another client: refused without confirmation', !$e->success && strpos((string) $e->last_error, 'já é usada por outro.pt (cliente #33)') !== false && EnhanceCdnStore::site($WEB2) === null, (string) $e->last_error);
$e = $save([$WEB2 => ['present' => 1, 'active' => 1, 'host' => 'other.wdh-cdn.eu', 'zone' => '1002', 'plan' => 'volume', 'confirm_shared' => 1]]);
$check('zone used by another client: accepted with confirmation', $e->success && EnhanceCdnStore::site($WEB2)->active);
Capsule::table('mod_enhance_cdn_sites')->where('website_id', 'cccccccc-0000-4000-8000-000000000001')->delete();

// --- 4. Sync: back-fill, idempotence, late corrections, zone change keeps history
$monthStart = date('Y-m-01');
$days = (int) ((strtotime($TODAY) - strtotime($monthStart)) / 86400) + 1;
$r = EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($WEB), $TODAY);
$u = EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY);
$check("first sync back-fills from the activation ($days days of 0,5 GB)", $r['ok'] && $u['month_bytes'] === $days * 536870912, json_encode([$r, $u['month_bytes']]));
EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($WEB), $TODAY);
EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($WEB), $TODAY);
$check('repeated syncs never duplicate traffic', EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes'] === $days * 536870912);
$bunny('bump=2');
EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($WEB), $TODAY);
$bunny('bump=1');
$resynced = min($days, EnhanceCdnSync::RESYNC_DAYS + 1);
$expected = ($days - $resynced) * 536870912 + $resynced * 2 * 536870912;
$check("late provider corrections: the last $resynced days are re-read, older days unchanged", EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes'] === $expected, (string) EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes']);
$site = EnhanceCdnStore::site($WEB);
$check('zone details stored for the admin (type, provider charges of the month)', $site->zone_type === 'premium' && (float) $site->provider_month_charges === 1.2345 && $site->last_sync_status === 'ok');

// --- 5. Alerts (admin only, once per level and period)
Capsule::table('mod_enhance_cdn_sites')->where('website_id', $WEB)->update(['limit_gb' => 1]);
$activityBefore = count($GLOBALS['activity']);
$raised = EnhanceCdnSync::checkAlerts(EnhanceCdnStore::site($WEB), $TODAY);
$again = EnhanceCdnSync::checkAlerts(EnhanceCdnStore::site($WEB), $TODAY);
$monthAlerts = array_filter($raised, fn($x) => strpos($x, 'month:') === 0);
$check('limit exceeded: warning (80%) and critical (100%) alerts raised once, in the activity log', count($monthAlerts) === 2 && $again === [] && count($GLOBALS['activity']) === $activityBefore + 2 && strpos(end($GLOBALS['activity']), 'WDH-CDN') === 0, json_encode([$raised, $again], JSON_UNESCAPED_UNICODE));
Capsule::table('mod_enhance_cdn_sites')->where('website_id', $WEB)->update(['limit_gb' => null]);
EnhanceSettings::save(['cdn_alerts' => ''], ['cdn_alerts']);
Capsule::table('mod_enhance_cdn_alerts')->delete();
$check('no alerts when alerts are off', EnhanceCdnSync::checkAlerts(EnhanceCdnStore::site($WEB), $TODAY) === []);
EnhanceSettings::save(['cdn_alerts' => '1'], ['cdn_alerts']);

// --- 6. Failures keep the last values; the CDN state is never changed
$before = EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes'];
$bunny('fail=1');
$r = EnhanceCdnSync::syncSite($cdn, EnhanceCdnStore::site($WEB), $TODAY);
$bunny('fail=0');
$site = EnhanceCdnStore::site($WEB);
$check('API unavailable: error recorded, values kept, CDN still active', !$r['ok'] && $site->last_sync_status === 'error' && $site->active && EnhanceCdnStore::usage($site, $TODAY)['month_bytes'] === $before, json_encode($r));

// --- 7. Client area: local data only, own websites only, privacy of the provider
$_SESSION = ['uid' => 9];
$mark = count($log());
$ca = enhance_ClientArea($p);
$bunnyCalls = array_filter(array_slice($log(), $mark), fn($r) => strpos($r[1], '/bunny/') === 0);
$items = [];
foreach ($ca['templateVariables']['sites']['items'] as $w) $items[$w['id']] = $w['cdn'];
$check('client area makes no CDN API call (local data only)', count($bunnyCalls) === 0);
$check('active website: consumption, plan limit (25 GB), bar, annuity', $items[$WEB]['active'] && $items[$WEB]['month_limit'] === '25,0 GB' && $items[$WEB]['percent'] !== null && $items[$WEB]['year_limit'] === '300 GB', json_encode($items[$WEB]));
$check('last sync failed: last known values marked as such, with their date', $items[$WEB]['stale'] && $items[$WEB]['updated'] !== '');
$check('website of another client on the page? never: only this service\'s websites are listed', count(array_diff(array_keys($items), array_keys(_enhance_cdn_service_websites(_enhance_api($p), $p)))) === 0);
$smarty = new Smarty(); $smarty->setCompileDir(__DIR__ . '/.tmp/smarty_c');
foreach ($ca['templateVariables'] as $k => $v) $smarty->assign($k, $v);
$html = $smarty->fetch($argv[1] . '/modules/servers/enhance/templates/clientarea.tpl');
$text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$check('template: WDH-CDN indicator with month, limit and annuity', strpos($text, 'WDH-CDN Ativo') !== false && strpos($text, 'Este mês:') !== false && strpos($text, '/ 25,0 GB') !== false && strpos($text, 'Anuidade (') !== false && strpos($text, 'Último consumo conhecido') !== false, $text);
$check('template: "Não ativo" on websites without CDN, no empty bars', substr_count($text, 'WDH-CDN Não ativo') === 3 && substr_count($html, 'class="wd-enhance-cdn-bar"') === 1);
$check('template: the provider is never named in the client area', stripos($html, 'bunny') === false && stripos($html, 'b-cdn') === false && stripos($html, 'pull zone') === false);
EnhanceSettings::save(['cdn_enabled' => ''], ['cdn_enabled']);
$ca = enhance_ClientArea($p);
$check('integration off: nothing CDN in the client area', !array_filter(array_column($ca['templateVariables']['sites']['items'], 'cdn')));
EnhanceSettings::save(['cdn_enabled' => '1'], ['cdn_enabled']);

// --- 8. Zone change keeps the history; deactivation keeps data; annuity and retention
$_SESSION = ['adminid' => 1];
$before = EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes'];
$e = $save([$WEB => ['present' => 1, 'active' => 1, 'host' => 'other.wdh-cdn.eu', 'zone' => '1002', 'plan' => 'volume', 'activated' => date('Y-m-01'), 'sync' => 1]]);
$after = EnhanceCdnStore::usage(EnhanceCdnStore::site($WEB), $TODAY)['month_bytes'];
$check('Pull Zone change keeps the previous zone\'s history (sync on save)', $e->success && $after > $before && Capsule::table('mod_enhance_cdn_daily')->where('website_id', $WEB)->where('zone_id', 1001)->exists(), json_encode([$e, $before, $after]));
$check('Volume plan on a Volume zone: no mismatch; history per month available', EnhanceCdnStore::site($WEB)->zone_type === 'volume' && count(EnhanceCdnStore::monthly($WEB)) >= 1);
$e = $save([$WEB => ['present' => 1, 'host' => 'other.wdh-cdn.eu', 'zone' => '1002', 'plan' => 'volume']]);
$site = EnhanceCdnStore::site($WEB);
$check('deactivation: kept with its date and data, not synced any more', !$site->active && $site->deactivated_at === date('Y-m-d') && EnhanceCdnStore::hasData($WEB) && !in_array($WEB, array_column(EnhanceCdnStore::activeSites(), 'website_id'), true));
$check('annuity from the activation anniversary', EnhanceCdnStore::annuity('2025-03-05', '2026-11-10') === ['2026-03-05', '2027-03-04'] && EnhanceCdnStore::annuity('2026-11-01', '2026-11-10') === ['2026-11-01', '2027-10-31']);
EnhanceCdnStore::putDay($WEB, 1002, '2020-01-01', 5, 1);
$check('retention purges old daily records only', EnhanceCdnStore::purge(24) === 1 && EnhanceCdnStore::hasData($WEB));

// --- 9. Enhance Manager tab and the scheduled sync
$form = enhance_importer_cdn_form(EnhanceSettings::all(), 'addonmodules.php?module=enhance_importer&tab=cdn');
$check('WDH-CDN tab: key field empty (never shows the key), CSRF tokens, plans', strpos($form, 'bunny_GOOD_key') === false && strpos($form, 'configurada (••••_key)') !== false && substr_count($form, 'name="token" value="CSRF1"') === 3 && strpos($form, 'name="cdn_plan_volume_gb"') !== false);
Capsule::table('tblconfiguration')->where('setting', EnhanceCdnSync::LAST_RUN)->delete();
$check('scheduled sync is due without a previous run', EnhanceCdnSync::isDue());
$save([$WEB => ['present' => 1, 'active' => 1, 'host' => 'other.wdh-cdn.eu', 'zone' => '1002', 'plan' => 'volume']]);
$res = EnhanceCdnSync::syncAll($TODAY);
$check('sync of all active websites, then not due before the interval', $res['sites'] === 2 && $res['ok'] === 2 && !EnhanceCdnSync::isDue(), json_encode($res));
$overview = enhance_importer_cdn_overview('x');
$check('overview lists the websites with CDN', strpos($overview, 'acme.pt') !== false && strpos($overview, 'site01.pt') !== false);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
