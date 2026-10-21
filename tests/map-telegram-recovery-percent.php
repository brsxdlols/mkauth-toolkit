<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_delivery.php';if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);
function pct_check($ok,$m){if(!$ok)throw new RuntimeException($m);}
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';$c['threshold']=10;
$clients=array();$outages=array();$sent=array();for($i=0;$i<644;$i++){$clients['p:'.$i]=array('online'=>false,'name'=>'Fixture','login'=>'fixture'.$i,'blocked'=>false);$outages[]=array('kind'=>'disconnect','at'=>1000,'client'=>$clients['p:'.$i]);}
$transport=function($t,$m,$p)use(&$sent){$sent[]=$p;return array('message_id'=>100+count($sent));};
mt_write('delivery',array('open'=>array(),'sent'=>array()));mt_send_event(array('id'=>'large-root','kind'=>'mass','at'=>1000,'count'=>644,'client_keys'=>array_keys($clients),'outages'=>$outages),$c,$transport);
foreach($clients as $k=>&$v)$v['online']=(int)substr($k,2)<515;unset($v);$e=mt_mass_updates($clients,$c,1015);pct_check($e&&!$e[0]['resolved'],'515/644 below 80 must not resolve');mt_send_event($e[0],$c,$transport);
$clients['p:515']['online']=true;pct_check(!mt_mass_updates($clients,$c,1030),'First 80% sample resolved');mt_mass_updates($clients,$c,1045);
$clients['p:515']['online']=false;mt_mass_updates($clients,$c,1060);$clients['p:515']['online']=true;
for($at=1075;$at<=1180;$at+=15)pct_check(!mt_mass_updates($clients,$c,$at),'Flap did not reset stability');
$limited=$clients;unset($limited['p:0']);pct_check(!mt_mass_updates($limited,$c,1195),'Scope must never resolve');
for($at=1210;$at<=1315;$at+=15)pct_check(!mt_mass_updates($clients,$c,$at),'Missing scope sample did not reset stability');
pct_check(!mt_mass_updates($clients,$c,1500),'Restart gap incorrectly used wall-clock stability');
for($at=1515;$at<1620;$at+=15)pct_check(!mt_mass_updates($clients,$c,$at),'Premature resolution after sample gap');
$e=mt_mass_updates($clients,$c,1620);pct_check(count($e)===1&&$e[0]['resolved']&&$e[0]['recovered']===516,'Stable 80% final missing');
try{mt_send_event($e[0],$c,function(){throw new RuntimeException('fixture failure');});}catch(Exception $x){}
pct_check(count(mt_read('delivery')['mass_open'])===1,'Failed final must preserve incident');$e=mt_mass_updates($clients,$c,1635);mt_send_event($e[0],$c,$transport);
$last=end($sent);pct_check(json_decode($last['reply_parameters'],true)['message_id']===101,'Final must reply original');pct_check(strpos($last['text'],'80,1%')!==false&&strpos($last['text'],'Ainda offline: 128')!==false&&strpos($last['text'],'ENCERRADA')!==false,'Final must show actual percent and residual offline');pct_check(!mt_read('delivery')['mass_open'],'Final not closed');
foreach($clients as &$v)$v['online']=true;unset($v);pct_check(!mt_mass_updates($clients,$c,1650),'100% generated another final');foreach($clients as &$v)$v['online']=false;unset($v);pct_check(!mt_mass_updates($clients,$c,1665),'Old occurrence reopened');
echo "PASS 80% closure: exact rounding, stable 120 seconds, flap/scope/gap reset, persisted timer, failed send retry, root reply and residual clients. No Telegram network.\n";
