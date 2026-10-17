#!/bin/bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/opt/mk-auth/backups/additional-block-patch-$STAMP"

for command_name in mysql php radclient; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Dependencia ausente: $command_name" >&2
        exit 1
    fi
done

if [ ! -f "$BASE_DIR/mkauth_additional_block_patch.sql" ] || [ ! -f "$BASE_DIR/mkauth_additional_block_worker.php" ]; then
    echo "Arquivos SQL/PHP do patch nao encontrados em $BASE_DIR" >&2
    exit 1
fi

# Falha antes de qualquer escrita quando o esquema nao tem as colunas esperadas.
mysql -uroot -pvertrigo -e "
USE mkradius;
SELECT login,bloqueado,plano_bloqc FROM sis_cliente LIMIT 0;
SELECT login,username,bloqueado,tipo,ramal,plano,plano_bloqa,ip,pool_name,pool6 FROM sis_adicional LIMIT 0;
SELECT username,ativo FROM radcheck LIMIT 0;
SELECT username,attribute,op,value,login FROM radreply LIMIT 0;
SELECT username,groupname,priority,login FROM radusergroup LIMIT 0;
SELECT nome,valor FROM sis_opcao LIMIT 0;
SELECT nasname,secret,senha FROM nas LIMIT 0;
SELECT username,nasipaddress,acctsessionid,framedipaddress,acctstoptime FROM radacct LIMIT 0;
" >/dev/null

mkdir -p "$BACKUP_DIR"
mysql -uroot -pvertrigo -N -B -e "USE mkradius; SHOW CREATE TRIGGER tig_cliente\G" > "$BACKUP_DIR/tig_cliente.before.txt"
mysql -uroot -pvertrigo -N -B -e "USE mkradius; SHOW TRIGGERS; SHOW PROCEDURE STATUS WHERE Db='mkradius';" > "$BACKUP_DIR/db_objects.before.txt"
crontab -l > "$BACKUP_DIR/root.crontab.before" 2>/dev/null || true
cp -a /etc/cron.d "$BACKUP_DIR/cron.d.before"

install -o root -g root -m 0750 "$BASE_DIR/mkauth_additional_block_worker.php" /opt/mk-auth/scripts/mkauth_additional_block_worker.php
mysql -uroot -pvertrigo < "$BASE_DIR/mkauth_additional_block_patch.sql"

cat > /etc/cron.d/mkauth-additional-block <<'CRON'
* * * * * root MYSQL_HOST="127.0.0.1" MYSQL_USER="root" MYSQL_PASS="vertrigo" MYSQL_DB="mkradius" API_USER="mkauth" API_PORT="8728" COA_PORT="3799" /usr/bin/php /opt/mk-auth/scripts/mkauth_additional_block_worker.php >/dev/null 2>&1
CRON
chmod 0644 /etc/cron.d/mkauth-additional-block

echo "$BACKUP_DIR" > /opt/mk-auth/backups/additional-block-patch-latest
echo "Patch instalado. Backup: $BACKUP_DIR"
