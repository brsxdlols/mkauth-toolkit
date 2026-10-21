<?php
require_once __DIR__.'/telegram_engine.php';
// Called under the worker lock. Persist message IDs separately from retry queues.
function mt_send_event($event,$config,$transport='mt_api') {
    $store=mt_read('delivery',array('open'=>array(),'sent'=>array()));
    $fingerprint=mt_fingerprint($config);$sentKey=$fingerprint.'|'.$event['id'];
    if(isset($store['sent'][$sentKey]))return array('message_id'=>$store['sent'][$sentKey]['message_id'],'reply_to'=>isset($store['sent'][$sentKey]['reply_to'])?$store['sent'][$sentKey]['reply_to']:null,'already_sent'=>true);
    $key=isset($event['client_key'])?$fingerprint.'|'.$event['client_key']:null;
    $incident=$key!==null&&isset($store['open'][$key])?$store['open'][$key]:null;
    $massKey=$fingerprint.'|'.(isset($event['incident_id'])?$event['incident_id']:$event['id']);
    $massIncident=isset($store['mass_open'][$massKey])?$store['mass_open'][$massKey]:null;
    if($event['kind']==='mass_update'&&!$massIncident)throw new RuntimeException('Ocorrência massiva original indisponível para resposta.');
    $params=array('chat_id'=>$config['chat_id'],'text'=>mt_message($event,$config));
    if($event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at']) {
        $params['reply_parameters']=json_encode(array('message_id'=>$incident['message_id'],'allow_sending_without_reply'=>true));
    }
    if($event['kind']==='mass_update')$params['reply_parameters']=json_encode(array('message_id'=>$massIncident['message_id'],'allow_sending_without_reply'=>false));
    $message=call_user_func($transport,$config['token'],'sendMessage',$params);
    if(empty($message['message_id'])||(int)$message['message_id']<1)throw new RuntimeException('Telegram não confirmou o identificador da mensagem.');
    $id=(int)$message['message_id'];
    if($event['kind']==='disconnect'&&(!$incident||(int)$incident['event_at']<=(int)$event['at']))$store['open'][$key]=array('message_id'=>$id,'event_at'=>$event['at'],'event_id'=>$event['id']);
    if($event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at'])unset($store['open'][$key]);
    if($event['kind']==='mass')$store['mass_open'][$massKey]=array('message_id'=>$id,'incident_id'=>$event['id'],'fingerprint'=>$fingerprint,'started_at'=>$event['at'],'client_keys'=>array_values(array_unique($event['client_keys'])),'recovered_keys'=>array(),'updated_at'=>time(),'sequence'=>0);
    if($event['kind']==='mass_update') {
        $store['mass_open'][$massKey]['sequence']=(isset($massIncident['sequence'])?$massIncident['sequence']:0)+1;
        $store['mass_open'][$massKey]['recovered_keys']=$event['recovered_keys'];$store['mass_open'][$massKey]['updated_at']=time();
        if(!empty($event['resolved']))unset($store['mass_open'][$massKey]);
    }
    if(isset($store['mass_open'])&&count($store['mass_open'])>500)$store['mass_open']=array_slice($store['mass_open'],-500,null,true);
    $replyTo=$event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at']?$incident['message_id']:null;
    if($event['kind']==='mass_update')$replyTo=$massIncident['message_id'];
    $store['sent'][$sentKey]=array('message_id'=>$id,'reply_to'=>$replyTo,'at'=>time());
    $store['sent']=array_slice($store['sent'],-5000,null,true);mt_write('delivery',$store);
    return array('message_id'=>$id,'reply_to'=>$replyTo);
}

// Recovery is independent of individual notification filters. Only the original
// customers within the current authorized snapshot may be counted.
function mt_mass_updates($clients,$config,$now) {
    if(empty($config['mass']))return array();
    $store=mt_read('delivery');$open=isset($store['mass_open'])?$store['mass_open']:array();$events=array();$fingerprint=mt_fingerprint($config);
    foreach($open as $incidentKey=>$incident) {
        if($incident['fingerprint']!==$fingerprint)continue;
        $keys=$incident['client_keys'];$recovered=array();$inScope=true;
        foreach($keys as $key){if(!isset($clients[$key])){$inScope=false;break;}if(!empty($clients[$key]['online']))$recovered[]=$key;}
        if(!$inScope){unset($store['mass_open'][$incidentKey]['recovery_since'],$store['mass_open'][$incidentKey]['recovery_sample']);continue;}
        sort($recovered);$before=$incident['recovered_keys'];sort($before);
        $resolved=false;$total=count($keys);$count=count($recovered);
        // Require 80% online for two minutes of continuous authorized samples.
        // A missing sample, restart gap or fall below the target restarts the timer.
        if($total>0&&$count*100 >= $total*80){
            $since=isset($incident['recovery_since'])?(int)$incident['recovery_since']:$now;
            $sample=isset($incident['recovery_sample'])?(int)$incident['recovery_sample']:0;
            if(!$sample||$now<$sample||$now-$sample>60)$since=$now;
            $store['mass_open'][$incidentKey]['recovery_since']=$since;
            $store['mass_open'][$incidentKey]['recovery_sample']=$now;
            $resolved=$now-$since>=120;
            if(!$resolved)continue;
        }else{unset($store['mass_open'][$incidentKey]['recovery_since'],$store['mass_open'][$incidentKey]['recovery_sample']);}
        $batch=max(1,(int)$config['threshold']);
        // Notify only a new recovery milestone, never a decrease or one-client flap.
        if(!$resolved&&intdiv(count($recovered),$batch)<=intdiv(count($before),$batch))continue;
        if(!$resolved&&$before===$recovered)continue;
        $events[]=array('id'=>hash('sha256',$incident['incident_id'].'|recovery|'.(isset($incident['sequence'])?$incident['sequence']:0).'|'.($resolved?'resolved':'partial').'|'.implode('|',$recovered)),
            'kind'=>'mass_update','incident_id'=>$incident['incident_id'],'at'=>$now,'started_at'=>$incident['started_at'],
            'resolved'=>$resolved,'recovery_percent'=>80,'client_keys'=>$keys,'recovered_keys'=>$recovered,'total'=>count($keys),'recovered'=>count($recovered));
    }
    if($store!==mt_read('delivery'))mt_write('delivery',$store);
    return $events;
}
