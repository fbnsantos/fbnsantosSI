'use strict';
const $ = id => document.getElementById(id);
const KEY = 'treino-timer:configurations:v1';
const defaults = {id:'default',name:'Treino habitual',rounds:10,work:70,rest:10};
let profiles = [], selected = '', dirty = false, storageBlocked = false;
const valid = p => p && typeof p.id === 'string' && p.id.length > 0 && typeof p.name === 'string' && p.name.trim().length > 0 && p.name.length <= 80 && Number.isInteger(p.rounds) && p.rounds >= 1 && p.rounds <= 1000 && Number.isInteger(p.work) && p.work >= 1 && p.work <= 86400 && Number.isInteger(p.rest) && p.rest >= 0 && p.rest <= 86400;
function message(text){ $('message').textContent = text; }
function persist(next, id){
  if(storageBlocked){message('Os dados guardados não puderam ser lidos. Não serão substituídos. Podes usar o temporizador nesta sessão.');return false;}
  try{localStorage.setItem(KEY,JSON.stringify({version:1,profiles:next,selected:id}));return true;}
  catch{message('Não foi possível guardar neste navegador. Verifica o espaço e as permissões de armazenamento.');return false;}
}
function load(){
  try{
    const raw=localStorage.getItem(KEY);
    if(raw!==null){
      const data=JSON.parse(raw);
      if(data.version!==1 || !Array.isArray(data.profiles) || !data.profiles.every(valid) || new Set(data.profiles.map(p=>p.id)).size!==data.profiles.length) throw new Error('Dados inválidos');
      profiles=data.profiles;selected=profiles.some(p=>p.id===data.selected)?data.selected:(profiles[0]?.id||'');
    }else{profiles=[{...defaults}];selected=defaults.id;persist(profiles,selected);}
  }catch{storageBlocked=true;message('Não foi possível ler as configurações locais. Os dados existentes foram preservados; o temporizador continua disponível.');}
  fill(profiles.find(p=>p.id===selected)||defaults);options();
}
function options(){
  $('presets').replaceChildren();
  if(!selected){const o=document.createElement('option');o.value='';o.textContent='Nova configuração (por guardar)';$('presets').append(o);}
  for(const p of profiles){const o=document.createElement('option');o.value=p.id;o.textContent=p.name;$('presets').append(o);}
  $('presets').value=selected;$('delete').disabled=!selected;
}
function fill(p){for(const k of ['name','rounds','work','rest'])$(k).value=p[k];dirty=false;$('dirty').textContent='';reset();}
function config(){return {name:$('name').value.trim(),rounds:Number($('rounds').value),work:Number($('work').value),rest:Number($('rest').value)};}
function checkedConfig(){if(!$('settings').reportValidity())return null;const p={...config(),id:'draft'};if(!valid(p)){message('Preenche um nome e valores inteiros válidos.');return null;}return p;}
function discard(){return !dirty || window.confirm('Descartar as alterações ainda não guardadas?');}
$('settings').addEventListener('input',()=>{dirty=true;$('dirty').textContent='Alterações por guardar';if(state==='idle'||state==='done'){remaining=Number($('work').value)||0;render();}});
$('settings').onsubmit=e=>{
  e.preventDefault();if(active())return;
  const p=checkedConfig();if(!p)return;
  if(profiles.some(x=>x.id!==selected&&x.name.toLocaleLowerCase('pt')===p.name.toLocaleLowerCase('pt'))){message('Já existe uma configuração com esse nome. Escolhe outro nome.');return;}
  p.id=selected || (globalThis.crypto?.randomUUID?.() || Date.now().toString(36)+Math.random().toString(36).slice(2));
  const next=selected?profiles.map(x=>x.id===selected?p:x):[...profiles,p];
  if(!persist(next,p.id))return;profiles=next;selected=p.id;fill(p);options();message('Configuração guardada neste navegador.');
};
$('new').onclick=()=>{if(active()||!discard())return;selected='';fill({...defaults,name:''});options();message('Dá um nome ao novo treino e guarda a configuração.');$('name').focus();};
$('presets').onchange=()=>{
  const id=$('presets').value;if(active()||!discard()){$('presets').value=selected;return;}
  selected=id;fill(profiles.find(p=>p.id===id)||defaults);options();if(persist(profiles,selected))message('Configuração carregada.');
};
$('delete').onclick=()=>{
  if(active()||!selected)return;const p=profiles.find(x=>x.id===selected);
  if(!window.confirm(`Eliminar a configuração «${p.name}»?${dirty?' As alterações por guardar também serão descartadas.':''}`))return;
  const next=profiles.filter(x=>x.id!==selected), id=next[0]?.id||'';
  if(!persist(next,id))return;profiles=next;selected=id;fill(next[0]||{...defaults,name:''});options();message('Configuração eliminada.');
};
let state='idle', phase='work', round=0, remaining=70, end=0, pausedMs=0, tickId=null, run=null, lastSec=null;
function active(){return state==='running'||state==='paused';}
const SCREEN_KEY='treino-timer:keep-screen-on:v1';
let screenLock=null, screenRevision=0, screenQueue=Promise.resolve();
try{const saved=localStorage.getItem(SCREEN_KEY);if(saved!==null)$('keepScreenOn').checked=saved==='true';}catch{}
function wantsScreenLock(){return $('keepScreenOn').checked&&state==='running'&&document.visibilityState==='visible';}
function screenStatus(text){$('screenStatus').textContent=text;}
function syncScreenLock(){
  const revision=++screenRevision;
  screenQueue=screenQueue.then(async()=>{
    if(revision!==screenRevision)return;
    if(!navigator.wakeLock || !window.isSecureContext){
      screenStatus('Manter o ecrã ligado não está disponível. Usa HTTPS e um navegador compatível.');return;
    }
    if(!wantsScreenLock()){
      const previous=screenLock;screenLock=null;
      if(previous&&!previous.released)await previous.release();
      if(revision!==screenRevision)return;
      screenStatus(!$('keepScreenOn').checked?'Manter ecrã ligado: desativado.':state==='running'?'Volta à aplicação para manter o ecrã ligado.':'O ecrã será mantido ligado ao iniciar ou retomar o treino.');
      return;
    }
    if(screenLock&&!screenLock.released)return;
    screenStatus('A pedir para manter o ecrã ligado…');
    const lock=await navigator.wakeLock.request('screen');
    // A request can finish after pause, reset, hiding the page or changing the option.
    if(revision!==screenRevision||!wantsScreenLock()){await lock.release();return;}
    screenLock=lock;
    lock.addEventListener('release',()=>{
      if(screenLock!==lock)return;
      screenLock=null;
      screenStatus('O sistema deixou de manter o ecrã ligado. Verifica a poupança de bateria ou desativa e volta a ativar esta opção.');
    });
    screenStatus('Ecrã mantido ligado durante o treino.');
  }).catch(()=>{
    if(revision===screenRevision)screenStatus('Não foi possível manter o ecrã ligado. O sistema pode estar a limitar esta função, por exemplo para poupar bateria.');
  });
  return screenQueue;
}
$('keepScreenOn').onchange=()=>{
  try{localStorage.setItem(SCREEN_KEY,String($('keepScreenOn').checked));}catch{}
  syncScreenLock();
};
let toneContext=null;
function enableTones(){
  try{
    const AudioEngine=window.AudioContext||window.webkitAudioContext;
    if(!AudioEngine)throw new Error('Este navegador não suporta sinais sonoros.');
    if(!toneContext)toneContext=new AudioEngine();
    return toneContext.resume().then(()=>{
      if(toneContext.state!=='running')throw new Error('O som está bloqueado no navegador.');
    });
  }catch(error){return Promise.reject(error);}
}
function tone(count=1){
  if(!$('tones').checked)return;
  if(!toneContext||toneContext.state!=='running'){
    $('toneStatus').textContent='Som por ativar. Toca em Ativar e testar sinais sonoros.';return;
  }
  for(let i=0;i<count;i++){
    const start=toneContext.currentTime+i*.25;
    const oscillator=toneContext.createOscillator(), gain=toneContext.createGain();
    oscillator.frequency.value=880;gain.gain.setValueAtTime(0,start);
    gain.gain.linearRampToValueAtTime(.25,start+.01);
    gain.gain.setValueAtTime(.25,start+.12);gain.gain.linearRampToValueAtTime(0,start+.17);
    oscillator.connect(gain);gain.connect(toneContext.destination);
    oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};
    oscillator.start(start);oscillator.stop(start+.18);
  }
}
function activateTones(test=false){
  if(!$('tones').checked)return;
  enableTones().then(()=>{
    $('toneStatus').textContent='Sinais sonoros ativados. Se não ouvires, verifica o volume multimédia, o Bluetooth e a permissão de som do site no Chrome.';
    if(test)tone(2);
  }).catch(error=>{$('toneStatus').textContent='Não foi possível ativar o som: '+error.message;});
}
$('testTone').onclick=()=>{$('tones').checked=true;activateTones(true);};
$('tones').onchange=()=>{if($('tones').checked)activateTones(true);};
let voiceList=[], currentSpeech=null, speechWatchdog=null;
function audioStatus(text){$('audioStatus').textContent=text;}
function refreshVoices(){
  if(!window.speechSynthesis)return;
  voiceList=window.speechSynthesis.getVoices();
  const previous=$('voiceChoice').value;
  $('voiceChoice').replaceChildren();
  const automatic=document.createElement('option');automatic.value='';automatic.textContent='Português — escolha automática';$('voiceChoice').append(automatic);
  for(const voice of voiceList.filter(v=>/^pt(?:-|_)/i.test(v.lang))){
    const option=document.createElement('option');option.value=voice.voiceURI;option.textContent=`${voice.name} (${voice.lang})`;$('voiceChoice').append(option);
  }
  $('voiceChoice').value=voiceList.some(v=>v.voiceURI===previous)?previous:'';
}
function stopVoice(){
  clearTimeout(speechWatchdog);currentSpeech=null;window.speechSynthesis?.cancel();
}
function say(text,{test=false,interrupt=false}={}){
  if(!$('voice').checked||!window.speechSynthesis)return;
  const synth=window.speechSynthesis;
  // Do not cancel before every utterance: some mobile engines fail to restart.
  if(synth.speaking||synth.pending){if(interrupt)stopVoice();else return;}
  if(synth.paused)synth.resume();
  voiceList=synth.getVoices();
  const chosen=voiceList.find(v=>v.voiceURI===$('voiceChoice').value)||voiceList.find(v=>/^pt[-_]PT$/i.test(v.lang))||voiceList.find(v=>/^pt[-_]/i.test(v.lang));
  const u=new SpeechSynthesisUtterance(text);u.lang=chosen?.lang||'pt-PT';u.rate=1;u.volume=1;
  if(chosen)u.voice=chosen;
  currentSpeech=u;clearTimeout(speechWatchdog);
  u.onstart=()=>{if(currentSpeech!==u)return;clearTimeout(speechWatchdog);audioStatus(test?'Teste em reprodução. Confirma que ouves a voz.':'Avisos de voz em reprodução.');};
  u.onend=()=>{if(currentSpeech!==u)return;clearTimeout(speechWatchdog);currentSpeech=null;};
  u.onerror=event=>{
    if(currentSpeech!==u)return;clearTimeout(speechWatchdog);currentSpeech=null;
    if(['canceled','interrupted'].includes(event.error))return;
    audioStatus(`Não foi possível reproduzir a voz (${event.error||'erro desconhecido'}). Toca em Testar voz. No Android, verifica o volume multimédia e o motor de conversão de texto em voz, com português instalado.`);
  };
  speechWatchdog=setTimeout(()=>{if(currentSpeech===u)audioStatus('A voz não começou. Verifica o volume multimédia, a saída Bluetooth e as definições de conversão de texto em voz do Android. Escolhe uma voz de português e volta a testar.');},5000);
  try{synth.speak(u);}catch{clearTimeout(speechWatchdog);currentSpeech=null;audioStatus('O navegador não conseguiu iniciar a voz. Verifica a conversão de texto em voz nas definições do Android.');}
}
$('testVoice').onclick=()=>{
  $('voice').checked=true;refreshVoices();
  audioStatus('A iniciar teste de voz…');
  say('Teste de voz. Três, dois, um. Vamos treinar.',{test:true,interrupt:true});
};
if(window.speechSynthesis){refreshVoices();window.speechSynthesis.addEventListener?.('voiceschanged',refreshVoices);}

function format(s){return String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');}
function render(){
  $('clock').textContent=format(remaining);
  $('phase').textContent=state==='idle'?'Pronto para começar':state==='done'?'Treino concluído!':`${state==='paused'?'Em pausa · ':''}${phase==='rest'?'Descanso':'Exercício'}`;
  $('progress').textContent=`Exercício ${round} / ${run?.rounds||Number($('rounds').value)||10}`;
  $('testVoice').disabled=active();$('voiceChoice').disabled=active();$('start').disabled=active();$('pause').disabled=!active();$('pause').textContent=state==='paused'?'Retomar':'Pausar';
  for(const id of ['name','rounds','work','rest','presets','new','save'])$(id).disabled=active();
  $('delete').disabled=active()||!selected;
}
function announce(){tone(2);say(phase==='work'?`Exercício ${round}. Começar. ${run.work} segundos.`:`Descanso. ${run.rest} segundos.`,{interrupt:true});}
function finish(){state='done';syncScreenLock();remaining=0;clearInterval(tickId);tone(3);say('Treino concluído',{interrupt:true});}
function tick(){
  if(state!=='running')return;
  const now=Date.now();let changed=false;
  while(now>=end && state==='running'){
    changed=true;
    if(phase==='work'&&round===run.rounds){finish();break;}
    if(phase==='work'&&run.rest>0){phase='rest';end+=run.rest*1000;}
    else{phase='work';round++;end+=run.work*1000;}
  }
  if(state==='running'){
    remaining=Math.max(0,Math.ceil((end-now)/1000));
    if(changed)announce();
    else if(remaining!==lastSec && remaining>0 && (remaining<=3 || remaining%10===0&&remaining<=60)) {tone();say(remaining===60?'Um minuto':remaining===1?'Um':remaining===2?'Dois':remaining===3?'Três':remaining+' segundos');}
    lastSec=remaining;
  }
  render();
}
function reset(){clearInterval(tickId);stopVoice();state='idle';syncScreenLock();phase='work';round=0;run=null;remaining=Math.max(0,Number($('work').value)||0);render();}
$('start').onclick=()=>{const c=checkedConfig();if(!c||active())return;activateTones();run={...c};round=1;phase='work';state='running';syncScreenLock();remaining=run.work;lastSec=remaining;end=Date.now()+remaining*1000;announce();clearInterval(tickId);tickId=setInterval(tick,100);render();};
$('pause').onclick=()=>{
  if(state==='paused'){activateTones();state='running';syncScreenLock();end=Date.now()+pausedMs;say('Continuar.',{interrupt:true});render();}
  else if(state==='running'){tick();if(state!=='running')return;pausedMs=Math.max(0,end-Date.now());state='paused';syncScreenLock();stopVoice();render();}
};
$('reset').onclick=reset;
$('voice').onchange=()=>{if(!$('voice').checked)stopVoice();else say('Avisos por voz ativados.',{interrupt:true});};
window.addEventListener('beforeunload',e=>{if(dirty||active()){e.preventDefault();e.returnValue='';}});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick();syncScreenLock();});
load();render();
if(!('speechSynthesis' in window)){$('voice').checked=false;$('voice').disabled=true;$('testVoice').disabled=true;audioStatus('Este navegador não suporta avisos por voz. O temporizador funciona sem voz.');}
if('serviceWorker' in navigator && /^https?:$/.test(location.protocol)){
  navigator.serviceWorker.register('./sw.js').then(()=>navigator.serviceWorker.ready).then(()=>{$('offline').textContent='Aplicação preparada para utilização offline.';}).catch(()=>{$('offline').textContent='Não foi possível preparar o modo offline. Abre a aplicação por HTTPS.';});
}else{$('offline').textContent='Para instalar e usar offline, publica a pasta num servidor HTTPS (ou localhost para testes).';}
