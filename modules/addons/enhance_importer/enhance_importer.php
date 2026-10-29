<?php
/**
 * Enhance Manager for WHMCS (v1.4.0) - addon folder "enhance_importer"
 *
 * Imports Enhance plans as WHMCS products and existing Enhance subscriptions
 * as WHMCS services, runs the status synchronisation on demand and manages
 * the module settings (client area appearance and sections).
 * Texts are translated through EnhanceLang (keys "imp.*" and "set.*").
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

require_once dirname(__FILE__, 3) . '/servers/enhance/EnhanceApi.php';
require_once dirname(__FILE__, 3) . '/servers/enhance/EnhanceSync.php';
require_once __DIR__ . '/lib/packages.php';
require_once __DIR__ . '/lib/services.php';
require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/settings.php';

function enhance_importer_config(): array
{
    return [
        'name'        => EnhanceLang::t('imp.name'),
        'description' => EnhanceLang::t('imp.description'),
        'version'     => EnhanceApi::VERSION,
        'author'      => 'WebDig.DEV',
        'language'    => 'english',
        'fields'      => [],
    ];
}

function enhance_importer_activate(): array
{
    try {
        enhance_importer_ensure_schema();
        EnhanceStore::ensure();
        return ['status' => 'success', 'description' => EnhanceLang::t('imp.activated')];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => $e->getMessage()];
    }
}

function enhance_importer_deactivate(): array
{
    return ['status' => 'success', 'description' => EnhanceLang::t('imp.deactivated')];
}

function enhance_importer_upgrade(array $vars): void
{
    enhance_importer_ensure_schema();
}

function enhance_importer_ensure_schema(): void
{
    EnhanceSettings::ensureTable();
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_enhance_package_map')) {
        $schema->create('mod_enhance_package_map', function ($table) {
            $table->increments('id');
            $table->integer('server_id')->default(0);
            $table->string('enhance_plan_id', 191);
            $table->integer('whmcs_product_id')->default(0);
            $table->string('plan_name', 191)->nullable();
            $table->text('plan_snapshot')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['server_id', 'enhance_plan_id'], 'server_plan_unique');
        });
    }
    if (!$schema->hasTable('mod_enhance_service_import_log')) {
        $schema->create('mod_enhance_service_import_log', function ($table) {
            $table->increments('id');
            $table->integer('server_id')->default(0);
            $table->string('enhance_org_id', 191);
            $table->string('enhance_subscription_id', 191);
            $table->integer('client_id')->default(0);
            $table->integer('service_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->string('status', 64)->nullable();
            $table->text('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['server_id', 'enhance_subscription_id'], 'server_subscription_unique');
        });
    }
}

/** Escapes for HTML output. */
function enhance_importer_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Translated and escaped. */
function enhance_importer_t(string $key, array $vars = []): string
{
    return enhance_importer_h(EnhanceLang::t($key, $vars));
}

function enhance_importer_output(array $vars): void
{
    try {
        enhance_importer_ensure_schema();
    } catch (Throwable $e) {
        echo enhance_importer_alert('danger', enhance_importer_t('imp.schema_failed', ['detail' => $e->getMessage()]));
        return;
    }

    $moduleLink = $vars['modulelink'];
    $tab = (string) ($_REQUEST['tab'] ?? 'packages');
    if (!in_array($tab, ['packages', 'services', 'sync', 'settings'], true)) $tab = 'packages';

    $servers      = Capsule::table('tblservers')->where('type', 'enhance')->where('disabled', 0)->orderBy('name')->get();
    $groups       = Capsule::table('tblproductgroups')->orderBy('order')->orderBy('name')->get();
    $serverGroups = Capsule::table('tblservergroups')->orderBy('name')->get();
    $currencies   = Capsule::table('tblcurrencies')->orderBy('default', 'desc')->orderBy('id')->get();

    $selectedServerId      = (int) ($_REQUEST['server_id'] ?? ($servers[0]->id ?? 0));
    $selectedGroupId       = (int) ($_REQUEST['gid'] ?? ($groups[0]->id ?? 0));
    $selectedServerGroupId = (int) ($_REQUEST['servergroupid'] ?? 0);

    echo '<div class="container-fluid">';
    echo '<h2>' . enhance_importer_t('imp.title') . ' <small>v' . enhance_importer_h(EnhanceApi::VERSION) . '</small></h2>';
    echo '<p>' . enhance_importer_t('imp.intro') . '</p>';
    echo enhance_importer_tabs($moduleLink, $tab, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['enhance_importer_action']) && !enhance_importer_valid_post((string) $_POST['enhance_importer_action'])) {
        echo enhance_importer_alert('danger', enhance_importer_t('imp.bad_token'));
    }

    // Settings need no Enhance server or API call.
    if ($tab === 'settings') {
        try {
            enhance_importer_settings_page($moduleLink);
        } catch (Throwable $e) {
            echo enhance_importer_alert('danger', enhance_importer_t('imp.error', ['detail' => $e->getMessage()]));
        }
        echo '</div>';
        return;
    }

    if ($servers->isEmpty()) {
        echo enhance_importer_alert('danger', enhance_importer_t('imp.no_server'));
        echo '</div>';
        return;
    }

    $server = $servers->firstWhere('id', $selectedServerId) ?: $servers->first();
    $selectedServerId = (int) $server->id;

    try {
        $api = EnhanceApi::fromServerRow($server);
        if ($tab === 'services') {
            enhance_importer_services_page($moduleLink, $api, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        } elseif ($tab === 'sync') {
            enhance_importer_sync_page($moduleLink, $api, $servers, $groups, $serverGroups, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        } else {
            enhance_importer_packages_page($moduleLink, $api, $servers, $groups, $serverGroups, $currencies, $selectedServerId, $selectedGroupId, $selectedServerGroupId);
        }
    } catch (Throwable $e) {
        echo enhance_importer_alert('danger', enhance_importer_t('imp.error', ['detail' => $e->getMessage()]));
    }

    echo '</div>';
}

function enhance_importer_tabs(string $moduleLink, string $active, int $serverId, int $gid, int $sgid): string
{
    $base = $moduleLink . '&server_id=' . $serverId . '&gid=' . $gid . '&servergroupid=' . $sgid;
    $tabs = [
        'packages' => EnhanceLang::t('imp.tab_packages'),
        'services' => EnhanceLang::t('imp.tab_services'),
        'sync'     => EnhanceLang::t('imp.tab_sync'),
        'settings' => EnhanceLang::t('imp.tab_settings'),
    ];
    $html = '<ul class="nav nav-tabs" style="margin:15px 0;">';
    foreach ($tabs as $key => $label) {
        $class = $active === $key ? ' class="active"' : '';
        $html .= '<li' . $class . '><a href="' . enhance_importer_h($base . '&tab=' . $key) . '">' . enhance_importer_h($label) . '</a></li>';
    }
    return $html . '</ul>';
}

function enhance_importer_filters(string $moduleLink, $servers, $groups, $serverGroups, int $selectedServerId, int $selectedGroupId, int $selectedServerGroupId, string $tab = 'packages'): string
{
    $serverOptions = '';
    foreach ($servers as $server) {
        $sel = ((int) $server->id === $selectedServerId) ? ' selected' : '';
        $serverOptions .= '<option value="' . (int) $server->id . '"' . $sel . '>' . enhance_importer_h(($server->name ?: $server->hostname) . ' - ' . $server->hostname) . '</option>';
    }
    $groupOptions = '';
    foreach ($groups as $group) {
        $sel = ((int) $group->id === $selectedGroupId) ? ' selected' : '';
        $groupOptions .= '<option value="' . (int) $group->id . '"' . $sel . '>' . enhance_importer_h($group->name) . '</option>';
    }
    $serverGroupOptions = '<option value="0">' . enhance_importer_t('imp.no_server_group') . '</option>';
    foreach ($serverGroups as $sg) {
        $sel = ((int) $sg->id === $selectedServerGroupId) ? ' selected' : '';
        $serverGroupOptions .= '<option value="' . (int) $sg->id . '"' . $sel . '>' . enhance_importer_h($sg->name) . '</option>';
    }
    $action = enhance_importer_h($moduleLink);
    $tab    = enhance_importer_h($tab);
    $lServer = enhance_importer_t('imp.filter_server');
    $lGroup  = enhance_importer_t('imp.filter_group');
    $lSGroup = enhance_importer_t('imp.filter_server_group');
    $lSearch = enhance_importer_t('imp.filter_search');
    return <<<HTML
<form method="get" action="{$action}" class="panel panel-default" style="padding:15px;margin:15px 0;">
    <input type="hidden" name="module" value="enhance_importer"><input type="hidden" name="tab" value="{$tab}">
    <div class="row">
        <div class="col-md-4"><label>{$lServer}</label><select name="server_id" class="form-control">{$serverOptions}</select></div>
        <div class="col-md-4"><label>{$lGroup}</label><select name="gid" class="form-control">{$groupOptions}</select></div>
        <div class="col-md-3"><label>{$lSGroup}</label><select name="servergroupid" class="form-control">{$serverGroupOptions}</select></div>
        <div class="col-md-1" style="padding-top:24px;"><button class="btn btn-primary" type="submit">{$lSearch}</button></div>
    </div>
</form>
HTML;
}

function enhance_importer_token_field(): string
{
    return '<input type="hidden" name="token" value="' . enhance_importer_h(generate_token('plain')) . '">';
}

function enhance_importer_valid_post(string $action): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['enhance_importer_action'] ?? '') !== $action) return false;
    try {
        return !empty($_POST['token']) && check_token('WHMCS.admin.default', $_POST['token']);
    } catch (Throwable $e) {
        return false;
    }
}

/** $message must already be escaped. */
function enhance_importer_alert(string $type, string $message): string
{
    return '<div class="alert alert-' . enhance_importer_h($type) . '">' . $message . '</div>';
}
