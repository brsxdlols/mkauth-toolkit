<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);require_once __DIR__.'/lib/telegram_engine.php';
$action=isset($argv[1])?$argv[1]:'help';
try {
 if(in_array($action,array('demo','disable'),true)&&function_exists('posix_geteuid')&&posix_geteuid()===0)throw new RuntimeException('Execute como www-data para preservar as permissões dos arquivos: runuser -u www-data -- php telegram_cli.php ...');
 $c=mt_config();
 if($action==='help') {echo "Uso: php telegram_cli.php status|history|check|groups|disable|demo TIPO --send\nTipos: connect disconnect recovered mass partial resolved\nDemonstrações enviam mensagens TESTE ao grupo salvo. Não alteram clientes.\n";exit;}
 if($action==='status') {$r=mt_read('runtime');echo json_encode(array('enabled'=>(bool)$c['enabled'],'token_configured'=>$c['token']!=='','chat_id'=>$c['chat_id'],'group'=>$c['chat_title'],'connect'=>$c['connect'],'disconnect'=>$c['disconnect'],'mass'=>$c['mass'],'timezone'=>$c['timezone'],'customer_filter'=>$c['customer_filter'],'threshold'=>$c['threshold'],'window_minutes'=>$c['window_minutes'],'interval_seconds'=>15,'collection_ms'=>isset($r['collection_ms'])?$r['collection_ms']:null,'last_sample'=>isset($r['last_sample'])?date('c',$r['last_sample']):null,'last_run'=>isset($r['last_run'])?date('c',$r['last_run']):null,'last_sent'=>isset($r['last_sent'])?date('c',$r['last_sent']):null,'queue'=>isset($r['queue'])?count($r['queue']):0,'error'=>isset($r['error'])?$r['error']:''),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
 if($action==='history'){echo json_encode(array_values(array_slice(mt_read('events'),-20,null,true)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;}
 if($action==='check'){echo json_encode(mt_validate_group($c),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;}
 if($action==='groups'){echo json_encode(mt_groups($c['token']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;}
 if($action==='disable'){$lock=fopen(mt_dir().'/config.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Falha ao desativar.');$c=mt_config();$before=$c;$c['enabled']=false;$c['revision']=bin2hex(random_bytes(12));mt_write('config',$c);flock($lock,LOCK_UN);fclose($lock);echo "Alertas desativados; credenciais preservadas.\n";$warning=mt_notify_switch($before,$c);if($warning)fwrite(STDERR,$warning."\n");exit;}
 if($action==='demo') {if(!in_array('--send',$argv,true))throw new RuntimeException('Para enviar a demonstração ao grupo, acrescente --send.');require_once __DIR__.'/lib/telegram_demo.php';$kind=isset($argv[2])?$argv[2]:'';$result=mt_demo($c,$kind);echo $result['message']."\n";exit;}
 throw new RuntimeException('Comando inválido. Use help.');
}catch(Exception $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
