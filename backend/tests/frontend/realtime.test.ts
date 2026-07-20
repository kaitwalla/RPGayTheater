import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRealtimeSnapshot } from '../../resources/shared/realtime';

describe('useRealtimeSnapshot', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllEnvs();
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
});
