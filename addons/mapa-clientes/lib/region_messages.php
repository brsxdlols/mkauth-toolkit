<?php
function map_message_selection($raw) {
    $items=json_decode($raw,true);
    if(!is_array($items)||!$items||count($items)>500)throw new RuntimeException('Selecione de 1 a 500 clientes por composição de mensagem.');
    $selected=array();
    foreach($items as $item){
        if(!is_array($item)||!isset($item['id'],$item['client_kind'])||!in_array($item['client_kind'],array('primary','additional'),true))throw new RuntimeException('Seleção inválida. Atualize o Mapa.');
        $id=filter_var($item['id'],FILTER_VALIDATE_INT);
        if($id===false||$id<1)throw new RuntimeException('Cliente inválido na seleção.');
        $selected[$item['client_kind'].':'.$id]=array('id'=>$id,'client_kind'=>$item['client_kind']);
    }
    return $selected;
}
function map_message_recipients($selected) {
    global $link;
    if(!permissao('perm_mensagem')||!permissao('perm_clientes')||!map_client_records_available())throw new RuntimeException('Sem permissão para preparar mensagens aos clientes.');
    $found=array();$recipients=array();
    foreach(array('primary','additional') as $kind){
        $ids=array();foreach($selected as $item)if($item['client_kind']===$kind)$ids[]=(int)$item['id'];
        if(!$ids)continue;
        // IDs are validated integers; group scope comes only from the native permission policy.
        $in=implode(',',$ids);
        $sql=$kind==='primary'?'SELECT c.id,c.login FROM sis_cliente c WHERE c.id IN ('.$in.') AND ('.map_client_scope_sql('c').')'
            :'SELECT a.id,c.login FROM sis_adicional a INNER JOIN sis_cliente c ON c.login=a.login WHERE a.id IN ('.$in.') AND ('.map_client_scope_sql('c').')';
        $result=$link->query($sql);if(!$result)throw new RuntimeException('Não foi possível conferir os destinatários.');
        while($row=$result->fetch_assoc()){
            $login=(string)$row['login'];
            // The native composer interpolates its login[] values in SQL. Never pass
            // quotes, backslashes or control characters into that legacy interface.
            if($login===''||preg_match('/[\x00-\x1f\x7f\x27\x5c]/',$login))throw new RuntimeException('Um login contém caracteres não suportados pelo compositor nativo. Confira o cadastro.');
            $found[$kind.':'.$row['id']]=true;$recipients[$login]=$login;
        }
    }
    if(count($found)!==count($selected))throw new RuntimeException('A seleção contém clientes indisponíveis ou fora dos grupos autorizados. Atualize o Mapa e selecione novamente.');
    return array_values($recipients);
}

function map_message_channels(){
    global $link;$channels=array('wapp'=>false,'email'=>false,'sms'=>false);
    if(!permissao('perm_mensagem')||!permissao('perm_clientes'))return $channels;
    $names=array('wapp_servidor'=>'wapp','client_smtp'=>'email','sms_gtw'=>'sms');
    $result=$link->query("SELECT nome,valor FROM sis_opcao WHERE nome IN ('wapp_servidor','client_smtp','sms_gtw')");
    if($result)while($row=$result->fetch_assoc())$channels[$names[$row['nome']]]=trim((string)$row['valor'])!==''&&$row['valor']!=='desativado';
    return $channels;
}
