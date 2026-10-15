const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync(require('path').join(__dirname,'../addons/mapa-clientes/maps.hhvm'),'utf8');
const functions=['stopSiren','startSiren','criticalAlert','scheduleCriticalClose','closeCritical'].map(n=>source.split('\n').find(l=>l.trimStart().startsWith('function '+n+'('))).join('\n');
for(const seconds of [0,60,300,600]){
let visible=false,now=0,next=0,stopped=0,resume;const tasks=new Map(),elements={'critical-alert':{classList:{contains:()=>visible,add:()=>visible=true,remove:()=>visible=false}},'critical-count':{textContent:''},'critical-duration':{value:String(seconds)},'alert-sound':{checked:true}};
const ctx={document:{getElementById:id=>elements[id]},canViewTotals:true,audioContext:null,sirenTimer:null,sirenOscillator:null,sirenGeneration:0,criticalTimer:null,prepareAudio(){},toast(){},setTimeout(fn,ms){tasks.set(++next,{at:now+ms,fn});return next},clearTimeout(id){tasks.delete(id)},clearInterval(){},setInterval(){return 99}};vm.createContext(ctx);vm.runInContext(functions,ctx);
const advance=ms=>{now+=ms;for(const [id,t]of tasks)if(t.at<=now){tasks.delete(id);t.fn()}};
ctx.criticalAlert(18,false);assert(visible);assert.equal(elements['critical-count'].textContent,18);ctx.sirenOscillator={stop(){stopped++}};ctx.sirenTimer=99;
advance(30000);ctx.criticalAlert(20,false);assert.equal(tasks.size,seconds?1:0);if(seconds){advance(seconds*1000-30001);assert(visible);advance(1);assert(!visible);assert.equal(stopped,1);assert.equal(ctx.sirenTimer,null)}else{advance(600000);assert(visible);ctx.closeCritical();assert.equal(stopped,1)}
ctx.criticalAlert(2,false);ctx.closeCritical();assert.equal(tasks.size,0);advance(600000);assert(!visible);
// A delayed browser audio resume must not restart the siren after dismissal.
ctx.audioContext={resume(){return {then(fn){resume=fn;return {catch(){}}}}},createGain(){throw Error('late audio restarted')}};ctx.startSiren(true);ctx.closeCritical();resume();assert(!visible);
console.log('PASS alert '+seconds+'s: deadline, repeat does not extend, automatic/manual close, siren cancellation and delayed audio guard');
}
