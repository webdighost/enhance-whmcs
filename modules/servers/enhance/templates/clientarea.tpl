{* Enhance WHMCS Module - Client Area (v1.4.0). Texts: lang/*.php, keys "ca.*" *}

<style>
    /*
     * Colours follow the active WHMCS theme:
     *   1. ENHANCE_COLOR_PRIMARY / ENHANCE_COLOR_SECONDARY in config.php (if set);
     *   2. the colour of the theme's .btn-primary, detected in the browser;
     *   3. the theme CSS variables --bs-primary (Bootstrap 5) or --primary (Bootstrap 4, Croster);
     *   4. a neutral default.
     */
    .wd-enhance-client-card {
        --enhance-primary: var(--bs-primary, var(--primary, #1f6feb));
        --enhance-secondary: color-mix(in srgb, var(--enhance-primary) 70%, #000000);
        --enhance-on-primary: #ffffff;
        --enhance-primary-hover: color-mix(in srgb, var(--enhance-primary) 85%, #000000);
        --enhance-primary-shadow: color-mix(in srgb, var(--enhance-primary) 28%, transparent);
        --enhance-primary-tint: color-mix(in srgb, var(--enhance-primary) 8%, #ffffff);
        --enhance-primary-tint-text: color-mix(in srgb, var(--enhance-primary) 55%, #25364d);
    }

    .wd-enhance-client-card {
        border: 1px solid #e7ecf2;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 12px 34px rgba(18, 38, 63, 0.08);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .wd-enhance-client-hero {
        background: linear-gradient(135deg, var(--enhance-primary) 0%, var(--enhance-secondary) 100%);
        color: var(--enhance-on-primary);
        padding: 28px 30px;
    }

    .wd-enhance-client-hero h3 {
        margin: 0 0 8px 0;
        font-size: 26px;
        font-weight: 700;
        color: var(--enhance-on-primary);
    }

    .wd-enhance-client-hero p {
        margin: 0;
        color: var(--enhance-on-primary);
        opacity: .88;
        font-size: 15px;
    }

    .wd-enhance-domain-pill {
        display: inline-block;
        margin-top: 16px;
        padding: 8px 14px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--enhance-on-primary) 16%, transparent);
        color: var(--enhance-on-primary);
        font-weight: 600;
    }

    .wd-enhance-client-body {
        padding: 28px 30px 30px;
    }

    .wd-enhance-actions {
        text-align: center;
        margin-bottom: 26px;
    }

    .wd-enhance-login-btn {
        display: inline-block;
        padding: 14px 28px;
        border-radius: 999px;
        background: var(--enhance-primary);
        color: var(--enhance-on-primary) !important;
        font-size: 16px;
        font-weight: 700;
        text-decoration: none !important;
        box-shadow: 0 8px 20px var(--enhance-primary-shadow);
        transition: all .18s ease-in-out;
    }

    .wd-enhance-login-btn:hover,
    .wd-enhance-login-btn:focus {
        background: var(--enhance-primary-hover);
        color: var(--enhance-on-primary) !important;
        transform: translateY(-1px);
        box-shadow: 0 10px 24px var(--enhance-primary-shadow);
    }

    .wd-enhance-feature-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 8px;
    }

    .wd-enhance-feature {
        border: 1px solid #edf1f6;
        border-radius: 14px;
        padding: 16px;
        background: #f9fbfd;
        text-align: center;
    }

    .wd-enhance-feature i {
        font-size: 22px;
        color: var(--enhance-primary);
        margin-bottom: 8px;
    }

    .wd-enhance-feature strong {
        display: block;
        color: #25364d;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .wd-enhance-feature span {
        color: #6b778c;
        font-size: 13px;
    }

    .wd-enhance-note {
        margin-top: 22px;
        padding: 14px 16px;
        border-radius: 12px;
        background: var(--enhance-primary-tint);
        color: var(--enhance-primary-tint-text);
        font-size: 13px;
        text-align: center;
    }

    .wd-enhance-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .wd-enhance-stat {
        border: 1px solid #edf1f6;
        border-radius: 14px;
        padding: 16px;
        background: #ffffff;
    }

    .wd-enhance-stat-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-size: 13px;
        color: #6b778c;
        margin-bottom: 8px;
    }

    .wd-enhance-stat-head strong {
        color: #25364d;
        font-size: 14px;
    }

    .wd-enhance-bar {
        height: 8px;
        border-radius: 999px;
        background: #edf1f6;
        overflow: hidden;
    }

    .wd-enhance-bar span {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: var(--enhance-primary);
    }

    .wd-enhance-bar span.is-high {
        background: #e0802b;
    }

    .wd-enhance-sites {
        list-style: none;
        margin: 0 0 22px;
        padding: 0;
        border: 1px solid #edf1f6;
        border-radius: 14px;
        overflow: hidden;
    }

    .wd-enhance-sites li {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 16px;
        border-top: 1px solid #edf1f6;
        font-size: 14px;
        color: #25364d;
    }

    .wd-enhance-sites li:first-child {
        border-top: 0;
    }

    .wd-enhance-sites small {
        color: #6b778c;
    }

    .wd-enhance-status {
        font-size: 12px;
        font-weight: 600;
        color: #2e8b57;
    }

    .wd-enhance-status.is-off {
        color: #c0392b;
    }

    @media (max-width: 767px) {
        .wd-enhance-client-hero,
        .wd-enhance-client-body {
            padding: 22px;
        }

        .wd-enhance-feature-grid,
        .wd-enhance-stats {
            grid-template-columns: 1fr;
        }

        .wd-enhance-client-hero h3 {
            font-size: 22px;
        }
    }
    /* Website tools (SSL, webmail, phpMyAdmin, WordPress, backups) */
    .wd-enhance-tools {
        border: 1px solid #e7ecf2;
        border-radius: 12px;
        padding: 16px 18px;
        margin: 0 0 16px 0;
    }
    .wd-enhance-tools h4 {
        margin: 0 0 10px 0;
        font-size: 16px;
        font-weight: 700;
    }
    .wd-enhance-tools-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin: 8px 0;
    }
    .wd-enhance-tools .dropdown-menu {
        max-height: 280px;
        overflow-y: auto;
    }
    .wd-enhance-ssl {
        font-size: 13px;
        color: #475569;
    }
    .wd-enhance-ssl.is-warn {
        color: #b45309;
        font-weight: 600;
    }
    .wd-enhance-ssl.is-expired {
        color: #b91c1c;
        font-weight: 600;
    }
    .wd-enhance-backups {
        width: 100%;
        font-size: 13px;
        margin-top: 8px;
    }
    .wd-enhance-backups td,
    .wd-enhance-backups th {
        padding: 6px 8px;
        border-top: 1px solid #eef2f6;
        vertical-align: middle;
    }
    .wd-enhance-restore {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin: 0;
    }
    .wd-enhance-restore label {
        font-weight: normal;
        margin: 0;
        font-size: 12px;
    }
    .wd-enhance-sites-head {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin: 0 0 12px 0;
    }
    .wd-enhance-search {
        display: flex;
        gap: 6px;
        margin: 0;
    }
    .wd-enhance-search input {
        max-width: 240px;
    }
    .wd-enhance-tools h4 small {
        font-weight: 400;
        color: #6b778c;
        margin-left: 6px;
    }
    .wd-enhance-tools h4 .wd-enhance-status {
        float: right;
    }
    .wd-enhance-nowrap {
        white-space: nowrap;
    }
    .wd-enhance-pager {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin: 4px 0 18px 0;
    }
    .wd-enhance-gap,
    .wd-enhance-page-info {
        color: #6b778c;
        font-size: 13px;
        padding: 0 4px;
    }
</style>

<div class="wd-enhance-client-card"{if $colorPrimary} data-color-fixed="1" style="--enhance-primary: {$colorPrimary};{if $colorSecondary} --enhance-secondary: {$colorSecondary};{/if}"{/if}>
    <div class="wd-enhance-client-hero">
        <h3>
            <i class="fa fa-server"></i>
            {$enhanceLang.title|escape}
        </h3>
        <p>{$enhanceLang.subtitle|escape}</p>

        {if $domain}
            <span class="wd-enhance-domain-pill">
                <i class="fa fa-globe"></i> {$domain|escape}
            </span>
        {/if}
    </div>

    <div class="wd-enhance-client-body">
        <div class="wd-enhance-actions">
            <a href="{$ssoUrl|escape}" class="wd-enhance-login-btn">
                <i class="fa fa-sign-in"></i> {$enhanceLang.open_panel|escape}
            </a>
        </div>

        {if $enhance.available}
            {if $enhance.suspended}
                <div class="alert alert-warning">{$enhanceLang.suspended|escape}</div>
            {/if}

            {if $enhance.disk || $enhance.bandwidth}
                <div class="wd-enhance-stats">
                    {if $enhance.disk}
                        <div class="wd-enhance-stat">
                            <div class="wd-enhance-stat-head"><strong>{$enhanceLang.disk|escape}</strong><span>{if $enhance.disk.limited}{$enhance.disk.used|escape} {$enhanceLang.of|escape} {$enhance.disk.limit|escape}{else}{$enhance.disk.used|escape} &middot; {$enhanceLang.unlimited|escape}{/if}</span></div>
                            {if $enhance.disk.limited}
                                <div class="wd-enhance-bar"><span{if $enhance.disk.high} class="is-high"{/if} style="width: {$enhance.disk.percent}%;"></span></div>
                            {/if}
                        </div>
                    {/if}
                    {if $enhance.bandwidth}
                        <div class="wd-enhance-stat">
                            <div class="wd-enhance-stat-head"><strong>{$enhanceLang.traffic|escape}</strong><span>{if $enhance.bandwidth.limited}{$enhance.bandwidth.used|escape} {$enhanceLang.of|escape} {$enhance.bandwidth.limit|escape}{else}{$enhance.bandwidth.used|escape} &middot; {$enhanceLang.unlimited|escape}{/if}</span></div>
                            {if $enhance.bandwidth.limited}
                                <div class="wd-enhance-bar"><span{if $enhance.bandwidth.high} class="is-high"{/if} style="width: {$enhance.bandwidth.percent}%;"></span></div>
                            {/if}
                        </div>
                    {/if}
                </div>
            {/if}

            {if $sites.total}
                <div class="wd-enhance-sites-head">
                    <strong>{$enhanceLang.websites_count|replace:':total':$sites.total|escape}</strong>
                    {if $sites.searchable}
                        <form method="get" action="{$pageUrl|escape}" class="wd-enhance-search">
                            <input type="hidden" name="action" value="productdetails">
                            <input type="hidden" name="id" value="{$serviceId|escape}">
                            <input type="search" name="enhsearch" class="form-control input-sm" value="{$sites.search|escape}" placeholder="{$enhanceLang.search_domain|escape}">
                            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i> {$enhanceLang.search|escape}</button>
                        </form>
                    {/if}
                </div>

                {if !$sites.items}
                    <div class="alert alert-info">{$enhanceLang.search_none|replace:':term':$sites.search|escape}</div>
                {/if}

                {foreach $sites.items as $site}
                    <div class="wd-enhance-tools">
                        <h4>
                            <i class="fa fa-globe"></i> {$site.domain|escape}
                            {if $site.php}<small>PHP {$site.php|escape}</small>{/if}
                            {if $site.active}
                                <span class="wd-enhance-status">{$enhanceLang.active|escape}</span>
                            {else}
                                <span class="wd-enhance-status is-off">{$enhanceLang.inactive|escape}</span>
                            {/if}
                        </h4>
                        {if $site.tools}

                            {if $site.tools.ssl}
                                <div class="wd-enhance-ssl{if $site.tools.ssl.expired} is-expired{elseif $site.tools.ssl.warn} is-warn{/if}">
                                    <i class="fa fa-lock"></i>
                                    {if $site.tools.ssl.expired}{$enhanceLang.ssl_expired|escape}{else}{$enhanceLang.ssl_valid|escape}{/if}
                                    {$site.tools.ssl.expires|escape}{if $site.tools.ssl.issuer} &middot; {$site.tools.ssl.issuer|escape}{/if}
                                    {if !$site.tools.ssl.expired && $site.tools.ssl.days !== null} ({$enhanceLang.ssl_days|replace:':days':$site.tools.ssl.days|escape}){/if}
                                </div>
                            {elseif $site.tools.ssl_known}
                                <div class="wd-enhance-ssl is-warn"><i class="fa fa-unlock"></i> {$enhanceLang.ssl_none|escape}</div>
                            {/if}

                            <div class="wd-enhance-tools-row">
                                {if $site.tools.mailboxes}
                                    <div class="dropdown btn-group">
                                        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fa fa-envelope"></i> {$enhanceLang.webmail|escape} <span class="caret"></span>
                                        </button>
                                        <ul class="dropdown-menu">
                                            {foreach $site.tools.mailboxes as $mailbox}
                                                <li><a class="dropdown-item" target="_blank" rel="noopener" href="{$actionUrl|escape}WebmailSso&amp;website={$site.id|escape:'url'}&amp;email={$mailbox|escape:'url'}">{$mailbox|escape}</a></li>
                                            {/foreach}
                                        </ul>
                                    </div>
                                {/if}
                                {if $site.tools.databases}
                                    <div class="dropdown btn-group">
                                        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fa fa-database"></i> phpMyAdmin <span class="caret"></span>
                                        </button>
                                        <ul class="dropdown-menu">
                                            {foreach $site.tools.databases as $db}
                                                <li><a class="dropdown-item" target="_blank" rel="noopener" href="{$actionUrl|escape}PhpMyAdminSso&amp;website={$site.id|escape:'url'}&amp;db={$db|escape:'url'}">{$db|escape}</a></li>
                                            {/foreach}
                                        </ul>
                                    </div>
                                {/if}
                                {foreach $site.tools.wordpress as $wp}
                                    <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="{$actionUrl|escape}WordpressSso&amp;website={$site.id|escape:'url'}&amp;app={$wp.id|escape:'url'}">
                                        <i class="fab fa-wordpress"></i> {$enhanceLang.wordpress|escape}{if $wp.path} ({$wp.path|escape}){/if}
                                    </a>
                                {/foreach}
                            </div>

                            {if $site.tools.backups}
                                <table class="wd-enhance-backups">
                                    <tr><th colspan="4">{$enhanceLang.backups|escape}</th></tr>
                                    {foreach $site.tools.backups as $backup}
                                        <tr>
                                            <td class="wd-enhance-nowrap">{$backup.date|escape}</td>
                                            <td>{$backup.kind|escape}</td>
                                            <td class="wd-enhance-nowrap">{$backup.size|escape}</td>
                                            <td>
                                                {if $backup.pending}
                                                    <span class="wd-enhance-status is-off">{$enhanceLang.backup_running|escape}</span>
                                                {elseif $backup.failed}
                                                    <span class="wd-enhance-status is-off">{$enhanceLang.backup_failed|escape}</span>
                                                {elseif $allowRestore && $backup.ok}
                                                    <form method="post" class="wd-enhance-restore" action="{$actionUrl|escape}RestoreBackup">
                                                        <input type="hidden" name="token" value="{$csrfToken|escape}">
                                                        <input type="hidden" name="website" value="{$site.id|escape}">
                                                        <input type="hidden" name="backup" value="{$backup.id|escape}">
                                                        <label><input type="checkbox" name="include_emails" value="1"> {$enhanceLang.backup_include_emails|escape}</label>
                                                        <label><input type="checkbox" name="confirm" value="1" required> {$enhanceLang.backup_confirm|escape}</label>
                                                        <button type="submit" class="btn btn-warning btn-xs btn-sm">{$enhanceLang.backup_restore|escape}</button>
                                                    </form>
                                                {/if}
                                            </td>
                                        </tr>
                                    {/foreach}
                                </table>
                            {/if}
                        {/if}
                    </div>
                {/foreach}

                {if $sites.pages > 1}
                    <nav class="wd-enhance-pager" aria-label="{$enhanceLang.pages|escape}">
                        {foreach $sites.links as $link}
                            {if $link.gap}
                                <span class="wd-enhance-gap">&hellip;</span>
                            {elseif $link.current}
                                <span class="btn btn-primary btn-sm" aria-current="page">{$link.label|escape}</span>
                            {else}
                                <a class="btn btn-default btn-sm" href="{$link.url|escape}">{$link.label|escape}</a>
                            {/if}
                        {/foreach}
                        <span class="wd-enhance-page-info">{$enhanceLang.page_info|replace:':pages':$sites.pages|replace:':page':$sites.page|escape}</span>
                    </nav>
                {/if}
            {/if}
        {/if}

        {if $showFeatures}
            <div class="wd-enhance-feature-grid">
                <div class="wd-enhance-feature">
                    <i class="fa fa-globe"></i>
                    <strong>{$enhanceLang.feat_websites|escape}</strong>
                    <span>{$enhanceLang.feat_websites_desc|escape}</span>
                </div>

                <div class="wd-enhance-feature">
                    <i class="fa fa-envelope"></i>
                    <strong>{$enhanceLang.feat_email|escape}</strong>
                    <span>{$enhanceLang.feat_email_desc|escape}</span>
                </div>

                <div class="wd-enhance-feature">
                    <i class="fa fa-database"></i>
                    <strong>{$enhanceLang.feat_db|escape}</strong>
                    <span>{$enhanceLang.feat_db_desc|escape}</span>
                </div>
            </div>

            <div class="wd-enhance-note">
                {$enhanceLang.redirect_note|escape}
            </div>
        {/if}
    </div>
</div>

{literal}
<script>
(function () {
    var cards = document.querySelectorAll('.wd-enhance-client-card:not([data-color-fixed])');
    if (!cards.length || !window.getComputedStyle) return;

    // Read the real colour of the active theme's primary button and compare it
    // with an unstyled button: if both match, the theme does not style
    // .btn-primary and the CSS variables (--bs-primary / --primary) are kept.
    var makeProbe = function (cls) {
        var el = document.createElement('button');
        el.type = 'button';
        if (cls) el.className = cls;
        el.setAttribute('aria-hidden', 'true');
        el.style.cssText = 'position:absolute;left:-9999px;top:-9999px;visibility:hidden;pointer-events:none;transition:none;';
        document.body.appendChild(el);
        return el;
    };
    var themed = makeProbe('btn btn-primary');
    var plain = makeProbe('');
    var themedStyle = window.getComputedStyle(themed);
    var plainStyle = window.getComputedStyle(plain);
    var bg = themedStyle.backgroundColor;
    var transparent = function (c) { return !c || c === 'transparent' || /rgba\(\s*0,\s*0,\s*0,\s*0\s*\)/.test(c); };
    if (transparent(bg)) {
        // Outline-style themes: use the button border colour instead.
        bg = themedStyle.borderTopColor;
        if (bg === plainStyle.borderTopColor) bg = '';
    } else if (bg === plainStyle.backgroundColor) {
        bg = '';
    }
    document.body.removeChild(themed);
    document.body.removeChild(plain);

    var m = bg && bg.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s\/]+([\d.]+))?/);
    if (!m || (m[4] !== undefined && parseFloat(m[4]) < 0.5)) return;

    var r = +m[1], g = +m[2], b = +m[3];
    // WCAG relative luminance, to pick readable text on the primary colour.
    var lin = function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    var luminance = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    var onPrimary = luminance > 0.45 ? '#1a2433' : '#ffffff';

    for (var i = 0; i < cards.length; i++) {
        cards[i].style.setProperty('--enhance-primary', 'rgb(' + r + ', ' + g + ', ' + b + ')');
        cards[i].style.setProperty('--enhance-on-primary', onPrimary);
    }
})();
</script>
{/literal}
