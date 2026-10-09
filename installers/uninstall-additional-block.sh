#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute este desinstalador como root." >&2
    exit 1
fi

REPOSITORY="${MKAUTH_TOOLKIT_REPOSITORY:-brsxdlols/mkauth-toolkit}"
REF="${MKAUTH_TOOLKIT_REF:-main}"
URL="https://raw.githubusercontent.com/${REPOSITORY}/${REF}/patches/additional-block/uninstall_patch.sh"
TMP_FILE="$(mktemp /tmp/uninstall-additional-block.XXXXXX.sh)"
trap 'rm -f -- "$TMP_FILE"' EXIT INT TERM

if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --connect-timeout 15 "$URL" -o "$TMP_FILE"
elif command -v wget >/dev/null 2>&1; then
    wget -q --timeout=15 --tries=3 -O "$TMP_FILE" "$URL"
else
    echo "curl ou wget e obrigatorio." >&2
    exit 1
fi

chmod 0750 "$TMP_FILE"
"$TMP_FILE"
