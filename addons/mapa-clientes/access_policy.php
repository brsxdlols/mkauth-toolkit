<?php
// Use the native permission function when the host has already loaded it.
// Standalone entries use the same sis_perm rule as the native addon config.
function map_operator_login() {
    foreach (array('MKA_Usuario', 'MM_Usuario') as $key) {
        if (!empty($_SESSION[$key])) return (string)$_SESSION[$key];
    }
    if (function_exists('map_access_cookie_token')) {
        $token = map_access_cookie_token();
        $store = map_access_read_store();
        $entry = $token !== '' && isset($store[hash('sha256', $token)]) ? $store[hash('sha256', $token)] : array();
        if (map_access_via_trusted_device() && !empty($entry['operator'])) return (string)$entry['operator'];
    }
    return '';
}

if (!function_exists('permissao')) {
    function permissao($permission) {
        global $link;
        $operator = map_operator_login();
        if ($operator === '' || !$link) return false;
        $query = $link->prepare("SELECT usuario FROM sis_perm WHERE nome=? AND usuario=? AND permissao='sim' LIMIT 1");
        if (!$query) return false;
        $query->bind_param('ss', $permission, $operator);
        $query->execute();
        $result = $query->get_result();
        $allowed = $result && $result->num_rows > 0;
        $query->close();
        return $allowed;
    }
}

function map_can_view_totals() { return (bool)permissao('perm_numClientes'); }

function map_client_groups() {
    global $link;
    $operator = map_operator_login();
    if ($operator === '' || !$link || !permissao('perm_clientes')) return null;
    $query = $link->prepare('SELECT cli_grupos FROM sis_acesso WHERE login=? LIMIT 1');
    if (!$query) return null;
    $query->bind_param('s', $operator);
    $query->execute();
    $result = $query->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $query->close();
    if (!$row) return null;
    $groups = array_map('trim', explode(',', (string)$row['cli_grupos']));
    if (trim((string)$row['cli_grupos']) === '' || in_array('full_clientes', $groups, true)) return array();
    return $groups;
}

function map_client_scope_sql($alias) {
    global $link;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid client alias');
    $groups = map_client_groups();
    if ($groups === null) return '0=1';
    if (!$groups) return '1=1';
    $quoted = array();
    foreach ($groups as $group) $quoted[] = "'".$link->real_escape_string($group)."'";
    return $alias.'.grupo IN ('.implode(',', $quoted).')';
}

function map_client_records_available() { return map_client_groups() !== null; }

function map_api_summary($clients, $allowed) {
    if (!$allowed) return null;
    $summary = array('total'=>count($clients), 'online'=>0, 'offline'=>0, 'mapped'=>0, 'unmapped'=>0);
    foreach ($clients as $client) {
        $summary[$client['status']==='online'?'online':'offline']++;
        $summary[$client['has_coordinates']?'mapped':'unmapped']++;
    }
    return $summary;
}
