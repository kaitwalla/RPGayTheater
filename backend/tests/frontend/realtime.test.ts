import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeSnapshot } from '../../resources/shared/realtime';

const realtimeTestState = vi.hoisted(() => ({
    listeners: [] as Array<(event: { revision?: number }) => void>,
    stateListeners: [] as Array<(change: { previous: string; current: string }) => void>,
}));

vi.mock('laravel-echo', () => ({
    default: class {
        connector = {
            pusher: {
                connection: {
                    bind: (_event: string, listener: (change: { previous: string; current: string }) => void): void => {
                        realtimeTestState.stateListeners.push(listener);
                    },
                },
            },
        };

        private(): { listen: (_event: string, listener: (event: { revision?: number }) => void) => void } {
            return { listen: (_event, listener): void => { realtimeTestState.listeners.push(listener); } };
        }

        leave(): void {}

        disconnect(): void {}
    },
}));

describe('useRealtimeSnapshot', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllEnvs();
        realtimeTestState.listeners = [];
        realtimeTestState.stateListeners = [];
    });

    it('falls back to two-second polling when no realtime client is configured', async () => {
        vi.useFakeTimers();
        vi.stubEnv('VITE_REVERB_APP_KEY', '');
        const load = vi.fn().mockResolvedValue({ revision: 1 });
        const realtime = useRealtimeSnapshot({ load, channel: () => 'campaigns' });

        await realtime.start();

        expect(realtime.snapshot.value).toEqual({ revision: 1 });
        expect(realtime.status.value).toBe('degraded');
        expect(load).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(2_000);

        expect(load).toHaveBeenCalledTimes(2);
    });

    it('keeps polling after a snapshot refresh failure and stops cleanly', async () => {
        vi.useFakeTimers();
        vi.stubEnv('VITE_REVERB_APP_KEY', '');
        const load = vi.fn()
            .mockResolvedValueOnce({ revision: 1 })
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValue({ revision: 2 });
        const realtime = useRealtimeSnapshot({ load, channel: () => 'campaigns' });

        await realtime.start();
        await vi.advanceTimersByTimeAsync(2_000);

        expect(realtime.status.value).toBe('degraded');
        expect(realtime.snapshot.value).toEqual({ revision: 1 });

        await vi.advanceTimersByTimeAsync(2_000);

        expect(realtime.snapshot.value).toEqual({ revision: 2 });
        expect(load).toHaveBeenCalledTimes(3);

        realtime.stop();
        await vi.advanceTimersByTimeAsync(6_000);

        expect(load).toHaveBeenCalledTimes(3);
    });

    it('refetches and reports a realtime revision gap', async () => {
        vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
        const load = vi.fn().mockResolvedValue({ revision: 1 });
        const onRevisionGap = vi.fn();
        const realtime = useRealtimeSnapshot({ load, channel: () => 'campaigns', revision: (snapshot) => snapshot.revision, onRevisionGap });

        await realtime.start();
        realtimeTestState.listeners[0]({ revision: 3 });

        await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(2));

        expect(onRevisionGap).toHaveBeenCalledWith(2, 3);
        realtime.stop();
    });

    it('polls after a disconnect and stops polling when it reconnects', async () => {
        vi.useFakeTimers();
        vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
        const load = vi.fn().mockResolvedValue({ revision: 1 });
        const realtime = useRealtimeSnapshot({ load, channel: () => 'campaigns' });

        await realtime.start();
        realtimeTestState.stateListeners[0]({ previous: 'connected', current: 'disconnected' });

        expect(realtime.status.value).toBe('degraded');
        await vi.advanceTimersByTimeAsync(2_000);
        expect(load).toHaveBeenCalledTimes(2);

        realtimeTestState.stateListeners[0]({ previous: 'disconnected', current: 'connected' });
        await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(3));

        expect(realtime.status.value).toBe('live');
        await vi.advanceTimersByTimeAsync(4_000);
        expect(load).toHaveBeenCalledTimes(3);

        realtime.stop();
    });
});
