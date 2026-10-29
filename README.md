# Enhance for WHMCS - v1.4.0

WHMCS server module, importer addon and hooks that connect WHMCS to the
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
subscription, website, last module action and last error, link/repair the subscription), importer
addon (Enhance plans to products, existing subscriptions to services, synchronisation).

## Contents

```
includes/hooks/enhance.php                      Client profile panel; profile edits rename the organisation
includes/hooks/enhance_importer_daily_sync.php  Daily status synchronisation (WHMCS cron)
modules/addons/enhance_importer/                Importer addon
  enhance_importer.php   Addon entry points, tabs, shared helpers
  lib/packages.php       Enhance Plans tab
  lib/services.php       Existing Services tab
  lib/sync.php           Synchronisation tab
modules/servers/enhance/                        Server module
  enhance.php            Module functions (provisioning, SSO, client area and tools, admin tab)
  EnhanceApi.php         API client (authentication, transport, diagnostics, all API calls)
  EnhanceStore.php       Associations, migrations, per-client lock, last action per service
  EnhanceSync.php        Status and usage synchronisation
  EnhanceLang.php        Translation loader
  lang/english.php       English (source)
  lang/portuguese-pt.php European Portuguese
  templates/clientarea.tpl
  config.php             Language, client area colours
tests/                  Test suite (not copied to WHMCS)
build.sh                Builds dist/enhance-whmcs-<version>.zip
```

## Installation and upgrade

1. Copy `includes/` and `modules/` to the WHMCS root, replacing the files. Module and table names
   do not change between versions; associations are kept and new tables are created automatically.
2. WHMCS > System Settings > Addon Modules: activate **Enhance Importer** and give your admin role access.
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

Create the token in Enhance > Settings > Access tokens of the **master organisation**, with the
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

Shown for up to 5 active websites of the service, never while the subscription is suspended.
The data is cached for 5 minutes; the login links are only created when the client clicks.

- **Webmail:** one entry per mailbox where Enhance allows SSO.
- **phpMyAdmin:** one entry per database.
- **WordPress admin:** per WordPress installation; logs in as the app's default user, otherwise
  the first administrator.
- **SSL:** certificate of the primary domain, expiry date and days left (highlighted from 14 days).
- **Backups:** last 5, with type, size and status. Restore (when enabled in the product) restores files
  and databases, optionally all mailboxes; it requires the account owner, a confirmation checkbox and a
  valid CSRF token, accepts only successful backups and is written to the activity log.

Users of a client account (WHMCS 8 users) need the **Single Sign-On** permission for the login buttons.
Creating backups is not offered: Enhance runs it as one long request that would exceed PHP limits.

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
English). Force one with `ENHANCE_LANGUAGE` in `config.php`. To add a language, copy
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

## Development

```bash
tests/run.sh                        # all tests (PHP >= 8.1, Composer, Python 3, OpenSSL)
ENHANCE_TEST_MYSQL=1 tests/run.sh   # plus the lock test on MySQL/MariaDB (database whmcs_test, user t/t)
./build.sh                          # dist/enhance-whmcs-<version>.zip
```

The suite never contacts a real Enhance panel. It uses a local HTTPS mock of the Enhance API (with the
behaviours seen on real panels), real Eloquent on SQLite for the WHMCS tables, the real Smarty engine
for the client area template, `php -S` to check the real SSO redirects, and real concurrent processes on
MariaDB for the lock. GitHub Actions (`.github/workflows/ci.yml`) also checks the syntax on PHP 7.4-8.3
and runs PHPCompatibility. Test only on a WHMCS staging installation before production.
