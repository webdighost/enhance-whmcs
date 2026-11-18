<?php
/**
 * Enhance WHMCS Hook - buttons in the client's services list (v1.6.0)
 *
 * On clientarea.php?action=services, adds "Open control panel" (single sign-on,
 * the same link as the button of the service page) and "Manage" to each Enhance
 * service, so clients find the panel without opening the service first.
 * Works with any client area theme: the buttons are placed in the row of the
 * service by a small script. Off in Addons > Enhance Manager > Settings.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceLang.php';
require_once dirname(__FILE__, 3) . '/modules/servers/enhance/EnhanceAccess.php';

use WHMCS\Database\Capsule;

/**
 * Enhance services of the client shown in the list: id => [domain, sso].
 * The panel login is offered only for active services and to users allowed to use it.
 */
function _enhance_services_list_buttons(int $clientId): array
{
    if ($clientId <= 0) return [];
    $rows = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('h.userid', $clientId)
        ->where('p.servertype', 'enhance')
        ->whereIn('h.domainstatus', ['Active', 'Suspended'])
        ->select(['h.id', 'h.domain', 'h.domainstatus'])
        ->get();
    if (count($rows) === 0) return [];
    $maySso = EnhanceAccess::userMayUse($clientId);
    $out = [];
    foreach ($rows as $row) {
        $out[(string) (int) $row->id] = [
            'domain' => strtolower((string) $row->domain),
            'sso'    => $maySso && $row->domainstatus === 'Active',
        ];
    }
    return $out;
}

/** Script that places the buttons; $services from _enhance_services_list_buttons(). */
function _enhance_services_list_script(array $services): string
{
    $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $data = json_encode($services, $json);
    $text = json_encode(['open' => EnhanceLang::t('ca.services_open'), 'manage' => EnhanceLang::t('ca.services_manage')], $json);
    return <<<HTML
<style>
.enhance-svc-actions{margin-top:6px}
.enhance-svc-actions .btn{display:inline-block;margin:2px 2px 0;white-space:nowrap}
</style>
<script>
(function () {
    var services = {$data}, text = {$text};
    function serviceId(el) {
        var source = (el.getAttribute('href') || '') + ' ' + (el.getAttribute('onclick') || '') + ' ' + (el.getAttribute('data-href') || '') + ' ' + (el.getAttribute('data-url') || '');
        var match = source.match(/action=productdetails(?:&amp;|&)id=(\d+)/);
        return match && services.hasOwnProperty(match[1]) ? match[1] : null;
    }
    function button(href, label, primary, newTab) {
        var a = document.createElement('a');
        a.href = href;
        a.className = 'btn btn-sm ' + (primary ? 'btn-primary' : 'btn-default btn-secondary');
        a.textContent = label;
        if (newTab) { a.target = '_blank'; a.rel = 'noopener'; }
        // The row itself opens the service page: a click on a button must not reach it.
        a.addEventListener('click', function (e) { e.stopPropagation(); });
        return a;
    }
    function apply() {
        var nodes = document.querySelectorAll('tr[onclick*="productdetails"], tr [href*="productdetails"], tr[data-href*="productdetails"], tr[data-url*="productdetails"]');
        for (var i = 0; i < nodes.length; i++) {
            var row = nodes[i].closest('tr'), id = serviceId(nodes[i]);
            if (!row || !id || row.getAttribute('data-enhance-actions')) continue;
            row.setAttribute('data-enhance-actions', id);
            var cells = row.querySelectorAll('td'), cell = null, domain = services[id].domain;
            for (var c = 0; c < cells.length && !cell; c++) {
                if (domain && cells[c].textContent.toLowerCase().indexOf(domain) !== -1) cell = cells[c];
            }
            cell = cell || cells[1] || cells[0];
            if (!cell) continue;
            var box = document.createElement('div'), base = 'clientarea.php?action=productdetails&id=' + id;
            box.className = 'enhance-svc-actions';
            if (services[id].sso) box.appendChild(button(base + '&dosinglesignon=1', text.open, true, true));
            box.appendChild(button(base, text.manage, false, false));
            cell.appendChild(box);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply); else apply();
    window.addEventListener('load', apply);
    // Paginated tables (DataTables) draw rows later: place the buttons on every draw.
    if (window.jQuery) window.jQuery(document).on('draw.dt', apply);
})();
</script>
HTML;
}

add_hook('ClientAreaFooterOutput', 1, function (array $vars): string {
    try {
        if (($vars['templatefile'] ?? '') !== 'clientareaproducts') return '';
        if (!EnhanceSettings::get('services_button')) return '';
        $services = _enhance_services_list_buttons(EnhanceAccess::currentClientId());
        return $services ? _enhance_services_list_script($services) : '';
    } catch (Throwable $e) {
        return ''; // Never break the services page.
    }
});
