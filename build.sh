#!/usr/bin/env bash
#
# Builds the installable plugin ZIP.
#
# JTL-Shop expects info.xml inside a folder named exactly like the PluginID,
# so the flat repository is staged as "flizpay/..." before packaging.
#
set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_ID="flizpay"
VERSION="$(sed -n 's:.*<Version>\(.*\)</Version>.*:\1:p' info.xml | head -1)"
OUTPUT="dist/flizpay-${VERSION}.zip"
STAGING_DIR="$(mktemp -d)"

trap 'rm -rf "${STAGING_DIR}"' EXIT

if [ -z "${VERSION}" ]; then
    echo "error: could not read <Version> from info.xml" >&2
    exit 1
fi

if command -v msgfmt > /dev/null 2>&1; then
    echo "==> Compiling translation catalogs"
    for catalog in locale/*/base.po; do
        msgfmt --check --output-file="${catalog%.po}.mo" "${catalog}"
    done
fi

echo "==> Linting PHP sources"
find Bootstrap.php Migrations adminmenu frontend lib paymentmethod -name '*.php' -print0 \
    | xargs -0 -n1 php -l > /dev/null

echo "==> Running tests"
php tests/run.php

echo "==> Packaging ${OUTPUT}"
rm -rf dist
mkdir -p dist
mkdir -p "${STAGING_DIR}/${PLUGIN_ID}"
cp -R Bootstrap.php README.md info.xml Migrations adminmenu frontend lib locale paymentmethod \
    "${STAGING_DIR}/${PLUGIN_ID}/"
(
    cd "${STAGING_DIR}"
    zip -r -q "${OLDPWD}/${OUTPUT}" "${PLUGIN_ID}" \
        -x '*.DS_Store' \
        -x '*.map'
)

echo "==> Done: ${OUTPUT}"
unzip -l "${OUTPUT}" | tail -n 5
