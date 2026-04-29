# Changelog

## Version 1.0.0

Initial stable public release

### Added

- WHMCS server module for Enhance Control Panel
- Client Single Sign-On (SSO)
- Direct admin login to Enhance panel
- Automatic EnhanceOrgId creation
- Automatic SUBSCRIPTION_ID creation
- Client synchronization
- Package provisioning
- Robust suspend / unsuspend actions
- Safe termination modes
- Package importer addon
- Manual package selection before import
- Existing service importer
- Import using EnhanceOrgId as primary match
- Owner email fallback detection
- Primary domain auto-detection
- Multiple domain dropdown selection
- Daily synchronization system
- Manual sync execution
- Duplicate prevention logic
- CSRF protection for admin actions
- Admin client profile Enhance widget
- Package mapping between Enhance and WHMCS
- Import validation and logging
- Automatic database table creation
- Better product display inside importer
- Priority ordering for main domains over technical subdomains

### Improved

- Admin login behavior now uses direct panel access
- Service import logic now uses EnhanceOrgId instead of email-first matching
- Domain detection expanded to include full account domains
- Importer UI improved with clearer product information
- Technical resource summaries made readable
- Existing product detection improved

### Fixed

- Broken admin SSO logic
- Missing importer tabs
- SQL missing table error
- Incorrect service matching by owner email
- Raw API output shown in technical summary
- Nested addon folder installation issue
- Import failures caused by missing import log tables

### Notes

This release replaces previous internal development builds and should be considered the first stable production release.
