#!/bin/sh
set -eu

VERSION="1.1.0"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/mapa-clientes"
ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
CENTRAL_DIR="${MKAUTH_CENTRAL:-/opt/mk-auth/central}"
ADDON_DIR="$ADMIN_DIR/addons/mapa-clientes"
STATE_DIR="${MKAUTH_MAP_STATE:-/var/lib/mkauth-mapa-clientes}"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
BACKUP_DIR="$BACKUP_ROOT/mk-auth-mapa-clientes-$STAMP-v$VERSION"

fail() { echo "ERRO: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$ADMIN_DIR/addons" ] || fail "diretorio de addons nao encontrado: $ADMIN_DIR/addons"
[ -d "$CENTRAL_DIR" ] || fail "diretorio central nao encontrado: $CENTRAL_DIR"

for file in auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm assets/MarkerCluster.css assets/MarkerCluster.Default.css assets/leaflet.markercluster.js central-compat/maps.hhvm central-compat/maps_clientes_api.hhvm central-compat/maps_clientes_coord_update.hhvm; do
    [ -f "$SOURCE_DIR/$file" ] || fail "arquivo do pacote ausente: $file"
done

mkdir -p "$BACKUP_DIR" "$STATE_DIR"
chmod 0770 "$STATE_DIR"
chown www-data:www-data "$STATE_DIR" 2>/dev/null || true
if [ -d "$ADDON_DIR" ]; then cp -a "$ADDON_DIR" "$BACKUP_DIR/mapa-clientes-addon"; else : > "$BACKUP_DIR/addon.absent"; fi
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$CENTRAL_DIR/$name" ]; then cp -a "$CENTRAL_DIR/$name" "$BACKUP_DIR/central-$name"; else : > "$BACKUP_DIR/central-$name.absent"; fi
done
mkdir -p "$ADDON_DIR/assets"

for file in auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    install -m 0644 "$SOURCE_DIR/$file" "$ADDON_DIR/$file"
done
for file in MarkerCluster.css MarkerCluster.Default.css leaflet.markercluster.js; do
    install -m 0644 "$SOURCE_DIR/assets/$file" "$ADDON_DIR/assets/$file"
done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    install -m 0644 "$SOURCE_DIR/central-compat/$name" "$CENTRAL_DIR/$name"
done

for file in auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do php -l "$ADDON_DIR/$file" >/dev/null; done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do php -l "$CENTRAL_DIR/$name" >/dev/null; done
grep -q 'require_map_access' "$ADDON_DIR/maps.hhvm"
grep -q '/admin/addons/mapa-clientes/maps.hhvm' "$CENTRAL_DIR/maps.hhvm"

printf 'Mapa protegido instalado.\nVersao: %s\nPagina: /admin/addons/mapa-clientes/maps.hhvm\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
