<?php
if (PHP_SAPI !== 'cli') exit(1);
$root=getenv('MKAUTH_GEO_TEST_SOURCE');
$source=file_get_contents($root.'/geocode.php');
$start=strpos($source,'function geocodeErrorLogOnce(');
$end=strpos($source,'function batchJobsDir(', $start);
eval(substr($source,$start,$end-$start));
$dir=sys_get_temp_dir().'/mkauth-log-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
ini_set('error_log',$dir.'/error.log');$cfg=array('cache_dir'=>$dir);
for($i=0;$i<100;$i++)geocodeErrorLogOnce($cfg);
if(count(file($dir.'/error.log'))!==1)throw new RuntimeException('Repeated errors flooded log');
file_put_contents($dir.'/error-log-rate.lock',(string)(time()-3601));geocodeErrorLogOnce($cfg);
if(count(file($dir.'/error.log'))!==2)throw new RuntimeException('Next hour did not log');
unlink($dir.'/error.log');unlink($dir.'/error-log-rate.lock');rmdir($dir);
echo "PASS geocoding diagnostic: 100 failures log once, next hour logs again.\n";
