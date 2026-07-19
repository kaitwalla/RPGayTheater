import { createApp, defineComponent, onBeforeUnmount, onMounted } from 'vue';
import { api } from '../shared/api';
import { useRealtimeSnapshot } from '../shared/realtime';
import '../css/app.css';


type Snapshot<T> = { data: T };
type PresentationState = { live_session_id: string; revision: number; state: { scene_id: string | null; stage_entries: unknown[] } };
type OverlayState = { live_session_id: string; revision: number; state: { corner: { current: { content: string } | null }; full: { current: { content: string } | null } } };

const PresentationApp = defineComponent({
    setup() {
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
        onMounted(() => { void presentation.start(); void overlays.start(); });
        onBeforeUnmount(() => { presentation.stop(); overlays.stop(); });

        return { presentation, overlays };
    },
    template: [
        '<main class="shell"><section class="panel stack"><div class="eyebrow">Theatrical RPG</div><h1>Presentation</h1>',
        '<p class="muted" role="status">Realtime: {{ presentation.status === \'live\' && overlays.status === \'live\' ? \'live\' : \'degraded — polling snapshots\' }}</p>',
        '<p v-if="!presentation.snapshot" class="muted">Pair this display to load the session.</p>',
        '<div v-else class="stack"><p class="muted">Scene: {{ presentation.snapshot.state.scene_id || \'No active scene\' }}</p>',
        '<p class="muted">Staged NPCs: {{ presentation.snapshot.state.stage_entries.length }}</p>',
        '<p v-if="overlays.snapshot?.state.corner.current"><strong>Corner overlay:</strong> {{ overlays.snapshot.state.corner.current.content }}</p>',
        '<p v-if="overlays.snapshot?.state.full.current"><strong>Full overlay:</strong> {{ overlays.snapshot.state.full.current.content }}</p></div></section></main>',
    ].join(''),
});

createApp(PresentationApp).mount('#app');
