/* La lista y la versión se generan junto con el frontend. */
const CACHE = '__CACHE_AGENDA__'
const RECURSOS = __RECURSOS_AGENDA__
const ENTRADA = '/admin/agenda'

self.addEventListener('install', evento => {
  evento.waitUntil(caches.open(CACHE).then(cache => cache.addAll([...RECURSOS, ENTRADA])))
})

self.addEventListener('activate', evento => {
  evento.waitUntil((async () => {
    const nombres = await caches.keys()
    await Promise.all(nombres.filter(nombre => nombre.startsWith('zenli-agenda-') && nombre !== CACHE)
      .map(nombre => caches.delete(nombre)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', evento => {
  const { request } = evento
  const url = new URL(request.url)
  if (request.method !== 'GET' || url.origin !== self.location.origin) return
  if (request.mode === 'navigate' && url.pathname === ENTRADA) {
    evento.respondWith(fetch(request).catch(async () => (await caches.open(CACHE)).match(ENTRADA)))
  } else if (RECURSOS.includes(url.pathname)) {
    evento.respondWith((async () => (await (await caches.open(CACHE)).match(url.pathname)) ?? fetch(request))())
  }
})
