<?php
define('MAP_ACCESS_COOKIE', 'mkauth_map_trusted');
$mapAccessStateDir = getenv('MKAUTH_MAP_STATE');
if (!$mapAccessStateDir) $mapAccessStateDir = '/var/lib/mkauth-mapa-clientes';
define('MAP_ACCESS_STORE', rtrim($mapAccessStateDir, '/') . '/trusted-devices.json');
define('MAP_ACCESS_TTL', 315360000); // dez anos: opcao pratica de "nunca sair"

function map_access_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('mka');
    session_start();
}

function map_access_via_admin() {
    map_access_start_session();
    return !empty($_SESSION['MKA_Logado']) || !empty($_SESSION['mka_logado']);
}

function map_access_read_store() {
    if (!is_file(MAP_ACCESS_STORE)) return array();
    $data = json_decode((string)@file_get_contents(MAP_ACCESS_STORE), true);
    return is_array($data) ? $data : array();
}

function map_access_write_store($data) {
    $dir = dirname(MAP_ACCESS_STORE);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $temp = MAP_ACCESS_STORE . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($temp, $json, LOCK_EX) === false) return false;
    @chmod($temp, 0660);
    return @rename($temp, MAP_ACCESS_STORE);
}

function map_access_cookie_token() {
    $token = isset($_COOKIE[MAP_ACCESS_COOKIE]) ? (string)$_COOKIE[MAP_ACCESS_COOKIE] : '';
    return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
}

function map_access_via_trusted_device() {
    $token = map_access_cookie_token();
    if ($token === '') return false;
    $hash = hash('sha256', $token);
    $store = map_access_read_store();
    if (!isset($store[$hash]) || !is_array($store[$hash])) return false;
    $created = isset($store[$hash]['created']) ? (int)$store[$hash]['created'] : 0;
    return $created > 0 && $created + MAP_ACCESS_TTL >= time();
}

function map_access_set_cookie($token, $expires) {
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        setcookie(MAP_ACCESS_COOKIE, $token, array('expires' => $expires, 'path' => '/admin/addons/mapa-clientes/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'));
    } else {
        setcookie(MAP_ACCESS_COOKIE, $token, $expires, '/admin/addons/mapa-clientes/; SameSite=Strict', '', $secure, true);
    }
}

function map_access_enable_trusted_device() {
    if (!map_access_via_admin()) return false;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $store = map_access_read_store();
    $now = time();
    foreach ($store as $key => $entry) {
        if (!is_array($entry) || (int)$entry['created'] + MAP_ACCESS_TTL < $now) unset($store[$key]);
    }
    $store[$hash] = array('created' => $now);
    if (!map_access_write_store($store)) return false;
    map_access_set_cookie($token, $now + MAP_ACCESS_TTL);
    $_COOKIE[MAP_ACCESS_COOKIE] = $token;
    return true;
}

function map_access_disable_trusted_device() {
    $token = map_access_cookie_token();
    if ($token !== '') {
        $store = map_access_read_store();
        unset($store[hash('sha256', $token)]);
        map_access_write_store($store);
    }
    map_access_set_cookie('', time() - 3600);
    unset($_COOKIE[MAP_ACCESS_COOKIE]);
}

function map_access_authorized() {
    return map_access_via_admin() || map_access_via_trusted_device();
}

function require_map_access($json) {
    if (map_access_authorized()) return;
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Acesso encerrado. Entre novamente no MK-AUTH.'));
    } else {
        header('Location: /admin/login.php');
    }
    exit;
}

function map_access_csrf() {
    map_access_start_session();
    if (empty($_SESSION['map_access_csrf'])) $_SESSION['map_access_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['map_access_csrf'];
}
