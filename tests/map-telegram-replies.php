<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_delivery.php';if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);mt_write('delivery',array('open'=>array(),'sent'=>array()));
function reply_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';$sent=array();$transport=function($t,$m,$p)use(&$sent){$sent[]=$p;return array('message_id'=>200+count($sent));};
function fixture_event($id,$kind,$at,$key='p:1'){return array('id'=>$id,'at'=>$at,'kind'=>$kind,'client_key'=>$key,'client'=>array('name'=>'Fixture','login'=>'fixture','blocked'=>false,'online'=>$kind==='connect'));}
mt_send_event(fixture_event('down1','disconnect',1000),$c,$transport);reply_check(!isset($sent[0]['reply_parameters']),'Drop must be standalone');
mt_send_event(fixture_event('up1','connect',1050),$c,$transport);$reply=json_decode($sent[1]['reply_parameters'],true);reply_check($reply['message_id']===201&&$reply['allow_sending_without_reply']===true,'Return not linked to drop');
mt_send_event(fixture_event('up1','connect',1050),$c,$transport);reply_check(count($sent)===2,'Retry after persisted delivery duplicated the event');
mt_send_event(fixture_event('down2','disconnect',1100),$c,$transport);mt_send_event(fixture_event('down3','disconnect',1200),$c,$transport);
mt_send_event(fixture_event('late-old-up','connect',1150),$c,$transport);reply_check(!isset($sent[4]['reply_parameters']),'Old return linked to newer outage');
mt_send_event(fixture_event('up3','connect',1250),$c,$transport);reply_check(json_decode($sent[5]['reply_parameters'],true)['message_id']===204,'Latest outage link lost');
mt_send_event(fixture_event('other','connect',1260,'p:2'),$c,$transport);reply_check(!isset($sent[6]['reply_parameters']),'Cross-customer reply');
mt_send_event(fixture_event('down4','disconnect',1300),$c,$transport);$other=$c;$other['chat_id']='-100999999999';mt_send_event(fixture_event('other-group','connect',1350),$other,$transport);reply_check(!isset($sent[8]['reply_parameters']),'Cross-group reply');
$failed=false;try{mt_send_event(fixture_event('up4','connect',1400),$c,function(){throw new RuntimeException('Fixture network failure');});}catch(Exception $e){$failed=true;}reply_check($failed,'Fixture failure not caught');
mt_send_event(fixture_event('up4','connect',1400),$c,$transport);reply_check(json_decode($sent[9]['reply_parameters'],true)['message_id']===208,'Failure erased original drop');
echo "PASS real-event delivery: persisted replies, retry dedup, latest incident, customer/group isolation and failure preservation. No Telegram requests.\n";
