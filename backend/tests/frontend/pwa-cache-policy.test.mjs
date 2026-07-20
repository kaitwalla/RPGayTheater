import assert from 'node:assert/strict';
import test from 'node:test';
import { isCacheablePlayerShellRequest, isPlayerNavigationRequest } from '../../public/pwa-cache-policy.js';

const origin = 'https://rpgays.test';
const request = (path, options = {}) => ({ url: `${origin}${path}`, method: 'GET', mode: 'cors', ...options });

test('caches only the Player shell and versioned build assets', () => {
    assert.equal(isCacheablePlayerShellRequest(request('/player'), origin), true);
    assert.equal(isCacheablePlayerShellRequest(request('/build/assets/player-abc.js'), origin), true);
    assert.equal(isCacheablePlayerShellRequest(request('/build/fonts/inter.woff2'), origin), true);
});

test('never caches APIs, signed media, other pages, queries, or unsafe methods', () => {
    assert.equal(isCacheablePlayerShellRequest(request('/api/participant/v1/map'), origin), false);
    assert.equal(isCacheablePlayerShellRequest(request('/storage/private.png?signature=secret'), origin), false);
    assert.equal(isCacheablePlayerShellRequest(request('/presentation'), origin), false);
    assert.equal(isCacheablePlayerShellRequest(request('/player?resume=true'), origin), false);
    assert.equal(isCacheablePlayerShellRequest(request('/player', { method: 'POST' }), origin), false);
});

test('only Player navigation falls back to the cached shell offline', () => {
    assert.equal(isPlayerNavigationRequest(request('/player', { mode: 'navigate' })), true);
    assert.equal(isPlayerNavigationRequest(request('/api/participant/v1/map', { mode: 'navigate' })), false);
});
