<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('MKAUTH_MAP_TEST_SOURCE');if(!$root)$root=__DIR__.'/../addons/mapa-clientes';require_once $root.'/lib/telegram_demo.php';
if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);
function demo_assert($ok,$label){if(!$ok)throw new RuntimeException($label);}
$config=mt_defaults();$config['token']='123456:'.str_repeat('fixture',5);$config['chat_id']='-100123456789';$messages=array();$next=100;
$transport=function($token,$method,$params)use(&$messages,&$next){if($method==='getMe')return array('id'=>123,'username'=>'fixture_bot');if($method==='getChat')return array('id'=>-100123456789,'type'=>'supergroup','title'=>'Fixture');if($method==='getChatMember')return array('status'=>'member');if($method==='sendMessage'){$messages[]=$params;return array('message_id'=>$next++);}throw new RuntimeException('Unexpected request');};
mt_write('demonstrations',array());$missing=false;try{mt_demo($config,'recovered',$transport);}catch(Exception $e){$missing=true;}demo_assert($missing&&!$messages,'Recovery sent without an original test');
mt_demo($config,'disconnect',$transport);mt_demo($config,'recovered',$transport);demo_assert(json_decode($messages[1]['reply_parameters'],true)['message_id']===100,'Individual recovery not linked to original');
mt_demo($config,'mass',$transport);mt_demo($config,'partial',$transport);mt_demo($config,'resolved',$transport);demo_assert(json_decode($messages[3]['reply_parameters'],true)['message_id']===102&&json_decode($messages[4]['reply_parameters'],true)['message_id']===102,'Mass replies not linked to same occurrence');
mt_demo($config,'connect',$transport);foreach($messages as $p)demo_assert(strpos($p['text'],'TESTE')!==false,'Demo not labelled TESTE');
$count=count($messages);$before=$config;$after=$config;$after['enabled']=true;$after['connect']=true;
demo_assert(mt_notify_switch($before,$after,$transport)===''&&count($messages)===$count+1,'Activation confirmation missing');demo_assert(strpos(end($messages)['text'],'ATIVADO')!==false,'Wrong activation text');
mt_notify_switch($after,$after,$transport);demo_assert(count($messages)===$count+1,'Unchanged save repeated confirmation');
mt_notify_switch($after,$before,$transport);demo_assert(strpos(end($messages)['text'],'DESATIVADO')!==false,'Deactivation confirmation missing');
$count=count($messages);mt_notify_saved($after,$after,$transport);mt_notify_saved($after,$after,$transport);demo_assert(count($messages)===$count+2,'Every save must notify');$text=end($messages)['text'];demo_assert(strpos($text,'America/Sao_Paulo')!==false&&strpos($text,'CLIENTE CONECTADO')!==false&&strpos($text,$after['token'])===false,'Incomplete or unsafe settings summary');
$warning=mt_notify_switch($after,$before,function(){throw new RuntimeException('Fixture delivery failure');});demo_assert($warning!==''&&!$before['enabled'],'Failure blocked disabled state');
echo "PASS Telegram demos: individual/mass replies, TESTE labels, activation/deactivation, unchanged-save dedup and failure-safe deactivation. No real messages sent.\n";
