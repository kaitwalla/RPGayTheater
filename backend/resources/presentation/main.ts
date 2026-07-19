import { createApp, defineComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import VueKonva from 'vue-konva';
import { api, ApiError } from '../shared/api';
import { useRealtimeSnapshot } from '../shared/realtime';
import { PresentationStage, type PresentationStageEntry } from '../shared/presentation-stage';
import '../css/app.css';


type Snapshot<T> = { data: T };
type PresentationState = { live_session_id: string; revision: number; state: { scene_id: string | null; backdrop_asset_id: string | null; stage_entries: unknown[]; standby: { backdrop_asset_id: string | null } | null; standby_status: 'idle' | 'preparing' | 'ready' | 'error'; standby_error: string | null } };
type OverlayState = { live_session_id: string; revision: number; state: { corner: { current: { content: string } | null }; full: { current: { content: string } | null } } };
type PresentationRenderCue = { scene: { id: string; name: string | null; transition: string; transition_duration_ms: number } | null; backdrop_asset_id: string | null; stage_tween: { duration_ms: number; easing: 'linear' | 'ease_in' | 'ease_out' | 'ease_in_out' }; stage_entries: PresentationStageEntry[] };
type PresentationRender = PresentationRenderCue & { live_session_id: string; revision: number; standby: PresentationRenderCue | null };

const PresentationApp = defineComponent({
    setup() {
        const pairingToken = ref('');
        const error = ref('');
        const render = ref<PresentationRender | null>(null);
        const assetUrls = ref<Record<string, string>>({});
        const presentation = useRealtimeSnapshot({
            load: async () => (await api<Snapshot<PresentationState>>('/api/presentation/v1/state')).data,
            channel: (snapshot) => `presentation_states.${snapshot.live_session_id}`,
            revision: (snapshot) => snapshot.revision,
        });
        const overlays = useRealtimeSnapshot({
            load: async () => (await api<Snapshot<OverlayState>>('/api/presentation/v1/overlays')).data,
            channel: (snapshot) => `overlay_states.${snapshot.live_session_id}`,
            revision: (snapshot) => snapshot.revision,
        });
        const loadRender = async (): Promise<void> => {
            const next = (await api<Snapshot<PresentationRender>>('/api/presentation/v1/render')).data;
            const cues = [next, next.standby].filter((cue): cue is PresentationRenderCue => cue !== null);
            const assetIds = cues.flatMap((cue) => [cue.backdrop_asset_id, ...cue.stage_entries.map((entry) => entry.asset_id)]).filter((assetId): assetId is string => assetId !== null);
            const missing = assetIds.filter((assetId) => assetUrls.value[assetId] === undefined);
            const urls = await Promise.all(missing.map(async (assetId) => [assetId, (await api<Snapshot<{ url: string }>>(`/api/presentation/v1/assets/${assetId}/read`)).data.url] as const));
            await Promise.all(urls.map(([, url]) => new Promise<void>((resolve, reject) => { const image = new Image(); image.onload = () => resolve(); image.onerror = () => reject(new Error('A presentation asset could not be decoded.')); image.src = url; })));
            assetUrls.value = { ...assetUrls.value, ...Object.fromEntries(urls) };
            render.value = next;
        };
        const start = async (): Promise<void> => {
            await Promise.all([presentation.start(), overlays.start()]);
        };
        const pair = async (): Promise<void> => {
            error.value = '';
            try {
                await api('/api/presentation/v1/pair', { method: 'POST', body: JSON.stringify({ token: pairingToken.value.trim() }) });
                pairingToken.value = '';
                presentation.stop();
                overlays.stop();
                await start();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to pair this display.';
            }
        };
        onMounted(() => { void start().catch((reason) => { if (!(reason instanceof ApiError && reason.status === 401)) error.value = reason instanceof Error ? reason.message : 'Unable to load Presentation.'; }); });
        onBeforeUnmount(() => { presentation.stop(); overlays.stop(); });

        watch(() => presentation.snapshot.value, async (snapshot) => {
            if (!snapshot) return;
            try {
                await loadRender();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to render the active scene.';
            }
            if (snapshot.state.standby_status !== 'preparing') return;
            try {
                await api('/api/presentation/v1/standby/report', { method: 'POST', body: JSON.stringify({ command_id: crypto.randomUUID(), expected_revision: snapshot.revision, status: 'ready' }) });
            } catch (reason) {
                try {
                    await api('/api/presentation/v1/standby/report', { method: 'POST', body: JSON.stringify({ command_id: crypto.randomUUID(), expected_revision: snapshot.revision, status: 'error', error: reason instanceof Error ? reason.message : 'Unable to prepare standby.' }) });
                } catch { /* A newer standby snapshot superseded this preload. */ }
            }
        });

        return { pairingToken, error, render, assetUrls, pair, presentation, overlays };
    },
    template: [
        '<main class="presentation-shell stack"><section v-if="!presentation.snapshot" class="panel stack"><div class="eyebrow">Theatrical RPG</div><h1>Pair Presentation</h1><p class="muted">Enter the one-time display token from the active Control session.</p><p v-if="error" class="error" role="alert">{{ error }}</p><form class="stack" @submit.prevent="pair"><label for="pairing-token">Display token</label><input id="pairing-token" v-model="pairingToken" autocomplete="off" minlength="64" maxlength="64" required><button>Pair display</button></form></section>',
        '<template v-else><PresentationStage v-if="render" :backdrop-asset-id="render.backdrop_asset_id" :transition="render.scene?.transition || \'cut\'" :transition-duration-ms="render.scene?.transition_duration_ms || 0" :stage-tween-duration-ms="render.stage_tween.duration_ms" :stage-tween-easing="render.stage_tween.easing" :entries="render.stage_entries" :asset-urls="assetUrls" />',
        '<section class="presentation-status"><div><div class="eyebrow">Theatrical RPG</div><strong>{{ render?.scene?.name || \'No active scene\' }}</strong></div>',
        '<p v-if="error" class="error" role="alert">{{ error }}</p>',
        '<p class="muted" role="status">Realtime: {{ presentation.status === \'live\' && overlays.status === \'live\' ? \'live\' : \'degraded — polling snapshots\' }}</p>',
        '<p v-if="presentation.snapshot.state.standby_status !== \'idle\'" class="muted">Standby: {{ presentation.snapshot.state.standby_status }}{{ presentation.snapshot.state.standby_error ? \' — \' + presentation.snapshot.state.standby_error : \'\' }}</p>',
        '<p v-if="overlays.snapshot?.state.corner.current"><strong>Corner overlay:</strong> {{ overlays.snapshot.state.corner.current.content }}</p>',
        '<p v-if="overlays.snapshot?.state.full.current"><strong>Full overlay:</strong> {{ overlays.snapshot.state.full.current.content }}</p></section></template></main>',
    ].join(''),
});

createApp(PresentationApp).use(VueKonva).component('PresentationStage', PresentationStage).mount('#app');
