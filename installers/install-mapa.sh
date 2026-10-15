#!/bin/sh
set -eu

VERSION="1.3.43"
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
SUITE_MONITOR_UPDATER="${MKAUTH_SUITE_MONITOR_UPDATER:-$(dirname "$ADMIN_DIR")/scripts/mkauth-suite-monitor/install-map-monitor.py}"
PRESERVE_MONITOR_ENTRY=0

fail() { echo "ERRO: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "execute como root"
command -v python3 >/dev/null 2>&1 || fail "python3 necessario para configurar o Telegram"
[ -f "$SCRIPT_DIR/configure-map-apparmor.py" ] || fail "configurador AppArmor ausente no pacote"
[ -d "$ADMIN_DIR/addons" ] || fail "diretorio de addons nao encontrado: $ADMIN_DIR/addons"
[ -d "$CENTRAL_DIR" ] || fail "diretorio central nao encontrado: $CENTRAL_DIR"
if [ -f "$ADDON_DIR/monitor/index.php" ] && grep -q 'suite-map-monitor-entry-v2' "$ADDON_DIR/monitor/index.php"; then
    PRESERVE_MONITOR_ENTRY=1
    [ -f "$SUITE_MONITOR_UPDATER" ] || fail "monitor isolado existente, mas atualizador da suite ausente; atualize a suite antes do Mapa"
fi
if [ -f "$SUITE_MONITOR_UPDATER" ]; then command -v python3 >/dev/null 2>&1 || fail "python3 necessario para atualizar o monitor isolado da suite"; fi

for file in VERSION index.hhvm monitor/index.php access_policy.php telegram_api.hhvm telegram_worker.php telegram_scheduler.php telegram_cli.php lib/telegram_engine.php lib/telegram_delivery.php lib/telegram_demo.php auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js lib/routeros_api.class.php assets/telegram.js assets/telegram.css assets/connection-log.js assets/connection-log.css assets/MarkerCluster.css assets/MarkerCluster.Default.css assets/leaflet.markercluster.js central-compat/maps.hhvm central-compat/maps_clientes_api.hhvm central-compat/maps_clientes_coord_update.hhvm; do
    [ -f "$SOURCE_DIR/$file" ] || fail "arquivo do pacote ausente: $file"
done

mkdir -p "$BACKUP_DIR" "$STATE_DIR"
chmod 0700 "$BACKUP_DIR"
printf 'mapa-clientes\n' > "$BACKUP_DIR/.mkauth-toolkit-backup-v1"
chmod 0770 "$STATE_DIR"
chown www-data:www-data "$STATE_DIR" 2>/dev/null || true
if [ -d "$ADDON_DIR" ]; then cp -a "$ADDON_DIR" "$BACKUP_DIR/mapa-clientes-addon"; else : > "$BACKUP_DIR/addon.absent"; fi
[ -f "$ADDON_JS" ] || fail "javascript de addons nao encontrado: $ADDON_JS"
cp -a "$ADDON_JS" "$BACKUP_DIR/addon.js"
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$CENTRAL_DIR/$name" ]; then cp -a "$CENTRAL_DIR/$name" "$BACKUP_DIR/central-$name"; else : > "$BACKUP_DIR/central-$name.absent"; fi
done
mkdir -p "$ADDON_DIR/assets" "$ADDON_DIR/lib" "$ADDON_DIR/monitor"
install -m 0644 "$SOURCE_DIR/VERSION" "$ADDON_DIR/VERSION"
# A monitor-only installation may hold the provider configuration only in runtime.
if [ ! -f "$ADDON_DIR/config.hhvm" ] && [ -f "$ADDON_DIR/monitor/runtime/config.hhvm" ]; then
    install -m 0644 "$ADDON_DIR/monitor/runtime/config.hhvm" "$ADDON_DIR/config.hhvm"
fi

for file in index.hhvm monitor/index.php access_policy.php telegram_api.hhvm telegram_worker.php telegram_scheduler.php telegram_cli.php lib/telegram_engine.php lib/telegram_delivery.php lib/telegram_demo.php auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm menu.js; do
    if [ "$file" = 'monitor/index.php' ] && [ "$PRESERVE_MONITOR_ENTRY" -eq 1 ]; then continue; fi
    if [ "$file" = 'config.hhvm' ] && [ -f "$ADDON_DIR/config.hhvm" ]; then continue; fi
    install -m 0644 "$SOURCE_DIR/$file" "$ADDON_DIR/$file"
done
install -m 0644 "$SOURCE_DIR/lib/routeros_api.class.php" "$ADDON_DIR/lib/routeros_api.class.php"

# Integra atalhos apos o carregamento dos menus nativos e do dashboard.
sed -i '/mka-mapa-clientes-menu/d;/mka-trafego-cliente-menu/d;/addons\/mapa-clientes\/maps.hhvm/d;/mka-map-shortcut-loader/d' "$ADDON_JS"
cat >> "$ADDON_JS" <<'JS'
(function(){if(document.getElementById('mka-map-shortcut-loader'))return;var s=document.createElement('script');s.id='mka-map-shortcut-loader';s.src='/admin/addons/mapa-clientes/menu.js?v=1.3.43';(document.head||document.documentElement).appendChild(s);})();
JS
DASH_TOP="$ADMIN_DIR/addons/dashboard/mkauth_dashboard_top.php"
if [ -f "$DASH_TOP" ]; then
    cp -a "$DASH_TOP" "$BACKUP_DIR/dashboard-top.php"
    if ! grep -q 'MKAUTH map and traffic shortcuts' "$DASH_TOP"; then
        cat >> "$DASH_TOP" <<'HTML'
<!-- MKAUTH map and traffic shortcuts -->
<script src="/admin/addons/mapa-clientes/menu.js?v=1.3.43"></script>
HTML
    fi
fi
for file in telegram.js telegram.css connection-log.js connection-log.css MarkerCluster.css MarkerCluster.Default.css leaflet.markercluster.js; do
    install -m 0644 "$SOURCE_DIR/assets/$file" "$ADDON_DIR/assets/$file"
done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    install -m 0644 "$SOURCE_DIR/central-compat/$name" "$CENTRAL_DIR/$name"
done

for file in index.hhvm monitor/index.php access_policy.php telegram_api.hhvm telegram_worker.php telegram_scheduler.php telegram_cli.php lib/telegram_engine.php lib/telegram_delivery.php lib/telegram_demo.php auth.php config.hhvm persistent_access.hhvm maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm route_api.hhvm traffic_api.hhvm nas_health.hhvm cto_api.hhvm lib/routeros_api.class.php; do php -l "$ADDON_DIR/$file" >/dev/null; done
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do php -l "$CENTRAL_DIR/$name" >/dev/null; done
# Telegram credentials live outside the web root, and remain disabled until configured.
TELEGRAM_STATE="${MKAUTH_MAP_TELEGRAM_STATE:-/var/lib/mkauth-map-telegram}"
TELEGRAM_CRON="${MKAUTH_MAP_TELEGRAM_CRON:-/etc/cron.d/mkauth-map-telegram}"
case "$TELEGRAM_STATE" in /|/var|/var/lib|/tmp|/var/tmp|/opt|/opt/mk-auth) fail "pasta privada de Telegram invalida" ;; esac
if [ -L "$TELEGRAM_STATE" ]; then fail "pasta privada de Telegram nao pode ser link simbolico"; fi
if [ -d "$TELEGRAM_STATE" ]; then cp -a "$TELEGRAM_STATE" "$BACKUP_DIR/telegram-private-state"; fi
mkdir -p "$TELEGRAM_STATE"
chmod 0700 "$TELEGRAM_STATE"
chown www-data:www-data "$TELEGRAM_STATE"
find "$TELEGRAM_STATE" -maxdepth 1 -type f \( -name "*.json" -o -name "*.lock" \) -exec chown www-data:www-data {} \; -exec chmod 0600 {} \;
python3 "$SCRIPT_DIR/configure-map-apparmor.py" --state "$TELEGRAM_STATE" --backup "$BACKUP_DIR/apparmor" --profiles "${MKAUTH_APPARMOR_PROFILES:-/etc/apparmor.d}"
if [ -f "$TELEGRAM_CRON" ]; then cp -p "$TELEGRAM_CRON" "$BACKUP_DIR/telegram-cron"; fi
# Paths used by cron are installation-owned; reject shell metacharacters.
case "$TELEGRAM_STATE$ADDON_DIR$TELEGRAM_CRON" in *[!a-zA-Z0-9_./-]*) fail "caminho de Telegram invalido para cron" ;; esac
printf 'SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n* * * * * www-data MKAUTH_MAP_TELEGRAM_STATE=%s /usr/bin/php %s/telegram_scheduler.php >/dev/null 2>&1\n' "$TELEGRAM_STATE" "$ADDON_DIR" > "$TELEGRAM_CRON"
chmod 0644 "$TELEGRAM_CRON"

# Preserve Dashboard/Busca configured links while retaining legacy URL compatibility.
php "$SCRIPT_DIR/migrate-map-monitor-links.php" "$BACKUP_DIR"

grep -q 'require_map_access' "$ADDON_DIR/maps.hhvm"
grep -q '/admin/addons/mapa-clientes/maps.hhvm' "$CENTRAL_DIR/maps.hhvm"
grep -q 'mka-map-shortcut-loader' "$ADDON_JS"
grep -q 'mka-trafego-cliente-menu' "$ADDON_DIR/menu.js"

# The suite owns its isolated runtime and entry. Rebuild only through its updater.
# Each invocation receives a newly allocated backup directory, never a reused one.
if [ -f "$SUITE_MONITOR_UPDATER" ]; then
    SUITE_MONITOR_BACKUP=$(mktemp -d "$BACKUP_DIR/suite-monitor.XXXXXX")
    chmod 0700 "$SUITE_MONITOR_BACKUP"
    echo "Atualizando runtime isolado da suite; backup: $SUITE_MONITOR_BACKUP"
    python3 "$SUITE_MONITOR_UPDATER" "$ADMIN_DIR" "$SUITE_MONITOR_BACKUP" || fail "Mapa instalado, mas runtime da suite nao sincronizado; consulte $SUITE_MONITOR_BACKUP e execute novamente o atualizador"
fi

chmod 0700 "$BACKUP_DIR"
printf 'mapa-clientes\n' > "$BACKUP_DIR/.mkauth-toolkit-backup-v1"
touch "$BACKUP_DIR/.mkauth-toolkit-backup-complete"
printf 'Mapa protegido instalado.\nVersao: %s\nPagina: /admin/addons/mapa-clientes/\nMonitor: /admin/addons/mapa-clientes/monitor/\nBackup: %s\n' "$VERSION" "$BACKUP_DIR"
