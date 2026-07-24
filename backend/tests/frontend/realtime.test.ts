import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeSnapshot } from '../../resources/shared/realtime';

const realtimeTestState = vi.hoisted(() => ({
    listeners: [] as Array<(event: { revision?: number }) => void>,
    stateListeners: [] as Array<(change: { previous: string; current: string }) => void>,
    leaves: [] as string[],
    disconnects: 0,
    configurations: [] as Array<Record<string, unknown>>,
}));

vi.mock('laravel-echo', () => ({
    default: class {
        constructor(configuration: Record<string, unknown>) {
            realtimeTestState.configurations.push(configuration);
        }

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
            return {
                listen: (_event, listener): void => {
                    realtimeTestState.listeners.push(listener);
                },
            };
        }

        leave(channel: string): void {
            realtimeTestState.leaves.push(channel);
        }

        disconnect(): void {
            realtimeTestState.disconnects++;
        }
    },
}));

describe('useRealtimeSnapshot', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllEnvs();
        delete window.RPGAYS_REALTIME_CONFIG;
        document.querySelector('meta[name="rpgays-realtime-config"]')?.remove();
        realtimeTestState.listeners = [];
        realtimeTestState.stateListeners = [];
        realtimeTestState.leaves = [];
        realtimeTestState.disconnects = 0;
        realtimeTestState.configurations = [];
    });

    it('falls back to two-second polling when no realtime client is configured', async () => {
        vi.useFakeTimers();
        vi.stubEnv('VITE_BROADCASTER', 'reverb');
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

    it('connects to hosted Pusher when configured', async () => {
        vi.stubEnv('VITE_BROADCASTER', 'pusher');
        vi.stubEnv('VITE_PUSHER_APP_KEY', 'test-key');
        vi.stubEnv('VITE_PUSHER_APP_CLUSTER', 'us2');
        const realtime = useRealtimeSnapshot({ load: vi.fn().mockResolvedValue({ revision: 1 }), channel: () => 'campaigns' });

        await realtime.start();

        expect(realtimeTestState.configurations).toContainEqual(
            expect.objectContaining({
                broadcaster: 'pusher',
                key: 'test-key',
                cluster: 'us2',
                forceTLS: true,
            }),
        );
        realtime.stop();
    });

    it('uses runtime Pusher configuration rendered by Laravel over build-time Vite defaults', async () => {
        vi.stubEnv('VITE_BROADCASTER', 'reverb');
        vi.stubEnv('VITE_REVERB_APP_KEY', 'local-key');
        window.RPGAYS_REALTIME_CONFIG = {
            broadcaster: 'pusher',
            key: 'runtime-key',
            cluster: 'eu',
        };
        const realtime = useRealtimeSnapshot({ load: vi.fn().mockResolvedValue({ revision: 1 }), channel: () => 'campaigns' });

        await realtime.start();

        expect(realtimeTestState.configurations).toContainEqual(
            expect.objectContaining({
                broadcaster: 'pusher',
                key: 'runtime-key',
                cluster: 'eu',
                forceTLS: true,
            }),
        );
        realtime.stop();
    });

    it('uses runtime Pusher configuration from the CSP-safe meta tag', async () => {
        vi.stubEnv('VITE_BROADCASTER', 'reverb');
        vi.stubEnv('VITE_REVERB_APP_KEY', 'local-key');
        const meta = document.createElement('meta');
        meta.name = 'rpgays-realtime-config';
        meta.content = JSON.stringify({ broadcaster: 'pusher', key: 'meta-key', cluster: 'mt1' });
        document.head.append(meta);
        const realtime = useRealtimeSnapshot({ load: vi.fn().mockResolvedValue({ revision: 1 }), channel: () => 'campaigns' });

        await realtime.start();

        expect(realtimeTestState.configurations).toContainEqual(
            expect.objectContaining({
                broadcaster: 'pusher',
                key: 'meta-key',
                cluster: 'mt1',
            }),
        );
        realtime.stop();
    });

    it('keeps polling after a snapshot refresh failure and stops cleanly', async () => {
        vi.useFakeTimers();
        vi.stubEnv('VITE_BROADCASTER', 'reverb');
        vi.stubEnv('VITE_REVERB_APP_KEY', '');
        const load = vi.fn().mockResolvedValueOnce({ revision: 1 }).mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ revision: 2 });
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

    it('deduplicates changing channel lists and does not report gaps without comparable revisions', async () => {
        vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
        const load = vi.fn().mockResolvedValueOnce({ revision: 1, channel: 'first' }).mockResolvedValue({ revision: 2, channel: 'second' });
        const onRevisionGap = vi.fn();
        const realtime = useRealtimeSnapshot({
            load,
            channel: (snapshot) => [snapshot.channel, snapshot.channel, 'shared'],
            revision: (snapshot) => snapshot.revision,
            onRevisionGap,
        });

        await realtime.start();
        expect(realtimeTestState.listeners).toHaveLength(2);
        realtimeTestState.listeners[0]({});
        await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(2));

        expect(onRevisionGap).not.toHaveBeenCalled();
        expect(realtimeTestState.leaves).toEqual(['first', 'shared']);
        realtimeTestState.stateListeners[0]({ previous: 'connecting', current: 'unavailable' });
        expect(realtime.status.value).toBe('degraded');

        realtime.stop();
        expect(realtimeTestState.disconnects).toBe(1);
    });
});
