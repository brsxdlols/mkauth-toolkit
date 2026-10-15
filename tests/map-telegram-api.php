<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('MKAUTH_MAP_TEST_SOURCE');$case=isset($argv[1])?$argv[1]:'get';
require '/opt/mk-auth/admin/addons/mapa-clientes/config.hhvm';
require $root.'/lib/telegram_engine.php';
if(!is_dir(mt_dir()))mkdir(mt_dir(),0700,true);
$c=mt_defaults();$c['token']='123456:'.str_repeat('fixture',5);$c['chat_id']='-100123456789';$c['enabled']=true;if(in_array($case,array('toggle-on','toggle-unverified','save-preserve-enabled'),true)){$c['enabled']=false;$c['connect']=true;}if($case==='toggle-off')$c['token']='';mt_write('config',$c);if($case==='toggle-on')mt_write('verified',array('fingerprint'=>mt_fingerprint($c),'at'=>time()));else mt_write('verified',array());
$link->query('CREATE TEMPORARY TABLE sis_perm (nome VARCHAR(64),usuario VARCHAR(64),permissao VARCHAR(3))');
if($case!=='forbidden')$link->query("INSERT INTO sis_perm VALUES ('perm_config','fixture','sim'),('perm_clientes','fixture','sim'),('perm_totais','fixture','sim')");
$_SESSION=array('MKA_Usuario'=>'fixture');
function map_access_csrf(){return 'fixture-csrf';}
function fixture_notify_switch(){return '';}
function fixture_notify_saved(){return '';}
$_SERVER['REQUEST_METHOD']=$case==='get'||$case==='forbidden'?'GET':'POST';
$_POST=array('csrf'=>$case==='csrf'?'wrong':'fixture-csrf','action'=>strpos($case,'toggle-')===0?'toggle':($case==='disable'?'disable':'save'),'enabled'=>'1','mass'=>'1','threshold'=>'2','window_minutes'=>'2');
ob_start();register_shutdown_function(function()use($case,$c){$raw=ob_get_clean();$data=json_decode($raw,true);$status=http_response_code();$ok=false;
 if($case==='get')$ok=$status===200&&$data['config']['token']===$c['token']&&$data['config']['token_present']===true;
 elseif($case==='csrf'||$case==='forbidden')$ok=$status===403&&$data['ok']===false;
 elseif($case==='disable'){$saved=mt_config();$ok=$status===200&&!$saved['enabled']&&$saved['token']===$c['token'];}
 elseif($case==='save-preserve-enabled'){$saved=mt_config();$ok=$status===200&&!$saved['enabled'];}
 elseif($case==='toggle-on'||$case==='toggle-off'){$saved=mt_config();$ok=$status===200&&$saved['enabled']===($case==='toggle-on')&&$saved['token']===$c['token'];}
 elseif($case==='toggle-unverified')$ok=$status===400&&!mt_config()['enabled'];
 elseif($case==='unverified')$ok=$status===400&&$data['ok']===false;
 if(!$ok){fwrite(STDERR,'FAIL Telegram API '.$case."\n");exit(1);}echo 'PASS Telegram API '.$case."\n";
});
$source=file_get_contents($root.'/telegram_api.hhvm');
$source=str_replace(array('<?php',"require __DIR__.'/auth.php';",'require_map_access(true);',"require __DIR__.'/config.hhvm';"),'',$source);
$source=str_replace("__DIR__.'/access_policy.php'",var_export('/opt/mk-auth/admin/addons/mapa-clientes/access_policy.php',true),$source);
$source=str_replace("__DIR__.'/lib/telegram_engine.php'",var_export($root.'/lib/telegram_engine.php',true),$source);
$source=str_replace(array('mt_notify_switch(', 'mt_notify_saved('), array('fixture_notify_switch(', 'fixture_notify_saved('), $source);
eval($source);
