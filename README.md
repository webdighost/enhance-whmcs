# Enhance WHMCS Module

Version: 1.0.1  
Compatible with: WHMCS 8.x · PHP 8.0+ · Enhance Control Panel

## Overview

Enhance WHMCS Module is a professional provisioning and management module for integrating WHMCS with Enhance Control Panel.

This module includes:

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

This module is designed for production environments and supports multi-server setups.

## Release 1.0.1 Notes

Version 1.0.1 includes the final production-tested Client SSO fix.

Client area SSO now uses the official Enhance endpoint:

```text
/orgs/{orgId}/members/{memberId}/sso
```

Invalid legacy fallback endpoints such as `/ssoToken` and `/login` were removed to prevent automatic authentication failures.

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
- Master Organisation UUID (masterOrgId)

---

## Installation

## 1. Upload Files

Copy the module files to your WHMCS root.

Correct structure:

modules/servers/enhance/
includes/hooks/enhance.php
modules/addons/enhance_importer/

Important:

The module folder must be named exactly:

enhance

---

## 2. Install Composer Dependencies

Run inside:

modules/servers/enhance/

Command:

composer install --no-dev --optimize-autoloader

Do not run as root.

Use the WHMCS web server user instead.

---

## 3. Permissions

Recommended permissions:

chmod 750 modules/servers/enhance
chmod 640 modules/servers/enhance/*.php

---

## 4. Create API Token in Enhance

Login as SuperAdmin.

Go to:

Profile → API Tokens → Create Token

Permissions required:

SuperAdmin

Save the token immediately.

It is only shown once.

---

## 5. Get masterOrgId

Go to:

Settings → General

Copy:

Organisation ID

This is your:

masterOrgId

---

## 6. Configure Server in WHMCS

Go to:

System Settings → Products/Services → Servers → Add New Server

Use:

### Name

Any descriptive name

Example:

Enhance Server 1

### Hostname

Example:

panel.example.com

(no https://)

### Server Type

Enhance

### Username

masterOrgId

### Access Hash

API Token

### Secure

Enabled

Save and click:

Test Connection

You should receive:

Connection Successful

---

## 7. Create Product

Go to:

System Settings → Products/Services → Products/Services

Create:

Hosting Account

Module:

Enhance

Then in:

Module Settings

Select:

- Hosting Package
- Termination Mode

Recommended:

soft

---

## 8. Activate Addon Importer

Go to:

System Settings → Addon Modules

Activate:

Enhance Package Importer

Grant admin permissions.

This enables:

Addons → Enhance Package Importer

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

EnhanceOrgId

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

Uses:

ssoToken

Fallback:

session login URL

---

## Admin Access

WHMCS administrators use:

Direct panel access

Not client SSO.

This matches the original Enhance module behavior.

---

## Automatic Custom Fields

### Client Level

EnhanceOrgId

Stores the Enhance organisation UUID.

### Service Level

SUBSCRIPTION_ID

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

EnhanceOrgId exists for the client

### Suspend does not work

Check:

SUBSCRIPTION_ID exists for the service

### Importer missing tabs

Clear:

templates_c/

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

v1.0.1

---

## License

Private commercial use permitted according to repository owner terms.
