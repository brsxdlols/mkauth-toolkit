<?php
// Private state is deliberately outside the addon/document root.
function mt_dir() { $p=getenv('MKAUTH_MAP_TELEGRAM_STATE'); return $p ? rtrim($p,'/') : '/var/lib/mkauth-map-telegram'; }
function mt_read($name, $default=array()) {
    $p=mt_dir().'/'.$name.'.json';if(!is_file($p))return $default;
    $raw=@file_get_contents($p);if($raw===false)throw new RuntimeException('Sem permissão para ler o estado do Telegram.');
    $v=json_decode($raw,true);if(is_array($v))return $v;
    // Samples and the transient queue can be rebuilt; credentials and delivery history must fail closed.
    if(in_array($name,array('runtime','transitions'),true)){error_log('Mapa Telegram: estado temporario invalido em '.$name.'; reconstruindo na proxima coleta.');return $default;}
    throw new RuntimeException($name==='config'?'Configuracao do Telegram invalida. Restaure um backup antes de salvar.':'Estado do Telegram invalido.');
}
function mt_write($name,$value) {
    $dir=mt_dir();if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Pasta privada do Telegram indisponível.');
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Falha ao codificar o estado do Telegram.');
    $tmp=tempnam($dir,'.telegram-');if($tmp===false)throw new RuntimeException('Falha ao salvar Telegram.');
    // A short write (for example a full disk) must never replace the last valid state.
    if(!chmod($tmp,0600)||@file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)||@file_get_contents($tmp)!==$json||!rename($tmp,$dir.'/'.$name.'.json')){@unlink($tmp);throw new RuntimeException('Falha ao salvar Telegram. Estado anterior preservado; verifique o espaco em disco.');}
}
function mt_config_lock() { $lock=@fopen(mt_dir().'/config.lock','c');if(!$lock)throw new RuntimeException('Servidor sem permissão para salvar o Telegram. Atualize o addon para corrigir as permissões e o AppArmor.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('Outra configuração está sendo salva. Aguarde e tente novamente.');}return $lock;}
function mt_defaults() { return array('enabled'=>false,'token'=>'','chat_id'=>'','chat_title'=>'','connect'=>false,'disconnect'=>false,'mass'=>true,'threshold'=>10,'trigger_mode'=>'count','mass_levels'=>array(10),'recovery_percent'=>80,'window_minutes'=>2,'timezone'=>'America/Sao_Paulo','customer_filter'=>'all','operator'=>'','revision'=>''); }
function mt_mass_levels($value){
    if(is_string($value))$value=$value===''?array():explode(',',$value);
    if(!is_array($value)||count($value)>9)throw new RuntimeException('Selecione níveis válidos de queda.');
    $out=array();foreach($value as $v){$n=filter_var($v,FILTER_VALIDATE_INT);if($n===false||!in_array($n,array(10,20,30,40,50,60,70,80,90),true))throw new RuntimeException('Níveis de queda: 10%, 20%, 30%, 40%, 50%, 60%, 70%, 80% e 90%.');$out[$n]=$n;}
    sort($out);return array_values($out);
}
function mt_recovery_percent($c){$n=isset($c['recovery_percent'])?(int)$c['recovery_percent']:80;return in_array($n,array(70,80,90,100),true)?$n:80;}
function mt_config() { return array_merge(mt_defaults(),mt_read('config')); }
function mt_fingerprint($c) { return hash('sha256',$c['token'].'|'.$c['chat_id']); }
function mt_token($token) { if(!preg_match('/^[0-9]{5,16}:[A-Za-z0-9_-]{20,120}$/D',$token))throw new RuntimeException('Informe um token válido do BotFather.'); return $token; }
function mt_chat_id($id) { if(!preg_match('/^(?:-?[0-9]{1,20}|@[A-Za-z0-9_]{5,64})$/D',$id))throw new RuntimeException('Informe o ID do grupo ou @nome público.'); return $id; }
function mt_api($token,$method,$params=array()) {
    mt_token($token);
    if(!in_array($method,array('getMe','getUpdates','getChat','getChatMember','sendMessage'),true))throw new RuntimeException('Ação Telegram inválida.');
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL não está instalado no servidor.');
    $curl=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false));
    $raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($raw===false)throw new RuntimeException('Não foi possível conectar ao Telegram.');
    $data=json_decode($raw,true);
    if(!is_array($data)||empty($data['ok'])) {
        $code=is_array($data)&&isset($data['error_code'])?(int)$data['error_code']:$http;
        $messages=array(401=>'Token recusado pelo Telegram.',403=>'O bot não pode enviar mensagens ao grupo. Verifique a participação e as permissões.',409=>'Este bot usa webhook ou outro leitor de atualizações. Informe o ID do grupo manualmente; a integração existente foi preservada.',429=>'Telegram limitou os envios. Aguarde antes de tentar novamente.');
        throw new RuntimeException(isset($messages[$code])?$messages[$code]:'Telegram recusou a solicitação. Confira o grupo e as permissões do bot.',$code);
    }
    return $data['result'];
}
function mt_validate_group($c,$transport='mt_api') {
    $me=call_user_func($transport,mt_token($c['token']),'getMe',array());
    $chat=call_user_func($transport,$c['token'],'getChat',array('chat_id'=>mt_chat_id($c['chat_id'])));
    if(!in_array($chat['type'],array('group','supergroup'),true))throw new RuntimeException('Selecione um grupo ou supergrupo do Telegram.');
    $member=call_user_func($transport,$c['token'],'getChatMember',array('chat_id'=>(string)$chat['id'],'user_id'=>$me['id']));
    $status=isset($member['status'])?$member['status']:'';
    if(!in_array($status,array('creator','administrator','member','restricted'),true))throw new RuntimeException('O bot não está ativo neste grupo.');
    if($status==='restricted'&&(empty($member['is_member'])||empty($member['can_send_messages'])))throw new RuntimeException('O bot não tem permissão para enviar neste grupo.');
    if($status==='member'&&isset($chat['permissions']['can_send_messages'])&&!$chat['permissions']['can_send_messages'])throw new RuntimeException('O grupo não permite mensagens deste bot.');
    return array('id'=>(string)$chat['id'],'title'=>isset($chat['title'])?$chat['title']:'Grupo','bot'=>isset($me['username'])?$me['username']:'bot');
}
function mt_activation_check($c,$transport='mt_api') {
    $group=mt_validate_group($c,$transport);
    $sent=call_user_func($transport,$c['token'],'sendMessage',array('chat_id'=>$group['id'],'text'=>"✅ MK-AUTH — teste automático de ativação\nBot e grupo verificados. O envio automático será ativado após salvar a confirmação.",'disable_notification'=>'true'));
    if(empty($sent['message_id']))throw new RuntimeException('O Telegram não confirmou a entrega do teste. Os alertas continuam desativados.');
    return $group;
}
function mt_groups($token,$transport='mt_api') {
    call_user_func($transport,mt_token($token),'getMe',array());
    $updates=call_user_func($transport,$token,'getUpdates',array('limit'=>100,'timeout'=>0));$groups=array();
    // No offset/allowed_updates change: never acknowledge messages or remove webhooks.
    foreach($updates as $update)foreach(array('message','edited_message','my_chat_member') as $key)if(isset($update[$key]['chat'])) {
        $chat=$update[$key]['chat'];if(in_array($chat['type'],array('group','supergroup'),true))$groups[(string)$chat['id']]=array('id'=>(string)$chat['id'],'title'=>isset($chat['title'])?$chat['title']:'Grupo');
    }
    return array_values($groups);
}
function mt_settings_message($c) {
    $types=array();if($c['connect'])$types[]='Cliente conectado';if($c['disconnect'])$types[]='Cliente desconectado';if($c['mass'])$types[]='Queda massiva';
    $filters=array('all'=>'Ativos e bloqueados','unblocked'=>'Ativos','blocked'=>'Bloqueados');
    $zone=mt_timezone(isset($c['timezone'])?$c['timezone']:'America/Sao_Paulo');
    $clock=(new DateTimeImmutable('now',new DateTimeZone($zone)))->format('d/m/Y H:i:s');
    return "✅ CONFIGURAÇÃO SALVA"
        ."\nEnvio: ".($c['enabled']?'ATIVADO':'DESATIVADO')
        ."\nAlertas: ".($types?implode(', ',$types):'Nenhum selecionado')
        ."\nContratos individuais: ".$filters[mt_customer_filter(isset($c['customer_filter'])?$c['customer_filter']:'all')]
        .($c['mass']?"\nQueda massiva: ".((isset($c['trigger_mode'])&&$c['trigger_mode']==='percent')?'níveis '.implode('%, ',mt_mass_levels($c['mass_levels'])).'% da base monitorada':$c['threshold'].' clientes').' em '.$c['window_minutes'].' min'."\nRetornos: lotes de ".$c['threshold'].'; encerra com '.mt_recovery_percent($c).'% recuperados por 2 min':'')
        ."\nSalvo em: ".$clock;
}
function mt_notify_saved($before,$after,$transport='mt_api') {
    $target=$after;
    if(!$after['enabled']&&($after['token']===''||$after['chat_id']===''))$target=$before;
    if($target['token']===''||$target['chat_id']==='')return '';
    try {call_user_func($transport,$target['token'],'sendMessage',array('chat_id'=>$target['chat_id'],'text'=>mt_settings_message($after)));return '';}
    catch(Exception $e){return 'A configuração foi salva, mas a confirmação não foi entregue ao Telegram. '.$e->getMessage();}
}
function mt_notify_switch($before,$after,$transport='mt_api') {
    if((bool)$before['enabled']===(bool)$after['enabled'])return '';
    $target=$after;if($target['token']===''||$target['chat_id']==='')$target=$before;
    if($target['token']===''||$target['chat_id']==='')return '';
    try {call_user_func($transport,$target['token'],'sendMessage',array('chat_id'=>$target['chat_id'],'text'=>($after['enabled']?'✅ MK-AUTH — ENVIO DE ALERTAS ATIVADO':'⏸ MK-AUTH — ENVIO DE ALERTAS DESATIVADO')."\n".mt_settings_message($after)));return '';}
    catch(Exception $e){return 'Estado salvo, mas a confirmação não foi entregue ao Telegram. '.$e->getMessage();}
}
function mt_timezone($zone) {
    if(!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new RuntimeException('Selecione um fuso horário válido.');
    return $zone;
}
function mt_customer_filter($value) {
    if(!in_array($value,array('all','unblocked','blocked'),true))throw new RuntimeException('Selecione quais contratos devem gerar alertas.');
    return $value;
}
function mt_filter_clients($clients,$config) {
    $filter=mt_customer_filter(isset($config['customer_filter'])?$config['customer_filter']:'all');
    return array_filter($clients,function($c)use($filter){return $filter==='all'||($filter==='blocked')===!empty($c['blocked']);});
}
// Pure transition detection: initial samples are a baseline, never a mass outage.
function mt_detect($previous,$clients,$config,$now) {
    if(empty($previous['baseline'])||$now-(int)$previous['sample_at']>300)return array('baseline'=>true,'sample_at'=>$now,'known'=>$clients,'pending'=>array(),'recent'=>array(),'mass_ids'=>array(),'events'=>array());
    $known=$previous['known'];$pending=isset($previous['pending'])?$previous['pending']:array();$recent=isset($previous['recent'])?$previous['recent']:array();$massIds=isset($previous['mass_ids'])?$previous['mass_ids']:array();$percentSeen=isset($previous['percent_seen'])?$previous['percent_seen']:array();$events=array();
    foreach($clients as $id=>$client) {
        if(!isset($known[$id])){$known[$id]=$client;continue;}
        if($known[$id]['online']===$client['online']){unset($pending[$id]);$client['offline_since']=isset($known[$id]['offline_since'])?$known[$id]['offline_since']:null;$known[$id]=$client;continue;}
        if(!isset($pending[$id])||$pending[$id]['online']!==$client['online'])$pending[$id]=array('online'=>$client['online'],'at'=>$now);
        $at=(int)$pending[$id]['at'];$event=array('id'=>hash('sha256',$id.'|'.$at.'|'.(int)$client['online']),'at'=>$at,'client_key'=>$id,'client'=>$client,'kind'=>$client['online']?'connect':'disconnect');
        if($client['online']) {
            $start=isset($client['connected_epoch'])?(int)$client['connected_epoch']:0;
            $stop=isset($client['disconnected_epoch'])?(int)$client['disconnected_epoch']:0;
            if($start>0&&$stop>0&&$start>=$stop&&$start<=$now&&$start>=$at-180) {
                $event['offline_seconds']=$start-$stop;$event['offline_estimated']=false;
            } elseif(!empty($known[$id]['offline_since'])) {
                $event['offline_seconds']=max(0,$at-$known[$id]['offline_since']);$event['offline_estimated']=true;
            }
            $client['offline_since']=null;
        } else $client['offline_since']=$at;
        if(!empty($config[$event['kind']])&&mt_filter_clients(array($id=>$client),$config))$events[]=$event;
        if(!$client['online'])$recent[$id]=$event;
        $known[$id]=$client;unset($pending[$id]);
    }
    foreach(array_keys($known) as $id)if(!isset($clients[$id])){unset($known[$id],$pending[$id]);}
    $validIds=array();foreach($recent as $id=>$event){if($now-$event['at']>(int)$config['window_minutes']*60||!isset($clients[$event['client_key']]))unset($recent[$id]);else $validIds[$event['id']]=true;}
    $massIds=array_intersect_key($massIds,$validIds);$unreported=array();foreach($recent as $event)if(!isset($massIds[$event['id']]))$unreported[$event['id']]=$event;
    $percentageMode=isset($config['trigger_mode'])&&$config['trigger_mode']==='percent';
    if(!empty($config['mass'])&&$percentageMode){
        // Only newly disconnected clients still offline count towards the current window.
        // The base includes active and blocked contracts inside the authorized scope.
        $affected=array();foreach($recent as $e)if(empty($clients[$e['client_key']]['online']))$affected[$e['id']]=$e;
        $base=count($clients);$ids=array_keys($affected);sort($ids);$keys=array();foreach($affected as $e)$keys[]=$e['client_key'];
        foreach(mt_mass_levels(isset($config['mass_levels'])?$config['mass_levels']:array(10)) as $level){
            $seen=isset($percentSeen[$level])?array_values(array_intersect($percentSeen[$level],array_keys($validIds))):array();
            if($base>0&&count($affected)*100 >= $base*$level){
                if(!array_intersect($seen,$ids))array_unshift($events,array('id'=>hash('sha256','percent|'.$level.'|'.implode('|',$ids)),'at'=>$now,'kind'=>'mass','count'=>count($affected),'client_keys'=>$keys,'outages'=>array_values($affected),'level_percent'=>$level,'base_count'=>$base,'recovery_percent'=>mt_recovery_percent($config)));
                $seen=array_values(array_unique(array_merge($seen,$ids)));
            }
            $percentSeen[$level]=$seen;
        }
    }
    if(!empty($config['mass'])&&!$percentageMode&&count($unreported)>=(int)$config['threshold']) {
        $ids=array_keys($unreported);sort($ids);$keys=array();foreach($unreported as $e)$keys[]=$e['client_key'];array_unshift($events,array('id'=>hash('sha256',implode('|',$ids)),'at'=>$now,'kind'=>'mass','count'=>count($unreported),'client_keys'=>$keys,'outages'=>array_values($unreported)));
        foreach($unreported as $id=>$event)$massIds[$id]=true;
    }
    return array('baseline'=>true,'sample_at'=>$now,'known'=>$known,'pending'=>$pending,'recent'=>$recent,'mass_ids'=>$massIds,'percent_seen'=>$percentSeen,'events'=>$events);
}
function mt_recorded_at($event) {
    if($event['kind']==='mass')return null;
    $key=$event['kind']==='connect'?'connected_epoch':'disconnected_epoch';
    $epoch=isset($event['client'][$key])?(int)$event['client'][$key]:0;
    return $epoch>0&&$epoch<=(int)$event['at']+5?$epoch:null;
}
function mt_clip($text,$limit=120) {
    $text=trim(preg_replace('/[\r\n\t]+/u',' ',(string)$text));
    if(function_exists('mb_substr'))return mb_substr($text,0,$limit,'UTF-8');
    $letters=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);return implode('',array_slice($letters?:array(),0,$limit));
}
function mt_cto_name($value) {
    $value=mt_clip($value,60);if($value==='')return 'CTO não informada';
    return preg_match('/^CTO\b/iu',$value)?$value:'CTO '.$value;
}
function mt_mass_message($event,$config,$date) {
    $outages=isset($event['outages'])?$event['outages']:array();$groups=array();$active=0;$blocked=0;$times=array();$allRecorded=true;
    foreach($outages as $outage) {
        $client=$outage['client'];$district=mt_clip(isset($client['district'])?$client['district']:'',70);if($district==='')$district='Bairro não informado';
        $cto=mt_cto_name(isset($client['cto'])?$client['cto']:'');$street=mt_clip(isset($client['street'])?$client['street']:'',70);
        if(!isset($groups[$district]))$groups[$district]=array('count'=>0,'ctos'=>array(),'streets'=>array());
        $groups[$district]['count']++;if(!isset($groups[$district]['ctos'][$cto]))$groups[$district]['ctos'][$cto]=0;$groups[$district]['ctos'][$cto]++;
        if($street!=='')$groups[$district]['streets'][$street]=true;
        if(!empty($client['blocked']))$blocked++;else $active++;
        $at=mt_recorded_at($outage);if($at===null){$at=$outage['at'];$allRecorded=false;}$times[]=$at;
    }
    uasort($groups,function($a,$b){return $b['count']-$a['count'];});
    $text='🚨 QUEDA MASSIVA — '.$event['count'].' CLIENTES';
    if(isset($event['level_percent']))$text.="\nNível: ".$event['level_percent'].'% · Base monitorada: '.$event['base_count'].' clientes'."\nQueda detectada: ".number_format(100*$event['count']/max(1,$event['base_count']),1,',','').'%';
    if($outages)$text.="\n✅ Ativos: ".$active.' · 🔒 Bloqueados: '.$blocked;
    $i=0;$remaining=0;
    foreach($groups as $district=>$group) {
        if($i++>=8){$remaining+=$group['count'];continue;}
        $text.="\n\n📍 ".$district.' — '.$group['count'].' clientes';arsort($group['ctos']);$j=0;
        foreach($group['ctos'] as $cto=>$count){if($j++>=6)break;$text.="\n• ".$cto.': '.$count.' clientes';}
        if(count($group['ctos'])>6)$text.="\n• +".(count($group['ctos'])-6).' CTOs';
        $streets=array_keys($group['streets']);natcasesort($streets);$shown=array_slice(array_values($streets),0,4);
        $text.="\nRuas: ".($shown?implode(', ',$shown):'não informadas');if(count($streets)>4)$text.=' (+'.(count($streets)-4).' ruas)';
    }
    if($remaining)$text.="\n\nOutros bairros: ".$remaining.' clientes';
    if($times){$start=min($times);$end=max($times);}else{$start=$event['at'];$end=$event['at'];$allRecorded=false;}
    $sameDay=$date($start)->format('Y-m-d')===$date($end)->format('Y-m-d');$format=$sameDay?'H:i:s':'d/m/Y H:i:s';
    $period="\n\n🕒 ".($allRecorded?'Período das quedas: ':'Período detectado: ').$date($start)->format($format).'–'.$date($end)->format($format);
    if($sameDay)$period.="\n📅 Data: ".$date($end)->format('d/m/Y');
    // Telegram has a message limit; preserve the total and the event period.
    if(strlen($text)>3400){$text=mt_clip(str_replace("\n","␤",$text),1500);$text=str_replace('␤',"\n",$text)."\n… resumo reduzido";}
    return $text.$period;
}
function mt_message($event,$config) {
    $zone=mt_timezone(isset($config['timezone'])?$config['timezone']:'America/Sao_Paulo');
    $date=function($epoch)use($zone){return (new DateTimeImmutable('@'.$epoch))->setTimezone(new DateTimeZone($zone));};
    if($event['kind']==='mass')return mt_mass_message($event,$config,$date);
    if($event['kind']==='mass_update') {
        $full=!empty($event['resolved']);$remaining=$event['total']-$event['recovered'];
        return ($full?'✅ QUEDA MASSIVA ENCERRADA — RECUPERAÇÃO CONFIRMADA':'🟡 ATUALIZAÇÃO DA QUEDA MASSIVA')
            .(isset($event['level_percent'])?"\nNível da ocorrência: ".$event['level_percent'].'%':'')
            ."\nVoltaram: ".$event['recovered'].' de '.$event['total'].' clientes'
            ."\nAinda offline: ".$remaining.' clientes'
            .($full?"\nRecuperação: ".number_format(100*$event['recovered']/max(1,$event['total']),1,',','')."%\nCritério: ao menos ".(isset($event['recovery_percent'])?$event['recovery_percent']:80)."% recuperados por 2 minutos.\nAcompanhamento desta ocorrência encerrado; os clientes restantes continuam offline.":'')
            ."\n⏱ Acompanhamento: ".intdiv(max(0,$event['at']-$event['started_at']),60).' min'
            ."\n🕒 Atualização: ".$date($event['at'])->format('H:i:s')."\n📅 Data: ".$date($event['at'])->format('d/m/Y');
    }

    $client=$event['client'];$cto=mt_cto_name(isset($client['cto'])?$client['cto']:'');$port=mt_clip(isset($client['port'])?$client['port']:'',30);
    $district=mt_clip(isset($client['district'])?$client['district']:'');$street=mt_clip(isset($client['street'])?$client['street']:'');$number=mt_clip(isset($client['number'])?$client['number']:'',30);
    $contract=array_key_exists('blocked',$client)?($client['blocked']?'🔒 Bloqueado':'✅ Ativo'):'Não informado';
    $text=($event['kind']==='connect'?'🟢 CLIENTE CONECTADO':'🔴 CLIENTE DESCONECTADO')."\nNome: ".mt_clip($client['name'])."\n\n📍 ".$cto.($port!==''?' · Porta '.$port:'')
        ."\nBairro: ".($district!==''?$district:'Não informado')."\nEndereço: ".($street!==''?$street.($number!==''?', '.$number:''):'Não informado')
        ."\n\nLogin: ".mt_clip($client['login'])."\nContrato: ".$contract;
    if($event['kind']==='connect'&&isset($event['offline_seconds'])) {
        $seconds=(int)$event['offline_seconds'];$days=intdiv($seconds,86400);$hours=intdiv($seconds%86400,3600);$minutes=intdiv($seconds%3600,60);
        $text.="\n⏱ Tempo desconectado".(!empty($event['offline_estimated'])?' estimado':'').': '.($days?$days.'d ':'').($hours?$hours.'h ':'').$minutes.'min '.($seconds%60).'s';
    }
    $recorded=mt_recorded_at($event);$at=$recorded!==null?$recorded:$event['at'];
    $label=$event['kind']==='connect'?'Conexão':'Queda';$label.=$recorded!==null?' registrada: ':' detectada: ';
    return $text."\n\n🕒 ".$label.$date($at)->format('H:i:s')."\n📅 Data: ".$date($at)->format('d/m/Y');
}

function mt_audit_event($event,$phase,$delivery=null) {
    $rows=mt_read('events');$id=$event['id'];
    if(!isset($rows[$id]))$rows[$id]=array('id'=>$id,'kind'=>$event['kind'],'event_at'=>$event['at'],'recorded_at'=>mt_recorded_at($event),'login'=>isset($event['client']['login'])?$event['client']['login']:null,'name'=>isset($event['client']['name'])?$event['client']['name']:null);
    $rows[$id][$phase.'_at']=time();$rows[$id]['status']=$phase;
    if(is_array($delivery)){$rows[$id]['message_id']=$delivery['message_id'];$rows[$id]['reply_to']=isset($delivery['reply_to'])?$delivery['reply_to']:null;}
    mt_write('events',array_slice($rows,-200,null,true));
}
