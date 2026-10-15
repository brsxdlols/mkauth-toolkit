<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('MKAUTH_MAP_TEST_SOURCE');if(!$root)$root=__DIR__.'/../addons/mapa-clientes';require $root.'/lib/telegram_engine.php';
function tgcheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function clients_tg($online){$c=array();foreach($online as $id=>$up)$c[$id]=array('login'=>$id,'name'=>'Cliente '.$id,'district'=>'Centro','online'=>$up);return $c;}
$config=mt_defaults();$config['threshold']=2;$config['window_minutes']=2;$config['connect']=true;$config['disconnect']=true;
$state=mt_detect(array(),clients_tg(array('a'=>true,'b'=>true)),$config,1000);tgcheck(!$state['events'],'Baseline sent history');
$state=mt_detect($state,clients_tg(array('a'=>false,'b'=>true)),$config,1015);tgcheck(count($state['events'])===1&&$state['events'][0]['kind']==='disconnect','Offline was delayed');
$state=mt_detect($state,clients_tg(array('a'=>true,'b'=>true)),$config,1065);tgcheck(count($state['events'])===1&&$state['events'][0]['kind']==='connect','50-second outage recovery was lost');
$state=mt_detect($state,clients_tg(array('a'=>true,'b'=>true)),$config,1080);tgcheck(!$state['events'],'Duplicate event');
$config['connect']=false;$config['disconnect']=false;
$state=mt_detect($state,clients_tg(array('a'=>false,'b'=>false)),$config,1095);tgcheck(count($state['events'])===1&&$state['events'][0]['kind']==='mass','Mass-only mode failed');
$state=mt_detect($state,clients_tg(array('a'=>false,'b'=>false)),$config,1110);tgcheck(!$state['events'],'Mass duplicate');
$state=mt_detect($state,clients_tg(array('b'=>false)),$config,1125);tgcheck(!$state['events'],'Removal generated offline event');
$state=mt_detect($state,clients_tg(array('b'=>true)),$config,2000);tgcheck(!$state['events'],'Stale baseline sent history');
$requests=array();$transport=function($token,$method,$params)use(&$requests){$requests[]=array($method,$params);if($method==='getMe')return array('id'=>123,'username'=>'fixture_bot');if($method==='getChat')return array('id'=>-100123456789,'type'=>'supergroup','title'=>'Fixture');if($method==='getChatMember')return array('status'=>'member');if($method==='getUpdates')return array(array('message'=>array('chat'=>array('id'=>-1001,'type'=>'group','title'=>'A'))),array('my_chat_member'=>array('chat'=>array('id'=>-1001,'type'=>'group','title'=>'A'))));throw new RuntimeException('Unexpected outbound action');};
$config['token']='123456:'.str_repeat('x',30);$config['chat_id']='-100123456789';
$group=mt_validate_group($config,$transport);tgcheck($group['id']==='-100123456789','Group validation failed');
$groups=mt_groups($config['token'],$transport);tgcheck(count($groups)===1,'Group dedup failed');
foreach($requests as $req)if($req[0]==='getUpdates')tgcheck(!isset($req[1]['offset'])&&!isset($req[1]['allowed_updates']),'Updates acknowledged/changed');
$rejected=false;try{mt_validate_group($config,function($token,$method,$params)use($transport){if($method==='getChatMember')return array('status'=>'left');return $transport($token,$method,$params);});}catch(Exception $e){$rejected=true;}tgcheck($rejected,'Inactive bot accepted');
echo "PASS Telegram: baseline, 50-second outage, immediate events, mass-only, deduplication, stale/removal guards, group sync and inactive bot. No real messages sent.\n";
