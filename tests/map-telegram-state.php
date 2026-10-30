<?php
$source=getenv('MKAUTH_MAP_TEST_SOURCE');require $source.'/lib/telegram_engine.php';
if(isset($argv[1])&&$argv[1]==='--short-write'){
 if(!function_exists('pcntl_signal'))exit(77);pcntl_signal(SIGXFSZ,SIG_IGN);
 try{mt_write('config',array('payload'=>str_repeat('x',8192)));exit(2);}catch(Exception $e){exit(0);}
}
function check_state($condition,$message){if(!$condition)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/mkauth-state-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('MKAUTH_MAP_TELEGRAM_STATE='.$dir);
try{
 mt_write('config',array('token'=>'fixture','chat_id'=>'fixture'));$before=file_get_contents($dir.'/config.json');
 file_put_contents($dir.'/runtime.json','');file_put_contents($dir.'/transitions.json','broken');
 check_state(mt_read('runtime')===array(),'runtime rebuild');check_state(mt_read('transitions')===array(),'transitions rebuild');
 check_state(mt_read('config')['token']==='fixture','config preserved');
 file_put_contents($dir.'/delivery.json','');$failed=false;try{mt_read('delivery');}catch(Exception $e){$failed=true;}check_state($failed,'delivery must fail closed');
 $failed=false;try{mt_write('config',array('bad'=>NAN));}catch(Exception $e){$failed=true;}check_state($failed&&file_get_contents($dir.'/config.json')===$before,'encoding failure preserves config');
 $command='ulimit -f 1; exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --short-write';exec('/bin/bash -c '.escapeshellarg($command),$out,$code);
 check_state($code===0,'short write test failed: '.$code);check_state(file_get_contents($dir.'/config.json')===$before,'short write overwrote config');
 check_state(count(glob($dir.'/.telegram-*'))===0,'temporary files leaked');
 mt_write('runtime',array('last_sample'=>1));check_state(mt_read('runtime')['last_sample']===1,'runtime resumed');
 echo "PASS state recovery, critical-state fail closed, encoding failure and real short-write preservation\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);foreach(glob($dir.'/.telegram-*') as $f)unlink($f);rmdir($dir);}
