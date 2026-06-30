const CACHE = 'wallet-static-v3';
const ASSETS = ['./sha3.min.js','./axlsign.min.js','./blakejs.min.js','./lang.js','./jsQR.min.js','./manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(()=>{})).then(()=>self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (url.origin === self.location.origin
      && e.request.method === 'GET'
      && ASSETS.some(a => url.pathname.endsWith(a.replace('./','')))) {
    e.respondWith(caches.match(e.request).then(c => c || fetch(e.request)));
  }
});