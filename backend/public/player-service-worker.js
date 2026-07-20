import { isCacheablePlayerShellRequest, isPlayerNavigationRequest } from './pwa-cache-policy.js';

const CACHE_NAME = 'rpgays-player-shell-v1';
const PLAYER_SHELL = '/player';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.add(PLAYER_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((names) => Promise.all(names.filter((name) => name.startsWith('rpgays-player-shell-') && name !== CACHE_NAME).map((name) => caches.delete(name)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (!isCacheablePlayerShellRequest(request, self.location.origin)) return;

    if (isPlayerNavigationRequest(request)) {
        event.respondWith(fetch(request).then((response) => {
            if (response.ok) void caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
            return response;
        }).catch(() => caches.match(request).then((cached) => cached ?? caches.match(PLAYER_SHELL))));
        return;
    }

    event.respondWith(caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
        if (response.ok) void caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
        return response;
    })));
});
