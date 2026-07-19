import { createApp, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import VueKonva from 'vue-konva';
import { api, ApiError } from '../shared/api';
import { useRealtimeSnapshot } from '../shared/realtime';
import { PresentationStage, type PresentationStageEntry } from '../shared/presentation-stage';
import '../css/app.css';


type Snapshot<T> = { data: T };
type PresentationState = { live_session_id: string; revision: number; state: { scene_id: string | null; backdrop_asset_id: string | null; stage_entries: unknown[]; standby: { backdrop_asset_id: string | null } | null; standby_status: 'idle' | 'preparing' | 'ready' | 'error'; standby_error: string | null } };
type OverlayState = { live_session_id: string; revision: number; state: { corner: { current: { content: string } | null }; full: { current: { content: string } | null } } };
type PresentationRenderCue = { scene: { id: string; name: string | null; transition: string; transition_duration_ms: number } | null; backdrop_asset_id: string | null; music: { asset_id: string; loop: boolean; volume: number; status: 'playing' | 'paused' | 'stopped'; position_seconds: number; position_command_id: string | null; fade_duration_ms: number } | null; video: { id: string; primary_asset_id: string; fallback_asset_id: string | null; completion_mode: 'restore_captured_scene' | 'enter_target_scene'; target_scene_id: string | null; music_during: 'continue' | 'pause' | 'stop'; music_after: 'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent'; embedded_audio_volume: number; embedded_audio_muted: boolean } | null; stage_tween: { duration_ms: number; easing: 'linear' | 'ease_in' | 'ease_out' | 'ease_in_out' }; stage_entries: PresentationStageEntry[] };
type PresentationRender = PresentationRenderCue & { live_session_id: string; revision: number; standby: PresentationRenderCue | null };

const PresentationApp = defineComponent({
    setup() {
        const pairingToken = ref('');
        const error = ref('');
        const render = ref<PresentationRender | null>(null);
        const assetUrls = ref<Record<string, string>>({});
        const audioUnlocked = ref(false);
        const videoElement = ref<HTMLVideoElement | null>(null);
        let music: HTMLAudioElement | null = null;
        let musicAssetId: string | null = null;
        let musicPositionCommandId: string | null = null;
        let videoCueId: string | null = null;
        let fallbackAttempted = false;
        let completingVideo = false;
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
            const imageAssetIds = cues.flatMap((cue) => [cue.backdrop_asset_id, ...cue.stage_entries.map((entry) => entry.asset_id)]).filter((assetId): assetId is string => assetId !== null);
            const audioAssetIds = cues.flatMap((cue) => [cue.music?.asset_id ?? null]).filter((assetId): assetId is string => assetId !== null);
            const videoAssetIds = cues.flatMap((cue) => [cue.video?.primary_asset_id ?? null, cue.video?.fallback_asset_id ?? null]).filter((assetId): assetId is string => assetId !== null);
            const assetIds = [...imageAssetIds, ...audioAssetIds, ...videoAssetIds];
            const missing = assetIds.filter((assetId) => assetUrls.value[assetId] === undefined);
            const urls = await Promise.all(missing.map(async (assetId) => [assetId, (await api<Snapshot<{ url: string }>>(`/api/presentation/v1/assets/${assetId}/read`)).data.url] as const));
            await Promise.all(urls.filter(([assetId]) => imageAssetIds.includes(assetId)).map(([, url]) => new Promise<void>((resolve, reject) => { const image = new Image(); image.onload = () => resolve(); image.onerror = () => reject(new Error('A presentation image could not be decoded.')); image.src = url; })));
            await Promise.all(urls.filter(([assetId]) => audioAssetIds.includes(assetId) || videoAssetIds.includes(assetId)).map(([assetId, url]) => new Promise<void>((resolve, reject) => { const media = videoAssetIds.includes(assetId) ? document.createElement('video') : new Audio(); media.preload = 'auto'; media.onloadedmetadata = () => resolve(); media.onerror = () => reject(new Error('A presentation media asset could not be decoded.')); media.src = url; media.load(); })));
            assetUrls.value = { ...assetUrls.value, ...Object.fromEntries(urls) };
            render.value = next;
        };
        const syncMusic = (): void => {
            const cue = render.value?.music;
            const video = render.value?.video;
            if (video?.music_during === 'pause') { music?.pause(); return; }
            if (video?.music_during === 'stop') { music?.pause(); if (music) music.currentTime = 0; music = null; musicAssetId = null; musicPositionCommandId = null; return; }
            if (!cue) { music?.pause(); music = null; musicAssetId = null; musicPositionCommandId = null; return; }
            if (cue.status === 'stopped') { music?.pause(); if (music) music.currentTime = 0; music = null; musicAssetId = null; musicPositionCommandId = null; return; }
            if (!audioUnlocked.value) return;
            const sourceChanged = cue.asset_id !== musicAssetId;
            if (sourceChanged) { music?.pause(); music = new Audio(assetUrls.value[cue.asset_id]); musicAssetId = cue.asset_id; }
            if (!music) return;
            music.loop = cue.loop;
            const targetVolume = Math.min(1, Math.max(0, cue.volume));
            if (cue.fade_duration_ms > 0) { const start = music.volume; const startedAt = performance.now(); const fade = (): void => { if (!music) return; const progress = Math.min(1, (performance.now() - startedAt) / cue.fade_duration_ms); music.volume = start + (targetVolume - start) * progress; if (progress < 1) requestAnimationFrame(fade); }; requestAnimationFrame(fade); } else music.volume = targetVolume;
            if (Number.isFinite(cue.position_seconds) && (sourceChanged || cue.position_command_id !== musicPositionCommandId)) music.currentTime = cue.position_seconds;
            musicPositionCommandId = cue.position_command_id;
            if (cue.status === 'paused') { music.pause(); return; }
            void music.play().catch((reason) => { error.value = reason instanceof Error ? reason.message : 'Unable to start scene music.'; });
        };
        const finishVideo = async (failed: boolean): Promise<void> => {
            const cue = render.value?.video;
            if (!cue || !presentation.snapshot.value || completingVideo) return;
            completingVideo = true;
            try {
                const response = await api<Snapshot<PresentationState>>(`/api/presentation/v1/video/${failed ? 'fail' : 'complete'}`, { method: 'POST', body: JSON.stringify({ command_id: crypto.randomUUID(), expected_revision: presentation.snapshot.value.revision, video_cue_id: cue.id }) });
                presentation.snapshot.value = response.data;
            } catch (reason) {
                if (!(reason instanceof ApiError && reason.status === 409)) error.value = reason instanceof Error ? reason.message : 'Unable to recover from video playback.';
            } finally { completingVideo = false; }
        };
        const syncVideo = (): void => {
            const cue = render.value?.video;
            const element = videoElement.value;
            if (!cue) { videoCueId = null; fallbackAttempted = false; if (element) { element.pause(); element.removeAttribute('src'); element.load(); } return; }
            if (!element) return;
            if (cue.id === videoCueId) return;
            videoCueId = cue.id;
            fallbackAttempted = false;
            element.src = assetUrls.value[cue.primary_asset_id];
            element.muted = cue.embedded_audio_muted;
            element.volume = Math.min(1, Math.max(0, cue.embedded_audio_volume / 100));
            element.load();
            if (audioUnlocked.value || element.muted) void element.play().catch((reason) => { error.value = reason instanceof Error ? reason.message : 'Enable sound to start the video.'; });
        };
        const recoverVideo = (): void => {
            const cue = render.value?.video;
            const element = videoElement.value;
            if (!cue || !element) return;
            if (!fallbackAttempted && cue.fallback_asset_id && assetUrls.value[cue.fallback_asset_id]) {
                fallbackAttempted = true;
                element.src = assetUrls.value[cue.fallback_asset_id];
                element.load();
                if (audioUnlocked.value || element.muted) void element.play().catch(() => { void finishVideo(true); });
                return;
            }
            error.value = 'Video playback failed; restoring the captured scene.';
            void finishVideo(true);
        };
        const playVideo = (): void => { const element = videoElement.value; if (element) void element.play().catch((reason) => { error.value = reason instanceof Error ? reason.message : 'Unable to start video playback.'; }); };
        const unlockAudio = (): void => { audioUnlocked.value = true; syncMusic(); playVideo(); };
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
        onBeforeUnmount(() => { presentation.stop(); overlays.stop(); music?.pause(); videoElement.value?.pause(); });

        watch(() => presentation.snapshot.value, async (snapshot) => {
            if (!snapshot) return;
            try {
                await loadRender();
                syncMusic();
                await nextTick();
                syncVideo();
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

        return { pairingToken, error, render, assetUrls, audioUnlocked, unlockAudio, pair, presentation, overlays, videoElement, finishVideo, recoverVideo };
    },
    template: [
        '<main class="presentation-shell stack"><section v-if="!presentation.snapshot" class="panel stack"><div class="eyebrow">Theatrical RPG</div><h1>Pair Presentation</h1><p class="muted">Enter the one-time display token from the active Control session.</p><p v-if="error" class="error" role="alert">{{ error }}</p><form class="stack" @submit.prevent="pair"><label for="pairing-token">Display token</label><input id="pairing-token" v-model="pairingToken" autocomplete="off" minlength="64" maxlength="64" required><button>Pair display</button></form></section>',
        '<template v-else><PresentationStage v-if="render" :backdrop-asset-id="render.backdrop_asset_id" :transition="render.scene?.transition || \'cut\'" :transition-duration-ms="render.scene?.transition_duration_ms || 0" :stage-tween-duration-ms="render.stage_tween.duration_ms" :stage-tween-easing="render.stage_tween.easing" :entries="render.stage_entries" :asset-urls="assetUrls" /><video v-if="render?.video" ref="videoElement" class="presentation-video" playsinline @ended="finishVideo(false)" @error="recoverVideo"></video>',
        '<section class="presentation-status"><div><div class="eyebrow">Theatrical RPG</div><strong>{{ render?.scene?.name || \'No active scene\' }}</strong></div>',
        '<p v-if="error" class="error" role="alert">{{ error }}</p>',
        '<button v-if="!audioUnlocked" class="secondary" @click="unlockAudio">Enable sound</button>',
        '<p class="muted" role="status">Realtime: {{ presentation.status === \'live\' && overlays.status === \'live\' ? \'live\' : \'degraded — polling snapshots\' }}</p>',
        '<p v-if="presentation.snapshot.state.standby_status !== \'idle\'" class="muted">Standby: {{ presentation.snapshot.state.standby_status }}{{ presentation.snapshot.state.standby_error ? \' — \' + presentation.snapshot.state.standby_error : \'\' }}</p>',
        '<p v-if="overlays.snapshot?.state.corner.current"><strong>Corner overlay:</strong> {{ overlays.snapshot.state.corner.current.content }}</p>',
        '<p v-if="overlays.snapshot?.state.full.current"><strong>Full overlay:</strong> {{ overlays.snapshot.state.full.current.content }}</p></section></template></main>',
    ].join(''),
});

createApp(PresentationApp).use(VueKonva).component('PresentationStage', PresentationStage).mount('#app');
