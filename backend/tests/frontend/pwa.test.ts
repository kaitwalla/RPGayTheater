import { afterEach, describe, expect, it, vi } from 'vitest';
import { registerParticipantServiceWorker } from '../../resources/participant/pwa';

describe('registerParticipantServiceWorker', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('registers the Player worker once after a secure page has loaded', async () => {
        const register = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('isSecureContext', true);
        vi.stubGlobal('navigator', { serviceWorker: { register } });

        registerParticipantServiceWorker();
        window.dispatchEvent(new Event('load'));
        window.dispatchEvent(new Event('load'));

        await vi.waitFor(() => expect(register).toHaveBeenCalledOnce());
        expect(register).toHaveBeenCalledWith('/player-service-worker.js', { scope: '/player', type: 'module', updateViaCache: 'none' });
    });

    it.each([
        ['the browser has no service worker support', true, {}],
        ['the page is not secure', false, { serviceWorker: { register: vi.fn() } }],
    ])('does not register when %s', (_reason, secure, navigatorValue) => {
        const addEventListener = vi.spyOn(window, 'addEventListener');
        vi.stubGlobal('isSecureContext', secure);
        vi.stubGlobal('navigator', navigatorValue);

        registerParticipantServiceWorker();

        expect(addEventListener).not.toHaveBeenCalled();
    });
});
