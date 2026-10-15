(function(){'use strict';
 function el(tag,cls,text){const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;}
 let logModal=null,logFocus=null,logRequest=null;
 function closeClientLog(){if(logRequest)logRequest.abort();logRequest=null;if(!logModal)return;const modal=logModal;logModal=null;modal.remove();if(logFocus&&logFocus.isConnected)logFocus.focus();logFocus=null;}
 function connectionRows(doc,login){
  const clean=node=>node.textContent.replace(/\s+/g,' ').trim(),norm=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase();
  for(const table of doc.querySelectorAll('table')){
   let columns=null;const rows=[];
   for(const row of table.querySelectorAll('tr')){
    const cells=Array.from(row.children).filter(cell=>['TD','TH'].includes(cell.tagName));if(!cells.length)continue;
    const values=cells.map(clean),labels=values.map(norm);
    if(labels.includes('LOGIN')&&labels.includes('HORA INICIAL')&&labels.includes('HORA FINAL')){columns=labels;continue;}
    if(!columns||values.length<columns.length)continue;
    const owner=values[columns.indexOf('LOGIN')];if(!owner||!(owner.toLowerCase()===login.toLowerCase()||owner.toLowerCase().startsWith(login.toLowerCase()+' ')))continue;
    const record={};for(const [label,field] of [['HORA INICIAL','Início'],['HORA FINAL','Fim'],['DURACAO','Duração'],['TRAFEGO','Tráfego'],['IP','IP'],['IPV6 PD','IPv6 PD']]){const index=columns.indexOf(label);if(index>=0)record[field]=values[index]||'—';}rows.push(record);if(rows.length===10)return rows;
   }
   if(columns)return rows;
  }
  throw Error('Não foi possível identificar o histórico de conexões nesta versão.');
 }
 function openClientLog(link,login,reportUrl){
  const url=new URL(reportUrl||link.getAttribute('href'),document.baseURI);if(url.origin!==location.origin||!/\/relatorios_u\.(hhvm|php)$/.test(url.pathname))return;
  closeClientLog();logFocus=link;const modal=el('dialog','mka-client-log-modal'),header=el('header'),title=el('span','','Últimas conexões — '+login),close=el('button','','Fechar'),content=el('div','mka-client-log-summary','Carregando conexões…');
  modal.setAttribute('aria-label','Últimas conexões de '+login);close.type='button';close.setAttribute('aria-label','Fechar log do cliente');header.append(title,close);modal.append(header,content);document.body.append(modal);logModal=modal;
  close.addEventListener('click',()=>modal.close());modal.addEventListener('close',closeClientLog);modal.addEventListener('click',event=>{if(event.target===modal){const rect=modal.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)modal.close();}});modal.showModal();close.focus();
  const request=new AbortController();logRequest=request;const timeout=setTimeout(()=>request.abort(),15000);
  fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:request.signal}).then(response=>{if(!response.ok)throw Error('Não foi possível consultar as conexões ('+response.status+').');return response.text();}).then(html=>{
   if(logModal!==modal)return;const rows=connectionRows(new DOMParser().parseFromString(html,'text/html'),login);content.replaceChildren();content.append(el('p','mka-client-log-note','Até 10 conexões recentes deste login. Tráfego conforme o registro da sessão.'));
   if(!rows.length){content.append(el('p','','Nenhuma conexão encontrada para este login.'));return;}
   rows.forEach((record,index)=>{const ongoing=record['Fim']==='—',card=el('article','mka-connection-card'+(index===0?(ongoing?' is-current-online':' is-current-offline'):''));
    if(index===0)card.append(el('div','mka-connection-state',ongoing?'● Conectado · sessão em andamento':'● Desconectado · última sessão encerrada'));
    for(const [label,value] of Object.entries(record)){const field=el('div','mka-connection-field');field.append(el('b','',label),el('span','',label==='Fim'&&ongoing?'Em andamento':value));card.append(field);}content.append(card);
   });
  }).catch(error=>{if(logModal===modal)content.textContent=error.name==='AbortError'?'A consulta demorou. Feche e abra novamente para tentar outra vez.':error.message;}).finally(()=>{clearTimeout(timeout);if(logRequest===request)logRequest=null;});
 }

 if(!window.mkaClientConnectionLog)window.mkaClientConnectionLog=function(login,trigger){openClientLog(trigger,login,'/admin/relatorios_u.hhvm?login='+encodeURIComponent(login));return false;};
 document.addEventListener('click',function(e){const link=e.target.closest('.client-log-btn[data-log-login]');if(!link||e.defaultPrevented)return;e.preventDefault();window.mkaClientConnectionLog(link.dataset.logLogin,link);});
 window.addEventListener('pagehide',closeClientLog);
}());
