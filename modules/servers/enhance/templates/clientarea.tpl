{* Enhance WHMCS Module — Client Area *}

<style>
    .wd-enhance-client-card {
        border: 1px solid #e7ecf2;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 12px 34px rgba(18, 38, 63, 0.08);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .wd-enhance-client-hero {
        background: linear-gradient(135deg, #17b8b0 0%, #2f6fdf 100%);
        color: #ffffff;
        padding: 28px 30px;
    }

    .wd-enhance-client-hero h3 {
        margin: 0 0 8px 0;
        font-size: 26px;
        font-weight: 700;
        color: #ffffff;
    }

    .wd-enhance-client-hero p {
        margin: 0;
        color: rgba(255,255,255,0.88);
        font-size: 15px;
    }

    .wd-enhance-domain-pill {
        display: inline-block;
        margin-top: 16px;
        padding: 8px 14px;
        border-radius: 999px;
        background: rgba(255,255,255,0.16);
        color: #ffffff;
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
        background: #17b8b0;
        color: #ffffff !important;
        font-size: 16px;
        font-weight: 700;
        text-decoration: none !important;
        box-shadow: 0 8px 20px rgba(23,184,176,0.28);
        transition: all .18s ease-in-out;
    }

    .wd-enhance-login-btn:hover,
    .wd-enhance-login-btn:focus {
        background: #129c96;
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(23,184,176,0.34);
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
        color: #17b8b0;
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
        background: #f3fbfa;
        color: #4b6375;
        font-size: 13px;
        text-align: center;
    }

    @media (max-width: 767px) {
        .wd-enhance-client-hero,
        .wd-enhance-client-body {
            padding: 22px;
        }

        .wd-enhance-feature-grid {
            grid-template-columns: 1fr;
        }

        .wd-enhance-client-hero h3 {
            font-size: 22px;
        }
    }
</style>

<div class="wd-enhance-client-card">
    <div class="wd-enhance-client-hero">
        <h3>
            <i class="fa fa-server"></i>
            Hosting Control Panel
        </h3>
        <p>Manage your website, email accounts, databases and hosting tools from one place.</p>

        {if $domain}
            <span class="wd-enhance-domain-pill">
                <i class="fa fa-globe"></i> {$domain}
            </span>
        {/if}
    </div>

    <div class="wd-enhance-client-body">
        <div class="wd-enhance-actions">
            <a href="{$ssoUrl}" class="wd-enhance-login-btn">
                <i class="fa fa-sign-in"></i> Open Hosting Panel
            </a>
        </div>

        <div class="wd-enhance-feature-grid">
            <div class="wd-enhance-feature">
                <i class="fa fa-globe"></i>
                <strong>Websites</strong>
                <span>Manage sites, domains and SSL.</span>
            </div>

            <div class="wd-enhance-feature">
                <i class="fa fa-envelope"></i>
                <strong>Email</strong>
                <span>Create and manage mailboxes.</span>
            </div>

            <div class="wd-enhance-feature">
                <i class="fa fa-database"></i>
                <strong>Databases</strong>
                <span>Access database tools and users.</span>
            </div>
        </div>

        <div class="wd-enhance-note">
            You will be securely redirected to your hosting control panel.
        </div>
    </div>
</div>
