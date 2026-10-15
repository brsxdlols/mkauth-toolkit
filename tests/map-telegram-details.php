<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_engine.php';
function check_detail($ok,$message){if(!$ok)throw new RuntimeException($message);}
$c=mt_defaults();$c['connect']=true;$c['disconnect']=true;$c['mass']=true;$c['threshold']=1;
$client=array('name'=>'Fixture','login'=>'fixture','district'=>'Centro','street'=>'Rua Teste','number'=>'123','online'=>true,'blocked'=>false);
$s=mt_detect(array(),array('p:1'=>$client),$c,1000);
$client['online']=false;$s=mt_detect($s,array('p:1'=>$client),$c,1060);check_detail(count($s['events'])===2,'Immediate outage missing');
$s=mt_detect($s,array('p:1'=>$client),$c,1120);check_detail(!$s['events'],'Duplicate outage');
$s=mt_detect($s,array('p:1'=>$client),$c,1180);
$client['online']=true;$client['connected_epoch']=1200;$client['disconnected_epoch']=1040;
$s=mt_detect($s,array('p:1'=>$client),$c,1240);
$event=$s['events'][0];check_detail($event['offline_seconds']===160&&!$event['offline_estimated'],'RADIUS duration incorrect');
$event['at']=1792004400;$text=mt_message($event,$c);check_detail(strpos($text,'Endereço: Rua Teste, 123')!==false&&strpos($text,'2min 40s')!==false,'Address/duration missing');
$c['timezone']='America/Manaus';$manaus=mt_message($event,$c);check_detail($manaus!==$text&&strpos($manaus,'America/')===false&&strpos($text,'-03:00')===false,'Timezone conversion or hidden suffix failed');
$blocked=$client;$blocked['blocked']=true;$c['customer_filter']='unblocked';check_detail(count(mt_filter_clients(array($client,$blocked),$c))===1,'Unblocked filter failed');
$s=mt_detect(array(),array('x'=>$blocked),$c,1000);$blocked['online']=false;$s=mt_detect($s,array('x'=>$blocked),$c,1060);check_detail(count($s['events'])===1&&$s['events'][0]['kind']==='mass','Blocked must count in mass, not individual');
$c['customer_filter']='blocked';check_detail(count(mt_filter_clients(array($client,$blocked),$c))===1,'Blocked filter failed');
$c['customer_filter']='all';unset($client['connected_epoch'],$client['disconnected_epoch']);$client['online']=false;
$s=mt_detect(array(),array('x'=>$client),$c,1000);$client['online']=true;$s=mt_detect($s,array('x'=>$client),$c,1060);check_detail(!isset($s['events'][0]['offline_seconds']),'Unknown duration invented');
foreach(array('timezone'=>'invalid','customer_filter'=>'invalid') as $kind=>$value){$bad=false;try{if($kind==='timezone')mt_timezone($value);else mt_customer_filter($value);}catch(Exception $e){$bad=true;}check_detail($bad,'Invalid setting accepted');}
echo "PASS timezone, street, RADIUS duration, unknown duration, block filters and mass exclusions. No real messages sent.\n";
