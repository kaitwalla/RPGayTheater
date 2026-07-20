import assert from 'node:assert/strict';
import test from 'node:test';

const workerPath = new URL('../../public/player-service-worker.js', import.meta.url);

async function loadWorker({ caches, skipWaiting = async () => undefined, claim = async () => undefined }) {
    const listeners = new Map();
    globalThis.caches = caches;
    globalThis.self = {
        addEventListener: (event, listener) => listeners.set(event, listener),
        skipWaiting,
        clients: { claim },
    };
    await import(`${workerPath.href}?test=${Math.random()}`);

    return listeners;
}

test('installs the Player shell and immediately activates the new service worker', async () => {
    const added = [];
    let skipWaitingCalls = 0;
    const listeners = await loadWorker({
        caches: { open: async () => ({ add: async (path) => added.push(path) }) },
        skipWaiting: async () => {
            skipWaitingCalls++;
        },
    });
    let install;

    listeners.get('install')({
        waitUntil: (promise) => {
            install = promise;
        },
    });
    await install;

    assert.deepEqual(added, ['/player']);
    assert.equal(skipWaitingCalls, 1);
});

test('activation removes obsolete Player shell caches and claims existing pages', async () => {
    const deleted = [];
    let claimCalls = 0;
    const listeners = await loadWorker({
        caches: {
            keys: async () => ['rpgays-player-shell-v0', 'rpgays-player-shell-v1', 'unrelated-cache'],
            delete: async (name) => {
                deleted.push(name);
            },
        },
        claim: async () => {
            claimCalls++;
        },
    });
    let activate;

    listeners.get('activate')({
        waitUntil: (promise) => {
            activate = promise;
        },
    });
    await activate;

    assert.deepEqual(deleted, ['rpgays-player-shell-v0']);
    assert.equal(claimCalls, 1);
});
