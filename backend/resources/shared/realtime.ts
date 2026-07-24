import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, type Ref } from 'vue';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        RPGAYS_REALTIME_CONFIG?: RuntimeRealtimeConfig;
    }
}

type RealtimeStatus = 'connecting' | 'live' | 'degraded';

type RealtimeEvent = { revision?: number };

type RuntimeRealtimeConfig = {
    broadcaster?: string | null;
    key?: string | null;
    cluster?: string | null;
    host?: string | null;
    port?: number | string | null;
    scheme?: string | null;
};

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

const realtimeConnectionGraceMs = 2_500;

function runtimeRealtimeConfig(): RuntimeRealtimeConfig {
    if (window.RPGAYS_REALTIME_CONFIG !== undefined) return window.RPGAYS_REALTIME_CONFIG;

    const encodedConfig = document.querySelector<HTMLMetaElement>('meta[name="rpgays-realtime-config"]')?.content;
    if (!encodedConfig) return {};

    try {
        const parsed = JSON.parse(encodedConfig) as unknown;

        return parsed !== null && typeof parsed === 'object' ? (parsed as RuntimeRealtimeConfig) : {};
    } catch {
        return {};
    }
}

function realtimeClient(): EchoClient | null {
    const runtime = runtimeRealtimeConfig();
    const key = runtime.key ?? (import.meta.env.VITE_PUSHER_APP_KEY as string | undefined);
    if (!key) return null;

    window.Pusher = Pusher;

    return new Echo({
        broadcaster: 'pusher',
        key,
        cluster: runtime.cluster ?? (import.meta.env.VITE_PUSHER_APP_CLUSTER as string | undefined),
        forceTLS: true,
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
    let connectionGraceTimer: number | null = null;
    let stopped = false;

    const poll = (): void => {
        if (pollingTimer !== null) return;
        pollingTimer = window.setInterval(() => void refresh(), 2_000);
    };
    const stopPolling = (): void => {
        if (pollingTimer !== null) window.clearInterval(pollingTimer);
        pollingTimer = null;
    };
    const stopConnectionGraceTimer = (): void => {
        if (connectionGraceTimer !== null) window.clearTimeout(connectionGraceTimer);
        connectionGraceTimer = null;
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
                stopConnectionGraceTimer();
                status.value = 'live';
                stopPolling();
                void refresh();
            } else if (current === 'disconnected' || current === 'unavailable' || current === 'failed') {
                degrade();
            }
        });
        connectionGraceTimer = window.setTimeout(degrade, realtimeConnectionGraceMs);
        if (snapshot.value !== null) subscribe(snapshot.value);
    };
    const stop = (): void => {
        stopped = true;
        stopConnectionGraceTimer();
        stopPolling();
        if (client !== null) client.disconnect();
        client = null;
        subscribedChannels = [];
    };

    return { snapshot, status, refresh, start, stop };
}
