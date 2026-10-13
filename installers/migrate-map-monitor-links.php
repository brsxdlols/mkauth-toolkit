<?php
if (PHP_SAPI !== 'cli') exit(1);
$adminDir = getenv('MKAUTH_ADMIN') ? getenv('MKAUTH_ADMIN') : '/opt/mk-auth/admin';
$backupDir = isset($argv[1]) ? $argv[1] : '';
if ($backupDir === '' || !is_dir($backupDir)) exit('Diretorio de backup indisponivel.');
require $adminDir.'/addons/mapa-clientes/config.hhvm';
try {
    $table = $link->query("SHOW TABLES LIKE 'dashboard_am_sis_cfg'");
    if (!$table || !$table->num_rows) { echo "Atalhos configurados: tabela opcional ausente.\n"; exit; }
    $result = $link->query('SELECT id,link FROM dashboard_am_sis_cfg');
    if (!$result) throw new RuntimeException('Falha ao consultar atalhos.');
    $changes = array();
    while ($row = $result->fetch_assoc()) {
        $old = (string)$row['link'];
        $new = preg_replace_callback('/mapa-clientes\/\?monitor=1(&)?/', function($match) { return 'mapa-clientes/monitor/'.(!empty($match[1]) ? '?' : ''); }, $old);
        if ($new !== $old) $changes[] = array('id'=>(int)$row['id'], 'old'=>$old, 'new'=>$new);
    }
    if ($changes) {
        $backup = $backupDir.'/monitor-shortcuts-before.json';
        if (file_put_contents($backup, json_encode($changes, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Backup de atalhos indisponivel.');
        chmod($backup, 0600);
        $update = $link->prepare('UPDATE dashboard_am_sis_cfg SET link=? WHERE id=? AND link=?');
        if (!$update) throw new RuntimeException('Falha ao preparar atualizacao.');
        foreach ($changes as $change) {
            $update->bind_param('sis', $change['new'], $change['id'], $change['old']);
            if (!$update->execute()) throw new RuntimeException('Falha ao migrar atalho.');
        }
        $update->close();
    }
    echo 'Atalhos de monitoramento migrados: '.count($changes)."\n";
} catch (Exception $error) {
    // Old links remain supported; optional dashboard migration cannot block installation.
    fwrite(STDERR, "Aviso: atalhos configurados preservados; entrada antiga continua compativel.\n");
}
