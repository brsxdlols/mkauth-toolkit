<?php
require_once __DIR__.'/telegram_engine.php';
// Called under the worker lock. Persist message IDs separately from retry queues.
function mt_send_event($event,$config,$transport='mt_api') {
    $store=mt_read('delivery',array('open'=>array(),'sent'=>array()));
    $fingerprint=mt_fingerprint($config);$sentKey=$fingerprint.'|'.$event['id'];
    if(isset($store['sent'][$sentKey]))return array('message_id'=>$store['sent'][$sentKey]['message_id'],'reply_to'=>isset($store['sent'][$sentKey]['reply_to'])?$store['sent'][$sentKey]['reply_to']:null,'already_sent'=>true);
    $key=isset($event['client_key'])?$fingerprint.'|'.$event['client_key']:null;
    $incident=$key!==null&&isset($store['open'][$key])?$store['open'][$key]:null;
    $params=array('chat_id'=>$config['chat_id'],'text'=>mt_message($event,$config));
    if($event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at']) {
        $params['reply_parameters']=json_encode(array('message_id'=>$incident['message_id'],'allow_sending_without_reply'=>true));
    }
    $message=call_user_func($transport,$config['token'],'sendMessage',$params);
    if(empty($message['message_id'])||(int)$message['message_id']<1)throw new RuntimeException('Telegram não confirmou o identificador da mensagem.');
    $id=(int)$message['message_id'];
    if($event['kind']==='disconnect'&&(!$incident||(int)$incident['event_at']<=(int)$event['at']))$store['open'][$key]=array('message_id'=>$id,'event_at'=>$event['at'],'event_id'=>$event['id']);
    if($event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at'])unset($store['open'][$key]);
    $replyTo=$event['kind']==='connect'&&$incident&&(int)$incident['event_at']<=(int)$event['at']?$incident['message_id']:null;
    $store['sent'][$sentKey]=array('message_id'=>$id,'reply_to'=>$replyTo,'at'=>time());
    $store['sent']=array_slice($store['sent'],-5000,null,true);mt_write('delivery',$store);
    return array('message_id'=>$id,'reply_to'=>$replyTo);
}
