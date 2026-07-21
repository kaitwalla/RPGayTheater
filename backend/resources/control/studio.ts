import { computed, defineComponent, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError } from '../shared/api';
import { commandId } from '../shared/command-id';

type ApiResponse<T> = { data: T };
type StudioRecord = Record<string, string | number | boolean | null | string[]> & { id: string };
type Studio = {
    campaign: { id: string; name: string; draft_revision: number };
    records: Record<string, StudioRecord[]>;
};
type HistoryEntry = { resource: string; id: string; before: Record<string, unknown>; after: Record<string, unknown> };
type SceneCueForm = { name: string; assetId: string; kind: 'music' | 'sfx'; videoName: string; videoAssetId: string };
type SceneModal = 'scene' | 'character' | 'backdrop' | 'stage-entry' | null;
type SceneForm = { name: string; backdropAssetId: string; musicCueId: string; transition: 'cut' | 'fade_black' | 'cross_dissolve' };
type SceneCharacterForm = { name: string; assetId: string; pronouns: string; description: string; facing: 'left' | 'right'; placeOnStage: boolean };
type SceneBackdropForm = { name: string; assetId: string };
type SceneStageEntryForm = { npcId: string; npcStateId: string; positionX: number; positionY: number; scale: number; facing: 'left' | 'right' };

const studioSections = [
    ['overview', 'Overview'], ['library', 'Media library'], ['cast', 'Cast'], ['scenes', 'Scenes'],
    ['maps', 'Maps'], ['cues', 'Sound, video & dice'], ['publish', 'Publish'], ['play', 'Play'],
] as const;

const title = (record: StudioRecord): string => String(record.name ?? record.label ?? record.original_filename ?? 'Untitled');
const inputValue = (event: Event): string => event.target instanceof HTMLInputElement ? event.target.value : '';
const inputChecked = (event: Event): boolean => event.target instanceof HTMLInputElement && event.target.checked;
const selectValue = (event: Event): string => event.target instanceof HTMLSelectElement ? event.target.value : '';
const textareaValue = (event: Event): string => event.target instanceof HTMLTextAreaElement ? event.target.value : '';

export const CampaignStudioView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const studio = ref<Studio | null>(null);
        const active = ref<(typeof studioSections)[number][0]>('overview');
        const saving = ref<'saved' | 'saving' | 'error'>('saved');
        const error = ref('');
        const busy = ref(false);
        const history = ref<HistoryEntry[]>([]);
        const redoHistory = ref<HistoryEntry[]>([]);
        const delayed = new Map<string, ReturnType<typeof setTimeout>>();
        const stagePresetId = ref('');
        const selectedSceneId = ref('');
        const mapId = ref('');
        const fogAssetId = ref('');
        const sceneCueForms = ref<Record<string, SceneCueForm>>({});
        const sceneAttachCueIds = ref<Record<string, string>>({});
        const sceneModal = ref<SceneModal>(null);
        const sceneCharacterForm = ref<SceneCharacterForm>({ name: '', assetId: '', pronouns: '', description: '', facing: 'right', placeOnStage: true });
        const sceneBackdropForm = ref<SceneBackdropForm>({ name: '', assetId: '' });
        const sceneForm = ref<SceneForm>({ name: '', backdropAssetId: '', musicCueId: '', transition: 'cut' });
        const sceneStageEntryForm = ref<SceneStageEntryForm>({ npcId: '', npcStateId: '', positionX: .5, positionY: .65, scale: 1, facing: 'right' });
        const cueSearch = ref('');
        const cueScopeFilter = ref<'global' | 'scene' | 'all'>('global');
        const cueTypeFilter = ref<'all' | 'music' | 'sfx' | 'video' | 'dice'>('all');
        const cueSceneFilter = ref('');
        const assetUrls = ref<Record<string, string>>({});
        const collectionName = ref('');

        const records = (key: string): StudioRecord[] => studio.value?.records[key] ?? [];
        const assets = computed(() => records('assets'));
        const readyImages = computed(() => assets.value.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready' && asset.archived_at === null));
        const stageEntries = computed(() => records('stage_preset_entries').filter((entry) => entry.stage_preset_id === stagePresetId.value));
        const selectedScene = computed(() => records('scenes').find((scene) => scene.id === selectedSceneId.value) ?? records('scenes')[0] ?? null);
        const mapTokens = computed(() => records('map_tokens').filter((token) => token.map_id === mapId.value));
        const selectedMap = computed(() => records('maps').find((map) => map.id === mapId.value) ?? null);
        const selectedFog = computed(() => records('map_fog_masks').find((mask) => mask.map_id === mapId.value) ?? null);
        const readyAudio = computed(() => assets.value.filter((asset) => asset.kind === 'audio' && asset.upload_status === 'ready' && asset.archived_at === null));
        const readyVideos = computed(() => assets.value.filter((asset) => asset.kind === 'video' && asset.upload_status === 'ready' && asset.archived_at === null));
        const cueRecords = computed(() => [...records('audio_cues'), ...records('video_cues')]);
        const cueType = (cue: StudioRecord): 'music' | 'sfx' | 'video' | 'dice' => {
            if (cue.expression) return 'dice';
            if (cue.primary_asset_id) return 'video';
            return cue.kind === 'sfx' ? 'sfx' : 'music';
        };
        const cueResource = (cue: StudioRecord): 'audio-cues' | 'video-cues' | 'dice-presets' => {
            if (cue.expression) return 'dice-presets';
            return cue.primary_asset_id ? 'video-cues' : 'audio-cues';
        };
        const cueScope = (cue: StudioRecord): string => {
            const scene = records('scenes').find((item) => item.id === cue.scene_id);
            return scene ? `Scene: ${title(scene)}` : 'Global';
        };
        const filteredCueLibrary = computed(() => {
            const search = cueSearch.value.trim().toLowerCase();
            return [...cueRecords.value, ...records('dice_presets')].filter((cue) => {
                const type = cueType(cue);
                const sceneId = typeof cue.scene_id === 'string' ? cue.scene_id : '';
                const scopeMatches = cueScopeFilter.value === 'all' || (cueScopeFilter.value === 'global' ? !sceneId : !!sceneId);
                const typeMatches = cueTypeFilter.value === 'all' || cueTypeFilter.value === type;
                const sceneMatches = !cueSceneFilter.value || sceneId === cueSceneFilter.value;
                const searchMatches = !search || title(cue).toLowerCase().includes(search);
                return scopeMatches && typeMatches && sceneMatches && searchMatches;
            });
        });
        const summary = computed(() => [
            ['Media', assets.value.length], ['Cast', records('player_characters').length + records('npcs').length],
            ['Scenes', records('scenes').length], ['Maps', records('maps').length], ['Cues', records('audio_cues').length + records('video_cues').length],
        ]);

        const sceneCues = (sceneId: string): StudioRecord[] => cueRecords.value.filter((cue) => cue.scene_id === sceneId);
        const sceneBackdrops = (sceneId: string): StudioRecord[] => records('scene_backdrops').filter((backdrop) => backdrop.scene_id === sceneId);
        const npcStates = (npcId: string): StudioRecord[] => records('npc_states').filter((state) => state.npc_id === npcId);
        const attachableCues = (sceneId: string): StudioRecord[] => cueRecords.value.filter((cue) => !cue.scene_id || cue.scene_id === sceneId);
        const sceneCueForm = (sceneId: string): SceneCueForm => {
            sceneCueForms.value[sceneId] ??= { name: '', assetId: '', kind: 'music', videoName: '', videoAssetId: '' };
            return sceneCueForms.value[sceneId];
        };
        const openSceneModal = (modal: Exclude<SceneModal, null>): void => { sceneModal.value = modal; };
        const closeSceneModal = (): void => { sceneModal.value = null; };
        const selectScene = (scene: StudioRecord): void => {
            selectedSceneId.value = scene.id;
            stagePresetId.value = String(scene.base_stage_preset_id || '');
            if (scene.primary_backdrop_asset_id) void loadAssetUrl(String(scene.primary_backdrop_asset_id));
        };

        const load = async (): Promise<void> => {
            try {
                const response = await api<ApiResponse<Studio>>(`/api/control/v1/campaigns/${campaignId}/studio`);
                studio.value = response.data;
                if (!selectedScene.value) selectedSceneId.value = records('scenes')[0]?.id ?? '';
                if (selectedScene.value) stagePresetId.value = String(selectedScene.value.base_stage_preset_id || '');
                else stagePresetId.value ||= records('stage_presets')[0]?.id ?? '';
                mapId.value ||= records('maps')[0]?.id ?? '';
                if (selectedScene.value?.primary_backdrop_asset_id) void loadAssetUrl(String(selectedScene.value.primary_backdrop_asset_id));
                records('scenes').forEach((scene) => {
                    if (scene.primary_backdrop_asset_id) void loadAssetUrl(String(scene.primary_backdrop_asset_id));
                });
                if (selectedMap.value) void loadAssetUrl(String(selectedMap.value.image_asset_id));
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load this campaign studio.';
            }
        };

        const loadAssetUrl = async (assetId: string): Promise<void> => {
            if (!assetId || assetUrls.value[assetId]) return;
            try {
                const response = await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${campaignId}/assets/${assetId}/read`);
                assetUrls.value = { ...assetUrls.value, [assetId]: response.data.url };
            } catch { /* A missing preview must not prevent authoring. */ }
        };

        const write = async (resource: string, record: StudioRecord, patch: Record<string, unknown>, remember = true): Promise<void> => {
            if (!studio.value) return;
            saving.value = 'saving'; error.value = '';
            const before = Object.fromEntries(Object.keys(patch).map((key) => [key, record[key]]));
            Object.assign(record, patch);
            try {
                const response = await api<ApiResponse<{ campaign: Studio['campaign']; record: StudioRecord }>>(`/api/control/v1/campaigns/${campaignId}/studio/${resource}/${record.id}`, {
                    method: 'PATCH', body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, patch }),
                });
                studio.value.campaign = response.data.campaign;
                Object.assign(record, response.data.record);
                if (remember) {
                    history.value.push({ resource, id: record.id, before, after: patch });
                    redoHistory.value = [];
                }
                saving.value = 'saved';
            } catch (reason) {
                Object.assign(record, before);
                saving.value = 'error';
                error.value = reason instanceof ApiError && reason.status === 409
                    ? 'This draft changed elsewhere. The studio was reloaded.'
                    : reason instanceof Error ? reason.message : 'Unable to save this change.';
                await load();
            }
        };

        const queueWrite = (resource: string, record: StudioRecord, fields: string[]): void => {
            const key = `${resource}:${record.id}`;
            const prior = delayed.get(key);
            if (prior) clearTimeout(prior);
            delayed.set(key, setTimeout(() => void write(resource, record, Object.fromEntries(fields.map((field) => [field, record[field]]))), 450));
        };

        const undo = async (): Promise<void> => {
            const entry = history.value.pop();
            if (!entry) return;
            const record = Object.values(studio.value?.records ?? {}).flat().find((item) => item.id === entry.id);
            if (!record) return;
            await write(entry.resource, record, entry.before, false);
            redoHistory.value.push(entry);
        };

        const redo = async (): Promise<void> => {
            const entry = redoHistory.value.pop();
            if (!entry) return;
            const record = Object.values(studio.value?.records ?? {}).flat().find((item) => item.id === entry.id);
            if (!record) return;
            await write(entry.resource, record, entry.after, false);
            history.value.push(entry);
        };

        const addCollection = async (): Promise<void> => {
            if (!studio.value || !collectionName.value.trim()) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/studio/asset-collections`, {
                    method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: collectionName.value }),
                });
                collectionName.value = '';
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create the collection.'; }
            finally { busy.value = false; }
        };

        const updateCollectionMembership = (collection: StudioRecord, assetId: string, checked: boolean): void => {
            const current = Array.isArray(collection.asset_ids) ? collection.asset_ids : [];
            const assetIds = checked ? [...current, assetId] : current.filter((id) => id !== assetId);
            void write('asset-collections', collection, { asset_ids: assetIds });
        };

        const beginDrag = (resource: string, record: StudioRecord, event: PointerEvent): void => {
            const target = event.currentTarget as HTMLElement;
            const bounds = target.parentElement?.getBoundingClientRect();
            if (!bounds) return;
            target.setPointerCapture(event.pointerId);
            const move = (moveEvent: PointerEvent): void => {
                record.position_x = Math.max(0, Math.min(1, (moveEvent.clientX - bounds.left) / bounds.width));
                record.position_y = Math.max(0, Math.min(1, (moveEvent.clientY - bounds.top) / bounds.height));
            };
            const finish = (): void => {
                target.removeEventListener('pointermove', move);
                target.removeEventListener('pointerup', finish);
                void write(resource, record, { position_x: record.position_x, position_y: record.position_y });
            };
            target.addEventListener('pointermove', move);
            target.addEventListener('pointerup', finish);
        };

        const setFog = async (): Promise<void> => {
            if (!studio.value || !mapId.value || !fogAssetId.value) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/maps/${mapId.value}/fog-mask`, {
                    method: 'PUT', body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, asset_id: fogAssetId.value }),
                });
                fogAssetId.value = '';
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to set the fog mask.'; }
            finally { busy.value = false; }
        };

        const attachCueToScene = async (sceneId: string): Promise<void> => {
            const cue = cueRecords.value.find((item) => item.id === sceneAttachCueIds.value[sceneId]);
            if (!cue) return;
            await write(cueResource(cue), cue, { scene_id: sceneId });
            sceneAttachCueIds.value = { ...sceneAttachCueIds.value, [sceneId]: '' };
        };

        const makeCueGlobal = async (cue: StudioRecord): Promise<void> => {
            await write(cueResource(cue), cue, { scene_id: null });
        };

        const createSceneAudio = async (sceneId: string): Promise<void> => {
            const form = sceneCueForm(sceneId);
            if (!studio.value || !form.name.trim() || !form.assetId) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/audio-cues`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: form.name, asset_id: form.assetId, scene_id: sceneId, kind: form.kind, loop: form.kind === 'music', default_volume: 100 }),
                });
                form.name = '';
                form.assetId = '';
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this scene sound.'; }
            finally { busy.value = false; }
        };

        const createSceneVideo = async (sceneId: string): Promise<void> => {
            const form = sceneCueForm(sceneId);
            if (!studio.value || !form.videoName.trim() || !form.videoAssetId) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/video-cues`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: form.videoName, primary_asset_id: form.videoAssetId, scene_id: sceneId, fallback_asset_id: null, completion_mode: 'restore_captured_scene', target_scene_id: null, music_during: 'pause', music_after: 'resume_prior', embedded_audio_volume: 100, embedded_audio_muted: false }),
                });
                form.videoName = '';
                form.videoAssetId = '';
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this scene video.'; }
            finally { busy.value = false; }
        };

        const createSceneStage = async (): Promise<string | null> => {
            if (!studio.value || !selectedScene.value) return null;
            const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/stage-presets`, {
                method: 'POST',
                body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: `${title(selectedScene.value)} staging layout`, tween_duration_ms: 800, tween_easing: 'ease_in_out' }),
            });
            await load();
            const scene = selectedScene.value;
            if (!scene) return String(response.data.id);
            await write('scenes', scene, { base_stage_preset_id: response.data.id });
            stagePresetId.value = String(response.data.id);
            return String(response.data.id);
        };

        const ensureSceneStage = async (): Promise<string | null> => {
            if (!selectedScene.value) return null;
            if (selectedScene.value.base_stage_preset_id) return String(selectedScene.value.base_stage_preset_id);
            return createSceneStage();
        };

        const submitScene = async (): Promise<void> => {
            if (!studio.value || !sceneForm.value.name.trim()) return;
            busy.value = true; error.value = '';
            try {
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/scenes`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: sceneForm.value.name, primary_backdrop_asset_id: sceneForm.value.backdropAssetId || null, default_music_cue_id: sceneForm.value.musicCueId || null, base_stage_preset_id: null, transition: sceneForm.value.transition, transition_duration_ms: 0 }),
                });
                selectedSceneId.value = String(response.data.id);
                sceneForm.value = { name: '', backdropAssetId: '', musicCueId: '', transition: 'cut' };
                closeSceneModal();
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this scene.'; await load(); }
            finally { busy.value = false; }
        };

        const submitSceneBackdrop = async (): Promise<void> => {
            const scene = selectedScene.value;
            if (!studio.value || !scene || !sceneBackdropForm.value.name.trim() || !sceneBackdropForm.value.assetId) return;
            busy.value = true; error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/scenes/${scene.id}/backdrops`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: sceneBackdropForm.value.name, asset_id: sceneBackdropForm.value.assetId }),
                });
                sceneBackdropForm.value = { name: '', assetId: '' };
                closeSceneModal();
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to add this backdrop.'; await load(); }
            finally { busy.value = false; }
        };

        const submitSceneStageEntry = async (): Promise<void> => {
            if (!studio.value || !sceneStageEntryForm.value.npcId) return;
            busy.value = true; error.value = '';
            try {
                const presetId = await ensureSceneStage();
                if (!presetId || !studio.value) return;
                await api(`/api/control/v1/campaigns/${campaignId}/stage-presets/${presetId}/entries`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, npc_id: sceneStageEntryForm.value.npcId, npc_state_id: sceneStageEntryForm.value.npcStateId || null, position_x: sceneStageEntryForm.value.positionX, position_y: sceneStageEntryForm.value.positionY, scale: sceneStageEntryForm.value.scale, layer_order: stageEntries.value.length, facing: sceneStageEntryForm.value.facing }),
                });
                sceneStageEntryForm.value = { npcId: '', npcStateId: '', positionX: .5, positionY: .65, scale: 1, facing: 'right' };
                closeSceneModal();
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to place this character.'; await load(); }
            finally { busy.value = false; }
        };

        const submitSceneCharacter = async (): Promise<void> => {
            if (!studio.value || !sceneCharacterForm.value.name.trim() || !sceneCharacterForm.value.assetId) return;
            busy.value = true; error.value = '';
            try {
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/npcs`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: sceneCharacterForm.value.name, normal_asset_id: sceneCharacterForm.value.assetId, pronouns: sceneCharacterForm.value.pronouns || null, public_description: sceneCharacterForm.value.description || null, native_facing: sceneCharacterForm.value.facing }),
                });
                await load();
                if (sceneCharacterForm.value.placeOnStage) {
                    sceneStageEntryForm.value = { npcId: String(response.data.id), npcStateId: '', positionX: .5, positionY: .65, scale: 1, facing: sceneCharacterForm.value.facing };
                    await submitSceneStageEntry();
                }
                sceneCharacterForm.value = { name: '', assetId: '', pronouns: '', description: '', facing: 'right', placeOnStage: true };
                closeSceneModal();
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to add this character.'; await load(); }
            finally { busy.value = false; }
        };

        const remove = async (resource: string, record: StudioRecord): Promise<void> => {
            if (!studio.value || !window.confirm(`Remove “${title(record)}”? Items in use will show where they need attention instead.`)) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/studio/${resource}/${record.id}`, {
                    method: 'DELETE', body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision }),
                });
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to remove this item.'; }
            finally { busy.value = false; }
        };

        const publish = async (): Promise<void> => {
            if (!studio.value || !window.confirm(`Publish ${studio.value.campaign.name} as an immutable revision?`)) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/publish`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision }) });
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to publish this revision.'; }
            finally { busy.value = false; }
        };

        onMounted(load);
        return { sections: studioSections, active, assets, readyImages, readyAudio, readyVideos, stageEntries, selectedSceneId, selectedScene, mapTokens, selectedMap, selectedFog, filteredCueLibrary, studio, saving, error, busy, history, redoHistory, stagePresetId, mapId, fogAssetId, sceneCueForms, sceneAttachCueIds, sceneModal, sceneForm, sceneCharacterForm, sceneBackdropForm, sceneStageEntryForm, cueSearch, cueScopeFilter, cueTypeFilter, cueSceneFilter, assetUrls, collectionName, summary, records, title, inputValue, inputChecked, selectValue, textareaValue, cueType, cueResource, cueScope, sceneCues, sceneBackdrops, npcStates, attachableCues, sceneCueForm, openSceneModal, closeSceneModal, selectScene, loadAssetUrl, queueWrite, undo, redo, addCollection, updateCollectionMembership, beginDrag, setFog, attachCueToScene, makeCueGlobal, createSceneAudio, createSceneVideo, submitScene, submitSceneCharacter, submitSceneBackdrop, submitSceneStageEntry, remove, publish, back: () => router.push('/'), openLegacy: (section: string) => router.push(`/campaigns/${campaignId}/${section}`), openSessions: () => router.push(`/campaigns/${campaignId}/sessions`) };
    },
    template: `
        <main v-if="studio" class="studio-shell">
            <aside class="studio-rail"><RouterLink class="studio-brand" to="/"><span>RPGays</span><strong>Control room</strong></RouterLink><nav aria-label="Campaign studio"><button v-for="section in sections" :key="section[0]" :class="{ active: active === section[0] }" @click="active = section[0]">{{ section[1] }}</button></nav><div class="studio-rail-footer"><span class="save-status" :class="saving">{{ saving === 'saving' ? 'Saving…' : saving === 'error' ? 'Save failed' : 'All changes saved' }}</span><button class="secondary" @click="back">All campaigns</button></div></aside>
            <section class="studio-main"><header class="studio-header"><div><div class="eyebrow">Campaign studio</div><h1>{{ studio.campaign.name }}</h1></div><div class="row"><button class="secondary" :disabled="history.length === 0 || saving === 'saving'" @click="undo">Undo</button><button class="secondary" :disabled="redoHistory.length === 0 || saving === 'saving'" @click="redo">Redo</button><button @click="active = 'publish'">Review & publish</button></div></header>
                <p v-if="error" class="error" role="alert">{{ error }}</p>

                <section v-if="active === 'overview'" class="studio-content stack"><div class="studio-hero"><div><div class="eyebrow">Build the show</div><h2>Everything your next session needs, in one place.</h2><p class="muted">Build the media, cast, scenes, maps, and cues; then publish a revision that stays safe during play.</p></div><button @click="active = 'library'">Start with media</button></div><div class="studio-stats"><article v-for="item in summary" :key="item[0]"><strong>{{ item[1] }}</strong><span>{{ item[0] }}</span></article></div><section class="studio-checklist"><h2>Production checklist</h2><button @click="active = 'library'" :class="{ done: assets.length > 0 }">{{ assets.length > 0 ? '✓' : '1' }} Add media</button><button @click="active = 'cast'" :class="{ done: records('player_characters').length + records('npcs').length > 0 }">{{ records('player_characters').length + records('npcs').length > 0 ? '✓' : '2' }} Build cast</button><button @click="active = 'scenes'" :class="{ done: records('scenes').length > 0 }">{{ records('scenes').length > 0 ? '✓' : '3' }} Compose scenes</button><button @click="active = 'publish'">4 Review & publish</button></section></section>

                <section v-if="active === 'library'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Private media</div><h2>Media library</h2></div><button @click="openLegacy('assets')">Upload media</button></header><div class="library-layout"><section class="asset-grid"><article v-for="asset in assets" :key="asset.id" class="media-card"><div class="media-thumb" :class="asset.kind"><span>{{ asset.kind }}</span></div><input :value="asset.label || asset.original_filename" :aria-label="'Label for ' + asset.original_filename" @input="asset.label = inputValue($event); queueWrite('assets', asset, ['label'])"><small>{{ asset.original_filename }} · {{ asset.upload_status }}</small><button v-if="!asset.archived_at" class="danger" :disabled="busy" @click="remove('assets', asset)">Archive</button></article><p v-if="assets.length === 0" class="muted">Upload images, audio, or video to start your production library.</p></section><aside class="studio-inspector stack"><h3>Collections</h3><form class="stack" @submit.prevent="addCollection"><input v-model="collectionName" maxlength="120" aria-label="Collection name" placeholder="e.g. Act one"><button :disabled="busy">Create collection</button></form><article v-for="collection in records('asset_collections')" :key="collection.id" class="collection-card"><input :value="collection.name" :aria-label="'Collection name ' + collection.name" @input="collection.name = inputValue($event); queueWrite('asset-collections', collection, ['name'])"><label v-for="asset in assets" :key="asset.id"><input type="checkbox" :checked="Array.isArray(collection.asset_ids) && collection.asset_ids.includes(asset.id)" @change="updateCollectionMembership(collection, asset.id, inputChecked($event))">{{ title(asset) }}</label><button class="danger" :disabled="busy" @click="remove('asset-collections', collection)">Remove collection</button></article></aside></div></section>

                <section v-if="active === 'cast'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">People on stage</div><h2>Cast</h2></div><div class="row"><button class="secondary" @click="openLegacy('pcs')">Add PC</button><button @click="openLegacy('npcs')">Add NPC</button></div></header><div class="studio-card-grid"><article v-for="record in [...records('player_characters'), ...records('npcs')]" :key="record.id" class="editor-card"><div class="media-thumb portrait"></div><div class="stack"><input :value="record.name" :aria-label="'Name for ' + title(record)" @input="record.name = inputValue($event); queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['name'])"><input :value="record.pronouns || ''" aria-label="Pronouns" placeholder="Pronouns" @input="record.pronouns = inputValue($event) || null; queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['pronouns'])"><textarea :value="record.public_description || ''" aria-label="Public description" placeholder="Public description" @input="record.public_description = textareaValue($event) || null; queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['public_description'])"></textarea><button class="danger" :disabled="busy" @click="remove(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record)">Remove</button></div></article><p v-if="records('player_characters').length + records('npcs').length === 0" class="muted">Add PCs and NPCs, then return here to edit their public profiles in place.</p></div></section>

                <section v-if="active === 'scenes'" class="studio-content stack">
                    <header class="section-heading"><div><div class="eyebrow">Compose the moment</div><h2>Scene board</h2><p class="muted">A scene is the moment your players see: its backdrop, music, transition, characters, and scene-only cues.</p></div><button @click="openSceneModal('scene')">Create scene</button></header>
                    <div class="scene-workspace">
                        <aside class="scene-selector stack"><div class="row"><h3>Your scenes</h3><span class="muted">{{ records('scenes').length }}</span></div><div class="scene-deck"><button v-for="scene in records('scenes')" :key="scene.id" class="scene-card" :class="{ active: selectedScene?.id === scene.id }" :style="assetUrls[String(scene.primary_backdrop_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(scene.primary_backdrop_asset_id)] + ')' } : {}" @click="selectScene(scene)"><strong>{{ scene.name }}</strong><span>{{ sceneCues(String(scene.id)).length }} scene cues · {{ scene.base_stage_preset_id ? 'cast placed' : 'no cast yet' }}</span></button></div><button class="secondary" @click="openSceneModal('scene')">+ Create another scene</button><p v-if="records('scenes').length === 0" class="muted">Start with a scene title and, if you have one, a backdrop. You can add the rest on the composition board.</p></aside>
                        <section v-if="selectedScene" class="scene-detail stack"><header class="scene-detail-header"><div><div class="eyebrow">Scene composition</div><input class="scene-title-input" :value="selectedScene.name" :aria-label="'Scene name ' + selectedScene.name" @input="selectedScene.name = inputValue($event); queueWrite('scenes', selectedScene, ['name'])"></div><button class="danger" :disabled="busy" @click="remove('scenes', selectedScene)">Delete scene</button></header>
                            <p class="muted">Set the look and sound, then drag characters onto the canvas to choose where they begin.</p>
                            <div class="scene-field-grid"><label>Backdrop<select :value="selectedScene.primary_backdrop_asset_id || ''" aria-label="Primary backdrop" @change="selectedScene.primary_backdrop_asset_id = selectValue($event) || null; queueWrite('scenes', selectedScene, ['primary_backdrop_asset_id']); loadAssetUrl(String(selectedScene.primary_backdrop_asset_id || ''))"><option value="">No backdrop yet</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label><label>Music on entry<select :value="selectedScene.default_music_cue_id || ''" aria-label="Default music" @change="selectedScene.default_music_cue_id = selectValue($event) || null; queueWrite('scenes', selectedScene, ['default_music_cue_id'])"><option value="">No music yet</option><option v-for="cue in records('audio_cues').filter((cue) => cue.kind === 'music')" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select></label><label>How it appears<select :value="selectedScene.transition" aria-label="Transition" @change="selectedScene.transition = selectValue($event); queueWrite('scenes', selectedScene, ['transition'])"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select></label></div>
                            <div class="scene-action-row"><button class="secondary" @click="openSceneModal('character')">Add new character</button><button class="secondary" @click="openSceneModal('stage-entry')">Place existing character</button><button class="secondary" @click="openSceneModal('backdrop')">Add alternate backdrop</button></div>
                            <section class="scene-preview-grid"><div class="scene-backdrop-preview" :style="assetUrls[String(selectedScene.primary_backdrop_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(selectedScene.primary_backdrop_asset_id)] + ')' } : {}"><span v-if="!selectedScene.primary_backdrop_asset_id" class="muted">Choose a backdrop to preview this scene</span></div><div class="composer-panel"><div class="row"><div><h3>Starting positions</h3><p class="muted">Drag characters to block their entrance.</p></div><button class="secondary" @click="openSceneModal('stage-entry')">Place character</button></div><div class="stage-composer" aria-label="Scene starting positions canvas"><button v-for="entry in stageEntries" :key="entry.id" class="stage-token" :style="{ left: (Number(entry.position_x) * 100) + '%', top: (Number(entry.position_y) * 100) + '%', transform: 'translate(-50%, -50%) scale(' + Number(entry.scale) + ')' }" @pointerdown="beginDrag('stage-preset-entries', entry, $event)">{{ records('npcs').find((npc) => npc.id === entry.npc_id)?.name || 'NPC' }}</button><p v-if="stageEntries.length === 0" class="muted">Place a character to set this scene’s starting positions. A private layout is created automatically.</p></div></div></section>
                            <section class="cue-shelf stack"><div class="row"><div><h3>Scene cues</h3><p class="muted">These sounds and videos are available only while this scene is active.</p></div><div class="row"><select v-model="sceneAttachCueIds[String(selectedScene.id)]" :aria-label="'Attach existing cue to ' + selectedScene.name"><option value="">Use an existing cue</option><option v-for="cue in attachableCues(String(selectedScene.id))" :key="cue.id" :value="cue.id">{{ title(cue) }} · {{ cueType(cue) }} · {{ cueScope(cue) }}</option></select><button class="secondary" :disabled="busy || !sceneAttachCueIds[String(selectedScene.id)]" @click="attachCueToScene(String(selectedScene.id))">Add to scene</button></div></div><div class="scene-cue-list"><article v-for="cue in sceneCues(String(selectedScene.id))" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cueType(cue) }}</div></div><div class="row"><button class="secondary" :disabled="busy" @click="makeCueGlobal(cue)">Make global</button><button class="danger" :disabled="busy" @click="remove(cueResource(cue), cue)">Remove</button></div></article><p v-if="sceneCues(String(selectedScene.id)).length === 0" class="muted">No scene-only cues yet.</p></div><div class="cue-create-grid"><form class="stack compact" @submit.prevent="createSceneAudio(String(selectedScene.id))"><h4>Create sound</h4><input v-model="sceneCueForm(String(selectedScene.id)).name" maxlength="120" :aria-label="'New sound for ' + selectedScene.name" placeholder="Sound or music name"><select v-model="sceneCueForm(String(selectedScene.id)).assetId" :aria-label="'Audio file for ' + selectedScene.name"><option value="">Choose audio</option><option v-for="asset in readyAudio" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><select v-model="sceneCueForm(String(selectedScene.id)).kind" :aria-label="'Sound type for ' + selectedScene.name"><option value="music">Music</option><option value="sfx">Sound effect</option></select><button :disabled="busy || !sceneCueForm(String(selectedScene.id)).name.trim() || !sceneCueForm(String(selectedScene.id)).assetId">Create sound</button></form><form class="stack compact" @submit.prevent="createSceneVideo(String(selectedScene.id))"><h4>Create video</h4><input v-model="sceneCueForm(String(selectedScene.id)).videoName" maxlength="120" :aria-label="'New video for ' + selectedScene.name" placeholder="Video cue name"><select v-model="sceneCueForm(String(selectedScene.id)).videoAssetId" :aria-label="'Video file for ' + selectedScene.name"><option value="">Choose video</option><option v-for="asset in readyVideos" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button :disabled="busy || !sceneCueForm(String(selectedScene.id)).videoName.trim() || !sceneCueForm(String(selectedScene.id)).videoAssetId">Create video</button></form></div></section>
                            <section class="cue-shelf stack"><div class="row"><h3>Alternate backdrops</h3><button class="secondary" @click="openSceneModal('backdrop')">Add alternate</button></div><article v-for="backdrop in sceneBackdrops(String(selectedScene.id))" :key="backdrop.id" class="asset"><strong>{{ backdrop.name }}</strong><span class="muted">{{ title(assets.find((asset) => asset.id === backdrop.asset_id) || backdrop) }}</span></article><p v-if="sceneBackdrops(String(selectedScene.id)).length === 0" class="muted">No alternate backdrops yet.</p></section>
                        </section>
                        <section v-else class="scene-empty-state"><h3>Select or create a scene</h3><p class="muted">Every scene gets its own visual composition board—no separate setup objects required.</p><button @click="openSceneModal('scene')">Create your first scene</button></section>
                    </div>
                </section>

                <section v-if="active === 'maps'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Player view</div><h2>Maps</h2></div><button @click="openLegacy('maps')">Add map or token</button></header><div class="studio-split"><aside class="studio-list"><button v-for="map in records('maps')" :key="map.id" :class="{ active: mapId === map.id }" @click="mapId = map.id; loadAssetUrl(String(map.image_asset_id))">{{ map.name }}</button><p v-if="records('maps').length === 0" class="muted">Add a map to start authoring its initial layout.</p></aside><section class="composer-panel"><div v-if="selectedMap" class="map-composer" :style="assetUrls[String(selectedMap.image_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(selectedMap.image_asset_id)] + ')' } : {}"><button v-for="token in mapTokens" :key="token.id" class="map-editor-token" :style="{ left: (Number(token.position_x) * 100) + '%', top: (Number(token.position_y) * 100) + '%', transform: 'translate(-50%, -50%) scale(' + Number(token.scale) + ')' }" @pointerdown="beginDrag('map-tokens', token, $event)">{{ token.label || records('player_characters').find((pc) => pc.id === token.player_character_id)?.name || records('npcs').find((npc) => npc.id === token.npc_id)?.name || 'Token' }}</button><span v-if="selectedFog" class="fog-note">Fog mask configured</span></div><div v-if="selectedMap" class="row"><select v-model="fogAssetId" aria-label="Imported fog mask"><option value="">Import a fog mask</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button class="secondary" :disabled="busy || !fogAssetId" @click="setFog">Set fog mask</button></div></section></div></section>

                <section v-if="active === 'cues'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Reusable and scene-bundled cues</div><h2>Sound, video & dice manager</h2><p class="muted">Filter global cues and scene-specific cues from one place. Scene bundles are edited directly on each scene.</p></div><div class="row"><button class="secondary" @click="openLegacy('audio')">Add audio</button><button class="secondary" @click="openLegacy('video')">Add video</button><button @click="openLegacy('dice')">Add dice preset</button></div></header><section class="filter-bar"><input v-model="cueSearch" aria-label="Search cues" placeholder="Search cues"><select v-model="cueScopeFilter" aria-label="Cue scope"><option value="global">Global only</option><option value="scene">Scene-specific</option><option value="all">All scopes</option></select><select v-model="cueTypeFilter" aria-label="Cue type"><option value="all">All types</option><option value="music">Music</option><option value="sfx">Sound effects</option><option value="video">Video</option><option value="dice">Dice</option></select><select v-model="cueSceneFilter" aria-label="Filter by scene"><option value="">Any scene</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select></section><div class="studio-card-grid"><article v-for="cue in filteredCueLibrary" :key="cue.id" class="editor-card"><div class="row"><div class="eyebrow">{{ cueType(cue) }}</div><small class="muted">{{ cueScope(cue) }}</small></div><input :value="cue.name" :aria-label="'Name for ' + cue.name" @input="cue.name = inputValue($event); queueWrite(cueResource(cue), cue, ['name'])"><p class="muted">{{ cue.expression || cue.completion_mode || (cue.loop ? 'Looping audio' : 'One-shot audio') }}</p><select v-if="cueResource(cue) !== 'dice-presets'" :value="cue.scene_id || ''" :aria-label="'Scene for ' + cue.name" @change="cue.scene_id = selectValue($event) || null; queueWrite(cueResource(cue), cue, ['scene_id'])"><option value="">Global</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select></article><p v-if="filteredCueLibrary.length === 0" class="muted">No cues match these filters.</p></div></section>

                <section v-if="active === 'publish'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Freeze a performance-ready revision</div><h2>Publish review</h2></div></header><article class="review-card"><h3>Draft revision {{ studio.campaign.draft_revision }}</h3><p class="muted">Publishing snapshots your complete campaign. Existing sessions stay pinned until you explicitly adopt the new revision.</p><button :disabled="busy || saving === 'saving'" @click="publish">Publish immutable revision</button></article><RouterLink class="button secondary" :to="'/campaigns/' + studio.campaign.id + '/sessions'">View revision history and sessions</RouterLink></section>

                <section v-if="active === 'play'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Take it live</div><h2>Session launcher</h2></div></header><article class="review-card"><h3>Ready to run the show?</h3><p class="muted">Choose a published revision, start or resume progress, then hand the player code and private display pairing token to the right people.</p><button @click="openSessions">Open session launcher</button></article></section>
            </section>
            <div v-if="sceneModal && (sceneModal === 'scene' || selectedScene)" class="modal-backdrop" role="presentation" @click.self="closeSceneModal"><section class="modal-panel stack" role="dialog" aria-modal="true"><header class="row"><div><div class="eyebrow">{{ sceneModal === 'scene' ? 'Scene board' : selectedScene?.name }}</div><h2 v-if="sceneModal === 'scene'">Create a scene</h2><h2 v-if="sceneModal === 'character'">Add character</h2><h2 v-if="sceneModal === 'stage-entry'">Place character</h2><h2 v-if="sceneModal === 'backdrop'">Add backdrop</h2></div><button class="secondary" @click="closeSceneModal">Close</button></header><form v-if="sceneModal === 'scene'" class="stack" @submit.prevent="submitScene"><p class="muted">Start with a title. Backdrop and entry music are optional and editable on the composition board.</p><input v-model="sceneForm.name" maxlength="120" aria-label="Scene name" placeholder="e.g. The moonlit archive"><select v-model="sceneForm.backdropAssetId" aria-label="Scene backdrop"><option value="">Choose a backdrop later</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><select v-model="sceneForm.musicCueId" aria-label="Scene entry music"><option value="">No entry music</option><option v-for="cue in records('audio_cues').filter((cue) => cue.kind === 'music')" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select><label>How it appears<select v-model="sceneForm.transition" aria-label="Scene transition"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select></label><button :disabled="busy || !sceneForm.name.trim()">{{ busy ? 'Creating...' : 'Create scene' }}</button></form><form v-if="sceneModal === 'character'" class="stack" @submit.prevent="submitSceneCharacter"><input v-model="sceneCharacterForm.name" maxlength="120" aria-label="Character name" placeholder="Character name"><select v-model="sceneCharacterForm.assetId" aria-label="Character image"><option value="">Choose character image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><input v-model="sceneCharacterForm.pronouns" maxlength="120" aria-label="Pronouns" placeholder="Pronouns"><textarea v-model="sceneCharacterForm.description" maxlength="500" aria-label="Public description" placeholder="Public description"></textarea><div class="segmented"><label><input v-model="sceneCharacterForm.facing" type="radio" value="left"> Facing left</label><label><input v-model="sceneCharacterForm.facing" type="radio" value="right"> Facing right</label></div><label class="check-row"><input v-model="sceneCharacterForm.placeOnStage" type="checkbox"> Place in this scene’s starting positions</label><button :disabled="busy || !sceneCharacterForm.name.trim() || !sceneCharacterForm.assetId">{{ busy ? 'Adding...' : 'Add character' }}</button></form><form v-if="sceneModal === 'stage-entry'" class="stack" @submit.prevent="submitSceneStageEntry"><p class="muted">Choose where this character begins. You can drag them on the scene board afterward.</p><select v-model="sceneStageEntryForm.npcId" aria-label="Character"><option value="">Choose character</option><option v-for="npc in records('npcs')" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><select v-model="sceneStageEntryForm.npcStateId" aria-label="Character appearance"><option value="">Normal appearance</option><option v-for="state in npcStates(sceneStageEntryForm.npcId)" :key="state.id" :value="state.id">{{ state.name }}</option></select><div class="scene-field-grid"><label>Horizontal position<input v-model.number="sceneStageEntryForm.positionX" type="number" min="0" max="1" step=".01"></label><label>Vertical position<input v-model.number="sceneStageEntryForm.positionY" type="number" min="0" max="1" step=".01"></label><label>Size<input v-model.number="sceneStageEntryForm.scale" type="number" min=".1" max="5" step=".1"></label></div><div class="segmented"><label><input v-model="sceneStageEntryForm.facing" type="radio" value="left"> Facing left</label><label><input v-model="sceneStageEntryForm.facing" type="radio" value="right"> Facing right</label></div><button :disabled="busy || !sceneStageEntryForm.npcId">{{ busy ? 'Placing...' : 'Place character' }}</button></form><form v-if="sceneModal === 'backdrop'" class="stack" @submit.prevent="submitSceneBackdrop"><input v-model="sceneBackdropForm.name" maxlength="120" aria-label="Backdrop name" placeholder="Backdrop name"><select v-model="sceneBackdropForm.assetId" aria-label="Backdrop image"><option value="">Choose image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button :disabled="busy || !sceneBackdropForm.name.trim() || !sceneBackdropForm.assetId">{{ busy ? 'Adding...' : 'Add backdrop' }}</button></form></section></div>
        </main>
        <main v-else class="shell stack"><p v-if="error" class="error" role="alert">{{ error }}</p><p v-else class="muted">Opening campaign studio…</p></main>`,
});
