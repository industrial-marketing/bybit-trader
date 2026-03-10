// Bybit Trader — minimal service worker for PWA installability
const CACHE_VERSION = 'v1';

self.addEventListener('install', function(event) {
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(self.clients.claim());
});
