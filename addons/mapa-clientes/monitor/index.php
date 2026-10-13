<?php
// suite-map-monitor-entry-v1
// Preserve the Mapa de Clientes authentication and group checks in its native entry.
header('Cache-Control: no-store, no-cache, must-revalidate');
$_GET['monitor']='1';
$mapRoot=dirname(__DIR__);
if(!is_file($mapRoot.'/index.hhvm')){http_response_code(404);exit('Mapa de Clientes indisponivel.');}
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'],2)),'/').'/';
ob_start(function($html)use($base){return preg_replace('/<head(?:\s[^>]*)?>/i','$0<base href="'.htmlspecialchars($base,ENT_QUOTES,'UTF-8').'">',$html,1);});
chdir($mapRoot);
require $mapRoot.'/index.hhvm';
