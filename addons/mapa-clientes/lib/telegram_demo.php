<?php
require_once __DIR__.'/telegram_engine.php';
function mt_demo($config,$kind,$transport='mt_api') {
    if(!in_array($kind,array('connect','disconnect','recovered','mass','partial','resolved'),true))throw new RuntimeException('Demonstração inválida.');
    $group=mt_validate_group($config,$transport);$config['chat_id']=$group['id'];
    $lock=fopen(mt_dir().'/demo.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Outro teste está sendo enviado. Aguarde.');
    try {
        $store=mt_read('demonstrations');$key=mt_fingerprint($config);$state=isset($store[$key])?$store[$key]:array();$reply=null;
        $texts=array(
          'connect'=>"🧪 TESTE — CLIENTE CONECTADO\nNome: Cliente Demonstração\nLogin: cliente_teste\nContrato: ✅ Ativo\nBairro: Bairro Teste\nExemplo fictício; nenhum cliente real foi alterado.",
          'disconnect'=>"🧪 TESTE — CLIENTE DESCONECTADO\nNome: Cliente Demonstração\nLogin: cliente_teste\nContrato: ✅ Ativo\nBairro: Bairro Teste\nExemplo fictício; nenhum cliente real foi desconectado.",
          'recovered'=>"🧪 TESTE — CLIENTE VOLTOU\nNome: Cliente Demonstração\nLogin: cliente_teste\nContrato: ✅ Ativo\nTempo offline: 4 minutos (simulado).\nResposta ao teste de desconexão, sem alterar clientes reais.",
          'mass'=>"🧪 TESTE — QUEDA MASSIVA\n🚨 10 clientes desconectaram em até 2 minutos (simulado).\nOcorrência de demonstração; nenhum cliente real foi alterado.",
          'partial'=>"🧪 TESTE — RECUPERAÇÃO PARCIAL\n🟡 8 de 10 clientes voltaram.\nAinda offline: 2 (simulado).\nAcompanhamento da ocorrência de demonstração.",
          'resolved'=>"🧪 TESTE — OCORRÊNCIA NORMALIZADA\n✅ 10/10 clientes recuperados.\nDuração: 12 minutos (simulado).\nEncerramento da ocorrência de demonstração."
        );
        $now=time();$sample=array('name'=>'Cliente Demonstração','login'=>'cliente_teste','blocked'=>false,'cto'=>'342','port'=>'01','district'=>'Jardim Leone (TESTE)','street'=>'Rua de Demonstração','number'=>'123','connected_epoch'=>$now-5,'disconnected_epoch'=>$now-245);
        $texts['connect']="🧪 TESTE — DADOS FICTÍCIOS\n".mt_message(array('kind'=>'connect','at'=>$now,'client'=>$sample),$config);
        $drop=$sample;$drop['disconnected_epoch']=$now-245;
        $texts['disconnect']="🧪 TESTE — DADOS FICTÍCIOS\n".mt_message(array('kind'=>'disconnect','at'=>$now,'client'=>$drop),$config);
        $texts['recovered']="🧪 TESTE — DADOS FICTÍCIOS\n".mt_message(array('kind'=>'connect','at'=>$now,'client'=>$sample,'offline_seconds'=>240,'offline_estimated'=>false),$config);
        $outages=array();for($i=0;$i<10;$i++){$fake=$sample;$fake['blocked']=$i>=7;$fake['district']=$i<7?'Jardim Leone (TESTE)':'Centro (TESTE)';$fake['cto']=$i<7?'342':'120';$fake['disconnected_epoch']=$now-(9-$i)*5;$outages[]=array('kind'=>'disconnect','at'=>$now,'client'=>$fake);}
        $texts['mass']="🧪 TESTE — DADOS FICTÍCIOS\n".mt_message(array('kind'=>'mass','at'=>$now,'count'=>10,'outages'=>$outages),$config);
        if($kind==='recovered'){$reply=isset($state['individual'])?$state['individual']:null;if(!$reply||time()-$reply['at']>86400)throw new RuntimeException('Envie primeiro o teste Cliente desconectou para responder à mensagem dele.');}
        if($kind==='partial'||$kind==='resolved'){$reply=isset($state['mass'])?$state['mass']:null;if(!$reply||time()-$reply['at']>86400)throw new RuntimeException('Envie primeiro o teste Queda massiva para responder à mensagem dele.');}
        $params=array('chat_id'=>$group['id'],'text'=>$texts[$kind],'disable_notification'=>'true');
        if($reply)$params['reply_parameters']=json_encode(array('message_id'=>$reply['id'],'allow_sending_without_reply'=>false));
        $message=call_user_func($transport,$config['token'],'sendMessage',$params);
        if(empty($message['message_id']))throw new RuntimeException('Telegram não confirmou a mensagem de teste.');
        if($kind==='disconnect')$state['individual']=array('id'=>(int)$message['message_id'],'at'=>time());
        if($kind==='mass')$state['mass']=array('id'=>(int)$message['message_id'],'at'=>time());
        $store[$key]=$state;if(count($store)>20)$store=array_slice($store,-20,null,true);mt_write('demonstrations',$store);
        return array('message'=>'Demonstração enviada ao grupo'.($reply?' como resposta ao teste da queda.':'.'),'kind'=>$kind,'message_id'=>(int)$message['message_id'],'chat'=>$group);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
