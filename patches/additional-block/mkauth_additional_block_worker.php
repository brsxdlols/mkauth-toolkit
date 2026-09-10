#!/usr/bin/env php
<?php
// Processa a fila de disconnect criada pelo trigger do patch de adicionais.
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

$cfg = array(
    'mysql_host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
    'mysql_user' => getenv('MYSQL_USER') ?: 'root',
    'mysql_pass' => getenv('MYSQL_PASS') ?: 'vertrigo',
    'mysql_db' => getenv('MYSQL_DB') ?: 'mkradius',
    'api_user' => getenv('API_USER') ?: 'mkauth',
    'api_port' => (int)(getenv('API_PORT') ?: 8728),
    'api_fallback_pass' => getenv('FALLBACK_API_PASS') ?: '123456',
    'coa_port' => (int)(getenv('COA_PORT') ?: 3799),
    'timeout' => (int)(getenv('DISCONNECT_TIMEOUT') ?: 4),
    'batch' => (int)(getenv('QUEUE_BATCH') ?: 100),
    'log_file' => getenv('ADDITIONAL_BLOCK_LOG') ?: '/var/log/mkauth_additional_block.log',
    'lock_file' => getenv('ADDITIONAL_BLOCK_LOCK') ?: '/var/run/mkauth_additional_block.lock',
);

$dryRun = in_array('--dry-run', $argv, true);
$noDisconnect = in_array('--no-disconnect', $argv, true);
$onlyLogin = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--login=') === 0) $onlyLogin = trim(substr($arg, 8));
}

function log_line($message) {
    global $cfg;
    $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    echo $line;
    @file_put_contents($cfg['log_file'], $line, FILE_APPEND | LOCK_EX);
}

function sql_quote(mysqli $db, $value) {
    return "'" . $db->real_escape_string($value) . "'";
}

function radius_value($value) {
    return str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '', ''), (string)$value);
}

class RouterosMiniApi {
    private $socket = null;
    private $timeout;
    public function __construct($timeout) { $this->timeout = $timeout; }

    public function connect($host, $user, $pass, $port) {
        $errno = 0; $errstr = '';
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

    public function command($path, $words) {
        $sentence = array($path);
        foreach ($words as $word) $sentence[] = $word;
        $this->writeSentence($sentence);
        return $this->readReply();
    }

    public function rows($reply) {
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

    public function disconnect() { if ($this->socket) fclose($this->socket); $this->socket = null; }
    private function hasDone($reply) { foreach ($reply as $s) if (isset($s[0]) && $s[0] === '!done') return true; return false; }
    private function findRet($reply) { foreach ($reply as $s) foreach ($s as $w) if (strpos($w, '=ret=') === 0) return substr($w, 5); return ''; }
    private function writeSentence($words) { foreach ($words as $word) $this->writeWord($word); $this->writeWord(''); }
    private function writeWord($word) {
        $len = strlen($word);
        if ($len < 0x80) fwrite($this->socket, chr($len));
        elseif ($len < 0x4000) fwrite($this->socket, chr(($len >> 8) | 0x80) . chr($len & 0xFF));
        elseif ($len < 0x200000) fwrite($this->socket, chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        else fwrite($this->socket, chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        if ($len > 0) fwrite($this->socket, $word);
    }
    private function readReply() {
        $reply = array(); $sentence = array();
        while (true) {
            $word = $this->readWord();
            if ($word === false) break;
            if ($word === '') {
                if ($sentence) {
                    $reply[] = $sentence;
                    if (isset($sentence[0]) && ($sentence[0] === '!done' || $sentence[0] === '!fatal')) break;
                    $sentence = array();
                }
            } else $sentence[] = $word;
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

function radclient_disconnect($host, $secret, $login, $sessionId, $ip) {
    global $cfg, $dryRun;
    $attrs = 'User-Name = "' . radius_value($login) . '"' . "\n";
    if ($sessionId !== '') $attrs .= 'Acct-Session-Id = "' . radius_value($sessionId) . '"' . "\n";
    if ($ip !== '') $attrs .= 'Framed-IP-Address = ' . radius_value($ip) . "\n";
    if ($dryRun) return array(true, 'dry_radius ' . $host . ':' . $cfg['coa_port']);

    $cmd = '/usr/bin/radclient -x -r 1 -t ' . (int)$cfg['timeout'] . ' '
         . escapeshellarg($host . ':' . $cfg['coa_port']) . ' disconnect ' . escapeshellarg($secret);
    $spec = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
    $proc = @proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) return array(false, 'radclient_start_failed');
    fwrite($pipes[0], $attrs); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $output = $stdout . $stderr;
    if (strpos($output, 'Disconnect-ACK') !== false) return array(true, 'radius_ack');
    if (strpos($output, 'Session-Context-Not-Found') !== false) return array(true, 'radius_offline');
    if (strpos($output, 'Disconnect-NAK') !== false) return array(false, 'radius_nak');
    return array(false, 'radius_no_reply');
}

function api_disconnect($nas, $login, $type) {
    global $cfg, $dryRun;
    if ($dryRun) return array(true, 'dry_api ' . $nas['nasname']);
    $pass = trim((string)$nas['senha']) !== '' ? trim((string)$nas['senha']) : $cfg['api_fallback_pass'];
    $api = new RouterosMiniApi($cfg['timeout']);
    if (!$api->connect($nas['nasname'], $cfg['api_user'], $pass, $cfg['api_port'])) return array(false, 'api_connect_failed');

    $paths = strtolower($type) === 'hotspot'
        ? array('/ip/hotspot/active')
        : array('/ppp/active');
    $removed = 0;
    foreach ($paths as $base) {
        $rows = $api->rows($api->command($base . '/print', array('?name=' . $login)));
        foreach ($rows as $row) {
            if (!isset($row['.id'])) continue;
            $api->command($base . '/remove', array('=.id=' . $row['.id']));
            $removed++;
        }
    }
    $api->disconnect();
    return array(true, $removed > 0 ? 'api_removed=' . $removed : 'api_offline');
}

function disconnect_additional(mysqli $db, $row) {
    global $cfg, $noDisconnect;
    if ($noDisconnect) return array(true, 'disconnect_disabled');
    $loginQ = sql_quote($db, $row['additional_username']);
    $sessions = array();
    $res = $db->query("SELECT nasipaddress,acctsessionid,framedipaddress FROM radacct WHERE LOWER(TRIM(username))=LOWER(TRIM($loginQ)) AND acctstoptime IS NULL ORDER BY radacctid DESC");
    if ($res) while ($s = $res->fetch_assoc()) $sessions[] = $s;

    $targets = array();
    if ($sessions) {
        foreach ($sessions as $s) $targets[$s['nasipaddress']] = $s;
    } elseif ($row['ramal'] !== '' && strtolower($row['ramal']) !== 'todos') {
        $targets[$row['ramal']] = array('nasipaddress'=>$row['ramal'],'acctsessionid'=>'','framedipaddress'=>$row['ip']);
    } else {
        $res = $db->query("SELECT nasname FROM nas WHERE nasname<>'' ORDER BY nasname");
        if ($res) while ($n = $res->fetch_assoc()) $targets[$n['nasname']] = array('nasipaddress'=>$n['nasname'],'acctsessionid'=>'','framedipaddress'=>$row['ip']);
    }

    if (!$targets) return array(true, 'no_nas_target');
    $messages = array(); $allOk = true;
    foreach ($targets as $host => $session) {
        $hostQ = sql_quote($db, $host);
        $nasRes = $db->query("SELECT nasname,secret,senha FROM nas WHERE nasname=$hostQ LIMIT 1");
        $nas = $nasRes ? $nasRes->fetch_assoc() : null;
        if (!$nas) { $allOk = false; $messages[] = $host . ':nas_not_found'; continue; }

        list($radiusOk, $radiusMsg) = radclient_disconnect(
            $host, $nas['secret'], $row['additional_username'],
            (string)$session['acctsessionid'], (string)$session['framedipaddress']
        );
        if ($radiusOk) { $messages[] = $host . ':' . $radiusMsg; continue; }

        list($apiOk, $apiMsg) = api_disconnect($nas, $row['additional_username'], $row['tipo']);
        $messages[] = $host . ':' . $radiusMsg . '+' . $apiMsg;
        if (!$apiOk) $allOk = false;
    }
    return array($allOk, implode(',', $messages));
}

$lock = fopen($cfg['lock_file'], 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$db = new mysqli($cfg['mysql_host'], $cfg['mysql_user'], $cfg['mysql_pass'], $cfg['mysql_db']);
if ($db->connect_error) { log_line('ERROR mysql_connect ' . $db->connect_error); exit(2); }
$db->set_charset('latin1');

if (!$dryRun) {
    $db->query("UPDATE mkauth_adicional_block_queue SET status='pending',result_message='recovered_stale_processing' WHERE status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");

    // Recupera alteracoes perdidas, inclusive adicionais cadastrados depois de um principal ja bloqueado.
    $mismatch = $db->query("SELECT DISTINCT c.login,c.bloqueado FROM sis_cliente c JOIN sis_adicional a ON a.login=c.login WHERE a.bloqueado<>c.bloqueado");
    if ($mismatch) while ($m = $mismatch->fetch_assoc()) {
        $principalQ = sql_quote($db, $m['login']);
        $stateQ = sql_quote($db, $m['bloqueado']);
        $db->query("CALL sp_mkauth_sync_adicionais_bloqueio($principalQ,$stateQ)");
        while ($db->more_results() && $db->next_result()) {;}
        $db->query("INSERT INTO mkauth_adicional_block_queue (principal_login,additional_username,desired_state,status,attempts,created_at,updated_at) SELECT c.login,a.username,c.bloqueado,'pending',0,NOW(),NOW() FROM sis_cliente c JOIN sis_adicional a ON a.login=c.login WHERE c.login=$principalQ ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),status='pending',attempts=0,updated_at=NOW(),processed_at=NULL,result_message=NULL");
    }
}

$where = "q.status IN ('pending','failed') AND q.attempts<10";
if ($onlyLogin !== null && $onlyLogin !== '') $where .= ' AND (q.principal_login=' . sql_quote($db,$onlyLogin) . ' OR q.additional_username=' . sql_quote($db,$onlyLogin) . ')';
$sql = "SELECT q.id,q.principal_login,q.additional_username,q.desired_state,q.attempts,a.tipo,COALESCE(a.ramal,'') ramal,COALESCE(a.ip,'') ip,c.bloqueado current_state FROM mkauth_adicional_block_queue q JOIN sis_adicional a ON a.username=q.additional_username AND a.login=q.principal_login JOIN sis_cliente c ON c.login=q.principal_login WHERE $where ORDER BY q.id LIMIT " . (int)$cfg['batch'];
$res = $db->query($sql);
if (!$res) { log_line('ERROR queue_query ' . $db->error); exit(2); }

$stats = array('seen'=>0,'done'=>0,'failed'=>0);
while ($row = $res->fetch_assoc()) {
    $stats['seen']++;
    $id = (int)$row['id'];
    $desired = $row['current_state'];
    if (!$dryRun) {
        $db->query("UPDATE mkauth_adicional_block_queue SET status='processing',attempts=attempts+1,updated_at=NOW() WHERE id=$id");
        $principalQ = sql_quote($db, $row['principal_login']);
        $desiredQ = sql_quote($db, $desired);
        if (!$db->query("CALL sp_mkauth_sync_adicionais_bloqueio($principalQ,$desiredQ)")) {
            $msgQ = sql_quote($db, 'sync_failed:' . $db->error);
            $db->query("UPDATE mkauth_adicional_block_queue SET status='failed',result_message=$msgQ WHERE id=$id");
            while ($db->more_results() && $db->next_result()) {;}
            $stats['failed']++; continue;
        }
        while ($db->more_results() && $db->next_result()) {;}
    }

    list($ok,$message) = disconnect_additional($db,$row);
    log_line(($dryRun ? 'DRY ' : '') . ($ok ? 'OK' : 'FAIL') . ' principal=' . $row['principal_login'] . ' adicional=' . $row['additional_username'] . ' state=' . $desired . ' ' . $message);
    if (!$dryRun) {
        $status = $ok ? 'done' : 'failed';
        $processed = $ok ? 'NOW()' : 'NULL';
        $messageQ = sql_quote($db, substr($message,0,490));
        $db->query("UPDATE mkauth_adicional_block_queue SET desired_state=" . sql_quote($db,$desired) . ",status='$status',processed_at=$processed,result_message=$messageQ WHERE id=$id");
    }
    if ($ok) $stats['done']++; else $stats['failed']++;
}

log_line('SUMMARY dry_run=' . ($dryRun?'yes':'no') . ' seen=' . $stats['seen'] . ' done=' . $stats['done'] . ' failed=' . $stats['failed']);
$db->close();
flock($lock, LOCK_UN); fclose($lock);
exit($stats['failed'] > 0 ? 1 : 0);
