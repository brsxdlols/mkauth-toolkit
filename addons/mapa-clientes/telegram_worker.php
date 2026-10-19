<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
require_once __DIR__.'/lib/telegram_engine.php';
require_once __DIR__.'/lib/telegram_delivery.php';
$workerLock=@fopen(mt_dir().'/worker.lock','c');if(!$workerLock||!flock($workerLock,LOCK_EX|LOCK_NB))exit;
$pollStarted=microtime(true);$runtime=mt_read('runtime');$runtime['last_run']=time();
try {
    $config=mt_config();
    if(empty($config['enabled'])){$runtime['queue']=array();$runtime['error']='';mt_write('runtime',$runtime);mt_write('transitions',array());exit;}
    require __DIR__.'/config.hhvm';
    require_once __DIR__.'/access_policy.php';
    $_SESSION=array('MKA_Usuario'=>$config['operator']);
    if(!permissao('perm_config')||!permissao('perm_clientes')||!map_client_records_available()||($config['mass']&&!map_can_view_totals()))throw new RuntimeException('O operador perdeu permissão. Nenhum alerta foi enviado.');
    $primary=map_client_scope_sql('c');$additional=map_client_scope_sql('owner');
    $open="LEFT JOIN (SELECT username,MAX(radacctid) AS id FROM radacct WHERE acctstoptime IS NULL GROUP BY username) os ON os.username=%s LEFT JOIN radacct op ON op.radacctid=os.id LEFT JOIN radacct closed ON closed.radacctid=(SELECT rr.radacctid FROM radacct rr WHERE rr.username=%s AND rr.acctstoptime IS NOT NULL ORDER BY rr.radacctid DESC LIMIT 1)";
    $primaryJoins=sprintf($open,'c.login','c.login');$additionalJoins=sprintf($open,'a.username','a.username');
    $sql="SELECT CONCAT('p:',c.id) AS client_key,c.login,c.nome,c.bairro,c.endereco,c.numero,c.bloqueado,c.caixa_herm AS cto,c.porta_splitter AS port,UNIX_TIMESTAMP(op.acctstarttime) AS connected_epoch,UNIX_TIMESTAMP(closed.acctstoptime) AS disconnected_epoch,(op.radacctid IS NOT NULL) AS online FROM sis_cliente c $primaryJoins WHERE c.cli_ativado='s' AND ($primary)
          UNION ALL SELECT CONCAT('a:',a.id),a.username,a.nome,a.end_bairro,a.end_endereco,a.end_numero,a.bloqueado,a.caixa_herm,a.porta_splitter,UNIX_TIMESTAMP(op.acctstarttime),UNIX_TIMESTAMP(closed.acctstoptime),(op.radacctid IS NOT NULL) FROM sis_adicional a INNER JOIN sis_cliente owner ON owner.login=a.login $additionalJoins WHERE owner.cli_ativado='s' AND TRIM(a.username)<>'' AND ($additional)";
    $query=$link->query($sql);if(!$query)throw new RuntimeException('Falha ao consultar o estado dos clientes.');
    $clients=array();while($row=$query->fetch_assoc())$clients[$row['client_key']]=array('login'=>trim((string)$row['login']),'name'=>(string)$row['nome'],'district'=>(string)$row['bairro'],'street'=>(string)$row['endereco'],'number'=>(string)$row['numero'],'cto'=>(string)$row['cto'],'port'=>(string)$row['port'],'blocked'=>in_array(strtolower(trim((string)$row['bloqueado'])),array('sim','s','1'),true),'connected_epoch'=>(int)$row['connected_epoch'],'disconnected_epoch'=>(int)$row['disconnected_epoch'],'online'=>(bool)$row['online']);
    $revision=hash('sha256',$config['operator'].'|'.$primary.'|'.$additional);
    $priorRevision=hash('sha256',$config['operator'].'|'.$primary.'|'.$additional.'|'.$config['customer_filter']);
    $legacyRevision=hash('sha256',$config['revision'].'|'.$primary.'|'.$additional.($config['customer_filter']==='all'?'':'|'.$config['customer_filter']));
    $old=mt_read('transitions');if(!isset($old['revision'])||($old['revision']!==$revision&&$old['revision']!==$legacyRevision&&$old['revision']!==$priorRevision)){$old=array();$runtime['queue']=array();}
    $state=mt_detect($old,$clients,$config,time());$events=array_merge($state['events'],mt_mass_updates($clients,$config,time()));$runtime['last_sample']=time();$runtime['collection_ms']=(int)round((microtime(true)-$pollStarted)*1000);$runtime['last_detected_count']=count($events);if($events){$runtime['last_detected_at']=time();$runtime['last_detected_kinds']=array_column($events,'kind');}unset($state['events']);$state['revision']=$revision;
    $queue=isset($runtime['queue'])?$runtime['queue']:array();$latest=array();foreach($events as $event)if($event['kind']==='mass_update')$latest[$event['incident_id']]=$event['id'];
    $queue=array_values(array_filter($queue,function($item)use($latest){$e=$item['event'];return $e['kind']!=='mass_update'||(isset($latest[$e['incident_id']])&&$latest[$e['incident_id']]===$e['id']);}));
    $ids=array();foreach($queue as $item)$ids[$item['id']]=true;
    $new=array();foreach($events as $event)if(!isset($ids[$event['id']])){mt_audit_event($event,'detected');$new[]=array('id'=>$event['id'],'at'=>time(),'event'=>$event,'tries'=>0,'next'=>0);$ids[$event['id']]=true;}
    // Mass alerts have priority; individual notices retain their event timestamps.
    foreach($new as $item){if(($item['event']['kind']==='mass'||$item['event']['kind']==='mass_update'))array_unshift($queue,$item);else $queue[]=$item;}
    $runtime['queue']=array_values(array_filter($queue,function($item)use($clients,$config){if(time()-$item['at']>3600||$item['tries']>=5)return false;$e=$item['event'];if($e['kind']==='mass'||$e['kind']==='mass_update'){if(empty($config['mass']))return false;foreach($e['client_keys'] as $key)if(!isset($clients[$key]))return false;return true;}return isset($clients[$e['client_key']])&&(bool)mt_filter_clients(array($e['client_key']=>$clients[$e['client_key']]),$config);}));
    if(count($runtime['queue'])>1000){$runtime['queue']=array_slice($runtime['queue'],0,1000);$runtime['error']='Fila limitada a 1000 avisos; reduza os alertas individuais.';}else $runtime['error']='';
    mt_write('runtime',$runtime);mt_write('transitions',$state);$deadline=microtime(true)+25;$sent=0;
    while($runtime['queue']&&$sent<3&&microtime(true)<$deadline) {
        $current=mt_config();if(empty($current['enabled'])||$current['revision']!==$config['revision'])break;
        $item=$runtime['queue'][0];if($item['next']>time())break;
        try {
            $delivery=mt_send_event($item['event'],$config);
            mt_audit_event($item['event'],'delivered',$delivery);array_shift($runtime['queue']);$runtime['last_sent']=time();$runtime['last_sent_kind']=$item['event']['kind'];$runtime['error']='';$sent++;mt_write('runtime',$runtime);usleep(1100000);
        } catch(Exception $e) {
            mt_audit_event($item['event'],'retry');$runtime['queue'][0]['tries']++;$runtime['queue'][0]['next']=time()+($e->getCode()===429?120:60);$runtime['error']=$e->getMessage();mt_write('runtime',$runtime);break;
        }
    }
} catch(Exception $e){$runtime['error']='Falha no monitor: consulte as permissões, o banco e a conexão com o Telegram.';mt_write('runtime',$runtime);exit(1);}
