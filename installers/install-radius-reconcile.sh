#!/bin/sh
set -eu

SCRIPT_DIR="/opt/mk-auth/scripts"
SCRIPT_FILE="$SCRIPT_DIR/mkauth_radius_ppp_reconcile.php"
CRON_FILE="/etc/cron.d/mkauth-radius-ppp-reconcile"
BACKUP_DIR="/root/mkauth_radius_reconcile_backup_$(date +%Y%m%d_%H%M%S)"
LOG_FILE="/var/log/mkauth_radius_ppp_reconcile.log"
STATE_DIR="/var/lib/mkauth_radius_ppp_reconcile"
STATE_FILE="$STATE_DIR/status.json"
ADMIN_DIR="/opt/mk-auth/admin"
DASHBOARD_DIR="$ADMIN_DIR/addons/dashboard"
DASHBOARD_INDEX="$DASHBOARD_DIR/index.php"
DASHBOARD_STATUS="$DASHBOARD_DIR/radius_status.php"

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-vertrigo}"
MYSQL_DB="${MYSQL_DB:-mkradius}"
API_USER="${API_USER:-mkauth}"
API_PORT="${API_PORT:-8728}"
FALLBACK_API_PASS="${FALLBACK_API_PASS:-123456}"
PPP_SERVICES="${PPP_SERVICES:-pppoe}"
CRON_INTERVAL="${CRON_INTERVAL:-*/2 * * * *}"

mkdir -p "$SCRIPT_DIR" "$BACKUP_DIR" "$STATE_DIR" "$DASHBOARD_DIR"
chmod 755 "$STATE_DIR"

if [ -f "$SCRIPT_FILE" ]; then
  cp -a "$SCRIPT_FILE" "$BACKUP_DIR/$(basename "$SCRIPT_FILE").bak"
fi
if [ -f "$CRON_FILE" ]; then
  cp -a "$CRON_FILE" "$BACKUP_DIR/$(basename "$CRON_FILE").bak"
fi
if [ -f "$DASHBOARD_INDEX" ]; then
  cp -a "$DASHBOARD_INDEX" "$BACKUP_DIR/dashboard_index.php.bak"
fi
if [ -f "$DASHBOARD_STATUS" ]; then
  cp -a "$DASHBOARD_STATUS" "$BACKUP_DIR/radius_status.php.bak"
fi

mysqldump -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" radacct nas 2>/dev/null | gzip > "$BACKUP_DIR/radacct_nas.sql.gz" || true

cat > "$SCRIPT_FILE" <<'PHP'
#!/usr/bin/env php
<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

$cfg = array(
    'mysql_host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
    'mysql_user' => getenv('MYSQL_USER') ?: 'root',
    'mysql_pass' => getenv('MYSQL_PASS') ?: 'vertrigo',
    'mysql_db' => getenv('MYSQL_DB') ?: 'mkradius',
    'api_user' => getenv('API_USER') ?: 'mkauth',
    'api_port' => (int)(getenv('API_PORT') ?: 8728),
    'fallback_api_pass' => getenv('FALLBACK_API_PASS') ?: '123456',
    'ppp_services' => getenv('PPP_SERVICES') ?: 'pppoe',
    'log_file' => getenv('RECONCILE_LOG') ?: '/var/log/mkauth_radius_ppp_reconcile.log',
    'state_file' => getenv('RECONCILE_STATE') ?: '/var/lib/mkauth_radius_ppp_reconcile/status.json',
    'timeout' => (int)(getenv('API_TIMEOUT') ?: 8),
);

$apply = in_array('--apply', $argv, true);
$onlyLogin = null;
$onlyRouter = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--login=') === 0) $onlyLogin = strtolower(trim(substr($arg, 8)));
    if (strpos($arg, '--router=') === 0) $onlyRouter = trim(substr($arg, 9));
}

function log_line($msg) {
    global $cfg;
    $line = date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($cfg['log_file'], $line, FILE_APPEND);
}

function uptime_to_seconds($uptime) {
    $total = 0;
    if (preg_match_all('/(\d+)(w|d|h|m|s)/', (string)$uptime, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $n = (int)$m[1];
            if ($m[2] === 'w') $total += $n * 604800;
            if ($m[2] === 'd') $total += $n * 86400;
            if ($m[2] === 'h') $total += $n * 3600;
            if ($m[2] === 'm') $total += $n * 60;
            if ($m[2] === 's') $total += $n;
        }
    }
    return $total;
}

function session_id_clean($sessionId) {
    $sessionId = strtolower(trim((string)$sessionId));
    if (strpos($sessionId, '0x') === 0) $sessionId = substr($sessionId, 2);
    return $sessionId;
}

function allowed_ppp_services($services) {
    $allowed = array();
    foreach (explode(',', strtolower((string)$services)) as $service) {
        $service = trim($service);
        if ($service !== '') $allowed[$service] = true;
    }
    return $allowed;
}

class RouterosMiniApi {
    private $socket = null;
    private $timeout;

    public function __construct($timeout = 8) {
        $this->timeout = $timeout;
    }

    public function connect($host, $user, $pass, $port = 8728) {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $this->timeout);
        if (!$this->socket) return false;
        stream_set_timeout($this->socket, $this->timeout);

        $this->writeSentence(array('/login', '=name=' . $user, '=password=' . $pass));
        $reply = $this->readReply();
        if ($this->hasDone($reply)) return true;

        $challenge = $this->findRet($reply);
        if ($challenge !== '') {
            $response = '00' . md5(chr(0) . $pass . pack('H*', $challenge));
            $this->writeSentence(array('/login', '=name=' . $user, '=response=' . $response));
            $reply = $this->readReply();
            if ($this->hasDone($reply)) return true;
        }

        $this->disconnect();
        return false;
    }

    public function comm($command) {
        $this->writeSentence(array($command));
        $reply = $this->readReply();
        $rows = array();
        foreach ($reply as $sentence) {
            if (!isset($sentence[0]) || $sentence[0] !== '!re') continue;
            $row = array();
            foreach ($sentence as $word) {
                if (strpos($word, '=') !== 0) continue;
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) $row[$parts[0]] = $parts[1];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function disconnect() {
        if ($this->socket) fclose($this->socket);
        $this->socket = null;
    }

    private function hasDone($reply) {
        foreach ($reply as $sentence) {
            if (isset($sentence[0]) && $sentence[0] === '!done') return true;
        }
        return false;
    }

    private function findRet($reply) {
        foreach ($reply as $sentence) {
            foreach ($sentence as $word) {
                if (strpos($word, '=ret=') === 0) return substr($word, 5);
            }
        }
        return '';
    }

    private function writeSentence($words) {
        foreach ($words as $word) $this->writeWord($word);
        $this->writeWord('');
    }

    private function writeWord($word) {
        $len = strlen($word);
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr(($len >> 8) | 0x80) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            fwrite($this->socket, chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
        if ($len > 0) fwrite($this->socket, $word);
    }

    private function readReply() {
        $reply = array();
        $sentence = array();
        while (true) {
            $word = $this->readWord();
            if ($word === false) break;
            if ($word === '') {
                if ($sentence) {
                    $reply[] = $sentence;
                    if (isset($sentence[0]) && ($sentence[0] === '!done' || $sentence[0] === '!fatal')) break;
                    $sentence = array();
                }
                continue;
            }
            $sentence[] = $word;
        }
        return $reply;
    }

    private function readWord() {
        $len = $this->readLength();
        if ($len === false) return false;
        if ($len === 0) return '';
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($this->socket, $len - strlen($data));
            if ($chunk === false || $chunk === '') return false;
            $data .= $chunk;
        }
        return $data;
    }

    private function readLength() {
        $c = fread($this->socket, 1);
        if ($c === false || $c === '') return false;
        $c = ord($c);
        if (($c & 0x80) === 0x00) return $c;
        if (($c & 0xC0) === 0x80) return (($c & ~0xC0) << 8) + ord(fread($this->socket, 1));
        if (($c & 0xE0) === 0xC0) return (($c & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($c & 0xF0) === 0xE0) return (($c & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        return false;
    }
}

$db = new mysqli($cfg['mysql_host'], $cfg['mysql_user'], $cfg['mysql_pass'], $cfg['mysql_db']);
if ($db->connect_error) {
    log_line('ERROR mysql_connect ' . $db->connect_error);
    exit(2);
}
$db->set_charset('latin1');

$nasRows = array();
$sql = "SELECT nasname, shortname, senha FROM nas WHERE nasname IS NOT NULL AND nasname <> '' ORDER BY nasname";
$res = $db->query($sql);
if (!$res) {
    log_line('ERROR nas_query ' . $db->error);
    exit(2);
}
while ($row = $res->fetch_assoc()) {
    if ($onlyRouter !== null && $row['nasname'] !== $onlyRouter) continue;
    $nasRows[] = $row;
}

$stats = array('routers_ok' => 0, 'routers_fail' => 0, 'active_seen' => 0, 'already_online' => 0, 'reopened' => 0, 'inserted' => 0, 'errors' => 0);
$failedRouters = array();
$allowedServices = allowed_ppp_services($cfg['ppp_services']);

foreach ($nasRows as $nas) {
    $router = $nas['nasname'];
    $routerName = $nas['shortname'];
    $password = trim((string)$nas['senha']) !== '' ? trim((string)$nas['senha']) : $cfg['fallback_api_pass'];
    $api = new RouterosMiniApi($cfg['timeout']);

    if (!$api->connect($router, $cfg['api_user'], $password, $cfg['api_port'])) {
        $stats['routers_fail']++;
        $failedRouters[] = array('router' => $router, 'name' => $routerName);
        log_line("ROUTER_FAIL router=$router name=\"$routerName\"");
        continue;
    }

    $stats['routers_ok']++;
    $active = $api->comm('/ppp/active/print');
    $api->disconnect();
    if (!is_array($active)) {
        log_line("ROUTER_EMPTY router=$router name=\"$routerName\"");
        continue;
    }

    foreach ($active as $ppp) {
        if (!isset($ppp['name'])) continue;
        $login = strtolower(trim($ppp['name']));
        if ($onlyLogin !== null && $login !== $onlyLogin) continue;
        $service = isset($ppp['service']) ? strtolower(trim((string)$ppp['service'])) : '';
        if ($allowedServices && !isset($allowedServices[$service])) continue;
        if (isset($ppp['radius']) && (string)$ppp['radius'] !== 'true') continue;

        $stats['active_seen']++;
        $address = isset($ppp['address']) ? $ppp['address'] : '';
        $caller = isset($ppp['caller-id']) ? $ppp['caller-id'] : '';
        $session = isset($ppp['session-id']) ? session_id_clean($ppp['session-id']) : '';
        $uptime = isset($ppp['uptime']) ? $ppp['uptime'] : '';
        $sessionSeconds = uptime_to_seconds($uptime);
        $startTime = date('Y-m-d H:i:s', time() - $sessionSeconds);

        $stmt = $db->prepare("SELECT radacctid FROM radacct WHERE LOWER(TRIM(username)) = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
        $stmt->bind_param('s', $login);
        $stmt->execute();
        $online = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($online) {
            $stats['already_online']++;
            log_line("ONLINE_OK login=$login router=$router radacctid={$online['radacctid']}");
            continue;
        }

        $stopped = null;
        if ($session !== '') {
            $stmt = $db->prepare("SELECT radacctid, acctsessionid, acctstarttime, acctstoptime FROM radacct WHERE LOWER(TRIM(username)) = ? AND nasipaddress = ? AND LOWER(acctsessionid) = ? ORDER BY radacctid DESC LIMIT 1");
            $stmt->bind_param('sss', $login, $router, $session);
            $stmt->execute();
            $stopped = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$stopped) {
            $stmt = $db->prepare("SELECT radacctid, acctsessionid, acctstarttime, acctstoptime FROM radacct WHERE LOWER(TRIM(username)) = ? AND nasipaddress = ? ORDER BY radacctid DESC LIMIT 1");
            $stmt->bind_param('ss', $login, $router);
            $stmt->execute();
            $stopped = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($stopped) {
            log_line(($apply ? 'REOPEN' : 'DRY_REOPEN') . " login=$login router=$router radacctid={$stopped['radacctid']} session=$session ip=$address caller=\"$caller\" uptime=$uptime stopped=\"{$stopped['acctstoptime']}\"");
            if ($apply) {
                $stmt = $db->prepare("UPDATE radacct SET acctstoptime = NULL, acctterminatecause = NULL, acctupdatetime = NOW(), acctsessiontime = ?, framedipaddress = ?, callingstationid = ?, acctsessionid = IF(? <> '', ?, acctsessionid) WHERE radacctid = ?");
                $stmt->bind_param('issssi', $sessionSeconds, $address, $caller, $session, $session, $stopped['radacctid']);
                if (!$stmt->execute()) {
                    $stats['errors']++;
                    log_line("ERROR_REOPEN login=$login radacctid={$stopped['radacctid']} error={$stmt->error}");
                } else {
                    $stats['reopened']++;
                }
                $stmt->close();
            }
        } else {
            $unique = md5($router . '|' . $session . '|' . $login . '|' . $startTime);
            log_line(($apply ? 'INSERT' : 'DRY_INSERT') . " login=$login router=$router session=$session ip=$address caller=\"$caller\" uptime=$uptime start=\"$startTime\"");
            if ($apply) {
                $stmt = $db->prepare("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, nasportid, acctstarttime, acctupdatetime, acctsessiontime, acctauthentic, acctinputoctets, acctoutputoctets, callingstationid, servicetype, framedprotocol, framedipaddress) VALUES (?, ?, ?, ?, 'Clientes', ?, NOW(), ?, 'RADIUS', 0, 0, ?, 'Framed-User', 'PPP', ?)");
                $stmt->bind_param('ssssssss', $session, $unique, $login, $router, $startTime, $sessionSeconds, $caller, $address);
                if (!$stmt->execute()) {
                    $stats['errors']++;
                    log_line("ERROR_INSERT login=$login error={$stmt->error}");
                } else {
                    $stats['inserted']++;
                }
                $stmt->close();
            }
        }
    }
}

$status = array(
    'generated_at' => date('Y-m-d H:i:s'),
    'apply' => $apply,
    'only_login' => $onlyLogin ?: 'all',
    'only_router' => $onlyRouter ?: 'all',
    'stats' => $stats,
    'failed_routers' => $failedRouters,
);
@file_put_contents($cfg['state_file'], json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@chmod($cfg['state_file'], 0644);

if ($failedRouters) {
    log_line('FAILED_ROUTERS ' . json_encode($failedRouters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
log_line("SUMMARY apply=" . ($apply ? 'yes' : 'no') . " only_login=" . ($onlyLogin ?: 'all') . " only_router=" . ($onlyRouter ?: 'all') . " " . json_encode($stats));
$db->close();
PHP

chmod 755 "$SCRIPT_FILE"
php -l "$SCRIPT_FILE"

cat > "$DASHBOARD_STATUS" <<'PHP'
<?php
header('Content-Type: application/json; charset=utf-8');
$file = '/var/lib/mkauth_radius_ppp_reconcile/status.json';
if (!is_file($file)) {
    echo json_encode(array('ok' => true, 'message' => 'Sem status gerado ainda', 'failed_routers' => array()));
    exit;
}
$raw = file_get_contents($file);
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(array('ok' => false, 'message' => 'Status invalido', 'failed_routers' => array()));
    exit;
}
$data['ok'] = empty($data['failed_routers']);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP
chmod 644 "$DASHBOARD_STATUS"
if [ -d "$ADMIN_DIR" ]; then
  cp -a "$DASHBOARD_STATUS" "$ADMIN_DIR/radius_status.php"
  chmod 644 "$ADMIN_DIR/radius_status.php"
fi

for DASHBOARD_INDEX in "$DASHBOARD_DIR/index.php" "$ADMIN_DIR/index.php" "$ADMIN_DIR/index.hhvm" "$ADMIN_DIR/indexnovo.hhvm"; do
if [ -f "$DASHBOARD_INDEX" ] && ! grep -q "mkauth-radius-alert-start" "$DASHBOARD_INDEX"; then
  tmp_file="/tmp/mkauth_dashboard_index.$$"
  snippet_file="/tmp/mkauth_radius_alert_snippet.$$"
  cat > "$snippet_file" <<'HTML'
<!-- mkauth-radius-alert-start -->
<style>
#mkauth-radius-alert{display:none;position:fixed;right:18px;bottom:18px;z-index:99999;max-width:460px;background:#fff3cd;color:#664d03;border:1px solid #ffda6a;border-left:6px solid #ffc107;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.2);font-family:Arial,sans-serif;font-size:14px}
#mkauth-radius-alert .mra-head{font-weight:700;padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.08)}
#mkauth-radius-alert .mra-body{padding:10px 12px;line-height:1.35}
#mkauth-radius-alert .mra-close{float:right;border:0;background:transparent;font-size:20px;line-height:16px;cursor:pointer;color:#664d03}
#mkauth-radius-alert ul{margin:8px 0 0 18px;padding:0}
</style>
<div id="mkauth-radius-alert">
  <div class="mra-head">
    <button class="mra-close" onclick="document.getElementById('mkauth-radius-alert').style.display='none'">&times;</button>
    Alerta de integracao Radius
  </div>
  <div class="mra-body" id="mkauth-radius-alert-body"></div>
</div>
<script>
(function(){
  function esc(s){
    return String(s || '').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function loadRadiusAlert(){
    fetch('radius_status.php?ts=' + Date.now(), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.failed_routers || !d.failed_routers.length) return;
        var html = '<div>Existe ramal/NAS com falha na API do MikroTik. Verifique usuario mkauth, senha do ramal, porta 8728 ou rota VPN.</div><ul>';
        d.failed_routers.forEach(function(x){
          html += '<li><strong>' + esc(x.name || 'Sem nome') + '</strong> - ' + esc(x.router) + '</li>';
        });
        html += '</ul><div style="margin-top:6px;font-size:12px">Ultima verificacao: ' + esc(d.generated_at) + '</div>';
        document.getElementById('mkauth-radius-alert-body').innerHTML = html;
        document.getElementById('mkauth-radius-alert').style.display = 'block';
      })
      .catch(function(){});
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadRadiusAlert);
  } else {
    loadRadiusAlert();
  }
  setInterval(loadRadiusAlert, 120000);
})();
</script>
<!-- mkauth-radius-alert-end -->
HTML
  awk 'FNR==NR { snippet = snippet $0 ORS; next } /<\/body>/ && !done { printf "%s", snippet; done=1 } { print }' "$snippet_file" "$DASHBOARD_INDEX" > "$tmp_file"
  cat "$tmp_file" > "$DASHBOARD_INDEX"
  rm -f "$tmp_file" "$snippet_file"
fi
done

echo "Backup criado em: $BACKUP_DIR"
echo "Rodando teste sem alterar banco..."
php "$SCRIPT_FILE" | tail -n 25

cat > "$CRON_FILE" <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$CRON_INTERVAL root MYSQL_HOST="$MYSQL_HOST" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB="$MYSQL_DB" API_USER="$API_USER" API_PORT="$API_PORT" FALLBACK_API_PASS="$FALLBACK_API_PASS" PPP_SERVICES="$PPP_SERVICES" /usr/bin/php "$SCRIPT_FILE" --apply >/dev/null 2>&1
EOF
chmod 644 "$CRON_FILE"
service cron reload 2>/dev/null || service crond reload 2>/dev/null || /etc/init.d/cron reload 2>/dev/null || true

echo "Instalado: $SCRIPT_FILE"
echo "Cron ativo: $CRON_FILE"
echo "Log: $LOG_FILE"
echo "Status: $STATE_FILE"
echo "Dashboard status: $DASHBOARD_STATUS"
echo "Rodando aplicacao imediata..."
php "$SCRIPT_FILE" --apply | tail -n 25
echo "Para testar manual: php $SCRIPT_FILE"
echo "Para aplicar manual: php $SCRIPT_FILE --apply"
echo "Para um login: php $SCRIPT_FILE --login=LOGIN --apply"
