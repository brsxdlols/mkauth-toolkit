#!/bin/sh
set -eu

VERSION="2.10.3"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/geocodificacao"
ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
ADDON_DIR="$ADMIN_DIR/addons/geocodificacao"
MAIN_JS="$ADMIN_DIR/scripts/mk-auth.js"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
BACKUP_DIR="$BACKUP_ROOT/mk-auth-geocodificacao-$STAMP-v$VERSION"
LOADER=';document.addEventListener("DOMContentLoaded",function(){if(location.pathname.indexOf("/admin/")>=0&&!document.getElementById("mka-geocodificacao-js")){var s=document.createElement("script");s.id="mka-geocodificacao-js";s.src="addons/geocodificacao/geocodificacao.js?v=2.10.3";document.head.appendChild(s);}});'

fail() { echo "ERRO: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$ADMIN_DIR" ] || fail "diretorio MK-AUTH nao encontrado: $ADMIN_DIR"
[ -f "$MAIN_JS" ] || fail "arquivo principal nao encontrado: $MAIN_JS"
for file in config.php geocode.php geocodificacao.js vendor/leaflet.css vendor/leaflet.js; do
    [ -f "$SOURCE_DIR/$file" ] || fail "arquivo do pacote ausente: $file"
done

mkdir -p "$BACKUP_DIR" "$ADDON_DIR"
mkdir -p /var/tmp/mkauth-geocodificacao/batch-jobs
chmod 0770 /var/tmp/mkauth-geocodificacao /var/tmp/mkauth-geocodificacao/batch-jobs
chown -R www-data:www-data /var/tmp/mkauth-geocodificacao 2>/dev/null || true
cp -a "$MAIN_JS" "$BACKUP_DIR/mk-auth.js"
if [ -d "$ADDON_DIR" ]; then cp -a "$ADDON_DIR" "$BACKUP_DIR/geocodificacao"; fi
for target in "$ADMIN_DIR/estilos/leaflet.css" "$ADMIN_DIR/scripts/leaflet.js"; do
    name=$(basename "$target")
    if [ -f "$target" ]; then cp -a "$target" "$BACKUP_DIR/$name"; else : > "$BACKUP_DIR/$name.absent"; fi
done

install -m 0644 "$SOURCE_DIR/config.php" "$ADDON_DIR/config.php"
install -m 0644 "$SOURCE_DIR/geocode.php" "$ADDON_DIR/geocode.php"
install -m 0644 "$SOURCE_DIR/geocodificacao.js" "$ADDON_DIR/geocodificacao.js"
install -m 0644 "$SOURCE_DIR/vendor/leaflet.css" "$ADMIN_DIR/estilos/leaflet.css"
install -m 0644 "$SOURCE_DIR/vendor/leaflet.js" "$ADMIN_DIR/scripts/leaflet.js"

if grep -q 'mka-geocodificacao-js' "$MAIN_JS"; then
    sed -i '/mka-geocodificacao-js/d' "$MAIN_JS"
fi
printf '\n%s\n' "$LOADER" >> "$MAIN_JS"

php -l "$ADDON_DIR/geocode.php" >/dev/null
grep -q 'mka-geocodificacao-js' "$MAIN_JS"
grep -q 'geocodificacao.js?v=2.10.3' "$MAIN_JS"
grep -q 'conf_mapas.hhvm' "$MAIN_JS"
grep -q 'nativeMapSettings' "$ADDON_DIR/geocodificacao.js"

printf '%s\n' "$VERSION" > "$ADDON_DIR/VERSION"
printf 'Instalacao concluida.\nVersao: %s\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
