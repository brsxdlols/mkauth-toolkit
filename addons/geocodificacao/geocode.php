<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'geocode';
$publicActions = array('photon', 'cep', 'address');

if (!in_array($action, $publicActions, true) && session_status() !== PHP_SESSION_ACTIVE) {
    $authenticatedSession = false;
    foreach ($_COOKIE as $cookieName => $cookieValue) {
        // O proxy pode prefixar o nome (ex.: _admin-<hash>-MKA). O valor
        // continua sendo o ID da sessao PHP gravada pelo MK-AUTH.
        if (stripos((string) $cookieName, 'mka') !== false
            && preg_match('/^[A-Za-z0-9,-]{16,128}$/', (string) $cookieValue)) {
            session_name('MKASESSID');
            session_id((string) $cookieValue);
            session_start();
            if (!empty($_SESSION['MKA_Logado']) || !empty($_SESSION['mka_logado'])) {
                $authenticatedSession = true;
                break;
            }
            session_write_close();
            $_SESSION = array();
        }
    }
}
if (!in_array($action, $publicActions, true) && empty($_SESSION['MKA_Logado']) && empty($_SESSION['mka_logado'])) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Sessao nao autenticada. Atualize a pagina e entre novamente no MK-AUTH.'));
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

function databaseConnection($cfg)
{
    if (!class_exists('mysqli')) {
        throw new RuntimeException('Extensao mysqli indisponivel.');
    }
    $db = @new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if ($db->connect_errno) {
        throw new RuntimeException('Falha ao conectar ao banco do MK-AUTH.');
    }
    $db->set_charset('utf8');
    return $db;
}

function onlyDigits($value)
{
    return preg_replace('/\D+/', '', (string) $value);
}

function batchRequestRequired()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_MKAUTH_BATCH']) || $_SERVER['HTTP_X_MKAUTH_BATCH'] !== '1') {
        respond(array('ok' => false, 'error' => 'Requisicao administrativa invalida.'), 405);
    }
}

function exactCepCoordinates($cep, $userAgent)
{
    $data = httpJson('https://brasilapi.com.br/api/cep/v2/' . rawurlencode($cep), $userAgent);
    if (!isset($data['cep']) || onlyDigits($data['cep']) !== $cep) {
        throw new RuntimeException('CEP exato nao confirmado.');
    }
    if (!isset($data['location']['coordinates']['latitude'], $data['location']['coordinates']['longitude'])) {
        throw new RuntimeException('CEP sem coordenadas.');
    }
    $lat = filter_var($data['location']['coordinates']['latitude'], FILTER_VALIDATE_FLOAT);
    $lon = filter_var($data['location']['coordinates']['longitude'], FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lon === false || ((float) $lat === 0.0 && (float) $lon === 0.0)) {
        throw new RuntimeException('Coordenadas do CEP invalidas.');
    }
    return array('coordinates' => number_format((float) $lat, 7, '.', '') . ',' . number_format((float) $lon, 7, '.', ''), 'precision' => 'cep');
}

function normalizedText($value)
{
    $value = trim((string) $value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $value));
}

function normalizedStreet($value)
{
    $value = normalizedText($value);
    return preg_replace('/^(?:rua|r|avenida|av|travessa|tv|estrada|rodovia)/', '', $value);
}

function waitForNominatim($cfg)
{
    $dir = isset($cfg['cache_dir']) ? $cfg['cache_dir'] : sys_get_temp_dir();
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $lock = fopen($dir . '/batch-rate-limit.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Falha no limitador de requisicoes.');
    $last = (float) trim((string) stream_get_contents($lock));
    $minimum = isset($cfg['min_interval_ms']) ? (int) $cfg['min_interval_ms'] : 1100;
    $waitUs = ($minimum * 1000) - (int) ((microtime(true) - $last) * 1000000);
    if ($waitUs > 0) usleep($waitUs);
    ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) microtime(true)); fflush($lock);
    flock($lock, LOCK_UN); fclose($lock);
}

function batchGeocodeClient($row, $cfg)
{
    $cep = onlyDigits($row['cep']);
    if (strlen($cep) !== 8) {
        throw new RuntimeException('CEP invalido.');
    }
    $postal = httpJson('https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/', $cfg['user_agent']);
    if (!empty($postal['erro']) || !isset($postal['cep']) || onlyDigits($postal['cep']) !== $cep) {
        throw new RuntimeException('CEP nao confirmado no ViaCEP.');
    }
    $clientStreet = normalizedStreet($row['endereco']);
    $postalStreet = isset($postal['logradouro']) ? normalizedStreet($postal['logradouro']) : '';
    if ($clientStreet !== '' && $postalStreet !== '' && strpos($clientStreet, $postalStreet) === false && strpos($postalStreet, $clientStreet) === false) {
        throw new RuntimeException('Logradouro do cadastro diverge do CEP.');
    }
    $number = trim((string) $row['numero']);
    $queryNumber = preg_match('/^(?:0+|s\/?n)$/i', $number) ? '' : $number;
    $street = isset($postal['logradouro']) && trim($postal['logradouro']) !== '' ? $postal['logradouro'] : $row['endereco'];
    $district = isset($postal['bairro']) && trim($postal['bairro']) !== '' ? $postal['bairro'] : $row['bairro'];
    $city = isset($postal['localidade']) && trim($postal['localidade']) !== '' ? $postal['localidade'] : $row['cidade'];
    $state = isset($postal['uf']) && trim($postal['uf']) !== '' ? $postal['uf'] : $row['estado'];
    $parts = array($street, $queryNumber, $district, $city, $state, $cep, 'Brasil');
    $query = implode(', ', array_values(array_filter($parts, function ($value) { return trim((string) $value) !== ''; })));
    if (trim((string) $street) !== '' && trim((string) $city) !== '') {
        try {
            $streetParts = array($street, $district, $city, $state, $cep, 'Brasil');
            $streetQuery = implode(', ', array_values(array_filter($streetParts, function ($value) { return trim((string) $value) !== ''; })));
            $cityStreetParts = array($street, $city, $state, 'Brasil');
            $cityStreetQuery = implode(', ', array_values(array_filter($cityStreetParts, function ($value) { return trim((string) $value) !== ''; })));
            $queries = array($query);
            if ($queryNumber !== '' && $streetQuery !== $query) $queries[] = $streetQuery;
            if ($cityStreetQuery !== $streetQuery) $queries[] = $cityStreetQuery;
            foreach ($queries as $queryIndex => $searchQuery) {
                waitForNominatim($cfg);
                $raw = httpJson($cfg['nominatim_url'] . '?' . http_build_query(array(
                    'q' => $searchQuery, 'format' => 'jsonv2', 'addressdetails' => 1, 'countrycodes' => 'br', 'limit' => 5,
                )), $cfg['user_agent']);
                $fallback = null;
                foreach ($raw as $item) {
                    $foundCep = isset($item['address']['postcode']) ? onlyDigits($item['address']['postcode']) : '';
                    $foundCity = '';
                    foreach (array('city', 'town', 'municipality', 'village') as $cityKey) {
                        if (isset($item['address'][$cityKey])) { $foundCity = normalizedText($item['address'][$cityKey]); break; }
                    }
                    $foundStreet = '';
                    foreach (array('road', 'pedestrian', 'residential', 'street') as $streetKey) {
                        if (isset($item['address'][$streetKey])) { $foundStreet = normalizedStreet($item['address'][$streetKey]); break; }
                    }
                    $expectedStreet = normalizedStreet($street);
                    $strictCep = $queryIndex < (count($queries) - 1);
                    if (!isset($item['lat'], $item['lon'])
                        || ($strictCep && $foundCep !== '' && $foundCep !== $cep)
                        || ($foundCity !== '' && $foundCity !== normalizedText($city))
                        || ($foundStreet !== '' && $expectedStreet !== '' && strpos($foundStreet, $expectedStreet) === false && strpos($expectedStreet, $foundStreet) === false)) continue;
                    $lat = filter_var($item['lat'], FILTER_VALIDATE_FLOAT);
                    $lon = filter_var($item['lon'], FILTER_VALIDATE_FLOAT);
                    if ($lat === false || $lon === false) continue;
                    $candidate = array('coordinates' => number_format((float) $lat, 7, '.', '') . ',' . number_format((float) $lon, 7, '.', ''), 'precision' => 'rua');
                    if ($queryIndex === 0 && $queryNumber !== '' && isset($item['address']['house_number']) && normalizedText($item['address']['house_number']) === normalizedText($queryNumber)) {
                        $candidate['precision'] = 'numero';
                    }
                    if ($foundCep === $cep) return $candidate;
                    if ($fallback === null) $fallback = $candidate;
                }
                if ($fallback !== null) return $fallback;
            }
        } catch (Exception $ignored) {
            /* A referencia exata do CEP permanece como fallback seguro. */
        }
    }
    return exactCepCoordinates($cep, $cfg['user_agent']);
}

try {
    if ($action === 'batch_preview') {
        batchRequestRequired();
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 500;
        if ($limit < 1) $limit = 1;
        if ($limit > 1000) $limit = 1000;
        $db = databaseConnection($cfg);
        $sql = "SELECT id,nome,login,cep,endereco,numero,bairro,cidade,estado FROM sis_cliente
                WHERE TRIM(COALESCE(coordenadas,''))=''
                  AND CHAR_LENGTH(REPLACE(REPLACE(TRIM(COALESCE(cep,'')),'-',''),' ',''))=8
                ORDER BY id ASC LIMIT " . $limit;
        $result = $db->query($sql);
        if (!$result) throw new RuntimeException('Falha ao consultar clientes.');
        $clients = array();
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $countResult = $db->query("SELECT COUNT(*) AS total FROM sis_cliente WHERE TRIM(COALESCE(coordenadas,''))='' AND CHAR_LENGTH(REPLACE(REPLACE(TRIM(COALESCE(cep,'')),'-',''),' ',''))=8");
        $countRow = $countResult ? $countResult->fetch_assoc() : array('total' => count($clients));
        $db->close();
        respond(array('ok' => true, 'total' => (int) $countRow['total'], 'clients' => $clients));
    }

    if ($action === 'batch_process') {
        batchRequestRequired();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $confirmed = isset($_POST['confirm']) && $_POST['confirm'] === '1';
        if ($id < 1 || !$confirmed) respond(array('ok' => false, 'error' => 'Confirmacao obrigatoria.'), 422);
        $db = databaseConnection($cfg);
        $result = $db->query("SELECT id,nome,login,cep,endereco,numero,bairro,cidade,estado,coordenadas FROM sis_cliente WHERE id=" . $id . " LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) throw new RuntimeException('Cliente nao encontrado.');
        if (trim((string) $row['coordenadas']) !== '') respond(array('ok' => true, 'status' => 'ignored', 'message' => 'Cliente ja possui coordenadas.'));
        $resolved = batchGeocodeClient($row, $cfg);
        $stmt = $db->prepare("UPDATE sis_cliente SET coordenadas=? WHERE id=? AND TRIM(COALESCE(coordenadas,''))=''");
        if (!$stmt) throw new RuntimeException('Falha ao preparar atualizacao.');
        $stmt->bind_param('si', $resolved['coordinates'], $id);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao atualizar cliente.');
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        $db->close();
        respond(array('ok' => true, 'status' => $updated ? 'updated' : 'ignored', 'coordinates' => $resolved['coordinates'], 'precision' => $resolved['precision']));
    }

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
