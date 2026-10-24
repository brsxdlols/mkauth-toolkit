(function () {
 'use strict';
 window.mkaClientInRegion=function(client,bounds){
  return !bounds || (!!client.has_coordinates && client.lat!=null && client.lon!=null && client.lat!=='' && client.lon!=='' && Number.isFinite(Number(client.lat)) && Number.isFinite(Number(client.lon)) && bounds.contains([Number(client.lat),Number(client.lon)]));
 };
 window.mkaMapRegionSelection=function(L,map,onChange,options){
  options=options||{};
  var container=map.getContainer(),bounds=null,selected=null,preview=null,start=null,last=null,armed=false,revealing=false,spaceHeld=false,saved=[],blockClick=false,regions=[],selectedLayers=[],editGroups=[],editing=null,panStart=null,panMoved=false;
  var control=L.control({position:'bottomleft'}),box,text,clear,main,toolbar,summary,actions,items=[],permissions={},polygonMode=false,vertices=[],vertexLayers=[],polygonButton;
  control.onAdd=function(){
   box=L.DomUtil.create('div','map-region-control');text=document.createElement('span');text.setAttribute('role','status');text.setAttribute('aria-live','polite');
   clear=document.createElement('button');clear.type='button';clear.textContent='Limpar região';clear.disabled=true;
   main=document.createElement('div');main.className='map-region-main';main.appendChild(text);toolbar=document.createElement('div');toolbar.className='map-region-toolbar';polygonButton=document.createElement('button');polygonButton.type='button';polygonButton.textContent='Polígono';polygonButton.addEventListener('click',function(){if(polygonMode){release();return;}release();polygonMode=true;arm();beginReveal();status();});toolbar.appendChild(polygonButton);toolbar.appendChild(clear);summary=document.createElement('div');summary.className='map-region-summary';summary.setAttribute('aria-live','polite');actions=document.createElement('div');actions.className='map-region-actions';box.appendChild(main);box.appendChild(toolbar);L.DomEvent.disableClickPropagation(box);L.DomEvent.disableScrollPropagation(box);
   clear.addEventListener('click',function(){reset(true);});status();return box;
  };
  function status(){if(!text)return;polygonButton.textContent=polygonMode?'Cancelar desenho':'Polígono';polygonButton.setAttribute('aria-pressed',polygonMode?'true':'false');text.textContent=revealing?'Todos os clientes dos filtros atuais estão visíveis para acrescentar uma área. Ajuste os pontos e pressione Enter para mostrar só a seleção.':polygonMode?'Clique nos pontos do mapa. Arraste um ponto para ajustar. Feche no primeiro ponto ou Enter. Sem Espaço, arraste o fundo para navegar.':armed?'Arraste para retângulo ou clique para iniciar um polígono.':bounds?'Arraste os pontos para ajustar · Espaço para acrescentar. Esc ou Limpar região remove tudo.':'Segure Espaço: arraste para retângulo ou clique para polígono.';clear.disabled=!bounds&&!polygonMode;}
  function setClients(clients,allowed){
   items=clients;permissions=allowed||{};
   if(bounds&&permissions.canViewTotals===true){
    var online=items.filter(function(c){return c.status==='online';}).length,offline=items.filter(function(c){return c.status==='offline';}).length;
    summary.textContent='Selecionados: '+items.length+' · Online: '+online+' · Offline: '+offline;main.appendChild(summary);
   }else{summary.textContent='';summary.remove();}
   if(bounds&&permissions.canMessage===true){
    if(!actions.children.length)[['wapp','WhatsApp'],['email','E-mail'],['sms','SMS']].forEach(function(pair){var button=document.createElement('button');button.type='button';button.dataset.channel=pair[0];button.textContent=pair[1];button.title='Abrir composição nativa de '+pair[1]+' para os clientes selecionados';button.addEventListener('click',function(){if(!revealing&&items.length&&typeof options.onMessage==='function')options.onMessage(pair[0]);});actions.appendChild(button);});
    Array.from(actions.children).forEach(function(button){var enabled=!permissions.channels||permissions.channels[button.dataset.channel]===true;button.disabled=revealing||!items.length||!enabled;button.title=enabled?'Abrir composição nativa de '+button.textContent+' para os clientes selecionados':'Canal não configurado no MK-AUTH';});toolbar.insertBefore(actions,clear);
   }else{actions.replaceChildren();actions.remove();}
  }
  function beginReveal(){if(revealing)return;revealing=true;onChange(bounds,true);status();}
  function endReveal(notify){if(!revealing)return;revealing=false;if(notify!==false)onChange(bounds,false);status();}
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
   if(valid){var shape=preview;preview=null;commitRegion(shape,shape.getBounds());onChange(bounds,revealing);}
   cancel();status();
  }
  function commitRegion(shape,region){selectedLayers.push(shape);regions.push(region);bounds={contains:function(point){return regions.some(function(part){return part.contains(point);});}};}
  function clearVertices(){vertexLayers.forEach(function(layer){map.removeLayer(layer);});vertexLayers=[];vertices=[];}
  function release(notify){editing=null;panStart=null;panMoved=false;spaceHeld=false;cancel();clearVertices();polygonMode=false;restore();endReveal(notify);status();}
  function polygonContains(ring,point){
   var x=Number(point[1]),y=Number(point[0]),inside=false;
   for(var i=0,j=ring.length-1;i<ring.length;j=i++){
    var a=ring[j],b=ring[i],dx=b.lng-a.lng,dy=b.lat-a.lat,cross=(x-a.lng)*dy-(y-a.lat)*dx;
    if(Math.abs(cross)<1e-10&&x>=Math.min(a.lng,b.lng)-1e-10&&x<=Math.max(a.lng,b.lng)+1e-10&&y>=Math.min(a.lat,b.lat)-1e-10&&y<=Math.max(a.lat,b.lat)+1e-10)return true;
    if((a.lat>y)!==(b.lat>y)&&x<(b.lng-a.lng)*(y-a.lat)/(b.lat-a.lat)+a.lng)inside=!inside;
   }return inside;
  }
  function finishPolygon(){
   if(vertices.length<3)return;
   var ring=vertices.slice(),area=0;ring.forEach(function(a,i){var b=ring[(i+1)%ring.length];area+=a.lng*b.lat-b.lng*a.lat;});if(Math.abs(area)<1e-12)return;
   var shape=preview;preview=null;commitRegion(shape,{contains:function(point){return polygonContains(ring,point);}});
   editGroups.push({ring:ring,shape:shape,handles:vertexLayers.slice()});vertexLayers=[];vertices=[];polygonMode=false;status();if(!spaceHeld)restore();onChange(bounds,revealing);
  }
  function polygonClick(event){
   var pos=point(event),latlng=map.containerPointToLatLng(pos);
   if(vertices.length>=3&&map.latLngToContainerPoint(vertices[0]).distanceTo(pos)<=12){finishPolygon();return;}
   if(vertices.length&&map.latLngToContainerPoint(vertices[vertices.length-1]).distanceTo(pos)<3)return;
   vertices.push(latlng);vertexLayers.push(L.circleMarker(latlng,{radius:5,color:'#1769aa',fillColor:'#fff',fillOpacity:1,interactive:false}).addTo(map));
   if(preview)preview.setLatLngs(vertices);else preview=L.polygon(vertices,{color:'#1769aa',weight:2,fillOpacity:0.15,interactive:false}).addTo(map);
  }
  function reset(notify){release(false);editGroups.forEach(function(group){group.handles.forEach(function(handle){map.removeLayer(handle);});});editGroups=[];selectedLayers.forEach(function(shape){map.removeLayer(shape);});selectedLayers=[];regions=[];selected=null;bounds=null;status();if(notify)onChange(null);}
  function point(event){return map.mouseEventToContainerPoint(event);}
  function update(event){last=point(event);preview.setBounds(L.latLngBounds(map.containerPointToLatLng(start),map.containerPointToLatLng(last)));}
  function keydown(event){
   if(event.key==='Enter'&&!editable(event.target)&&(polygonMode||revealing)){event.preventDefault();if(polygonMode){finishPolygon();if(polygonMode)return;}endReveal();if(!spaceHeld)restore();return;}
   if(event.key==='Escape'&&(bounds||armed||polygonMode)){event.preventDefault();reset(true);return;}
   if((event.code!=='Space'&&event.key!==' ')||event.repeat||editable(event.target)||!available())return;
   spaceHeld=true;
   event.preventDefault();event.stopPropagation();arm();beginReveal();
  }
  function keyup(event){if(event.code==='Space'||event.key===' '){spaceHeld=false;if(armed){event.preventDefault();if(!polygonMode)finish();restore();}}}
  function down(event){
   blockClick=false;panMoved=false;
   if(event.button!==0||event.target.closest('.leaflet-control,.leaflet-popup'))return;
   var pos=point(event),groups=editGroups.slice();if(polygonMode)groups.push({ring:vertices,shape:preview,handles:vertexLayers,draft:true});
   for(var g=groups.length-1;g>=0;g--){for(var i=0;i<groups[g].ring.length;i++){if(map.latLngToContainerPoint(groups[g].ring[i]).distanceTo(pos)<=9){editing={group:groups[g],index:i,start:pos,moved:false};arm();event.preventDefault();event.stopImmediatePropagation();return;}}}
   if(polygonMode){if(spaceHeld)arm();else{panStart=pos;panMoved=false;}return;}
   if(!armed||event.button!==0||event.target.closest('.leaflet-control,.leaflet-popup'))return;
   event.preventDefault();event.stopImmediatePropagation();beginReveal();cancel();start=last=point(event);
   preview=L.rectangle(L.latLngBounds(map.containerPointToLatLng(start),map.containerPointToLatLng(start)),{color:'#1769aa',weight:2,fillColor:'#2684ff',fillOpacity:0.15,interactive:false}).addTo(map);
  }
  function move(event){
   if(editing){event.preventDefault();event.stopPropagation();var pos=point(event);if(pos.distanceTo(editing.start)<3&&!editing.moved)return;editing.moved=true;var group=editing.group,ll=map.containerPointToLatLng(pos);group.ring[editing.index]=ll;if(group.draft)vertices=group.ring;group.handles[editing.index].setLatLng(ll);if(group.shape)group.shape.setLatLngs(group.ring);if(!group.draft)onChange(bounds,revealing);return;}
   if(panStart&&!spaceHeld){if(point(event).distanceTo(panStart)>=5)panMoved=true;return;}
   if(!armed||!start)return;event.preventDefault();event.stopPropagation();update(event);}
  function up(event){
   if(editing){var moved=editing.moved;editing=null;if(!spaceHeld)restore();if(moved){event.preventDefault();event.stopPropagation();blockClick=true;}return;}
   if(panStart){panStart=null;if(panMoved){blockClick=true;}return;}
   if(!armed||!start)return;event.preventDefault();event.stopPropagation();update(event);blockClick=true;if(last.distanceTo(start)<5){cancel();polygonMode=true;polygonClick(event);status();}else finish();}
  function click(event){if(blockClick&&!event.target.closest('.leaflet-control')){blockClick=false;event.preventDefault();event.stopImmediatePropagation();return;}if(polygonMode&&!event.target.closest('.leaflet-control,.leaflet-popup')){event.preventDefault();event.stopImmediatePropagation();polygonClick(event);if(!spaceHeld)restore();return;}if(armed||blockClick){if(!event.target.closest('.leaflet-control')){event.preventDefault();event.stopImmediatePropagation();}}}
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
