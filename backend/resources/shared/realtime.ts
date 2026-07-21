import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, type Ref } from 'vue';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

type RealtimeStatus = 'connecting' | 'live' | 'degraded';

type RealtimeEvent = { revision?: number };

type SnapshotOptions<T> = {
    load: () => Promise<T>;
    channel: (snapshot: T) => string | string[];
    revision?: (snapshot: T) => number | undefined;
    onRevisionGap?: (expected: number, received: number) => void;
};

type EchoClient = {
    private: (channel: string) => { listen: (event: string, listener: (payload: RealtimeEvent) => void) => void };
    leave: (channel: string) => void;
    disconnect: () => void;
    connector: {
        pusher: {
            connection: {
                bind: (event: string, listener: (change: { previous: string; current: string }) => void) => void;
            };
        };
    };
};

function realtimeClient(): EchoClient | null {
    const broadcaster = import.meta.env.VITE_BROADCASTER === 'pusher' ? 'pusher' : 'reverb';
    const key = (broadcaster === 'pusher'
        ? import.meta.env.VITE_PUSHER_APP_KEY
        : import.meta.env.VITE_REVERB_APP_KEY) as string | undefined;
    if (!key) return null;

    const host = (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname;
    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? window.location.protocol.replace(':', '');
    const port = Number((import.meta.env.VITE_REVERB_PORT as string | undefined) ?? (scheme === 'https' ? 443 : 80));
    window.Pusher = Pusher;

    if (broadcaster === 'pusher') {
        return new Echo({
            broadcaster: 'pusher',
            key,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER as string | undefined,
            forceTLS: true,
            authEndpoint: '/broadcasting/auth',
            withCredentials: true,
        }) as unknown as EchoClient;
    }

    return new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: scheme === 'https' ? ['wss'] : ['ws'],
        authEndpoint: '/broadcasting/auth',
        withCredentials: true,
    }) as unknown as EchoClient;
}

export function useRealtimeSnapshot<T>(options: SnapshotOptions<T>): {
    snapshot: Ref<T | null>;
    status: Ref<RealtimeStatus>;
    refresh: () => Promise<void>;
    start: () => Promise<void>;
    stop: () => void;
} {
    const snapshot = ref<T | null>(null) as Ref<T | null>;
    const status = ref<RealtimeStatus>('connecting');
    let client: EchoClient | null = null;
    let subscribedChannels: string[] = [];
    let pollingTimer: number | null = null;
    let stopped = false;

    const poll = (): void => {
        if (pollingTimer !== null) return;
        pollingTimer = window.setInterval(() => void refresh(), 2_000);
    };
    const stopPolling = (): void => {
        if (pollingTimer !== null) window.clearInterval(pollingTimer);
        pollingTimer = null;
    };
    const degrade = (): void => {
        if (stopped) return;
        status.value = 'degraded';
        poll();
    };
    const subscribe = (nextSnapshot: T): void => {
        const channels = options.channel(nextSnapshot);
        const nextChannels = Array.from(new Set(Array.isArray(channels) ? channels : [channels])).sort();
        if (client === null || JSON.stringify(nextChannels) === JSON.stringify(subscribedChannels)) return;
        subscribedChannels.forEach((channel) => client?.leave(channel));
        subscribedChannels = nextChannels;
        nextChannels.forEach((channel) =>
            client?.private(channel).listen('.rpgays.outbox', (event: RealtimeEvent) => {
                const current = snapshot.value;
                const currentRevision = current === null ? undefined : options.revision?.(current);
                if (currentRevision !== undefined && event.revision !== undefined && event.revision !== currentRevision + 1) {
                    options.onRevisionGap?.(currentRevision + 1, event.revision);
                }
                void refresh();
            }),
        );
    };
    const refresh = async (): Promise<void> => {
        try {
            const nextSnapshot = await options.load();
            snapshot.value = nextSnapshot;
            subscribe(nextSnapshot);
        } catch {
            degrade();
        }
    };
    const start = async (): Promise<void> => {
        stopped = false;
        await refresh();
        client = realtimeClient();
        if (client === null) {
            degrade();

            return;
        }
        client.connector.pusher.connection.bind('state_change', ({ current }: { previous: string; current: string }) => {
            if (current === 'connected') {
                status.value = 'live';
                stopPolling();
                void refresh();
            } else if (current === 'disconnected' || current === 'unavailable' || current === 'failed') {
                degrade();
            }
        });
        if (snapshot.value !== null) subscribe(snapshot.value);
    };
    const stop = (): void => {
        stopped = true;
        stopPolling();
        if (client !== null) client.disconnect();
        client = null;
        subscribedChannels = [];
    };

    return { snapshot, status, refresh, start, stop };
}
