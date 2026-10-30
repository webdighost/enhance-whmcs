# Enhance for WHMCS - v1.4.0

WHMCS server module, management addon (Enhance Manager) and hooks that connect WHMCS to the
[Enhance](https://enhance.com) control panel through its REST API.
English interface with a complete European Portuguese translation.
No Composer or external libraries are needed in WHMCS.

> 🇵🇹 Guia em português: [LEIA-ME.md](LEIA-ME.md) · History: [CHANGELOG.md](CHANGELOG.md) ·
> Download: [latest release](https://github.com/webdighost/enhance-whmcs/releases/latest)

Upgrading from 1.0.x: the Composer `vendor/` folder of `modules/servers/enhance` is no longer used and
can be deleted; associations stored by 1.0.x (`EnhanceOrgId`, `SUBSCRIPTION_ID`) are migrated automatically.

## Features

**Provisioning:** create (organisation, owner login, subscription, website), suspend, unsuspend,
terminate (soft or hard), change plan, usage update (disk and traffic), daily status synchronisation.
Create can be repeated safely after an interruption and runs under a per-client lock.

**Client area:** usage meters, websites with PHP version, SSL certificate expiry, login to the control
panel (as the organisation owner), direct login to webmail, phpMyAdmin and WordPress admin, recent
backups and (optional) restore.

**Admin:** test connection with step-by-step diagnostics, client profile panel (organisation status,
suspend/reactivate, password reset link, search and link organisations), service tab (organisation,
subscription, website, last module action and last error, link/repair the subscription), and the
**Enhance Manager** addon: client area settings, Enhance plans to products, existing subscriptions to
services, synchronisation.

## Contents

```
includes/hooks/enhance.php                      Client profile panel; profile edits rename the organisation
includes/hooks/enhance_importer_daily_sync.php  Daily status synchronisation (WHMCS cron)
modules/addons/enhance_importer/                Importer addon
  enhance_importer.php   Addon entry points, tabs, shared helpers
  lib/packages.php       Enhance Plans tab
  lib/services.php       Existing Services tab
  lib/sync.php           Synchronisation tab
  lib/settings.php       Settings tab
modules/servers/enhance/                        Server module
  enhance.php            Module functions (provisioning, SSO, client area and tools, admin tab)
  EnhanceApi.php         API client (authentication, transport, diagnostics, all API calls)
  EnhanceStore.php       Associations, migrations, per-client lock, last action per service
  EnhanceSync.php        Status and usage synchronisation
  EnhanceLang.php        Translation loader
  EnhanceSettings.php    Settings (mod_enhance_settings) and config.php overrides
  lang/english.php       English (source)
  lang/portuguese-pt.php European Portuguese
  templates/clientarea.tpl
  config.php             Optional overrides that lock settings
```

## Installation and upgrade

1. Copy `includes/` and `modules/` to the WHMCS root, replacing the files. Module and table names
   do not change between versions; associations are kept and new tables are created automatically.
2. WHMCS > System Settings > Addon Modules: activate **Enhance Manager** (folder `enhance_importer`;
   called Enhance Importer before 1.4.0) and give your admin role access.
3. Configure the Enhance server (below) and click **Test Connection**.

### Server

WHMCS > System Settings > Servers > Add New Server, module **Enhance**:

| WHMCS field  | Value |
|--------------|-------|
| Hostname     | Control panel domain only, e.g. `cp.example.com` (no `https://`, no path). |
| Username     | UUID of the **master organisation** (the organisation that owns the token). |
| Access Hash  | Enhance API access token. |
| Password     | Leave empty (or store the token here, encrypted, and leave Access Hash empty). |
| Secure       | Ticked. |

Create the token in Enhance > Settings > Access tokens (`https://<panel>/settings/access-tokens`) of the **master organisation**, with the
**Super Admin** role; the token is shown only once. Tokens can have an **expiry date** and an
**IP restriction**: an expired token, or one that does not allow the public IP address of the WHMCS
server, is rejected like a wrong token. Watch the browser's autofill on the WHMCS server form: it may
put your WHMCS password in Password and your username in Username.

### Product (Module Settings)

| Option | Stored in | Description |
|---|---|---|
| Enhance plan | configoption1 | Plan of the master organisation (loaded from Enhance). Same as the official module. |
| Termination mode | configoption2 | Soft (keep data) or Hard (delete websites and wipe the subscription). |
| Server group | configoption3 | Where new websites are created; default: the plan's setting. |
| Client backup restore | configoption4 | Lets the account owner restore backups in the client area. Off by default. |

## Client area tools

Every website of the service is listed (1 or 1000), sorted by domain, 5 per page by default, with a
domain search when there is more than one page. The website list costs one API call; the tools below are
loaded only for the websites of the page shown (up to 5 calls each, only for the sections shown) and never
while the subscription is suspended. Everything is cached for 5 minutes by default; the login links are
only created when the client clicks. All of this is configurable in the Settings tab (below).

- **Webmail:** one entry per mailbox where Enhance allows SSO.
- **phpMyAdmin:** one entry per database.
- **WordPress admin:** per WordPress installation; logs in as the app's default user, otherwise
  the first administrator.
- **SSL:** certificate of the primary domain, expiry date and days left (highlighted from 14 days).
  "No SSL certificate" is only shown when Enhance confirms it, never when the query fails.
- **Backups:** last 5, newest first, with date and time, type, size and status. The size is the backed
  up content (files + databases + emails) in B/KB/MB/GB; it is left empty when Enhance reports 0 and can be
  hidden in the settings. A backup with nothing to copy is never
  shown as failed. Restore (when enabled in the product) restores files
  and databases, optionally all mailboxes; it requires the account owner, a confirmation checkbox and a
  valid CSRF token, accepts only successful backups and is written to the activity log.

Users of a client account (WHMCS 8 users) need the **Single Sign-On** permission for the login buttons.
Creating backups is not offered: Enhance runs it as one long request that would exceed PHP limits.

## Settings (Addons > Enhance Manager > Settings)

| Section | Settings |
|---|---|
| Appearance | Primary and secondary colour (empty = follow the WHMCS theme), with a live preview; language (automatic, English, Portuguese). |
| Client area sections | Usage, SSL, webmail, phpMyAdmin, WordPress, backups, backup size, information cards. Hidden sections are not requested from Enhance. |
| Listing and performance | Websites per page (1-50), backups shown per website (1-30), cache in seconds (0-3600, 0 = off). |
| Backup restore | Global switch; restore also needs the option in each product and is reserved to the account owner. |

Settings are stored in the WHMCS database (`mod_enhance_settings`), so module updates keep them.
To force a setting from the files, uncomment its constant in `modules/servers/enhance/config.php`;
it is then shown as locked in the Settings tab.

## Admin service tab

- Organisation, subscription, server and website details.
- **Last module action**: action, result and date, plus the last error and its message (kept until a
  newer error), so failures are visible without enabling the Module Log.
- **Link subscription**: choose another subscription of the client's organisation, or remove the
  link (nothing is deleted in Enhance). Applied when the service is saved; subscriptions linked to other
  services are shown but cannot be chosen.
- The panel login button that WHMCS shows on the admin service page uses the same SSO function as
  the client area: the admin enters as the client (organisation owner), and this is written to the
  activity log.

## Replacing the official Enhance module (1.1.x)

Same module name (`modules/servers/enhance`) and same plan option (`configoption1`), so existing
products keep working. On first use the module migrates the official module's data: the client field
`enhOrgId` and the product fields `enhanceSubscriptionId` / `subscriptionId` (only on products using
the Enhance module). Delete `modules/servers/enhance` (including its `api/` folder) before copying
this version.

| | Official 1.1.4 | This module |
|---|---|---|
| Owner | member created with the `Owner` role | same; owner-less organisations repaired; confirmed via the API |
| Password | the WHMCS service password is set on the Enhance login | random, never emailed; Enhance sends a password-setting link |
| Failed website | organisation, login and subscription left behind | subscription rolled back; Create can be repeated |
| Concurrency | none | per-client lock |
| Termination | soft | soft or hard |
| Client area | login link | usage, SSL, webmail, phpMyAdmin, WordPress, backups |

## Translations

The language follows WHMCS: the administrator's language in the admin area and the visitor's
language in the client area (any `portuguese*` language uses European Portuguese; others fall back to
English). Force one in the Settings tab. To add a language, copy
`lang/english.php` to `lang/<whmcs-language-name>.php` and translate the values.

The email template **Enhance Panel Access** is created with an English master and a `portuguese-pt`
translation. Merge fields: `{$panel_url}`, `{$login_email}`, `{$new_login}`.

## API

All 39 API calls were checked against the official OpenAPI specification (https://apidocs.enhance.com,
orchd **12.26.3**). Authentication is `Authorization: Bearer <token>` to `https://<hostname>/api/`.
Behaviour observed on a real Enhance panel and handled by the module: `PUT /orgs/{id}/owner` is refused
to access tokens (403) and member updates do not accept the `Owner` role, so owners are created as
members with that role. API calls appear in Utilities > Logs > Module Log when module logging is on;
tokens and passwords are always masked.
