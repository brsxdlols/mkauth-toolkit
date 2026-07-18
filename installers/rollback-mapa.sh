#!/bin/sh
set -eu

ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
CENTRAL_DIR="${MKAUTH_CENTRAL:-/opt/mk-auth/central}"
ADDON_DIR="$ADMIN_DIR/addons/mapa-clientes"
ADDON_JS="$ADMIN_DIR/addons/addon.js"
BACKUP_DIR="${1:-}"
[ "$(id -u)" -eq 0 ] || { echo "ERRO: execute como root" >&2; exit 1; }
[ -n "$BACKUP_DIR" ] || { echo "Uso: $0 /root/backups/mk-auth-mapa-clientes-DATA-vVERSAO" >&2; exit 1; }
[ -f "$BACKUP_DIR/addon.js" ] && cp -a "$BACKUP_DIR/addon.js" "$ADDON_JS"
[ -d "$BACKUP_DIR" ] || { echo "ERRO: backup inexistente: $BACKUP_DIR" >&2; exit 1; }

if [ -d "$BACKUP_DIR/mapa-clientes-addon" ]; then
    rm -rf "$ADDON_DIR"
    cp -a "$BACKUP_DIR/mapa-clientes-addon" "$ADDON_DIR"
elif [ -f "$BACKUP_DIR/addon.absent" ]; then
    rm -rf "$ADDON_DIR"
fi
for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$BACKUP_DIR/central-$name" ]; then cp -a "$BACKUP_DIR/central-$name" "$CENTRAL_DIR/$name"; fi
    if [ -f "$BACKUP_DIR/central-$name.absent" ]; then rm -f "$CENTRAL_DIR/$name"; fi
done
echo "Rollback do mapa concluido a partir de: $BACKUP_DIR"
