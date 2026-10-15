#!/bin/sh
set -eu
[ "$(id -u)" -eq 0 ] || exit 1
command -v python3 >/dev/null 2>&1 || { echo 'ERRO: python3 necessario para a manutencao do Toolkit' >&2; exit 1; }
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ADMIN_DIR="${MKAUTH_ADMIN:-/opt/mk-auth/admin}"
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
MAINT_DIR="${MKAUTH_MAINTENANCE_DIR:-$(dirname "$ADMIN_DIR")/scripts/mkauth-toolkit-maintenance}"
MAINT_CRON="${MKAUTH_MAINTENANCE_CRON:-/etc/cron.d/mkauth-toolkit-maintenance}"
TELEGRAM_STATE="${MKAUTH_MAP_TELEGRAM_STATE:-/var/lib/mkauth-map-telegram}"
CACHE_DIR=$(php -r '$cfg=require $argv[1]; echo $cfg["cache_dir"];' "$ADMIN_DIR/addons/geocodificacao/config.php")
case "$MAINT_DIR$MAINT_CRON" in *[!a-zA-Z0-9_./-]*) echo 'ERRO: caminho de manutencao invalido' >&2; exit 1 ;; esac
mkdir -p "$MAINT_DIR"
chmod 0700 "$MAINT_DIR"
install -m 0700 "$SCRIPT_DIR/toolkit-maintenance.py" "$MAINT_DIR/maintain.py"
python3 - "$MAINT_DIR" "$ADMIN_DIR" "$BACKUP_ROOT" "$CACHE_DIR" "$TELEGRAM_STATE" <<'PY'
import json,os,pathlib,sys
root,admin,backups,cache,telegram=map(pathlib.Path,sys.argv[1:])
for p in [root,backups,cache,telegram]:
    if not p.is_absolute() or p.resolve()!=p or str(p) in ('/','/tmp','/var/tmp','/var','/root','/home','/opt'):raise ValueError('Caminho de manutencao inseguro')
cfg=root/'config.json'
if cfg.is_symlink():raise ValueError('Configuracao simbolica recusada')
data=json.loads(cfg.read_text()) if cfg.exists() else {}
for key,p in [('cache_dirs',cache),('backup_roots',backups),('telegram_dirs',telegram)]:
    data[key]=sorted(set(data.get(key,[])+[str(p)]))
data.update(lock=str(root/'maintenance.lock'),log=str(root/'maintenance.log'),disk_path=str(admin))
tmp=root/'config.new';tmp.write_text(json.dumps(data,indent=2));os.chmod(str(tmp),0o600);os.replace(str(tmp),str(cfg))
PY
printf 'SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n17 * * * * root /usr/bin/python3 %s/maintain.py --config %s/config.json >/dev/null 2>&1\n' "$MAINT_DIR" "$MAINT_DIR" > "$MAINT_CRON"
chmod 0644 "$MAINT_CRON"
python3 "$MAINT_DIR/maintain.py" --config "$MAINT_DIR/config.json"
echo 'Manutencao automatica instalada: cache horario, backups com retencao e log limitado.'
