(function () {
    'use strict';

    var TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    function loadAsset(type, url, id) {
        return new Promise(function (resolve, reject) {
            if (document.getElementById(id)) { resolve(); return; }
            var el = document.createElement(type === 'js' ? 'script' : 'link');
            el.id = id;
            if (type === 'js') { el.src = url; el.onload = resolve; }
            else { el.rel = 'stylesheet'; el.href = url; el.onload = resolve; }
            el.onerror = reject;
            document.head.appendChild(el);
        });
    }

    function initBatchCoordinates() {
        if (window.location.pathname.indexOf('conf_mapas.hhvm') === -1 || document.getElementById('geo_batch_open')) return;
        var submit = document.querySelector('button[type="submit"],input[type="submit"]');
        if (!submit) return;

        var style = document.createElement('style');
        style.textContent =
            '#geo_batch_modal{position:fixed;inset:0;background:rgba(0,0,0,.58);z-index:99999;display:none;padding:4vh 4vw}' +
            '#geo_batch_card{background:#fff;max-width:1250px;height:92vh;margin:auto;border-radius:6px;display:flex;flex-direction:column;overflow:hidden}' +
            '#geo_batch_header,#geo_batch_footer{padding:12px 16px;display:flex;gap:10px;align-items:center;border-bottom:1px solid #ddd}' +
            '#geo_batch_footer{border-top:1px solid #ddd;border-bottom:0;justify-content:space-between}' +
            '#geo_batch_body{padding:12px 16px;overflow:auto;flex:1}' +
            '#geo_batch_table{width:100%;font-size:12px;border-collapse:collapse}' +
            '#geo_batch_table th,#geo_batch_table td{padding:7px;border-bottom:1px solid #e7e7e7;text-align:left;vertical-align:top}' +
            '#geo_batch_table tr.geo_ok{background:#ecfff6}#geo_batch_table tr.geo_fail{background:#fff0f0}' +
            '#geo_batch_summary{padding:9px 14px;background:#e8f7ff;color:#175b75}' +
            '@media(max-width:700px){#geo_batch_modal{padding:0}#geo_batch_card{height:100vh;border-radius:0}}';
        document.head.appendChild(style);

        var open = document.createElement('button');
        open.type = 'button';
        open.id = 'geo_batch_open';
        open.className = 'button is-warning';
        open.style.marginLeft = '8px';
        open.textContent = 'Atualizar coordenadas de todos os clientes';
        submit.parentNode.appendChild(open);

        var modal = document.createElement('div');
        modal.id = 'geo_batch_modal';
        modal.innerHTML = '<div id="geo_batch_card">' +
            '<div id="geo_batch_header"><strong>Atualização de coordenadas em lote</strong><span style="flex:1"></span>' +
            '<button type="button" class="button" id="geo_batch_close">Fechar</button></div>' +
            '<div id="geo_batch_summary">Consulte os clientes antes de confirmar. Coordenadas existentes nunca serão alteradas.</div>' +
            '<div id="geo_batch_body"><p>Carregando clientes…</p></div>' +
            '<div id="geo_batch_footer"><label><input type="checkbox" id="geo_batch_all" checked> Selecionar todos</label>' +
            '<span id="geo_batch_progress">Aguardando consulta.</span>' +
            '<button type="button" class="button is-primary" id="geo_batch_confirm" disabled>Confirmar e atualizar selecionados</button></div></div>';
        document.body.appendChild(modal);

        var body = document.getElementById('geo_batch_body');
        var summary = document.getElementById('geo_batch_summary');
        var progress = document.getElementById('geo_batch_progress');
        var confirmButton = document.getElementById('geo_batch_confirm');
        var allCheckbox = document.getElementById('geo_batch_all');
        var running = false;

        function api(action, fields) {
            var data = new URLSearchParams();
            Object.keys(fields || {}).forEach(function(key){ data.append(key, fields[key]); });
            return fetch('addons/geocodificacao/geocode.php?action=' + encodeURIComponent(action), {
                method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-MKAUTH-Batch':'1'}, body:data.toString()
            }).then(function(response){
                return response.json().catch(function(){ throw new Error('Resposta inválida do servidor.'); }).then(function(data){
                    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
                    return data;
                });
            });
        }

        function selectedBoxes() {
            return Array.prototype.slice.call(body.querySelectorAll('.geo_batch_select:checked'));
        }
        function updateSelection() {
            var count = selectedBoxes().length;
            confirmButton.disabled = running || count === 0;
            progress.textContent = count + ' cliente(s) selecionado(s).';
        }
        function clientAddress(client) {
            return [client.endereco, client.numero, client.bairro, client.cidade, client.estado].filter(Boolean).join(', ');
        }
        function loadPreview() {
            running = false; confirmButton.disabled = true; body.innerHTML = '<p>Consultando clientes sem coordenadas…</p>';
            api('batch_preview', {limit:1000}).then(function(data){
                summary.textContent = data.total + ' cliente(s) sem coordenadas e com CEP válido. Exibindo ' + data.clients.length + ' para conferência.';
                if (!data.clients.length) { body.innerHTML = '<p>Nenhum cliente elegível encontrado.</p>'; progress.textContent = 'Nada para atualizar.'; return; }
                var table = document.createElement('table'); table.id = 'geo_batch_table';
                table.innerHTML = '<thead><tr><th></th><th>ID</th><th>Cliente</th><th>CEP</th><th>Endereço cadastrado</th><th>Status</th></tr></thead><tbody></tbody>';
                var tbody = table.querySelector('tbody');
                data.clients.forEach(function(client){
                    var tr = document.createElement('tr'); tr.setAttribute('data-client-id', client.id);
                    var tdCheck = document.createElement('td');
                    var check = document.createElement('input'); check.type='checkbox'; check.checked=true; check.className='geo_batch_select'; check.value=client.id; check.addEventListener('change', updateSelection); tdCheck.appendChild(check);
                    var values = [client.id, client.nome || client.login, client.cep, clientAddress(client), 'Pronto para consultar'];
                    tr.appendChild(tdCheck);
                    values.forEach(function(value){ var td=document.createElement('td'); td.textContent=value || ''; tr.appendChild(td); });
                    tbody.appendChild(tr);
                });
                body.innerHTML=''; body.appendChild(table); allCheckbox.checked=true; updateSelection();
            }).catch(function(error){ body.innerHTML='<p class="has-text-danger"></p>'; body.firstChild.textContent=error.message; progress.textContent='Falha na consulta.'; });
        }

        function processSelected() {
            var boxes = selectedBoxes();
            if (!boxes.length || running) return;
            if (!window.confirm('Confirma a busca e gravação das coordenadas para ' + boxes.length + ' cliente(s)? Coordenadas existentes não serão alteradas.')) return;
            running=true; confirmButton.disabled=true; allCheckbox.disabled=true;
            var index=0, updated=0, failed=0, ignored=0;
            function next() {
                if (index >= boxes.length) {
                    running=false; allCheckbox.disabled=false; confirmButton.disabled=false;
                    progress.textContent='Concluído: ' + updated + ' atualizado(s), ' + ignored + ' ignorado(s), ' + failed + ' falha(s).';
                    summary.textContent='Processamento concluído. Revise os status abaixo antes de fechar.'; return;
                }
                var box=boxes[index++], row=body.querySelector('tr[data-client-id="' + box.value + '"]'), statusCell=row.cells[row.cells.length-1];
                statusCell.textContent='Consultando…'; progress.textContent='Processando ' + index + ' de ' + boxes.length + '…';
                if (row && typeof row.scrollIntoView === 'function') row.scrollIntoView({block:'center',behavior:'smooth'});
                api('batch_process', {id:box.value,confirm:'1'}).then(function(data){
                    if(data.status==='updated'){updated++;row.className='geo_ok';statusCell.textContent='Atualizado: '+data.coordinates+' ('+data.precision+')';}
                    else{ignored++;statusCell.textContent=data.message||'Ignorado';}
                }).catch(function(error){failed++;row.className='geo_fail';statusCell.textContent='Falha: '+error.message;}).then(next);
            }
            next();
        }

        open.addEventListener('click', function(){ modal.style.display='block'; loadPreview(); });
        document.getElementById('geo_batch_close').addEventListener('click', function(){ if(!running) modal.style.display='none'; });
        allCheckbox.addEventListener('change', function(){ Array.prototype.forEach.call(body.querySelectorAll('.geo_batch_select'),function(box){box.checked=allCheckbox.checked;});updateSelection(); });
        confirmButton.addEventListener('click', processSelected);
    }

    function init() {
        var coord = document.getElementById('coordenadas');
        var form = document.getElementById('form');
        if (!coord || !form || !document.getElementById('endereco')) return;

        var fields = {};
        var residentialIds = { cep:'cep_res', endereco:'endereco_res', numero:'numero_res', bairro:'bairro_res', cidade:'cidade_res', estado:'estado_res' };
        Object.keys(residentialIds).forEach(function (id) { fields[id] = document.getElementById(residentialIds[id]); });
        fields.ibge = document.getElementById('cidade_ibge');

        var style = document.createElement('style');
        style.textContent =
            '#geo_modal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:none;padding:3vh 3vw}' +
            '#geo_card{background:#fff;max-width:1100px;height:94vh;margin:auto;border-radius:6px;display:flex;flex-direction:column;overflow:hidden}' +
            '#geo_header{display:flex;gap:8px;padding:12px;align-items:center;border-bottom:1px solid #ddd}' +
            '#geo_tip{padding:10px 14px;background:#e8f7ff;border-bottom:1px solid #b8e4f7;color:#175b75;font-size:13px;line-height:1.35}' +
            '#geo_search{flex:1}' +
            '#geo_body{display:grid;grid-template-columns:minmax(260px,34%) 1fr;min-height:0;flex:1}' +
            '#geo_suggestions{overflow:auto;border-right:1px solid #ddd}' +
            '.geo_item{display:block;width:100%;padding:12px;text-align:left;border:0;border-bottom:1px solid #eee;background:#fff;cursor:pointer}' +
            '.geo_item:hover{background:#f3f7fa}' +
            '#geo_map{min-height:420px}' +
            '#geo_footer{padding:10px 12px;border-top:1px solid #ddd;display:flex;justify-content:space-between;align-items:center}' +
            '.mka_geo_pin{background:transparent!important;border:0!important}' +
            '.mka_geo_pin_shape{width:30px;height:30px;background:#00bfa5;border:3px solid #fff;border-radius:50% 50% 50% 0;box-shadow:0 2px 7px rgba(0,0,0,.45);transform:rotate(-45deg);position:relative}' +
            '.mka_geo_pin_shape:after{content:"";position:absolute;width:9px;height:9px;border-radius:50%;background:#fff;left:8px;top:8px}' +
            '@media(max-width:700px){#geo_modal{padding:0}#geo_card{height:100vh;border-radius:0}#geo_body{grid-template-columns:1fr;grid-template-rows:32% 68%}#geo_suggestions{border-right:0;border-bottom:1px solid #ddd}}';
        document.head.appendChild(style);

        var controls = document.createElement('div');
        controls.className = 'mt-2';
        controls.innerHTML = '<div class="buttons are-small">' +
            '<button type="button" class="button is-info" id="geo_open">Pesquisar no mapa</button>' +
            '<a class="button is-light" id="geo_external" target="_blank" rel="noopener" style="display:none">Abrir posição</a>' +
            '</div><p id="geo_status" class="help">Busca gratuita com Photon/OpenStreetMap; Coordenadas continua editável.</p>';
        coord.parentNode.parentNode.appendChild(controls);

        var modal = document.createElement('div');
        modal.id = 'geo_modal';
        modal.innerHTML = '<div id="geo_card">' +
            '<div id="geo_header"><input id="geo_search" class="input" type="search" placeholder="Digite endereço, número, bairro, cidade e UF">' +
            '<button type="button" class="button" id="geo_close">Fechar</button></div>' +
            '<div id="geo_tip"><strong>\ud83d\udccd Ajuste fino da localiza\u00e7\u00e3o:</strong> depois de localizar o endere\u00e7o, arraste o marcador at\u00e9 o ponto exato do im\u00f3vel. Isso altera somente as coordenadas; o CEP, a rua e o n\u00famero pesquisados ser\u00e3o mantidos.</div>' +
            '<div id="geo_body"><div id="geo_suggestions"><p class="p-4">Digite pelo menos 3 caracteres.</p></div><div id="geo_map"></div></div>' +
            '<div id="geo_footer"><span id="geo_modal_status">Selecione uma sugestão ou arraste o marcador.</span>' +
            '<button type="button" class="button is-primary" id="geo_confirm">Usar esta posição</button></div></div>';
        document.body.appendChild(modal);

        var status = document.getElementById('geo_status');
        var modalStatus = document.getElementById('geo_modal_status');
        var search = document.getElementById('geo_search');
        var suggestions = document.getElementById('geo_suggestions');
        var external = document.getElementById('geo_external');
        var timer = null, controller = null, map = null, marker = null, selected = null;
        var activeProvider = 'osm', googleKey = '', googleMap = null, googleMarker = null;
        var googleAutocomplete = null, googlePlaces = null, googleGeocoder = null, googleScriptPromise = null, googlePlacesAvailable = null;

        function nativeMapSettings() {
            return fetch('conf_mapas.hhvm', { credentials:'same-origin', cache:'no-store' }).then(function(r){
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            }).then(function(html){
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var googleRadio = doc.getElementById('server_maps_google');
                var keyInput = doc.getElementById('key_googlemaps');
                return {
                    provider: googleRadio && googleRadio.hasAttribute('checked') ? 'google' : 'osm',
                    key: keyInput ? String(keyInput.value || '').trim() : ''
                };
            }).catch(function(){ return { provider:'osm', key:'' }; });
        }

        function loadGoogleMaps(key) {
            if (window.google && google.maps && google.maps.places) return Promise.resolve();
            if (googleScriptPromise) return googleScriptPromise;
            googleScriptPromise = new Promise(function(resolve, reject){
                var callback = '__mkaGoogleMapsReady';
                window[callback] = function(){ delete window[callback]; resolve(); };
                var script = document.createElement('script');
                script.id = 'mka-google-maps-js';
                script.async = true;
                script.defer = true;
                script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=places&language=pt-BR&region=BR&callback=' + callback;
                script.onerror = function(){ googleScriptPromise = null; reject(new Error('Google Maps indisponivel')); };
                document.head.appendChild(script);
            });
            return googleScriptPromise;
        }

        function fieldValue(id) {
            var el = fields[id];
            if (!el) return '';
            if (el.tagName === 'SELECT') {
                var o = el.options[el.selectedIndex];
                return o ? o.text.trim() : '';
            }
            return el.value.trim();
        }
        function currentQuery() {
            return ['endereco', 'numero', 'bairro', 'cidade', 'estado', 'cep'].map(fieldValue).filter(Boolean).join(', ');
        }
        function parseCoordinate() {
            var m = coord.value.match(/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/);
            return m ? [parseFloat(m[1]), parseFloat(m[2])] : [-22.9056, -47.0608];
        }
        function setSelect(el, value, text) {
            if (!el || !value) return;
            var normalized = String(value).toLowerCase();
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].value.toLowerCase() === normalized || el.options[i].text.toLowerCase() === normalized || (text && el.options[i].text.toLowerCase() === String(text).toLowerCase())) {
                    el.selectedIndex = i; el.dispatchEvent(new Event('change', { bubbles: true })); return;
                }
            }
            var opt = new Option(text || value, value, true, true);
            el.add(opt); el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function setInput(el, value) {
            if (!el || value === undefined || value === null || value === '') return;
            el.value = value; el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function ufFromState(p) {
            if (p.statecode && p.statecode.indexOf('-') > -1) return p.statecode.split('-').pop().toUpperCase();
            var states = {'São Paulo':'SP','Minas Gerais':'MG','Rio de Janeiro':'RJ','Espírito Santo':'ES','Paraná':'PR','Santa Catarina':'SC','Rio Grande do Sul':'RS','Bahia':'BA','Goiás':'GO','Distrito Federal':'DF','Mato Grosso':'MT','Mato Grosso do Sul':'MS','Tocantins':'TO','Pará':'PA','Amazonas':'AM','Acre':'AC','Rondônia':'RO','Roraima':'RR','Amapá':'AP','Maranhão':'MA','Piauí':'PI','Ceará':'CE','Rio Grande do Norte':'RN','Paraíba':'PB','Pernambuco':'PE','Alagoas':'AL','Sergipe':'SE'};
            return states[p.state] || '';
        }
        function applyFeature(feature, fillAddress) {
            var c = feature.geometry.coordinates, p = feature.properties || {};
            selected = { lat: c[1], lon: c[0], properties: p };
            if (activeProvider === 'osm' && map) {
                marker.setLatLng([selected.lat, selected.lon]);
                map.setView([selected.lat, selected.lon], 18);
            }
            if (activeProvider === 'google' && googleMap && googleMarker) {
                var googlePosition = { lat:selected.lat, lng:selected.lon };
                googleMarker.setPosition(googlePosition);
                googleMap.setCenter(googlePosition);
                googleMap.setZoom(18);
            }
            if (fillAddress && !p.postal_unverified) {
                setInput(fields.cep, p.postcode);
                setInput(fields.endereco, p.street || p.name);
                setInput(fields.numero, p.housenumber);
                var district = p.district || p.locality || p.neighbourhood;
                if (fields.bairro && fields.bairro.tagName === 'SELECT') setSelect(fields.bairro, district, district); else setInput(fields.bairro, district);
                setInput(fields.cidade, p.city || p.county);
                var uf = ufFromState(p);
                if (fields.estado && fields.estado.tagName === 'SELECT') setSelect(fields.estado, uf, p.state); else setInput(fields.estado, uf);
                setInput(fields.ibge, p.ibge);
            }
            modalStatus.textContent = p.approximate
                ? 'Número ' + (p.housenumber || '') + ' não mapeado: posição aproximada da rua. Arraste o marcador até a casa.'
                : 'Posição selecionada: ' + selected.lat.toFixed(7) + ', ' + selected.lon.toFixed(7);
        }
        function featureLabel(p) {
            return [p.name, p.street, p.housenumber, p.district || p.locality, p.city, p.state, p.postcode].filter(function (v, i, a) { return v && a.indexOf(v) === i; }).join(', ');
        }
        function normalizeText(value) {
            return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\b(rua|r\.?|avenida|av\.?|travessa|tv\.?)\b/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
        }
        function validatePostalFeature(feature, searchNumber) {
            var p = feature.properties || {};
            if ((p.source || '') === 'viacep') return Promise.resolve(feature);
            var street = p.street || p.name || '', city = p.city || p.county || '', uf = ufFromState(p);
            if (!street || !city || !uf) return Promise.resolve(feature);
            var confirm = document.getElementById('geo_confirm');
            confirm.disabled = true;
            modalStatus.textContent = 'Conferindo CEP e logradouro no ViaCEP…';
            var url = 'addons/geocodificacao/geocode.php?action=address&uf=' + encodeURIComponent(uf) + '&city=' + encodeURIComponent(city) + '&street=' + encodeURIComponent(street);
            return fetch(url, { credentials:'same-origin' }).then(function(r){ if(!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }).then(function(data){
                var wanted = normalizeText(street);
                var matches = (data.results || []).filter(function(item){
                    var got = normalizeText(item.logradouro);
                    return got && wanted && (got === wanted || got.indexOf(wanted) >= 0 || wanted.indexOf(got) >= 0);
                });
                if (!matches.length) {
                    p.postcode = '';
                    p.postal_unverified = true;
                    modalStatus.textContent = 'Rua localizada, mas o CEP do mapa não foi confirmado. Os dados postais atuais serão preservados.';
                    return feature;
                }
                var via = matches[0];
                p.source = 'viacep_address';
                p.street = via.logradouro;
                p.housenumber = searchNumber || p.housenumber || '';
                p.district = via.bairro;
                p.city = via.localidade;
                p.state = via.estado || via.uf;
                p.statecode = 'BR-' + via.uf;
                p.postcode = via.cep;
                p.ibge = via.ibge;
                p.approximate = !p.housenumber;
                modalStatus.textContent = 'Endereço postal confirmado pelo ViaCEP: ' + via.cep + '. Confira a posição do marcador.';
                return feature;
            }).catch(function(){
                p.postcode = '';
                p.postal_unverified = true;
                modalStatus.textContent = 'Não foi possível confirmar o CEP. Os dados postais atuais serão preservados.';
                return feature;
            }).finally(function(){ confirm.disabled = false; });
        }
        function googleAddressProperties(components) {
            var p = { source:'google', country:'Brasil', countrycode:'BR' };
            (components || []).forEach(function(c){
                var types = c.types || [];
                if (types.indexOf('route') >= 0) p.street = c.long_name;
                if (types.indexOf('street_number') >= 0) p.housenumber = c.long_name;
                if (types.indexOf('postal_code') >= 0) p.postcode = c.long_name;
                if (types.indexOf('administrative_area_level_2') >= 0) p.city = c.long_name;
                if (types.indexOf('administrative_area_level_1') >= 0) { p.state = c.long_name; p.statecode = 'BR-' + c.short_name; }
                if (!p.district && (types.indexOf('sublocality_level_1') >= 0 || types.indexOf('neighborhood') >= 0)) p.district = c.long_name;
            });
            return p;
        }
        function enrichGoogleWithViaCep(feature) {
            var p = feature.properties || {};
            var cep = String(p.postcode || '').replace(/\D/g, '');
            if (cep.length !== 8) return Promise.resolve(feature);
            return fetch('addons/geocodificacao/geocode.php?action=cep&cep=' + cep, {credentials:'same-origin'}).then(function(r){ if(!r.ok) return null; return r.json(); }).then(function(data){
                var via = data && data.result;
                if (!via) return feature;
                p.postcode = via.cep;
                p.street = p.street || via.logradouro;
                p.district = p.district || via.bairro;
                p.city = via.localidade || p.city;
                p.state = via.estado || p.state;
                p.statecode = 'BR-' + via.uf;
                p.ibge = via.ibge;
                return feature;
            }).catch(function(){ return feature; });
        }
        function selectGooglePrediction(prediction) {
            modalStatus.textContent = 'Carregando endere\u00e7o do Google\u2026';
            googlePlaces.getDetails({ placeId:prediction.place_id, fields:['address_components','formatted_address','geometry'] }, function(place, serviceStatus){
                if (serviceStatus !== google.maps.places.PlacesServiceStatus.OK || !place || !place.geometry || !place.geometry.location) {
                    modalStatus.textContent = 'O Google n\u00e3o retornou a localiza\u00e7\u00e3o completa.';
                    return;
                }
                var loc = place.geometry.location;
                var feature = { type:'Feature', geometry:{ type:'Point', coordinates:[loc.lng(),loc.lat()] }, properties:googleAddressProperties(place.address_components) };
                enrichGoogleWithViaCep(feature).then(function(enriched){
                    applyFeature(enriched, false);
                    modalStatus.textContent = 'Endere\u00e7o selecionado no Google. Confira o marcador e confirme.';
                });
            });
        }
        function doGoogleSearch(q) {
            if (googlePlacesAvailable === false) { doGoogleGeocode(q); return; }
            suggestions.innerHTML = '<p class="p-4">Buscando no Google\u2026</p>';
            googleAutocomplete.getPlacePredictions({ input:q, componentRestrictions:{country:'br'} }, function(items, serviceStatus){
                suggestions.innerHTML = '';
                if (serviceStatus !== google.maps.places.PlacesServiceStatus.OK || !items || !items.length) {
                    if (serviceStatus === google.maps.places.PlacesServiceStatus.REQUEST_DENIED) googlePlacesAvailable = false;
                    doGoogleGeocode(q);
                    return;
                }
                googlePlacesAvailable = true;
                items.forEach(function(item){
                    var b = document.createElement('button'); b.type='button'; b.className='geo_item'; b.textContent=item.description;
                    b.addEventListener('click', function(){ selectGooglePrediction(item); });
                    suggestions.appendChild(b);
                });
            });
        }
        function doGoogleGeocode(q) {
            suggestions.innerHTML = '<p class="p-4">Buscando endere\u00e7o no Google Geocoding\u2026</p>';
            googleGeocoder.geocode({ address:q, componentRestrictions:{country:'BR'}, region:'BR' }, function(results, geocodeStatus){
                suggestions.innerHTML = '';
                if (geocodeStatus !== 'OK' || !results || !results.length) {
                    suggestions.innerHTML = '<p class="p-4">Nenhum endere\u00e7o encontrado pelo Google.</p>';
                    return;
                }
                results.slice(0, 7).forEach(function(result){
                    var b=document.createElement('button'); b.type='button'; b.className='geo_item'; b.textContent=result.formatted_address;
                    b.addEventListener('click', function(){
                        var loc=result.geometry.location, p=googleAddressProperties(result.address_components);
                        p.approximate = result.geometry.location_type !== 'ROOFTOP';
                        var feature={type:'Feature',geometry:{type:'Point',coordinates:[loc.lng(),loc.lat()]},properties:p};
                        enrichGoogleWithViaCep(feature).then(function(enriched){ applyFeature(enriched,false); modalStatus.textContent='Endere\u00e7o localizado pelo Google Geocoding. Confira o marcador e confirme.'; });
                    });
                    suggestions.appendChild(b);
                });
            });
        }
        function doOsmSearch(q) {
            if (controller) controller.abort();
            controller = new AbortController();
            suggestions.innerHTML = '<p class="p-4">Buscando…</p>';
            var url = 'addons/geocodificacao/geocode.php?action=photon&q=' + encodeURIComponent(q);
            var cepMatch = q.replace(/\D/g, '').match(/(?:^|\D)(\d{8})(?:\D|$)/) || q.match(/(\d{5})-?(\d{3})/);
            var cepDigits = cepMatch ? (cepMatch[1] + (cepMatch[2] || '')) : '';
            var queryWithoutCep = q;
            if (cepDigits.length === 8) {
                queryWithoutCep = queryWithoutCep.replace(new RegExp(cepDigits.slice(0,5) + '-?' + cepDigits.slice(5)), ' ');
            }
            var numberMatches = queryWithoutCep.match(/(?:^|[\s,])([0-9]{1,6})(?=$|[\s,])/g) || [];
            var searchNumber = numberMatches.length ? numberMatches[numberMatches.length - 1].replace(/\D/g, '') : fieldValue('numero');
            var requests = [fetch(url, { signal: controller.signal, credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })];
            if (cepDigits.length === 8) {
                requests.push(fetch('addons/geocodificacao/geocode.php?action=cep&cep=' + cepDigits, { signal:controller.signal, credentials:'same-origin' }).then(function(r){ if(!r.ok) return null; return r.json(); }));
            }
            Promise.all(requests)
                .then(function (responses) {
                    var data = responses[0] || {};
                    var via = responses[1] && responses[1].result;
                    if (!via || via.erro) return { data:data, via:null, resolved:null };
                    var resolveQuery = [via.logradouro, searchNumber, via.localidade, via.uf].filter(Boolean).join(', ');
                    return fetch('addons/geocodificacao/geocode.php?action=photon&q=' + encodeURIComponent(resolveQuery), {signal:controller.signal,credentials:'same-origin'})
                        .then(function(r){if(!r.ok)return null;return r.json();})
                        .then(function(resolved){return {data:data,via:via,resolved:resolved};});
                })
                .then(function (ctx) {
                    var data = ctx.data || {};
                    var via = ctx.via;
                    var fs = (data.features || []).filter(function (f) { return (f.properties.countrycode || '').toUpperCase() === 'BR'; });
                    if (via && !via.erro) {
                        var pos = parseCoordinate();
                        var resolvedFeatures = (ctx.resolved && ctx.resolved.features || []).filter(function(f){
                            return (f.properties.countrycode||'').toUpperCase()==='BR' && (f.properties.city||'').toLowerCase()===(via.localidade||'').toLowerCase();
                        });
                        if (resolvedFeatures.length) {
                            var rc = resolvedFeatures[0].geometry.coordinates;
                            pos = [rc[1],rc[0]];
                        }
                        var rp = resolvedFeatures.length ? (resolvedFeatures[0].properties || {}) : {};
                        var exactNumber = searchNumber && String(rp.housenumber || '').toLowerCase() === String(searchNumber).toLowerCase();
                        fs.unshift({ type:'Feature', geometry:{ type:'Point', coordinates:[pos[1],pos[0]] }, properties:{
                            source:'viacep', name: exactNumber ? 'CEP e número exatos' : 'CEP exato — posição aproximada da rua', street:via.logradouro, housenumber:searchNumber,
                            district:via.bairro, city:via.localidade, state:via.estado || via.uf, statecode:'BR-' + via.uf,
                            postcode:via.cep, ibge:via.ibge, approximate:!exactNumber, country:'Brasil', countrycode:'BR'
                        }});
                    }
                    var wantedCity = fieldValue('cidade').toLowerCase();
                    fs.sort(function(a,b){
                        if ((a.properties.source||'') === 'viacep') return -1;
                        if ((b.properties.source||'') === 'viacep') return 1;
                        var ac=(a.properties.city||'').toLowerCase()===wantedCity?0:1, bc=(b.properties.city||'').toLowerCase()===wantedCity?0:1;
                        return ac-bc;
                    });
                    suggestions.innerHTML = '';
                    if (!fs.length) { suggestions.innerHTML = '<p class="p-4">Nenhum endereço encontrado. Use o mapa e arraste o marcador.</p>'; return; }
                    fs.forEach(function (f) {
                        var b = document.createElement('button'); b.type = 'button'; b.className = 'geo_item'; b.textContent = featureLabel(f.properties || {});
                        b.addEventListener('click', function () {
                            applyFeature(f, false);
                            validatePostalFeature(f, searchNumber).then(function(validated){ applyFeature(validated, false); });
                        }); suggestions.appendChild(b);
                    });
                })
                .catch(function (e) { if (e.name !== 'AbortError') suggestions.innerHTML = '<p class="p-4">Serviço de busca indisponível. O mapa continua disponível.</p>'; });
        }
        function openOsmModal() {
            activeProvider = 'osm';
            if (googleMap) { googleMap = null; googleMarker = null; googlePlaces = null; googleAutocomplete = null; googleGeocoder = null; document.getElementById('geo_map').innerHTML = ''; }
            Promise.all([loadAsset('css', 'estilos/leaflet.css', 'geo-leaflet-css'), loadAsset('js', 'scripts/leaflet.js', 'geo-leaflet-js')]).then(function () {
                var pos = parseCoordinate();
                if (!map) {
                    map = L.map('geo_map').setView(pos, 17);
                    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
                    var pinIcon = L.divIcon({
                        className: 'mka_geo_pin',
                        html: '<div class="mka_geo_pin_shape" title="Localiza\u00e7\u00e3o selecionada"></div>',
                        iconSize: [36, 42],
                        iconAnchor: [15, 38]
                    });
                    marker = L.marker(pos, { draggable: true, icon: pinIcon, title: 'Localiza\u00e7\u00e3o selecionada' }).addTo(map);
                    marker.on('dragend', function () { var p = marker.getLatLng(), props = selected && selected.properties ? selected.properties : {}; selected = { lat:p.lat, lon:p.lng, properties:props }; modalStatus.textContent = 'Marcador ajustado. Somente as coordenadas foram alteradas; o endereço foi mantido.'; });
                    map.on('click', function (e) { var props = selected && selected.properties ? selected.properties : {}; marker.setLatLng(e.latlng); selected = { lat:e.latlng.lat, lon:e.latlng.lng, properties:props }; modalStatus.textContent = 'Posição ajustada. Somente as coordenadas foram alteradas; o endereço foi mantido.'; });
                } else { map.setView(pos, 17); marker.setLatLng(pos); setTimeout(function () { map.invalidateSize(); }, 50); }
                selected = { lat:pos[0], lon:pos[1], properties:{} };
                modalStatus.textContent = 'OpenStreetMap selecionado nas op\u00e7\u00f5es do MK-AUTH.';
                if (search.value.length >= 3) doOsmSearch(search.value);
            }).catch(function () { modalStatus.textContent = 'Não foi possível carregar o mapa local.'; });
        }
        function openGoogleModal() {
            activeProvider = 'google';
            if (!googleKey) { modalStatus.textContent = 'GoogleMaps est\u00e1 selecionado, mas a chave n\u00e3o foi encontrada.'; return; }
            if (map) { map.remove(); map = null; marker = null; document.getElementById('geo_map').innerHTML = ''; }
            loadGoogleMaps(googleKey).then(function(){
                var pos = parseCoordinate(), center = {lat:pos[0],lng:pos[1]};
                googleMap = new google.maps.Map(document.getElementById('geo_map'), { center:center, zoom:17, mapTypeControl:true, streetViewControl:false });
                googleMarker = new google.maps.Marker({ map:googleMap, position:center, draggable:true, title:'Localiza\u00e7\u00e3o selecionada' });
                googleAutocomplete = new google.maps.places.AutocompleteService();
                googlePlaces = new google.maps.places.PlacesService(googleMap);
                googleGeocoder = new google.maps.Geocoder();
                googleMarker.addListener('dragend', function(){ var p=googleMarker.getPosition(),props=selected&&selected.properties?selected.properties:{}; selected={lat:p.lat(),lon:p.lng(),properties:props}; modalStatus.textContent='Marcador ajustado. Somente as coordenadas foram alteradas; o endere\u00e7o foi mantido.'; });
                googleMap.addListener('click', function(e){ var props=selected&&selected.properties?selected.properties:{}; googleMarker.setPosition(e.latLng); selected={lat:e.latLng.lat(),lon:e.latLng.lng(),properties:props}; modalStatus.textContent='Posi\u00e7\u00e3o ajustada. Somente as coordenadas foram alteradas; o endere\u00e7o foi mantido.'; });
                selected = {lat:pos[0],lon:pos[1],properties:{}};
                modalStatus.textContent = 'GoogleMaps selecionado nas op\u00e7\u00f5es do MK-AUTH.';
                if (search.value.length >= 3) doGoogleSearch(search.value);
            }).catch(function(){
                modalStatus.textContent = 'N\u00e3o foi poss\u00edvel carregar o Google Maps. Confira a chave, faturamento e APIs habilitadas.';
                suggestions.innerHTML = '<p class="p-4">Ative Maps JavaScript API e Places API na chave do Google.</p>';
            });
        }
        function openModal() {
            modal.style.display = 'block'; search.value = currentQuery(); suggestions.innerHTML='<p class="p-4">Carregando configura\u00e7\u00e3o de mapas\u2026</p>';
            nativeMapSettings().then(function(settings){
                googleKey = settings.key;
                if (settings.provider === 'google') openGoogleModal(); else openOsmModal();
            });
        }
        function closeModal() { modal.style.display = 'none'; }

        search.addEventListener('input', function () { clearTimeout(timer); var q=search.value.trim(); if(q.length<3){suggestions.innerHTML='<p class="p-4">Digite pelo menos 3 caracteres.</p>';return;} timer=setTimeout(function(){if(activeProvider==='google')doGoogleSearch(q);else doOsmSearch(q);},500); });
        document.getElementById('geo_open').addEventListener('click', openModal);
        document.getElementById('geo_close').addEventListener('click', closeModal);
        document.getElementById('geo_confirm').addEventListener('click', function () {
            if (!selected) return;
            if (selected.properties && Object.keys(selected.properties).length) {
                applyFeature({ geometry:{ coordinates:[selected.lon,selected.lat] }, properties:selected.properties }, true);
            }
            coord.value = selected.lat.toFixed(7) + ',' + selected.lon.toFixed(7);
            coord.dispatchEvent(new Event('change', { bubbles:true }));
            external.href = activeProvider === 'google'
                ? 'https://www.google.com/maps/search/?api=1&query=' + selected.lat + ',' + selected.lon
                : 'https://www.openstreetmap.org/?mlat=' + selected.lat + '&mlon=' + selected.lon + '#map=18/' + selected.lat + '/' + selected.lon;
            external.style.display = '';
            status.textContent = 'Coordenadas preenchidas. Salve o cadastro para gravar.';
            closeModal();
        });
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    }

    function boot() { initBatchCoordinates(); init(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
