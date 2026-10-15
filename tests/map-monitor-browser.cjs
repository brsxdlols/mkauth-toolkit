const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const source = fs.readFileSync(process.env.MKAUTH_MAP_TEST_FILE || path.join(__dirname,'../addons/mapa-clientes/maps.hhvm'),'utf8');
const css = source.split('<style>')[1].split('</style>')[0];
const functionLine = name => source.split('\n').find(line => line.trimStart().startsWith('function '+name+'('));
const popup = source.slice(source.indexOf('  function popup(c)'),source.indexOf('  function markerLock'));
const graphFunctions=['trafficGeometry','trafficPoints','trafficScale','trafficDots','renderTraffic'].map(functionLine).join('\n');
(async()=>{
const browser = await chromium.launch({headless:true,executablePath:process.env.MAP_TEST_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try {
const page=await browser.newPage();
for(const size of [{width:1440,height:900},{width:390,height:844}]) {
await page.setViewportSize(size);
await page.setContent('<style>'+css+'</style><body class="monitor-client"><div class="leaflet-popup"><div class="leaflet-popup-content-wrapper"><div class="leaflet-popup-content"></div></div></div></body>');
await page.addScriptTag({content:`var nearestProvider=()=>null,registeredAddress=()=>'',duration=()=>'',dateText=String,esc=String,value=String;var trafficSeries={rx:[],tx:[],times:[]};${popup}\n${graphFunctions}\ndocument.querySelector('.leaflet-popup-content').innerHTML=popup({name:'Cliente teste',login:'fixture',status:'online',connected_at:'2026-10-13',framed_ip:'10.0.0.1',plan:'600 Mbps',cto:'CTO teste',splitter_port:'1'});var box=document.querySelector('.traffic-mini');renderTraffic(box,{rx_mbps:12.4,tx_mbps:3.5});renderTraffic(box,{rx_mbps:14.2,tx_mbps:14.1});`});
const result=await page.evaluate(()=>{
const svg=document.querySelector('svg'),labels=[...svg.querySelectorAll('.traffic-mini-point-value')];
return {viewBox:svg.getAttribute('viewBox').split(' ').map(Number),width:svg.clientWidth,height:svg.clientHeight,labels:labels.map(l=>({text:l.textContent,color:l.getAttribute('fill'),x:l.getBBox().x+l.getBBox().width,y:l.getBBox().y})),areas:[...svg.querySelectorAll('polygon')].map(a=>a.getAttribute('fill-opacity')),tooltips:[...svg.querySelectorAll('circle title')].map(t=>t.textContent),dataHeight:document.querySelector('.popup-grid').getBoundingClientRect().height,trafficHeight:document.querySelector('.traffic-mini').getBoundingClientRect().height};
});
assert(result.viewBox[2]>=result.width && result.viewBox[3]>=result.height);
assert.deepEqual(result.areas,['0.16','0.16']);assert.equal(result.labels.length,4);result.labels=result.labels.filter(l=>l.text.includes('Mbps'));assert.equal(result.labels.length,2);
assert(result.labels[0].text.includes('14.20 Mbps')&&result.labels[0].color==='#3182ce');assert(result.labels[1].text.includes('14.10 Mbps')&&result.labels[1].color==='#e45756');
assert(result.labels.every(l=>l.x<=result.viewBox[2]+1 && l.y>=0));assert(result.tooltips.every(t=>t.includes('Coleta ')&&t.includes('Download ')&&t.includes('Upload ')));
if(size.width>820) assert(Math.abs(result.trafficHeight-result.dataHeight)<3,'Chart panel height does not follow client data');
console.log('PASS gráfico '+size.width+'×'+size.height+'; altura, valores, cores, áreas e tooltips');
}
// Use the actual shortcut function with stubs; no requests or customer information.
for(const [url,requested,expect] of [['/admin/addons/mapa-clientes/monitor/?login=second','second','second'],['/admin/addons/mapa-clientes/?monitor=1&login=second','second','second'],['/admin/addons/mapa-clientes/monitor/index.php?login=missing','missing',null],['/admin/addons/mapa-clientes/monitor/',null,'first'],['/admin/addons/mapa-clientes/',null,null]]) {
await page.setContent('<body class="monitor-entry"></body>');
const u=new URL(url,'https://fixture.invalid');
const script=functionLine('openMonitorShortcut').replaceAll('location.search',JSON.stringify(u.search)).replaceAll('location.pathname',JSON.stringify(u.pathname));
await page.addScriptTag({content:`var state={clients:[{login:'first',status:'online'},{login:'second',status:'online'}]},monitorShortcutOpened=false,activePopupLogin=null,monitorPopupElement=null;var toast=()=>{},popup=()=>'',setMonitorMode=()=>{},setupMonitorButton=()=>{},setupMonitorSearch=()=>{},startTraffic=()=>{};${script};openMonitorShortcut();`});
assert.equal(await page.evaluate(()=>activePopupLogin),expect);
}
console.log('PASS nova rota, rota antiga, login selecionado e login indisponível');
} finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
