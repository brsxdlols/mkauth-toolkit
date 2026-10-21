<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_delivery.php';if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);mt_write('delivery',array('open'=>array(),'sent'=>array()));
function batch_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';$c['threshold']=10;$clients=array();$outages=array();$sent=array();
for($i=0;$i<23;$i++){$clients['p:'.$i]=array('name'=>'Fixture','login'=>'fixture'.$i,'online'=>false,'blocked'=>false);$outages[]=array('kind'=>'disconnect','at'=>1000,'client'=>$clients['p:'.$i]);}
$transport=function($t,$m,$p)use(&$sent){$sent[]=$p;return array('message_id'=>100+count($sent));};
mt_send_event(array('id'=>'batch-root','kind'=>'mass','at'=>1000,'count'=>23,'client_keys'=>array_keys($clients),'outages'=>$outages),$c,$transport);
for($i=0;$i<9;$i++){$clients['p:'.$i]['online']=true;batch_check(!mt_mass_updates($clients,$c,1100+$i),'Individual recovery notified below configured minimum');}
$clients['p:9']['online']=true;$e=mt_mass_updates($clients,$c,1120);batch_check(count($e)===1&&$e[0]['recovered']===10,'First configured batch absent');mt_send_event($e[0],$c,$transport);
$clients['p:0']['online']=false;batch_check(!mt_mass_updates($clients,$c,1130),'Fall in count notified');$clients['p:0']['online']=true;batch_check(!mt_mass_updates($clients,$c,1140),'One-client flap repeated batch');
for($i=10;$i<19;$i++){$clients['p:'.$i]['online']=true;batch_check(!mt_mass_updates($clients,$c,1200+$i),'Unbatched partial notification');}
$c['threshold']=20;batch_check(!mt_mass_updates($clients,$c,1250),'Current configured minimum ignored');$c['threshold']=10;
$clients['p:19']['online']=true;batch_check(!mt_mass_updates($clients,$c,1260),'80% must wait for stability');
for($at=1275;$at<=1380;$at+=15)$e=mt_mass_updates($clients,$c,$at);
batch_check(count($e)===1&&$e[0]['recovered']===20&&!empty($e[0]['resolved']),'80% stable final absent');mt_send_event($e[0],$c,$transport);
for($i=20;$i<23;$i++)$clients['p:'.$i]['online']=true;batch_check(!mt_mass_updates($clients,$c,1400),'Closed incident must not wait for 80% closure');
batch_check(count($sent)===3,'Unexpected notification count');foreach(array_slice($sent,1) as $p)batch_check(json_decode($p['reply_parameters'],true)['message_id']===101,'Batch replied outside original occurrence');
echo "PASS configured recovery batches: 10 then stable 20/23 closure, no individual flaps or zero updates, current settings, 80% closure and replies.\n";
