const PREFIX='treino-timer-';
const CACHE=PREFIX+'v5-screen';
const ASSETS=['./','./index.html','./style.css','./app.js','./manifest.webmanifest','./icon-192.png','./icon-512.png','./audio/beep.wav'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith(PREFIX)&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET'||new URL(event.request.url).origin!==self.location.origin)return;
  event.respondWith(caches.open(CACHE).then(async cache=>{
    if(event.request.mode==='navigate'){
      try{return await fetch(event.request);}catch{return (await cache.match('./index.html'))||Response.error();}
    }
    return (await cache.match(event.request))||fetch(event.request);
  }));
});
