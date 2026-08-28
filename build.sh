#!/usr/bin/env bash
#
# Builds the installable plugin ZIP.
#
# JTL-Shop expects info.xml at the root of the archive inside a folder named
# exactly like the PluginID, so the archive contains "flizpay/…".
#
set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_DIR="flizpay"
VERSION="$(sed -n 's:.*<Version>\(.*\)</Version>.*:\1:p' "${PLUGIN_DIR}/info.xml" | head -1)"
OUTPUT="dist/flizpay-${VERSION}.zip"

if [ -z "${VERSION}" ]; then
    echo "error: could not read <Version> from ${PLUGIN_DIR}/info.xml" >&2
    exit 1
fi

echo "==> Linting PHP sources"
find "${PLUGIN_DIR}" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "==> Running tests"
php tests/run.php

echo "==> Packaging ${OUTPUT}"
rm -rf dist
mkdir -p dist
zip -r -q "${OUTPUT}" "${PLUGIN_DIR}" \
    -x '*.DS_Store' \
    -x '*/.git/*' \
    -x '*.map'

echo "==> Done: ${OUTPUT}"
unzip -l "${OUTPUT}" | tail -n 5
