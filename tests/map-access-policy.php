<?php
if (PHP_SAPI !== 'cli') exit(1);
require '/opt/mk-auth/admin/addons/mapa-clientes/config.hhvm';
$mapPolicySource = getenv('MKAUTH_MAP_TEST_SOURCE');
require ($mapPolicySource ? $mapPolicySource : __DIR__.'/../addons/mapa-clientes').'/access_policy.php';
function check_map_policy($ok, $message) { if (!$ok) throw new RuntimeException($message); }
// Connection-local tables: never change operator permissions or production data.
$link->query('CREATE TEMPORARY TABLE sis_perm (nome VARCHAR(64),usuario VARCHAR(64),permissao VARCHAR(3))');
$link->query('CREATE TEMPORARY TABLE sis_acesso (login VARCHAR(64),cli_grupos VARCHAR(255))');
$link->query("INSERT INTO sis_acesso VALUES ('restricted','A'),('full','full_clientes'),('empty',''),('quoted', 'O\'Brien')");
$link->query("INSERT INTO sis_perm VALUES ('perm_numClientes','full','sim'),('perm_numClientes','restricted','nao'),('perm_totais','restricted','sim'),('perm_totais','full','nao')");
$link->query("INSERT INTO sis_perm VALUES ('perm_clientes','full','sim'),('perm_clientes','restricted','sim'),('perm_clientes','empty','sim'),('perm_clientes','quoted','sim')");
$link->query('CREATE TEMPORARY TABLE map_fixture_clients (login VARCHAR(64),grupo VARCHAR(64))');
$link->query("INSERT INTO map_fixture_clients VALUES ('visible','A'),('hidden','B')");
$_SESSION=array('MKA_Usuario'=>'restricted');
check_map_policy(!map_can_view_totals(), 'Restricted totals leaked');
$scope=map_client_scope_sql('c');
$result=$link->query('SELECT c.login FROM map_fixture_clients c WHERE '.$scope);
check_map_policy($result && $result->num_rows===1 && $result->fetch_assoc()['login']==='visible', 'Group restriction failed');
$clients=array(array('status'=>'online','has_coordinates'=>true),array('status'=>'offline','has_coordinates'=>false));
check_map_policy(map_api_summary($clients,false)===null, 'API summary leaked without Totais');
check_map_policy(count($clients)===2, 'Visible records removed when hiding totals');
$_SESSION=array('MM_Usuario'=>'full');
check_map_policy(map_can_view_totals() && map_client_scope_sql('c')==='1=1', 'Legacy full operator failed');
$summary=map_api_summary($clients,true);
check_map_policy($summary['total']===2 && $summary['online']===1 && $summary['unmapped']===1, 'Allowed totals incorrect');
$_SESSION=array('MKA_Usuario'=>'empty');check_map_policy(map_client_scope_sql('c')==='1=1','Native empty-groups rule changed');
$_SESSION=array('MKA_Usuario'=>'unknown');check_map_policy(map_client_scope_sql('c')==='0=1' && !map_can_view_totals(),'Unknown operator permitted');
$_SESSION=array();check_map_policy(map_client_scope_sql('c')==='0=1' && !map_can_view_totals(),'Anonymous operator permitted');
$_SESSION=array('MKA_Usuario'=>'quoted');check_map_policy(strpos(map_client_scope_sql('c'),"O\\'Brien")!==false,'Group literal not escaped');
echo "PASS Totais granted/denied, API redaction, native groups, legacy identity and unknown/anonymous denial.\n";
