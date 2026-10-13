<?php
if (PHP_SAPI !== 'cli') exit(1);
$source = getenv('MKAUTH_MAP_TEST_SOURCE');
if (!$source) $source=__DIR__.'/../addons/mapa-clientes';
$root=sys_get_temp_dir().'/map-monitor-entry-'.bin2hex(random_bytes(8));
mkdir($root.'/monitor',0700,true);
copy($source.'/monitor/index.php',$root.'/monitor/index.php');
file_put_contents($root.'/index.hhvm', '<?php echo "<html><head><title>Native entry</title></head><body>"; echo $_GET["monitor"]."|".$_GET["login"]."|".$_SESSION["group_fixture"]; echo "</body></html>";');
$_GET=array('login'=>'client+login');$_SESSION=array('group_fixture'=>'allowed-group');
$_SERVER['SCRIPT_NAME']='/admin/addons/mapa-clientes/monitor/index.php';
$cwd=getcwd();
ob_start();require $root.'/monitor/index.php';ob_end_flush();$html=ob_get_clean();
chdir($cwd);
unlink($root.'/monitor/index.php');unlink($root.'/index.hhvm');rmdir($root.'/monitor');rmdir($root);
if (substr_count($html,'<base href="/admin/addons/mapa-clientes/">')!==1 || strpos($html,'1|client+login|allowed-group')===false) throw new RuntimeException('Native delegation, base URL or request/session preservation failed.');
echo "PASS monitor entry delegates, sets monitor=1, preserves login/groups and inserts one base URL.\n";
