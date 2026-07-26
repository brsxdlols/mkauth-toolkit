#!/bin/bash
# Estabiliza MariaDB/TokuDB + FreeRADIUS em servidores MK-Auth.
# Uso: bash install-mkauth-radius-stability.sh
set -euo pipefail

MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-vertrigo}"
MYSQL_DB="${MYSQL_DB:-mkradius}"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="/root/mkauth-radius-stability-backup-${STAMP}"
MYSQL_OVERRIDE="/etc/mysql/conf.d/zzz-mkauth-radius-stability.cnf"
RADIUS_DIR="/etc/freeradius/3.0"
RADIUS_SITE="${RADIUS_DIR}/sites-enabled/default"
RADIUS_CONF="${RADIUS_DIR}/radiusd.conf"
WATCHDOG="/usr/local/sbin/check_mariadb_freeradius_health.sh"
WATCHDOG_CRON="/etc/cron.d/mkauth_service_health"

[[ $EUID -eq 0 ]] || { echo "ERRO: execute como root."; exit 1; }
command -v mysql >/dev/null || { echo "ERRO: cliente mysql ausente."; exit 1; }
[[ -d /etc/mysql/conf.d ]] || { echo "ERRO: /etc/mysql/conf.d ausente."; exit 1; }
[[ -f "$RADIUS_SITE" && -f "$RADIUS_CONF" ]] ||
    { echo "ERRO: FreeRADIUS 3 não encontrado em $RADIUS_DIR."; exit 1; }

mysql_cmd=(mysql "-u${MYSQL_USER}" "-p${MYSQL_PASS}")
"${mysql_cmd[@]}" --connect-timeout=5 -NBe "SELECT 1" | grep -qx 1 ||
    { echo "ERRO: não foi possível consultar o MariaDB."; exit 1; }

mem_mb=$(awk '/MemTotal:/ {print int($2/1024)}' /proc/meminfo)
if (( mem_mb < 3000 )); then
    toku_mb=768; innodb_mb=192; max_conn=200
elif (( mem_mb < 6000 )); then
    toku_mb=1024; innodb_mb=256; max_conn=300
elif (( mem_mb < 12000 )); then
    toku_mb=2048; innodb_mb=512; max_conn=500
else
    toku_mb=$((mem_mb / 4))
    (( toku_mb > 4096 )) && toku_mb=4096
    innodb_mb=$((mem_mb / 10))
    (( innodb_mb > 2048 )) && innodb_mb=2048
    max_conn=800
fi

mkdir -p "$BACKUP_DIR"
cp -a /etc/mysql/conf.d "$BACKUP_DIR/mysql-conf.d"
cp -a "$RADIUS_CONF" "$BACKUP_DIR/radiusd.conf"
cp -a "$RADIUS_SITE" "$BACKUP_DIR/freeradius-default"
cp -a "$WATCHDOG" "$BACKUP_DIR/" 2>/dev/null || true
cp -a "$WATCHDOG_CRON" "$BACKUP_DIR/" 2>/dev/null || true

tokudb_line=""
if "${mysql_cmd[@]}" -NBe "SHOW VARIABLES LIKE 'tokudb_cache_size'" | grep -q tokudb_cache_size; then
    tokudb_line="tokudb_cache_size = ${toku_mb}M"
fi

cat > "$MYSQL_OVERRIDE" <<EOF
[mysqld]
# Gerado por install-mkauth-radius-stability.sh em ${STAMP}
max_connections = ${max_conn}
key_buffer_size = 32M
query_cache_size = 64M
query_cache_limit = 2M
tmp_table_size = 64M
max_heap_table_size = 64M
innodb_buffer_pool_size = ${innodb_mb}M
${tokudb_line}
EOF

# Desativa sqlippool somente quando ele comprovadamente não é usado.
pool_rows=$("${mysql_cmd[@]}" "$MYSQL_DB" -NBe \
    "SELECT COUNT(*) FROM radippool" 2>/dev/null || echo unknown)
pool_attrs=$("${mysql_cmd[@]}" "$MYSQL_DB" -NBe \
    "SELECT (SELECT COUNT(*) FROM radreply WHERE attribute='Pool-Name') +
            (SELECT COUNT(*) FROM radgroupreply WHERE attribute='Pool-Name')" \
    2>/dev/null || echo unknown)
if [[ "$pool_rows" == "0" && "$pool_attrs" == "0" ]]; then
    sed -i -E \
        '/^[[:space:]]*sqlippool([[:space:]]*(#.*)?)?$/s/^([[:space:]]*)sqlippool/\1# sqlippool  # desativado: pool SQL não utilizado/' \
        "$RADIUS_SITE"
    echo "sqlippool: desativado (radippool e atributos Pool-Name vazios)."
else
    echo "sqlippool: preservado (uso detectado ou verificação inconclusiva)."
fi

# Libera o teste local e silencioso de saúde do event loop.
sed -i -E 's/^([[:space:]]*)status_server[[:space:]]*=[[:space:]]*no/\1status_server = yes/' \
    "$RADIUS_CONF"

# Remove índices únicos redundantes da mesma coluna, mantendo um.
mapfile -t duplicate_indexes < <(
    "${mysql_cmd[@]}" "$MYSQL_DB" -NBe "
        SELECT INDEX_NAME
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA='${MYSQL_DB}'
           AND TABLE_NAME='radacct'
           AND COLUMN_NAME='acctuniqueid'
           AND NON_UNIQUE=0
           AND INDEX_NAME<>'PRIMARY'
         GROUP BY INDEX_NAME
         ORDER BY INDEX_NAME" | tail -n +2
)
for idx in "${duplicate_indexes[@]}"; do
    [[ "$idx" =~ ^[A-Za-z0-9_]+$ ]] || continue
    "${mysql_cmd[@]}" "$MYSQL_DB" -e "ALTER TABLE radacct DROP INDEX \`${idx}\`;"
    echo "Índice redundante removido: $idx"
done

cat > "$WATCHDOG" <<'EOF'
#!/bin/bash
set -u
PATH=/usr/sbin:/usr/bin:/sbin:/bin
LOG=/var/log/mkauth_service_health.log
LOCK=/run/lock/mkauth_service_health.lock
MYSQL_DEFAULTS=/etc/mysql/debian.cnf
RADIUS_SECRET=testing123
exec 9>"$LOCK"
flock -n 9 || exit 0
log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }
mysql_ok() {
    if [[ -r "$MYSQL_DEFAULTS" ]]; then
        timeout 6 mysql --defaults-file="$MYSQL_DEFAULTS" --connect-timeout=3 \
            -NBe "SELECT 1" 2>/dev/null | grep -qx 1
    else
        timeout 6 mysql --connect-timeout=3 -NBe "SELECT 1" 2>/dev/null | grep -qx 1
    fi
}
radius_ok() {
    pgrep -x freeradius >/dev/null 2>&1 || pgrep -x radiusd >/dev/null 2>&1 || return 1
    ss -H -lun | awk '{print $4}' | grep -Eq '(^|:)1812$' || return 1
    local response
    response=$(printf 'Message-Authenticator = 0x00\n' |
        timeout 6 radclient -x -r 1 -t 3 127.0.0.1:1812 status "$RADIUS_SECRET" 2>&1) || true
    grep -q 'Received Access-Accept' <<<"$response"
}
confirm_failure() {
    local check=$1 try
    for try in 1 2 3; do
        "$check" && return 1
        [[ $try -eq 3 ]] || sleep 3
    done
    return 0
}
restart_one() {
    log "ALERT service=$1 state=unresponsive action=restart"
    timeout 90 service "$1" restart >>"$LOG" 2>&1
    log "INFO service=$1 restart_command=success"
}
mysql_restarted=0
if confirm_failure mysql_ok; then
    restart_one mysql || exit 1
    mysql_restarted=1
    sleep 8
    mysql_ok || { log "CRITICAL service=mysql state=down_after_restart"; exit 1; }
fi
if [[ $mysql_restarted -eq 1 ]] || confirm_failure radius_ok; then
    restart_one freeradius || exit 1
    sleep 5
    radius_ok || { log "CRITICAL service=freeradius state=down_after_restart"; exit 1; }
fi
EOF
chmod 750 "$WATCHDOG"
cat > "$WATCHDOG_CRON" <<EOF
SHELL=/bin/bash
* * * * * root $WATCHDOG
EOF
chmod 644 "$WATCHDOG_CRON"

freeradius -XC >/tmp/mkauth-radius-stability-check.log 2>&1

echo "Reiniciando MariaDB..."
timeout 120 service mysql restart
sleep 8
"${mysql_cmd[@]}" --connect-timeout=5 -NBe "SELECT 1" | grep -qx 1

echo "Reiniciando FreeRADIUS..."
timeout 90 service freeradius restart
sleep 5
"$WATCHDOG"

echo
echo "Instalação concluída."
echo "RAM detectada: ${mem_mb} MB"
echo "TokuDB: ${tokudb_line:-não instalado}"
echo "InnoDB: ${innodb_mb}M"
echo "max_connections: ${max_conn}"
echo "Backup para rollback: $BACKUP_DIR"
