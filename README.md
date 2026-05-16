# Enhance WHMCS Module

Professional WHMCS provisioning and automation module for Enhance Control Panel.

---

## Latest Stable Release

Current stable version: **v1.0.2**

Download:  
https://github.com/webdighost/enhance-whmcs/releases/latest

---

Version: 1.0.2  
Compatible with: WHMCS 8.x · PHP 8.0+ · Enhance Control Panel

## Overview

Enhance WHMCS Module is a professional provisioning and management module for integrating WHMCS with Enhance Control Panel.

This module extends and improves the original Enhance WHMCS module by adding:

- Client Single Sign-On (SSO)
- Direct Admin Login to Enhance Panel
- Automatic client synchronization
- Package import from Enhance to WHMCS
- Existing service import using EnhanceOrgId
- Daily synchronization
- Smart primary domain detection
- Robust suspend / unsuspend actions
- Subscription mapping
- Duplicate prevention
- Secure admin actions with CSRF protection
- Multi-server support
- WHMCS Server Group support

This module is designed for production environments and supports multi-server setups.

---

## Release 1.0.2 Notes

Version 1.0.2 includes multiple fixes and provisioning improvements.

### Fixed

- Fixed website creation issue caused by invalid integer conversion:

```text
invalid type: string "11", expected i32
```

- Fixed incorrect `YespleMode` parameter naming
- Fixed provisioning compatibility issues with newer Enhance API responses

### Added

- Added missing `composer.json`
- Added WHMCS Server Group compatibility
- Improved package provisioning validation
- Improved API request handling

### Improved

- Better error handling
- Better compatibility with production environments
- Improved logging and diagnostics

---

## Requirements

### Server

- PHP 8.0 or higher
- Composer installed
- cURL extension enabled
- HTTPS access to Enhance Panel

### WHMCS

- WHMCS 8.x or higher
- Module Log enabled

### Enhance

- Active Enhance installation
- SuperAdmin API Token
- Master Organisation UUID (`masterOrgId`)

---

## Installation

## 1. Upload Files

Copy the module files to your WHMCS root.

Correct structure:

```text
modules/servers/enhance/
includes/hooks/enhance.php
modules/addons/enhance_importer/
```

Important:

The module folder must be named exactly:

```text
enhance
```

---

## 2. Install Composer Dependencies

Run inside:

```text
modules/servers/enhance/
```

Command:

```bash
composer install --no-dev --optimize-autoloader
```

Do not run as root.

Use the WHMCS web server user instead.

---

## 3. Permissions

Recommended permissions:

```bash
chmod 750 modules/servers/enhance
chmod 640 modules/servers/enhance/*.php
```

---

## 4. Create API Token in Enhance

Login as SuperAdmin.

Go to:

```text
Profile → API Tokens → Create Token
```

Permissions required:

```text
SuperAdmin
```

Save the token immediately.

It is only shown once.

---

## 5. Get masterOrgId

Go to:

```text
Settings → General
```

Copy:

```text
Organisation ID
```

This is your:

```text
masterOrgId
```

---

## 6. Configure Server in WHMCS

Go to:

```text
System Settings → Products/Services → Servers → Add New Server
```

Use:

### Name

Any descriptive name

Example:

```text
Enhance Server 1
```

### Hostname

Example:

```text
panel.example.com
```

(no https://)

### Server Type

```text
Enhance
```

### Username

```text
masterOrgId
```

### Access Hash

```text
API Token
```

### Secure

```text
Enabled
```

Save and click:

```text
Test Connection
```

You should receive:

```text
Connection Successful
```

---

## 7. Create Product

Go to:

```text
System Settings → Products/Services → Products/Services
```

Create:

```text
Hosting Account
```

Module:

```text
Enhance
```

Then in:

```text
Module Settings
```

Select:

- Hosting Package
- Termination Mode

Recommended:

```text
soft
```

---

## 8. Activate Addon Importer

Go to:

```text
System Settings → Addon Modules
```

Activate:

```text
Enhance Package Importer
```

Grant admin permissions.

This enables:

```text
Addons → Enhance Package Importer
```

---

## Features

## Package Importer

Import packages from Enhance into WHMCS with:

- Manual selection
- Product group selection
- Hidden product creation
- Duplicate prevention
- Existing product updates

---

## Existing Service Import

Import services already provisioned in Enhance using:

```text
EnhanceOrgId
```

Priority:

1. EnhanceOrgId
2. Owner email (fallback only)

This prevents incorrect client matching.

Includes:

- Subscription mapping
- Product matching
- Primary domain detection
- Existing domain selection
- Import validation

---

## Daily Sync

Automatic synchronization includes:

- Imported service status
- Subscription validation
- Duplicate prevention
- Manual sync option

Automatic import is disabled by default for safety.

---

## SSO

### Client Area

Clients can login directly to Enhance without entering credentials.

Uses the official Enhance SSO endpoint:

```text
/orgs/{orgId}/members/{memberId}/sso
```

Legacy fallback endpoints were removed to improve reliability and compatibility.

---

## Admin Access

WHMCS administrators use:

```text
Direct panel access
```

Not client SSO.

This matches the original Enhance module behavior.

---

## Automatic Custom Fields

### Client Level

```text
EnhanceOrgId
```

Stores the Enhance organisation UUID.

### Service Level

```text
SUBSCRIPTION_ID
```

Stores the Enhance subscription ID.

Do not rename or delete these fields.

---

## Troubleshooting

### Common Issues

### Admin login fails

Check:

- Server hostname
- API token
- masterOrgId

### SSO not working

Check:

```text
EnhanceOrgId
```

exists for the client.

### Suspend does not work

Check:

```text
SUBSCRIPTION_ID
```

exists for the service.

### Importer missing tabs

Clear:

```text
templates_c/
```

Then disable and re-enable the addon.

### SQL table missing

Open the importer again.

The module auto-creates required tables.

---

## Recommended Final Step

Always test:

- CreateAccount
- Suspend
- Unsuspend
- SSO
- Package Import
- Existing Service Import

before production deployment.

---

## Version

Current stable release:

```text
v1.0.2
```

---

## License

Private commercial use permitted according to repository owner terms.
