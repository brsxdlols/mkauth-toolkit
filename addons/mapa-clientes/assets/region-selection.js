(function () {
 'use strict';
 window.mkaClientInRegion=function(client,bounds){
  return !bounds || (!!client.has_coordinates && client.lat!=null && client.lon!=null && client.lat!=='' && client.lon!=='' && Number.isFinite(Number(client.lat)) && Number.isFinite(Number(client.lon)) && bounds.contains([Number(client.lat),Number(client.lon)]));
 };
 window.mkaMapRegionSelection=function(L,map,onChange,options){
  options=options||{};
  var container=map.getContainer(),bounds=null,selected=null,preview=null,start=null,last=null,armed=false,saved=[],blockClick=false;
  var control=L.control({position:'bottomleft'}),box,text,clear,main,toolbar,summary,actions,items=[],permissions={};
  control.onAdd=function(){
   box=L.DomUtil.create('div','map-region-control');text=document.createElement('span');text.setAttribute('role','status');text.setAttribute('aria-live','polite');
   clear=document.createElement('button');clear.type='button';clear.textContent='Limpar região';clear.disabled=true;
   main=document.createElement('div');main.className='map-region-main';main.appendChild(text);toolbar=document.createElement('div');toolbar.className='map-region-toolbar';toolbar.appendChild(clear);summary=document.createElement('div');summary.className='map-region-summary';summary.setAttribute('aria-live','polite');actions=document.createElement('div');actions.className='map-region-actions';box.appendChild(main);box.appendChild(toolbar);L.DomEvent.disableClickPropagation(box);L.DomEvent.disableScrollPropagation(box);
   clear.addEventListener('click',function(){reset(true);});status();return box;
  };
  function status(){if(!text)return;text.textContent=armed?'Arraste dentro do mapa para selecionar. Solte Espaço para navegar.':bounds?'Região selecionada · Esc ou Limpar região para remover.':'Segure Espaço + arraste no mapa para selecionar uma região.';clear.disabled=!bounds;}
  function setClients(clients,allowed){
   items=clients;permissions=allowed||{};
   if(bounds&&permissions.canViewTotals===true){
    var online=items.filter(function(c){return c.status==='online';}).length,offline=items.filter(function(c){return c.status==='offline';}).length;
    summary.textContent='Selecionados: '+items.length+' · Online: '+online+' · Offline: '+offline;main.appendChild(summary);
   }else{summary.textContent='';summary.remove();}
   if(bounds&&permissions.canMessage===true){
    if(!actions.children.length)[['wapp','WhatsApp'],['email','E-mail'],['sms','SMS']].forEach(function(pair){var button=document.createElement('button');button.type='button';button.dataset.channel=pair[0];button.textContent=pair[1];button.title='Abrir composição nativa de '+pair[1]+' para os clientes selecionados';button.addEventListener('click',function(){if(items.length&&typeof options.onMessage==='function')options.onMessage(pair[0]);});actions.appendChild(button);});
    Array.from(actions.children).forEach(function(button){var enabled=!permissions.channels||permissions.channels[button.dataset.channel]===true;button.disabled=!items.length||!enabled;button.title=enabled?'Abrir composição nativa de '+button.textContent+' para os clientes selecionados':'Canal não configurado no MK-AUTH';});toolbar.insertBefore(actions,clear);
   }else{actions.replaceChildren();actions.remove();}
  }
  function editable(target){return !!(target&&target.closest&&target.closest('input,textarea,select,button,a,[role="button"],[contenteditable]:not([contenteditable="false"])'));}
  function available(){return !document.body.classList.contains('monitor-client')&&!document.querySelector('dialog[open],.guide-modal.show,#coordinate-modal.show');}
  function arm(){
   if(armed)return;armed=true;saved=[];
   ['dragging','touchZoom','doubleClickZoom','scrollWheelZoom','boxZoom','keyboard'].forEach(function(name){var handler=map[name];if(handler&&handler.enabled){saved.push([handler,handler.enabled()]);handler.disable();}});
   map.stop();container.classList.add('map-region-armed');status();
  }
  function restore(){if(!armed)return;armed=false;saved.forEach(function(pair){if(pair[1])pair[0].enable();});saved=[];container.classList.remove('map-region-armed');status();}
  function cancel(){if(preview)map.removeLayer(preview);preview=null;start=last=null;}
  function finish(){
   if(!start||!last){cancel();return;}
   var valid=Math.abs(last.x-start.x)>=5&&Math.abs(last.y-start.y)>=5;
   if(valid){if(selected)map.removeLayer(selected);selected=preview;preview=null;bounds=selected.getBounds();onChange(bounds);}
   cancel();status();
  }
  function release(){cancel();restore();}
  function reset(notify){release();if(selected)map.removeLayer(selected);selected=null;bounds=null;status();if(notify)onChange(null);}
  function point(event){return map.mouseEventToContainerPoint(event);}
  function update(event){last=point(event);preview.setBounds(L.latLngBounds(map.containerPointToLatLng(start),map.containerPointToLatLng(last)));}
  function keydown(event){
   if(event.key==='Escape'&&(bounds||armed)){event.preventDefault();reset(true);return;}
   if((event.code!=='Space'&&event.key!==' ')||event.repeat||editable(event.target)||!available())return;
   event.preventDefault();event.stopPropagation();arm();
  }
  function keyup(event){if(event.code==='Space'||event.key===' '){if(armed){event.preventDefault();finish();restore();}}}
  function down(event){
   if(!armed||event.button!==0||event.target.closest('.leaflet-control,.leaflet-popup'))return;
   event.preventDefault();event.stopImmediatePropagation();cancel();start=last=point(event);
   preview=L.rectangle(L.latLngBounds(map.containerPointToLatLng(start),map.containerPointToLatLng(start)),{color:'#1769aa',weight:2,fillColor:'#2684ff',fillOpacity:0.15,interactive:false}).addTo(map);
  }
  function move(event){if(!armed||!start)return;event.preventDefault();event.stopPropagation();update(event);}
  function up(event){if(!armed||!start)return;event.preventDefault();event.stopPropagation();update(event);blockClick=true;finish();setTimeout(function(){blockClick=false;},0);}
  function click(event){if(armed||blockClick){if(!event.target.closest('.leaflet-control')){event.preventDefault();event.stopImmediatePropagation();}}}
  function visibility(){if(document.hidden)release();}
  document.addEventListener('keydown',keydown,true);document.addEventListener('keyup',keyup,true);
  container.addEventListener('pointerdown',down,true);document.addEventListener('pointermove',move,true);document.addEventListener('pointerup',up,true);container.addEventListener('click',click,true);
  window.addEventListener('blur',release);document.addEventListener('visibilitychange',visibility);window.addEventListener('pagehide',release);document.addEventListener('pointercancel',release,true);
  control.addTo(map);
  return {setClients:setClients,clear:function(notify){reset(notify!==false);},getBounds:function(){return bounds;},destroy:function(){
   reset(false);document.removeEventListener('keydown',keydown,true);document.removeEventListener('keyup',keyup,true);container.removeEventListener('pointerdown',down,true);document.removeEventListener('pointermove',move,true);document.removeEventListener('pointerup',up,true);container.removeEventListener('click',click,true);window.removeEventListener('blur',release);document.removeEventListener('visibilitychange',visibility);window.removeEventListener('pagehide',release);document.removeEventListener('pointercancel',release,true);control.remove();
  }};
 };
}());
