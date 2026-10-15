<?php
$root=getenv('MKAUTH_MAP_TEST_SOURCE');require $root.'/lib/telegram_engine.php';
function mass_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$c=mt_defaults();$c['connect']=true;$c['disconnect']=true;$c['customer_filter']='unblocked';$c['threshold']=3;
$clients=array();for($i=0;$i<3;$i++)$clients['p:'.$i]=array('name'=>'Fixture '.$i,'login'=>'fixture'.$i,'online'=>true,'blocked'=>$i===2,'district'=>$i===2?'Centro':'Jardim Leone','cto'=>'342','street'=>'Rua Teste','disconnected_epoch'=>1792004370+$i);
$s=mt_detect(array(),$clients,$c,1792004350);foreach($clients as &$client)$client['online']=false;unset($client);$s=mt_detect($s,$clients,$c,1792004400);
$mass=array_values(array_filter($s['events'],function($e){return $e['kind']==='mass';}));$individual=array_filter($s['events'],function($e){return $e['kind']==='disconnect';});mass_check(count($mass)===1&&$mass[0]['count']===3&&count($individual)===2,'Blocked mass versus individual filter failed');
$text=mt_message($mass[0],$c);mass_check(strpos($text,'Ativos: 2')!==false&&strpos($text,'Bloqueados: 1')!==false,'Contract breakdown absent');mass_check(strpos($text,'Jardim Leone — 2 clientes')!==false&&strpos($text,'Centro — 1 clientes')!==false,'District counts wrong');mass_check(substr_count($text,'CTO 342')===2,'Shared CTO must appear with each district count');mass_check(strpos($text,'UTC')===false&&strpos($text,'America/')===false&&strpos($text,'-03:00')===false,'Timezone leaked into alert');mass_check(strpos($text,'Período das quedas:')!==false,'Period absent');
$s=mt_detect($s,$clients,$c,1792004415);mass_check(!$s['events'],'Already offline contracts recounted');
$blank=$mass[0];$blank['outages'][0]['client']['district']='';$blank['outages'][0]['client']['cto']='';$text=mt_message($blank,$c);mass_check(strpos($text,'Bairro não informado')!==false&&strpos($text,'CTO não informada')!==false,'Missing registration guessed');
echo "PASS blocked clients in mass only, counts by district/CTO, no double-counting, no timezone, period and unknown registration.\n";
