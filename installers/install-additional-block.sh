#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute este instalador como root." >&2
    exit 1
fi

REPOSITORY="${MKAUTH_TOOLKIT_REPOSITORY:-brsxdlols/mkauth-toolkit}"
REF="${MKAUTH_TOOLKIT_REF:-main}"
RAW_BASE="https://raw.githubusercontent.com/${REPOSITORY}/${REF}/patches/additional-block"
TMP_DIR="$(mktemp -d /tmp/mkauth-additional-block.XXXXXX)"

cleanup() {
    rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT INT TERM

download() {
    url="$1"
    output="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 3 --connect-timeout 15 "$url" -o "$output"
    elif command -v wget >/dev/null 2>&1; then
        wget -q --timeout=15 --tries=3 -O "$output" "$url"
    else
        echo "curl ou wget e obrigatorio." >&2
        exit 1
    fi
}

for file in install_patch.sh mkauth_additional_block_patch.sql mkauth_additional_block_worker.php; do
    download "$RAW_BASE/$file" "$TMP_DIR/$file"
done

chmod 0750 "$TMP_DIR/install_patch.sh"
/usr/bin/php -l "$TMP_DIR/mkauth_additional_block_worker.php"
"$TMP_DIR/install_patch.sh"

echo "Instalacao concluida. Log: /var/log/mkauth_additional_block.log"
