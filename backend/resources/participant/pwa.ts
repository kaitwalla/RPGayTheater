export function registerParticipantServiceWorker(): void {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;

    window.addEventListener(
        'load',
        () => {
            void navigator.serviceWorker.register('/player-service-worker.js', { scope: '/player', type: 'module', updateViaCache: 'none' });
        },
        { once: true },
    );
}
