# Changelog

## 1.4.0 - 2026-10-29

### Client area
- Direct login buttons per website: **webmail** (Roundcube, per mailbox), **phpMyAdmin** (per database)
  and **WordPress admin** (the app's default user, otherwise the first administrator). Links are
  created only when the client clicks, after checking that the website, mailbox, database or app
  belongs to the service.
- **SSL certificate** of the primary domain with expiry date and days left (highlighted from 14 days).
- **Recent backups** (last 5) with date, type, size and status. Optional **restore** by the client
  (product option, off by default): POST + CSRF token, account owner only, explicit confirmation,
  successful backups only, optional mailboxes. Restores are written to the activity log.
- Every website of the service is listed (1 or 1000), 5 per page, with a domain search; tools are loaded
  only for the websites of the page shown (one API call for the whole list) and never while the
  subscription is suspended. `ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE` in `config.php`.
- Backups show date and time, and the size of the backed up content (files + databases + emails) in
  B/KB/MB/GB instead of the incremental delta rounded to "0 MB"; empty when Enhance reports 0;
  `ENHANCE_CLIENTAREA_BACKUP_SIZE` hides it. Incremental backups with nothing to copy are not shown as failed; "no SSL certificate" is
  only shown when Enhance confirms it; WordPress installed in the website root (`public_html`) shows no
  path; WordPress brand icon fixed for Font Awesome 5.
- Users of the client account need the WHMCS "Single Sign-On" (`productsso`) permission for the
  login buttons; restores are reserved to the account owner.

### Products
- **Server group** option (configoption3, loaded from Enhance): where new websites are created.
- **Client backup restore** option (configoption4).
- The service **username** shows the Enhance login email (the organisation owner).

### Admin
- **Last module action** per service in the service tab (action, result, date) and the **last error**
  with its message, kept until a newer error.
- **Link / repair subscription** in the service tab: choose any subscription of the client's
  organisation (subscriptions linked to other services are shown disabled), or remove the link.
  Applied when the service is saved, under the per-client lock, after validating the subscription.
- Logging in to the panel as the client from the admin service page is written to the activity log;
  admins see the technical reason when it fails.

### Enhance Manager (settings)
- The importer addon is now **Enhance Manager** (same folder and tables) with a new **Settings** tab:
  colours with live preview, language, visible client area sections, websites per page, backups shown,
  cache time and a global backup restore switch. Hidden sections are not requested from Enhance.
- Settings are stored in `mod_enhance_settings`, so updates keep them; `config.php` no longer sets
  defaults and only holds optional overrides, shown as locked in the Settings tab.

### WHMCS integration
- `whmcs.json` for the server module and the importer addon: name, description, features, authors and
  support links in WHMCS > Apps & Integrations, with the module version in the description.
- Module version shown in the importer title, the admin service tab and the client profile panel.
- Messages and documentation point to the exact token page: `https://<panel>/settings/access-tokens`.

### Quality
- Importer split into `lib/packages.php`, `lib/services.php` and `lib/sync.php`.
- 39 API calls, all checked against the official OpenAPI specification (orchd 12.26.3).

## 1.3.1 - 2026-10-29
- Per-client database lock (MySQL `GET_LOCK`) for Create, service import and organisation linking.
- Logins looked up in the master organisation's realm; HTTP 409 on login creation reuses the login.
- Client profile panel: organisation search (name or UUID) instead of listing every customer.
- Importer Existing Services tab: 20 clients per page, 3 API calls per client.
- Owner created directly with the `Owner` role (Enhance refuses `PUT /owner` and the `Owner` role on
  member updates to access tokens); owner-less organisations repaired; existing owners never replaced.
- Migration of the official Enhance module's data (`enhOrgId`, `enhanceSubscriptionId`, `subscriptionId`).

## 1.3.0 - 2026-10-28
- Authentication: HTML entities decoded, spaces/line breaks/`Bearer ` removed from the token, Password
  field used when Access Hash is empty, redirects and HTML pages treated as errors, step-by-step
  Test Connection (public `/version`, 401/403/404, token in the other field, first five characters).
- English base with a complete European Portuguese translation.
- Client profile panel buttons no longer submit the whole profile form (nested forms).
- Profile edits only rename the linked organisation.
- Password recovery uses only `PUT /login/password-recovery`.
- Importer: billing cycles in the format `AddOrder` expects.
- Web-accessible debug log removed.
