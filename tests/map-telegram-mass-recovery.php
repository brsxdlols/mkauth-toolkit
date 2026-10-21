<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_delivery.php';if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);mt_write('delivery',array('open'=>array(),'sent'=>array()));
function check_recovery($ok,$label){if(!$ok)throw new RuntimeException($label);}
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';$c['customer_filter']='unblocked';$c['threshold']=1;$sent=array();
$transport=function($t,$m,$p)use(&$sent){$sent[]=$p;return array('message_id'=>100+count($sent));};
$clients=array();$outages=array();for($i=1;$i<=3;$i++){$clients['p:'.$i]=array('name'=>'Fixture','login'=>'fixture'.$i,'online'=>false,'blocked'=>$i===3,'district'=>'Teste','cto'=>'12','street'=>'Teste');$outages[]=array('kind'=>'disconnect','at'=>1000,'client'=>$clients['p:'.$i]);}
$rootEvent=array('id'=>'mass-root','kind'=>'mass','at'=>1000,'count'=>3,'client_keys'=>array_keys($clients),'outages'=>$outages);
mt_send_event($rootEvent,$c,$transport);check_recovery(count(mt_read('delivery')['mass_open'])===1,'Mass root not persisted');check_recovery(!mt_mass_updates($clients,$c,1010),'No recovery must not notify');
$clients['p:3']['online']=true;$events=mt_mass_updates($clients,$c,1020);check_recovery(count($events)===1&&$events[0]['recovered']===1,'Blocked recovery lost with individual notices off');
try{mt_send_event($events[0],$c,function(){throw new RuntimeException('network failure');});}catch(Exception $e){}
check_recovery(count(mt_mass_updates($clients,$c,1020))===1,'Failed delivery advanced count');mt_send_event($events[0],$c,$transport);
check_recovery(json_decode($sent[1]['reply_parameters'],true)['message_id']===101,'Partial response did not reply to root');check_recovery(strpos($sent[1]['text'],'Voltaram: 1 de 3')!==false&&strpos($sent[1]['text'],'Ainda offline: 2')!==false,'Wrong recovery counts');
mt_send_event($events[0],$c,$transport);check_recovery(count($sent)===2&&!mt_mass_updates($clients,$c,1030),'Retry/restart duplicates');
$clients['p:3']['online']=false;check_recovery(!mt_mass_updates($clients,$c,1040),'Decrease must not notify');$clients['p:3']['online']=true;check_recovery(!mt_mass_updates($clients,$c,1050),'Repeated milestone after flapping notified');
$other=$c;$other['chat_id']='-100999999999';check_recovery(!mt_mass_updates($clients,$other,1060),'Cross group recovery');$limited=$clients;unset($limited['p:1']);check_recovery(!mt_mass_updates($limited,$c,1060),'Unauthorized client counted');
foreach($clients as &$v)$v['online']=true;unset($v);$e=array();for($at=1100;$at<=1220;$at+=15)$e=mt_mass_updates($clients,$c,$at);mt_send_event($e[0],$c,$transport);check_recovery(json_decode(end($sent)['reply_parameters'],true)['message_id']===101,'Full recovery did not reply to original');check_recovery(strpos(end($sent)['text'],'ENCERRADA')!==false&&strpos(end($sent)['text'],'Voltaram: 3 de 3')!==false,'Wrong full recovery');check_recovery(!mt_read('delivery')['mass_open']&&!mt_mass_updates($clients,$c,1235),'Resolved incident not closed');
echo "PASS real mass recovery: persisted root, partial/full reply, blocked, restart, retries, oscillation, scope and group isolation. No real Telegram messages.\n";
