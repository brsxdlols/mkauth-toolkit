#!/bin/sh
set -eu

CENTRAL_DIR="${MKAUTH_CENTRAL:-/opt/mk-auth/central}"
BACKUP_DIR="${1:-}"
[ "$(id -u)" -eq 0 ] || { echo "ERRO: execute como root" >&2; exit 1; }
[ -n "$BACKUP_DIR" ] || { echo "Uso: $0 /root/backups/mk-auth-mapa-clientes-DATA-vVERSAO" >&2; exit 1; }
[ -d "$BACKUP_DIR" ] || { echo "ERRO: backup inexistente: $BACKUP_DIR" >&2; exit 1; }

for name in maps.hhvm maps_clientes_api.hhvm maps_clientes_coord_update.hhvm; do
    if [ -f "$BACKUP_DIR/$name" ]; then cp -a "$BACKUP_DIR/$name" "$CENTRAL_DIR/$name"; fi
    if [ -f "$BACKUP_DIR/$name.absent" ]; then rm -f "$CENTRAL_DIR/$name"; fi
done
if [ -d "$BACKUP_DIR/maps_assets" ]; then
    rm -rf "$CENTRAL_DIR/maps_assets"
    cp -a "$BACKUP_DIR/maps_assets" "$CENTRAL_DIR/maps_assets"
fi
echo "Rollback do mapa concluido a partir de: $BACKUP_DIR"
