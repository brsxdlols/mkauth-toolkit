#!/bin/sh
set -eu

VERSION="1.0.0"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/mapa-clientes"
CENTRAL_DIR="${MKAUTH_CENTRAL:-/opt/mk-auth/central}"
ASSET_DIR="$CENTRAL_DIR/maps_assets"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
BACKUP_DIR="$BACKUP_ROOT/mk-auth-mapa-clientes-$STAMP-v$VERSION"

fail() { echo "ERRO: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$CENTRAL_DIR" ] || fail "diretorio central do MK-AUTH nao encontrado: $CENTRAL_DIR"

for file in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm assets/MarkerCluster.css assets/MarkerCluster.Default.css assets/leaflet.markercluster.js; do
    [ -f "$SOURCE_DIR/$file" ] || fail "arquivo do pacote ausente: $file"
done

mkdir -p "$BACKUP_DIR" "$ASSET_DIR"
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$CENTRAL_DIR/$name" ]; then
        cp -a "$CENTRAL_DIR/$name" "$BACKUP_DIR/$name"
    else
        : > "$BACKUP_DIR/$name.absent"
    fi
done
if [ -d "$ASSET_DIR" ]; then cp -a "$ASSET_DIR" "$BACKUP_DIR/maps_assets"; fi

php -l "$SOURCE_DIR/maps.hhvm" >/dev/null
php -l "$SOURCE_DIR/maps_clientes_api.hhvm" >/dev/null
php -l "$SOURCE_DIR/maps_clientes_coord_update.hhvm" >/dev/null

install -m 0755 "$SOURCE_DIR/maps.hhvm" "$CENTRAL_DIR/maps.hhvm"
install -m 0755 "$SOURCE_DIR/maps_clientes_api.hhvm" "$CENTRAL_DIR/maps_clientes_api.hhvm"
install -m 0755 "$SOURCE_DIR/maps_clientes_coord_update.hhvm" "$CENTRAL_DIR/maps_clientes_coord_update.hhvm"
install -m 0644 "$SOURCE_DIR/assets/MarkerCluster.css" "$ASSET_DIR/MarkerCluster.css"
install -m 0644 "$SOURCE_DIR/assets/MarkerCluster.Default.css" "$ASSET_DIR/MarkerCluster.Default.css"
install -m 0644 "$SOURCE_DIR/assets/leaflet.markercluster.js" "$ASSET_DIR/leaflet.markercluster.js"

php -l "$CENTRAL_DIR/maps.hhvm" >/dev/null
php -l "$CENTRAL_DIR/maps_clientes_api.hhvm" >/dev/null
php -l "$CENTRAL_DIR/maps_clientes_coord_update.hhvm" >/dev/null
grep -q 'maps_clientes_api.hhvm' "$CENTRAL_DIR/maps.hhvm"
grep -q 'leaflet.markercluster.js' "$CENTRAL_DIR/maps.hhvm"

printf 'Mapa de clientes instalado.\nVersao: %s\nPagina: /central/maps.hhvm\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
