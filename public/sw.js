// Cache only the public app shell and static build assets. Financial API data stays on the network.
const BASE = new URL('./', self.location.href).pathname
const CACHE = `rumbo-shell-v2-${BASE}`
const SHELL = ['', 'manifest.webmanifest', 'icon.svg', 'icons/icon-192.png', 'icons/icon-512.png'].map((path) => BASE + path)

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(Promise.all([
    caches.keys().then((names) => Promise.all(names.filter((name) => name.startsWith('rumbo-shell-') && name !== CACHE).map((name) => caches.delete(name)))),
    self.clients.claim(),
  ]))
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith(BASE + 'api/')) return

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then((response) => {
      if (response.ok && response.type === 'basic') {
        const copy = response.clone()
        event.waitUntil(caches.open(CACHE).then((cache) => cache.put(BASE, copy)))
      }
      return response
    }).catch(async () => (await caches.match(BASE)) || Response.error()))
    return
  }

  if (url.pathname.startsWith(BASE + 'assets/') || SHELL.includes(url.pathname)) {
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok && response.type === 'basic') {
        const copy = response.clone()
        event.waitUntil(caches.open(CACHE).then((cache) => cache.put(request, copy)))
      }
      return response
    })))
  }
})
