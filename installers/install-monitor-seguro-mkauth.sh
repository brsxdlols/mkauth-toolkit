#!/bin/bash
# Instala monitor conservador de MariaDB/MySQL e FreeRadius no MK-AUTH.
# Execute como root: bash instalar_monitor_seguro_mkauth.sh
set -Eeuo pipefail

STAMP="$(date +%Y%m%d-%H%M%S)"
WATCHDOG="/usr/local/sbin/check_mariadb_freeradius_health.sh"
CRON_FILE="/etc/cron.d/mkauth_service_health"
BACKUP_DIR="/root/backup-monitor-mkauth-${STAMP}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die() { log "ERRO: $*" >&2; exit 1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "execute este instalador como root"
command -v flock >/dev/null 2>&1 || die "comando flock nao encontrado"
command -v mysqladmin >/dev/null 2>&1 || die "comando mysqladmin nao encontrado"
command -v ss >/dev/null 2>&1 || die "comando ss nao encontrado"
command -v timeout >/dev/null 2>&1 || die "comando timeout nao encontrado"

mkdir -p "$BACKUP_DIR"
chmod 0700 "$BACKUP_DIR"

backup_file() {
    local file=$1
    [[ -e "$file" ]] || return 0
    local destination="$BACKUP_DIR${file}"
    mkdir -p "$(dirname "$destination")"
    cp -a "$file" "$destination"
}

log "Procurando crons antigos do check_backup_freeradius..."
mapfile -t LEGACY_FILES < <(
    grep -RIl --include='*' 'check_backup_freeradius' \
        /etc/crontab /etc/cron.d /var/spool/cron/crontabs 2>/dev/null || true
)

for file in "${LEGACY_FILES[@]}"; do
    backup_file "$file"
    # Comenta somente linhas ativas que chamam check_backup_freeradius.
    sed -i -E "/^[[:space:]]*#/! {/check_backup_freeradius/ s|^|# DESATIVADO ${STAMP}: watchdog antigo generico - |}" "$file"
    log "Desativado watchdog antigo em: $file"
done

backup_file "$WATCHDOG"
backup_file "$CRON_FILE"

log "Instalando monitor seguro..."
cat > "$WATCHDOG" <<'WATCHDOG_EOF'
#!/bin/bash
set -u

PATH=/usr/sbin:/usr/bin:/sbin:/bin
LOG=/var/log/mkauth_service_health.log
LOCK=/run/lock/mkauth_service_health.lock
MYSQL_DEFAULTS=/etc/mysql/debian.cnf

exec 9>"$LOCK"
flock -n 9 || exit 0

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG"
}

mysql_ok() {
    if [[ -r "$MYSQL_DEFAULTS" ]]; then
        mysqladmin --defaults-file="$MYSQL_DEFAULTS" ping --silent >/dev/null 2>&1
    else
        mysqladmin ping --silent >/dev/null 2>&1
    fi
}

radius_process_ok() {
    pgrep -x freeradius >/dev/null 2>&1 || pgrep -x radiusd >/dev/null 2>&1
}

radius_socket_ok() {
    # Em iproute2/ss, o endereco local e a quarta coluna com -H -lun.
    ss -H -lun 2>/dev/null | awk '{print $4}' | grep -Eq '(^|:)1812$'
}

radius_ok() {
    radius_process_ok && radius_socket_ok
}

# Retorna sucesso apenas quando a falha persistir por tres tentativas.
confirm_failure() {
    local check_function=$1
    local attempt
    for attempt in 1 2 3; do
        "$check_function" && return 1
        [[ $attempt -eq 3 ]] || sleep 3
    done
    return 0
}

restart_service() {
    local service_name=$1
    log "ALERT service=$service_name state=down action=restart"
    if timeout 90 service "$service_name" restart >> "$LOG" 2>&1; then
        log "INFO service=$service_name restart_command=success"
    else
        local result=$?
        log "ERROR service=$service_name restart_command=failed exit=$result"
        return "$result"
    fi
}

# MariaDB/MySQL primeiro, pois o FreeRadius depende do banco.
if confirm_failure mysql_ok; then
    restart_service mysql || exit 1
    sleep 8
    if ! mysql_ok; then
        log "CRITICAL service=mysql state=down_after_restart"
        exit 1
    fi
    log "RECOVERY service=mysql state=healthy"
fi

# Reinicia FreeRadius somente se processo ou porta UDP 1812 estiverem ausentes.
if confirm_failure radius_ok; then
    restart_service freeradius || exit 1
    sleep 5
    if ! radius_ok; then
        log "CRITICAL service=freeradius state=down_after_restart"
        exit 1
    fi
    log "RECOVERY service=freeradius state=healthy"
fi

exit 0
WATCHDOG_EOF

chown root:root "$WATCHDOG"
chmod 0750 "$WATCHDOG"
bash -n "$WATCHDOG" || die "falha de sintaxe no monitor; cron novo nao foi ativado"

log "Validando servicos sem reiniciar..."
if [[ -r /etc/mysql/debian.cnf ]]; then
    mysqladmin --defaults-file=/etc/mysql/debian.cnf ping --silent \
        || die "MariaDB/MySQL nao respondeu; cron novo nao foi ativado"
else
    mysqladmin ping --silent \
        || die "MariaDB/MySQL nao respondeu; cron novo nao foi ativado"
fi

(pgrep -x freeradius >/dev/null 2>&1 || pgrep -x radiusd >/dev/null 2>&1) \
    || die "processo FreeRadius nao encontrado; cron novo nao foi ativado"
ss -H -lun | awk '{print $4}' | grep -Eq '(^|:)1812$' \
    || die "porta UDP 1812 nao encontrada; cron novo nao foi ativado"

MYSQL_PID_BEFORE="$(pgrep -o mysqld || pgrep -o mariadbd || true)"
RADIUS_PID_BEFORE="$(pgrep -o freeradius || pgrep -o radiusd || true)"

# Com ambos saudaveis, esta execucao nao pode reiniciar nenhum servico.
"$WATCHDOG"

MYSQL_PID_AFTER="$(pgrep -o mysqld || pgrep -o mariadbd || true)"
RADIUS_PID_AFTER="$(pgrep -o freeradius || pgrep -o radiusd || true)"
[[ -n "$MYSQL_PID_BEFORE" && "$MYSQL_PID_BEFORE" == "$MYSQL_PID_AFTER" ]] \
    || die "PID do banco mudou durante a validacao; cron novo nao foi ativado"
[[ -n "$RADIUS_PID_BEFORE" && "$RADIUS_PID_BEFORE" == "$RADIUS_PID_AFTER" ]] \
    || die "PID do FreeRadius mudou durante a validacao; cron novo nao foi ativado"

cat > "$CRON_FILE" <<'CRON_EOF'
# Monitor conservador MK-AUTH: nao interpreta radius.log e evita execucao concorrente.
* * * * * root /usr/local/sbin/check_mariadb_freeradius_health.sh
CRON_EOF
chown root:root "$CRON_FILE"
chmod 0644 "$CRON_FILE"

log "Instalacao concluida sem reiniciar servicos."
log "Backup: $BACKUP_DIR"
log "Monitor: $WATCHDOG"
log "Cron: $CRON_FILE"
log "Log de recuperacoes: /var/log/mkauth_service_health.log"
printf 'MariaDB/MySQL PID: %s\nFreeRadius PID: %s\n' "$MYSQL_PID_AFTER" "$RADIUS_PID_AFTER"
