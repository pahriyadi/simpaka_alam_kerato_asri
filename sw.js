const CACHE_NAME = 'simpaka-aka-v3';
const ASSETS_TO_CACHE = [
  './',
  './manifest.json',
  './assets/css/simpaka.css',
  './assets/js/simpaka.js',
  './assets/img/pwa-icon-192.png',
  './assets/img/pwa-icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});

// Web Push Listener (mengikuti standar Minishlink/web-push-php-example)
self.addEventListener('push', (event) => {
  let data = { title: 'SIMPAKA', body: 'Ada informasi terbaru untuk Anda.', url: './warga/notifikasi' };
  if (event.data) {
    try {
      data = Object.assign(data, event.data.json());
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: './assets/img/pwa-icon-192.png',
    badge: './assets/img/pwa-icon-192.png',
    vibrate: [200, 100, 200],
    data: { url: data.url || './warga/notifikasi' }
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : './warga/notifikasi';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(targetUrl);
    })
  );
});
