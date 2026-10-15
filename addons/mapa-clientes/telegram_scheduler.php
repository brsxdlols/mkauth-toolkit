<?php
// Persistent collector, supervised by cron. It never depends on a browser session.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);require_once __DIR__.'/lib/telegram_engine.php';
$lock=fopen(mt_dir().'/scheduler.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
mt_write('scheduler',array('pid'=>getmypid(),'started_at'=>time(),'interval_seconds'=>15));
while(true){
    $next=microtime(true)+15;
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/telegram_worker.php'),$code);
    $remaining=$next-microtime(true);if($remaining>0)usleep((int)($remaining*1000000));
}
