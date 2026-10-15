<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_engine.php';
function message_assert($ok,$label){if(!$ok)throw new RuntimeException($label);}
$c=mt_defaults();$e=array('at'=>1792004400,'kind'=>'connect','client'=>array('name'=>'Fixture','login'=>'fixture','blocked'=>false,'connected_epoch'=>1792004380));
$text=mt_message($e,$c);message_assert(strpos($text,'Nome: Fixture')!==false&&strpos($text,'Contrato: ✅ Ativo')!==false,'Identity or active contract missing');
message_assert(strpos($text,'Conexão registrada:')!==false&&strpos($text,'Fuso:')===false&&mt_recorded_at($e)===1792004380,'Recorded time confused with detection');
$e['kind']='disconnect';$e['client']['blocked']=true;$e['client']['disconnected_epoch']=1792004370;$text=mt_message($e,$c);message_assert(strpos($text,'Contrato: 🔒 Bloqueado')!==false&&strpos($text,'Queda registrada:')!==false,'Blocked contract or stop timestamp missing');
unset($e['client']['disconnected_epoch']);$text=mt_message($e,$c);message_assert(strpos($text,'Queda detectada:')!==false,'Missing RADIUS timestamp fabricated');
$e['client']['disconnected_epoch']=1792004500;message_assert(mt_recorded_at($e)===null,'Future timestamp presented as exact');
echo "PASS Nome, contract icons, RADIUS versus detection time, missing/future timestamps. No outbound messages.\n";
