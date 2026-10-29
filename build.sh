#!/usr/bin/env bash
# Builds dist/enhance-whmcs-<version>.zip with only the files to copy to WHMCS
# (the tests, CI and Git files are left out). The version is read from EnhanceApi::VERSION.
set -euo pipefail
cd "$(dirname "$0")"
version=$(grep -oP "const VERSION\s*=\s*'\K[0-9.]+" modules/servers/enhance/EnhanceApi.php)
mkdir -p dist
rm -f "dist/enhance-whmcs-$version.zip"
zip -qr "dist/enhance-whmcs-$version.zip" includes modules README.md LEIA-ME.md CHANGELOG.md -x '*.DS_Store'
echo "dist/enhance-whmcs-$version.zip"
