#!/bin/sh
set -eu

ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
BACKUP_DIR="${1:-}"
[ "$(id -u)" -eq 0 ] || { echo "ERRO: execute como root" >&2; exit 1; }
[ -n "$BACKUP_DIR" ] || { echo "Uso: $0 /root/backups/mk-auth-geocodificacao-DATA-vVERSAO" >&2; exit 1; }
[ -d "$BACKUP_DIR" ] || { echo "ERRO: backup inexistente: $BACKUP_DIR" >&2; exit 1; }
[ -f "$BACKUP_DIR/mk-auth.js" ] || { echo "ERRO: backup invalido" >&2; exit 1; }

cp -a "$BACKUP_DIR/mk-auth.js" "$ADMIN_DIR/scripts/mk-auth.js"
if [ -d "$BACKUP_DIR/geocodificacao" ]; then
    rm -rf "$ADMIN_DIR/addons/geocodificacao"
    cp -a "$BACKUP_DIR/geocodificacao" "$ADMIN_DIR/addons/geocodificacao"
fi
for name in leaflet.css leaflet.js; do
    case "$name" in
        leaflet.css) target="$ADMIN_DIR/estilos/$name" ;;
        leaflet.js) target="$ADMIN_DIR/scripts/$name" ;;
    esac
    if [ -f "$BACKUP_DIR/$name" ]; then cp -a "$BACKUP_DIR/$name" "$target"; fi
    if [ -f "$BACKUP_DIR/$name.absent" ]; then rm -f "$target"; fi
done
echo "Rollback concluido a partir de: $BACKUP_DIR"
