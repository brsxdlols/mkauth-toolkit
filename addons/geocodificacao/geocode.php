<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'geocode';
$publicActions = array('photon', 'cep', 'address');

if (!in_array($action, $publicActions, true) && session_status() !== PHP_SESSION_ACTIVE) {
    foreach (array('MKASESSID', 'mka', 'PHPSESSID') as $candidate) {
        if (!empty($_COOKIE[$candidate]) && preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $_COOKIE[$candidate])) {
            session_name($candidate);
            session_id((string) $_COOKIE[$candidate]);
            break;
        }
    }
    session_start();
}
if (!in_array($action, $publicActions, true) && empty($_SESSION['MKA_Logado']) && empty($_SESSION['mka_logado'])) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Sessao nao autenticada.'));
    exit;
}

$cfg = require __DIR__ . '/config.php';
function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function httpJson($url, $userAgent)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        CURLOPT_USERAGENT => $userAgent,
    ));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Servico externo respondeu HTTP ' . $status);
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Resposta invalida do servico externo.');
    }
    return $json;
}

try {
    if ($action === 'photon') {
        $query = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
        if (mb_strlen($query, 'UTF-8') < 3 || mb_strlen($query, 'UTF-8') > 256) {
            respond(array('ok' => false, 'error' => 'Digite entre 3 e 256 caracteres.'), 422);
        }
        $cacheDir = (string) (isset($cfg['cache_dir']) ? $cfg['cache_dir'] : (sys_get_temp_dir() . '/mkauth-geocodificacao'));
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
        $cacheFile = $cacheDir . '/photon-' . hash('sha256', mb_strtolower($query, 'UTF-8')) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < (int) $cfg['cache_ttl']) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) respond($cached);
        }
        $lock = fopen($cacheDir . '/photon-rate-limit.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Falha no limitador Photon.');
        $last = (float) trim((string) stream_get_contents($lock));
        $waitUs = 500000 - (int) ((microtime(true) - $last) * 1000000);
        if ($waitUs > 0) usleep($waitUs);
        $data = httpJson('https://photon.komoot.io/api/?limit=7&q=' . rawurlencode($query), $cfg['user_agent']);
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) microtime(true)); fflush($lock);
        flock($lock, LOCK_UN); fclose($lock);
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        respond($data);
    }

    if ($action === 'cep') {
        $cep = preg_replace('/\D+/', '', (string) (isset($_GET['cep']) ? $_GET['cep'] : ''));
        if (strlen($cep) !== 8) {
            respond(array('ok' => false, 'error' => 'CEP deve conter 8 digitos.'), 422);
        }
        $data = httpJson('https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/', $cfg['user_agent']);
        if (!empty($data['erro'])) {
            respond(array('ok' => false, 'error' => 'CEP nao encontrado.'), 404);
        }
        respond(array('ok' => true, 'result' => $data));
    }

    if ($action === 'address') {
        $uf = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) (isset($_GET['uf']) ? $_GET['uf'] : '')));
        $city = trim((string) (isset($_GET['city']) ? $_GET['city'] : ''));
        $street = trim((string) (isset($_GET['street']) ? $_GET['street'] : ''));
        if (strlen($uf) !== 2 || mb_strlen($city, 'UTF-8') < 3 || mb_strlen($street, 'UTF-8') < 3) {
            respond(array('ok' => false, 'error' => 'Informe UF, cidade e logradouro.'), 422);
        }
        $data = httpJson(
            'https://viacep.com.br/ws/' . rawurlencode($uf) . '/' . rawurlencode($city) . '/' . rawurlencode($street) . '/json/',
            $cfg['user_agent']
        );
        respond(array('ok' => true, 'results' => array_slice($data, 0, 20)));
    }

    $number = trim((string) (isset($_GET['numero']) ? $_GET['numero'] : ''));
    $numberForQuery = preg_match('/^0+$/', $number) ? '' : $number;
    $parts = array(
        trim((string) (isset($_GET['endereco']) ? $_GET['endereco'] : '')),
        $numberForQuery,
        trim((string) (isset($_GET['bairro']) ? $_GET['bairro'] : '')),
        trim((string) (isset($_GET['cidade']) ? $_GET['cidade'] : '')),
        trim((string) (isset($_GET['estado']) ? $_GET['estado'] : '')),
        preg_replace('/\D+/', '', (string) (isset($_GET['cep']) ? $_GET['cep'] : '')),
        'Brasil',
    );
    if ($parts[0] === '' || $number === '' || $parts[3] === '' || strlen($parts[4]) !== 2) {
        respond(array('ok' => false, 'error' => 'Informe logradouro, numero, cidade e UF.'), 422);
    }
    $query = implode(', ', array_values(array_filter($parts, function ($value) {
        return $value !== '';
    })));
    if (strlen($query) > 500) {
        respond(array('ok' => false, 'error' => 'Endereco muito longo.'), 422);
    }

    $cacheDir = (string) (isset($cfg['cache_dir']) ? $cfg['cache_dir'] : (sys_get_temp_dir() . '/mkauth-geocodificacao'));
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0770, true);
    }
    $cacheFile = $cacheDir . '/' . hash('sha256', mb_strtolower($query, 'UTF-8')) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < (int) $cfg['cache_ttl']) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['cached'] = true;
            respond($cached);
        }
    }

    $lock = fopen($cacheDir . '/rate-limit.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Nao foi possivel aplicar o limite de requisicoes.');
    }
    $last = (float) trim((string) stream_get_contents($lock));
    $waitUs = ((int) $cfg['min_interval_ms'] * 1000) - (int) ((microtime(true) - $last) * 1000000);
    if ($waitUs > 0) {
        usleep($waitUs);
    }
    $url = $cfg['nominatim_url'] . '?' . http_build_query(array(
        'q' => $query,
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'countrycodes' => 'br',
        'limit' => 3,
    ));
    $raw = httpJson($url, $cfg['user_agent']);
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, (string) microtime(true));
    fflush($lock);
    flock($lock, LOCK_UN);
    fclose($lock);

    $results = array();
    foreach ($raw as $item) {
        if (!isset($item['lat'], $item['lon'])) continue;
        $lat = filter_var($item['lat'], FILTER_VALIDATE_FLOAT);
        $lon = filter_var($item['lon'], FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) continue;
        $results[] = array(
            'lat' => number_format((float) $lat, 7, '.', ''),
            'lon' => number_format((float) $lon, 7, '.', ''),
            'label' => (string) (isset($item['display_name']) ? $item['display_name'] : $query),
        );
    }
    $payload = array('ok' => true, 'query' => $query, 'results' => $results, 'cached' => false, 'approximate' => ($numberForQuery === ''));
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    respond($payload);
} catch (Exception $e) {
    error_log('[mka-geocodificacao] ' . get_class($e) . ': ' . $e->getMessage());
    respond(array('ok' => false, 'error' => 'Falha temporaria ao consultar geocodificacao.'), 502);
}
