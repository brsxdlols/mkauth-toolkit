#!/bin/sh
set -eu

VERSION="1.3.26"
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

for file in VERSION index.hhvm auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js lib/routeros_api.class.php assets/MarkerCluster.css assets/MarkerCluster.Default.css assets/leaflet.markercluster.js central-compat/maps.hhvm central-compat/maps_clientes_api.hhvm central-compat/maps_clientes_coord_update.hhvm; do
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

for file in index.hhvm auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js; do
    install -m 0644 "$SOURCE_DIR/$file" "$ADDON_DIR/$file"
done
install -m 0644 "$SOURCE_DIR/lib/routeros_api.class.php" "$ADDON_DIR/lib/routeros_api.class.php"

# Integra atalhos apos o carregamento dos menus nativos e do dashboard.
sed -i '/mka-mapa-clientes-menu/d;/mka-trafego-cliente-menu/d;/addons\/mapa-clientes\/maps.hhvm/d;/mka-map-shortcut-loader/d' "$ADDON_JS"
cat >> "$ADDON_JS" <<'JS'
(function(){if(document.getElementById('mka-map-shortcut-loader'))return;var s=document.createElement('script');s.id='mka-map-shortcut-loader';s.src='/admin/addons/mapa-clientes/menu.js?v=1.3.26';(document.head||document.documentElement).appendChild(s);})();
JS
DASH_TOP="$ADMIN_DIR/addons/dashboard/mkauth_dashboard_top.php"
if [ -f "$DASH_TOP" ]; then
    cp -a "$DASH_TOP" "$BACKUP_DIR/dashboard-top.php"
    if ! grep -q 'MKAUTH map and traffic shortcuts' "$DASH_TOP"; then
        cat >> "$DASH_TOP" <<'HTML'
<!-- MKAUTH map and traffic shortcuts -->
<script src="/admin/addons/mapa-clientes/menu.js?v=1.3.26"></script>
HTML
    fi
fi
for file in MarkerCluster.css MarkerCluster.Default.css leaflet.markercluster.js; do
    install -m 0644 "$SOURCE_DIR/assets/$file" "$ADDON_DIR/assets/$file"
done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    install -m 0644 "$SOURCE_DIR/central-compat/$name" "$CENTRAL_DIR/$name"
done

for file in index.hhvm auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm lib/routeros_api.class.php; do php -l "$ADDON_DIR/$file" >/dev/null; done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do php -l "$CENTRAL_DIR/$name" >/dev/null; done
grep -q 'require_map_access' "$ADDON_DIR/maps.hhvm"
grep -q '/admin/addons/mapa-clientes/maps.hhvm' "$CENTRAL_DIR/maps.hhvm"
grep -q 'mka-map-shortcut-loader' "$ADDON_JS"
grep -q 'mka-trafego-cliente-menu' "$ADDON_DIR/menu.js"

printf 'Mapa protegido instalado.\nVersao: %s\nPagina: /admin/addons/mapa-clientes/maps.hhvm\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
