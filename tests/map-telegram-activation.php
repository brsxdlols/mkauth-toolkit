<?php
require $argv[1].'/lib/telegram_engine.php';
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';
function fake_activation_transport($token,$method,$params){
 $GLOBALS['calls'][]=$method;
 if($method==='getMe')return array('id'=>12,'username'=>'fixture_bot');
 if($method==='getChat')return array('id'=>-100123456789,'type'=>'supergroup','title'=>'Fixture');
 if($method==='getChatMember')return array('status'=>'administrator');
 if($GLOBALS['mode']==='failure')throw new RuntimeException('Delivery failed');
 return $GLOBALS['mode']==='empty'?array():array('message_id'=>123);
}
foreach(array('success','failure','empty') as $mode){$calls=array();$failed=false;try{$group=mt_activation_check($c,'fake_activation_transport');}catch(Exception $e){$failed=true;}
 if($failed!==($mode!=='success')||$calls!==array('getMe','getChat','getChatMember','sendMessage'))exit(1);
}
if(strpos(mt_settings_message($c),'CONFIGURAÇÃO SALVA')===false)exit(1);
echo "PASS automatic activation: validated group, confirmed delivery, network failure, missing receipt, neutral save title\n";
