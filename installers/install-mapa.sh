#!/bin/sh
set -eu

VERSION="1.3.22"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/mapa-clientes"
ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
CENTRAL_DIR="${MKAUTH_CENTRAL:-/opt/mk-auth/central}"
ADDON_DIR="$ADMIN_DIR/addons/mapa-clientes"
ADDON_JS="$ADMIN_DIR/addons/addon.js"
STATE_DIR="${MKAUTH_MAP_STATE:-/var/tmp/mkauth-mapa-clientes}"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
BACKUP_DIR="$BACKUP_ROOT/mk-auth-mapa-clientes-$STAMP-v$VERSION"

fail() { echo "ERRO: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$ADMIN_DIR/addons" ] || fail "diretorio de addons nao encontrado: $ADMIN_DIR/addons"
[ -d "$CENTRAL_DIR" ] || fail "diretorio central nao encontrado: $CENTRAL_DIR"

for file in VERSION auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js lib/routeros_api.class.php assets/MarkerCluster.css assets/MarkerCluster.Default.css assets/leaflet.markercluster.js central-compat/maps.hhvm central-compat/maps_clientes_api.hhvm central-compat/maps_clientes_coord_update.hhvm; do
    [ -f "$SOURCE_DIR/$file" ] || fail "arquivo do pacote ausente: $file"
done

mkdir -p "$BACKUP_DIR" "$STATE_DIR"
chmod 0770 "$STATE_DIR"
chown www-data:www-data "$STATE_DIR" 2>/dev/null || true
if [ -d "$ADDON_DIR" ]; then cp -a "$ADDON_DIR" "$BACKUP_DIR/mapa-clientes-addon"; else : > "$BACKUP_DIR/addon.absent"; fi
[ -f "$ADDON_JS" ] || fail "javascript de addons nao encontrado: $ADDON_JS"
cp -a "$ADDON_JS" "$BACKUP_DIR/addon.js"
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$CENTRAL_DIR/$name" ]; then cp -a "$CENTRAL_DIR/$name" "$BACKUP_DIR/central-$name"; else : > "$BACKUP_DIR/central-$name.absent"; fi
done
mkdir -p "$ADDON_DIR/assets" "$ADDON_DIR/lib"
install -m 0644 "$SOURCE_DIR/VERSION" "$ADDON_DIR/VERSION"

for file in auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js; do
    install -m 0644 "$SOURCE_DIR/$file" "$ADDON_DIR/$file"
done
install -m 0644 "$SOURCE_DIR/lib/routeros_api.class.php" "$ADDON_DIR/lib/routeros_api.class.php"

# Integracao minima e idempotente com o menu nativo Clientes.
sed -i '/mka-mapa-clientes-menu/d;/mka-trafego-cliente-menu/d;/addons\/mapa-clientes\/maps.hhvm/d' "$ADDON_JS"
printf '%s\n' '// mka-mapa-clientes-menu' 'add_menu.clientes('\''{"plink": "'\'' + minha_url + '\''addons/mapa-clientes/maps.hhvm", "ptext": "<b>?? Mapa de clientes</b>"}'\'');' '// mka-trafego-cliente-menu' 'add_menu.clientes('\''{"plink": "'\'' + minha_url + '\''addons/mapa-clientes/maps.hhvm?monitor=1", "ptext": "<b>?? Tr?fego de cliente</b>"}'\'');' >> "$ADDON_JS"
for file in MarkerCluster.css MarkerCluster.Default.css leaflet.markercluster.js; do
    install -m 0644 "$SOURCE_DIR/assets/$file" "$ADDON_DIR/assets/$file"
done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    install -m 0644 "$SOURCE_DIR/central-compat/$name" "$CENTRAL_DIR/$name"
done

for file in auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm lib/routeros_api.class.php; do php -l "$ADDON_DIR/$file" >/dev/null; done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do php -l "$CENTRAL_DIR/$name" >/dev/null; done
grep -q 'require_map_access' "$ADDON_DIR/maps.hhvm"
grep -q '/admin/addons/mapa-clientes/maps.hhvm' "$CENTRAL_DIR/maps.hhvm"
grep -q 'add_menu.clientes.*mapa-clientes/maps.hhvm' "$ADDON_JS"
grep -q 'Tr?fego de cliente' "$ADDON_JS"

printf 'Mapa protegido instalado.\nVersao: %s\nPagina: /admin/addons/mapa-clientes/maps.hhvm\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
