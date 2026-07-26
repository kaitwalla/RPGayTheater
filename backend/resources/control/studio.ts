import { computed, defineComponent, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError } from '../shared/api';
import { commandId } from '../shared/command-id';
import { PresentationStage, type PresentationStageEntry } from '../shared/presentation-stage';

type ApiResponse<T> = { data: T };
type CampaignRevision = { id: string; number: number; name: string; published_at: string; archived_at: string | null };
type LiveSessionRecord = { id: string };
type StudioRecord = Record<string, string | number | boolean | null | string[]> & { id: string };
type Studio = {
    campaign: { id: string; name: string; draft_revision: number };
    records: Record<string, StudioRecord[]>;
};
type HistoryEntry = { resource: string; id: string; before: Record<string, unknown>; after: Record<string, unknown> };
type QueuedWrite = { resource: string; record: StudioRecord; fields: Set<string> };
type SceneModal = 'scene' | 'character' | 'backdrop' | 'stage-entry' | null;
type SceneCueEditorForm = {
    id: string;
    type: 'music' | 'sfx' | 'video';
    name: string;
    assetId: string;
    scope: 'global' | 'scene';
    sceneId: string;
    fallbackAssetId: string;
    completionMode: 'restore_captured_scene' | 'enter_target_scene';
    targetSceneId: string;
    concurrentMusicCueId: string;
    musicDuring: 'continue' | 'pause' | 'stop';
    musicAfter: 'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent';
    embeddedAudioVolume: number;
    embeddedAudioMuted: boolean;
};
type SceneForm = { name: string; controlNotes: string; backdropAssetId: string; musicCueId: string; videoCueId: string; transition: 'cut' | 'fade_black' | 'cross_dissolve' };
type SceneCharacterForm = { name: string; assetId: string; pronouns: string; description: string; controlNotes: string; placeOnStage: boolean };
type NpcStateDraft = { name: string; assetId: string };
type EmotionDrafts = Record<string, Record<string, string>>;
type CharacterArtUploadTarget = 'pc' | 'npc' | 'emotion';
type SceneBackdropForm = { name: string; assetId: string };
type SceneStageEntryForm = { npcId: string; npcStateId: string; positionX: number; positionY: number; scale: number; facing: 'left' | 'right' };
type StagePresetDraft = { name: string; backdropId: string; tweenDurationMs: number; tweenEasing: 'linear' | 'ease_in' | 'ease_out' | 'ease_in_out' };
type CharacterDraft = { name: string; pronouns: string; description: string; controlNotes: string; imageAssetId: string };
type MapTokenDraft = {
    type: 'pc' | 'npc' | 'custom';
    playerCharacterId: string;
    npcId: string;
    assetId: string;
    label: string;
    positionX: number;
    positionY: number;
    scale: number;
};
type CueDraft = { type: 'music' | 'sfx' | 'video' | 'dice'; name: string; assetId: string; expression: string };

const studioSections = [
    ['overview', 'Overview'],
    ['library', 'Media library'],
    ['cast', 'Cast'],
    ['scenes', 'Scenes'],
    ['maps', 'Maps'],
    ['cues', 'Sound, video & dice'],
    ['publish', 'Publish'],
    ['play', 'Preview'],
] as const;
const emotionNames = ['Normal', 'Shocked', 'Angry', 'Sad', 'Sneaky', 'Disgusted', 'Thinking', 'Smirk'] as const;

const title = (record: StudioRecord): string => String(record.name ?? record.label ?? record.original_filename ?? 'Untitled');
const inputValue = (event: Event): string => (event.target instanceof HTMLInputElement ? event.target.value : '');
const inputChecked = (event: Event): boolean => event.target instanceof HTMLInputElement && event.target.checked;
const selectValue = (event: Event): string => (event.target instanceof HTMLSelectElement ? event.target.value : '');
const textareaValue = (event: Event): string => (event.target instanceof HTMLTextAreaElement ? event.target.value : '');

export const CampaignStudioView = defineComponent({
    components: { PresentationStage },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const studio = ref<Studio | null>(null);
        const requestedSection = typeof route.query.section === 'string' ? route.query.section : 'overview';
        const active = ref<(typeof studioSections)[number][0]>(
            studioSections.some(([section]) => section === requestedSection) ? (requestedSection as (typeof studioSections)[number][0]) : 'overview',
        );
        const saving = ref<'saved' | 'saving' | 'error'>('saved');
        const error = ref('');
        const busy = ref(false);
        const history = ref<HistoryEntry[]>([]);
        const redoHistory = ref<HistoryEntry[]>([]);
        const delayed = new Map<string, ReturnType<typeof setTimeout>>();
        const queuedWrites = new Map<string, QueuedWrite>();
        const writesInFlight = new Set<string>();
        const stagePresetId = ref('');
        const sceneStagePresetName = ref('');
        const stagePresetDraft = ref<StagePresetDraft>({ name: '', backdropId: '', tweenDurationMs: 800, tweenEasing: 'ease_in_out' });
        const stagePresetSaveState = ref<'saved' | 'saving' | 'error'>('saved');
        const stageLayoutSaveState = ref<'saved' | 'saving' | 'error'>('saved');
        const selectedSceneId = ref('');
        const mapId = ref('');
        const fogAssetId = ref('');
        const cueModalOpen = ref(false);
        const cueEditor = ref<SceneCueEditorForm>({
            id: '',
            type: 'music',
            name: '',
            assetId: '',
            scope: 'scene',
            sceneId: '',
            fallbackAssetId: '',
            completionMode: 'restore_captured_scene',
            targetSceneId: '',
            concurrentMusicCueId: '',
            musicDuring: 'pause',
            musicAfter: 'resume_prior',
            embeddedAudioVolume: 100,
            embeddedAudioMuted: false,
        });
        const cueFile = ref<File | null>(null);
        const backdropFile = ref<File | null>(null);
        const backdropUploadModalOpen = ref(false);
        const sceneModal = ref<SceneModal>(null);
        const sceneCharacterForm = ref<SceneCharacterForm>({ name: '', assetId: '', pronouns: '', description: '', controlNotes: '', placeOnStage: true });
        const sceneBackdropForm = ref<SceneBackdropForm>({ name: '', assetId: '' });
        const sceneForm = ref<SceneForm>({ name: '', controlNotes: '', backdropAssetId: '', musicCueId: '', videoCueId: '', transition: 'cut' });
        const sceneStageEntryForm = ref<SceneStageEntryForm>({ npcId: '', npcStateId: '', positionX: 0.5, positionY: 0.65, scale: 1, facing: 'right' });
        const cueSearch = ref('');
        const cueTypeFilter = ref<'all' | 'music' | 'sfx' | 'video' | 'dice'>('all');
        const assetUrls = ref<Record<string, string>>({});
        const collectionName = ref('');
        const collectionAssetSelections = ref<Record<string, string>>({});
        const npcStateDrafts = ref<Record<string, NpcStateDraft>>({});
        const emotionDrafts = ref<EmotionDrafts>({});
        const libraryFiles = ref<File[]>([]);
        const libraryUploadProgress = ref({ completed: 0, total: 0 });
        const mapFile = ref<File | null>(null);
        const mapDraft = ref({ name: '', imageAssetId: '' });
        const mapTokenDraft = ref<MapTokenDraft>({
            type: 'pc',
            playerCharacterId: '',
            npcId: '',
            assetId: '',
            label: '',
            positionX: 0.5,
            positionY: 0.5,
            scale: 1,
        });
        const playerCharacterDraft = ref<CharacterDraft>({ name: '', pronouns: '', description: '', controlNotes: '', imageAssetId: '' });
        const npcDraft = ref<CharacterDraft>({ name: '', pronouns: '', description: '', controlNotes: '', imageAssetId: '' });
        const castCreationKind = ref<'pc' | 'npc'>('pc');
        const characterArtUploadModalOpen = ref(false);
        const characterArtUploadFile = ref<File | null>(null);
        const characterArtUploadTarget = ref<CharacterArtUploadTarget>('pc');
        const characterArtUploadNpcId = ref('');
        const characterArtUploadEmotion = ref<(typeof emotionNames)[number]>('Normal');
        const replacementAsset = ref<StudioRecord | null>(null);
        const replacementFile = ref<File | null>(null);
        const cueDraft = ref<CueDraft>({ type: 'music', name: '', assetId: '', expression: '' });

        const records = (key: string): StudioRecord[] => studio.value?.records[key] ?? [];
        const assets = computed(() => records('assets'));
        const activeAssets = computed(() => assets.value.filter((asset) => asset.archived_at === null));
        const archivedAssets = computed(() => assets.value.filter((asset) => asset.archived_at !== null));
        const readyImages = computed(() => activeAssets.value.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready'));
        const stagePresets = computed(() => records('stage_presets'));
        const stageEntries = computed(() => records('stage_preset_entries').filter((entry) => entry.stage_preset_id === stagePresetId.value));
        const selectedScene = computed(() => records('scenes').find((scene) => scene.id === selectedSceneId.value) ?? records('scenes')[0] ?? null);
        const selectedStagePreset = computed(() => stagePresets.value.find((preset) => preset.id === stagePresetId.value) ?? null);
        const selectedSceneBackdrops = computed(() => records('scene_backdrops').filter((backdrop) => backdrop.scene_id === selectedScene.value?.id));
        const stageEntryName = (entry: StudioRecord): string => title(records('npcs').find((npc) => npc.id === entry.npc_id) ?? entry);
        const sceneStageEntries = computed<PresentationStageEntry[]>(() =>
            stageEntries.value.flatMap((entry) => {
                const npc = records('npcs').find((record) => record.id === entry.npc_id);
                if (!npc) return [];
                const state = entry.npc_state_id ? records('npc_states').find((record) => record.id === entry.npc_state_id) : undefined;
                const assetId = String(state?.asset_id ?? npc.normal_asset_id ?? '');
                if (!assetId) return [];

                return [
                    {
                        npc_id: String(entry.npc_id),
                        npc_state_id: entry.npc_state_id ? String(entry.npc_state_id) : null,
                        name: title(npc),
                        asset_id: assetId,
                        position_x: Number(entry.position_x),
                        position_y: Number(entry.position_y),
                        scale: Number(entry.scale),
                        layer_order: Number(entry.layer_order),
                        facing: entry.facing === 'left' ? 'left' : 'right',
                        native_facing: 'right',
                    },
                ];
            }),
        );
        const mapTokens = computed(() => records('map_tokens').filter((token) => token.map_id === mapId.value));
        const selectedMap = computed(() => records('maps').find((map) => map.id === mapId.value) ?? null);
        const selectedFog = computed(() => records('map_fog_masks').find((mask) => mask.map_id === mapId.value) ?? null);
        const readyAudio = computed(() => activeAssets.value.filter((asset) => asset.kind === 'audio' && asset.upload_status === 'ready'));
        const readyVideos = computed(() => activeAssets.value.filter((asset) => asset.kind === 'video' && asset.upload_status === 'ready'));
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
        const globalCueLibrary = computed(() => {
            const search = cueSearch.value.trim().toLowerCase();
            return [...cueRecords.value, ...records('dice_presets')].filter((cue) => {
                const type = cueType(cue);
                const typeMatches = cueTypeFilter.value === 'all' || cueTypeFilter.value === type;
                const searchMatches = !search || title(cue).toLowerCase().includes(search);
                return !cue.scene_id && typeMatches && searchMatches;
            });
        });
        const summary = computed(() => [
            ['Media', activeAssets.value.length],
            ['Cast', records('player_characters').length + records('npcs').length],
            ['Scenes', records('scenes').length],
            ['Maps', records('maps').length],
            ['Cues', records('audio_cues').length + records('video_cues').length],
        ]);

        const sceneCues = (sceneId: string): StudioRecord[] => cueRecords.value.filter((cue) => cue.scene_id === sceneId);
        const sceneBackdrops = (sceneId: string): StudioRecord[] => records('scene_backdrops').filter((backdrop) => backdrop.scene_id === sceneId);
        const npcStates = (npcId: string): StudioRecord[] => records('npc_states').filter((state) => state.npc_id === npcId);
        const isNpc = (record: StudioRecord): boolean => records('npcs').some((npc) => npc.id === record.id);
        const characterArtId = (record: StudioRecord): string => String(isNpc(record) ? record.normal_asset_id || '' : record.avatar_asset_id || '');
        const characterInitial = (record: StudioRecord): string => title(record).trim().slice(0, 1).toUpperCase() || '?';
        const characterKind = (record: StudioRecord): string => (isNpc(record) ? 'NPC · stage ready' : 'PC · map roster');
        const npcStateDraft = (npcId: string): NpcStateDraft => npcStateDrafts.value[npcId] ?? (npcStateDrafts.value[npcId] = { name: '', assetId: '' });
        const emotionState = (npcId: string, emotion: string): StudioRecord | undefined =>
            npcStates(npcId).find((state) => String(state.name).toLowerCase() === emotion.toLowerCase());
        const emotionAssetId = (npc: StudioRecord, emotion: string): string => {
            if (emotion === 'Normal') return emotionDrafts.value[npc.id]?.[emotion] ?? String(npc.normal_asset_id || '');
            return emotionDrafts.value[npc.id]?.[emotion] ?? String(emotionState(npc.id, emotion)?.asset_id || '');
        };
        const setEmotionAsset = (npcId: string, emotion: string, assetId: string): void => {
            emotionDrafts.value = { ...emotionDrafts.value, [npcId]: { ...emotionDrafts.value[npcId], [emotion]: assetId } };
        };
        const emotionKitComplete = (npc: StudioRecord): boolean => emotionNames.every((emotion) => !!emotionAssetId(npc, emotion));
        const cueEditorAssets = computed(() => (cueEditor.value.type === 'video' ? readyVideos.value : readyAudio.value));
        const cueDraftAssets = computed(() => (cueDraft.value.type === 'video' ? readyVideos.value : readyAudio.value));
        const openSceneModal = (modal: Exclude<SceneModal, null>): void => {
            sceneModal.value = modal;
        };
        const closeSceneModal = (): void => {
            sceneModal.value = null;
        };
        const openBackdropUploadModal = (): void => {
            backdropFile.value = null;
            backdropUploadModalOpen.value = true;
        };
        const closeBackdropUploadModal = (): void => {
            backdropFile.value = null;
            backdropUploadModalOpen.value = false;
        };
        const cueEditorDefaults = (type: SceneCueEditorForm['type'], scope: SceneCueEditorForm['scope'], sceneId = ''): SceneCueEditorForm => ({
            id: '',
            type,
            name: '',
            assetId: '',
            scope,
            sceneId,
            fallbackAssetId: '',
            completionMode: 'restore_captured_scene',
            targetSceneId: '',
            concurrentMusicCueId: '',
            musicDuring: 'pause',
            musicAfter: 'resume_prior',
            embeddedAudioVolume: 100,
            embeddedAudioMuted: false,
        });
        const openCueModal = (type: SceneCueEditorForm['type'] = 'music', cue?: StudioRecord): void => {
            const cueKind = cue ? cueType(cue) : type;
            const editorType = cueKind === 'video' ? 'video' : cueKind === 'sfx' ? 'sfx' : 'music';
            cueEditor.value = cue
                ? {
                      ...cueEditorDefaults(editorType, cue.scene_id ? 'scene' : 'global', String(cue.scene_id || '')),
                      id: cue.id,
                      name: title(cue),
                      assetId: String(cue.primary_asset_id || cue.asset_id || ''),
                      fallbackAssetId: String(cue.fallback_asset_id || ''),
                      completionMode: cue.completion_mode === 'enter_target_scene' ? 'enter_target_scene' : 'restore_captured_scene',
                      targetSceneId: String(cue.target_scene_id || ''),
                      concurrentMusicCueId: String(cue.concurrent_music_cue_id || ''),
                      musicDuring: cue.music_during === 'continue' || cue.music_during === 'stop' ? cue.music_during : 'pause',
                      musicAfter:
                          cue.music_after === 'keep_current' || cue.music_after === 'start_target_default' || cue.music_after === 'remain_silent'
                              ? cue.music_after
                              : 'resume_prior',
                      embeddedAudioVolume: Number(cue.embedded_audio_volume ?? 100),
                      embeddedAudioMuted: Boolean(cue.embedded_audio_muted),
                  }
                : cueEditorDefaults(editorType, 'scene', selectedScene.value?.id ?? '');
            cueFile.value = null;
            cueModalOpen.value = true;
        };
        const openGlobalCueModal = (type: SceneCueEditorForm['type']): void => {
            cueEditor.value = cueEditorDefaults(type, 'global');
            cueFile.value = null;
            cueModalOpen.value = true;
        };
        const closeCueModal = (): void => {
            cueModalOpen.value = false;
            cueFile.value = null;
        };
        const selectScene = (scene: StudioRecord): void => {
            selectedSceneId.value = scene.id;
            stagePresetId.value = String(scene.base_stage_preset_id || '');
            if (scene.primary_backdrop_asset_id) void loadAssetUrl(String(scene.primary_backdrop_asset_id));
        };

        watch(
            () => selectedStagePreset.value?.id,
            () => {
                const preset = selectedStagePreset.value;
                stagePresetDraft.value = preset
                    ? {
                          name: title(preset),
                          backdropId: String(preset.scene_backdrop_id || ''),
                          tweenDurationMs: Number(preset.tween_duration_ms ?? 800),
                          tweenEasing:
                              preset.tween_easing === 'linear' || preset.tween_easing === 'ease_in' || preset.tween_easing === 'ease_out'
                                  ? preset.tween_easing
                                  : 'ease_in_out',
                      }
                    : { name: '', backdropId: '', tweenDurationMs: 800, tweenEasing: 'ease_in_out' };
                stagePresetSaveState.value = 'saved';
                stageLayoutSaveState.value = 'saved';
            },
            { immediate: true },
        );

        const load = async (): Promise<void> => {
            try {
                const response = await api<ApiResponse<Studio>>(`/api/control/v1/campaigns/${campaignId}/studio`);
                studio.value = response.data;
                if (!selectedScene.value) selectedSceneId.value = records('scenes')[0]?.id ?? '';
                if (!stagePresets.value.some((preset) => preset.id === stagePresetId.value)) {
                    stagePresetId.value = String(selectedScene.value?.base_stage_preset_id || records('stage_presets')[0]?.id || '');
                }
                mapId.value ||= records('maps')[0]?.id ?? '';
                if (selectedScene.value?.primary_backdrop_asset_id) void loadAssetUrl(String(selectedScene.value.primary_backdrop_asset_id));
                records('scenes').forEach((scene) => {
                    if (scene.primary_backdrop_asset_id) void loadAssetUrl(String(scene.primary_backdrop_asset_id));
                });
                if (selectedMap.value) void loadAssetUrl(String(selectedMap.value.image_asset_id));
                loadActiveArtwork();
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
            } catch {
                /* A missing preview must not prevent authoring. */
            }
        };

        const loadCastArtwork = (): void => {
            if (active.value !== 'cast') return;
            [...records('player_characters'), ...records('npcs')].forEach((record) => {
                const assetId = characterArtId(record);
                if (assetId) void loadAssetUrl(assetId);
            });
        };
        const loadLibraryArtwork = (): void => {
            if (active.value !== 'library') return;
            assets.value.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready').forEach((asset) => void loadAssetUrl(asset.id));
        };
        const loadActiveArtwork = (): void => {
            loadCastArtwork();
            loadLibraryArtwork();
        };
        watch(active, loadActiveArtwork);
        const loadSceneStageArtwork = (): void => {
            if (active.value !== 'scenes') return;
            if (selectedScene.value?.primary_backdrop_asset_id) void loadAssetUrl(String(selectedScene.value.primary_backdrop_asset_id));
            sceneStageEntries.value.forEach((entry) => {
                if (entry.asset_id) void loadAssetUrl(entry.asset_id);
            });
        };
        watch(
            () => [active.value, selectedScene.value?.id, selectedScene.value?.primary_backdrop_asset_id, ...sceneStageEntries.value.map((entry) => entry.asset_id)],
            loadSceneStageArtwork,
        );

        const write = async (resource: string, record: StudioRecord, patch: Record<string, unknown>, remember = true): Promise<void> => {
            if (!studio.value) return;
            saving.value = 'saving';
            error.value = '';
            const before = Object.fromEntries(Object.keys(patch).map((key) => [key, record[key]]));
            Object.assign(record, patch);
            const requestSnapshot = { ...record };
            try {
                const response = await api<ApiResponse<{ campaign: Studio['campaign']; record: StudioRecord }>>(
                    `/api/control/v1/campaigns/${campaignId}/studio/${resource}/${record.id}`,
                    {
                        method: 'PATCH',
                        body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, patch }),
                    },
                );
                studio.value.campaign = response.data.campaign;
                const localChanges = Object.fromEntries(Object.entries(record).filter(([field, value]) => value !== requestSnapshot[field]));
                Object.assign(record, response.data.record);
                Object.assign(record, localChanges);
                if (remember) {
                    history.value.push({ resource, id: record.id, before, after: patch });
                    redoHistory.value = [];
                }
                saving.value = 'saved';
            } catch (reason) {
                Object.assign(
                    record,
                    Object.fromEntries(Object.entries(before).filter(([field]) => record[field] === patch[field])),
                );
                saving.value = 'error';
                error.value =
                    reason instanceof ApiError && reason.status === 409
                        ? 'This draft changed elsewhere. The studio was reloaded.'
                        : reason instanceof Error
                          ? reason.message
                          : 'Unable to save this change.';
                if (reason instanceof ApiError && reason.status === 409) await load();
            }
        };

        const flushQueuedWrite = async (key: string): Promise<void> => {
            if (writesInFlight.has(key)) return;
            const queued = queuedWrites.get(key);
            if (!queued) return;
            queuedWrites.delete(key);
            writesInFlight.add(key);
            try {
                await write(queued.resource, queued.record, Object.fromEntries([...queued.fields].map((field) => [field, queued.record[field]])));
            } finally {
                writesInFlight.delete(key);
                if (queuedWrites.has(key) && !delayed.has(key)) void flushQueuedWrite(key);
            }
        };

        const queueWrite = (resource: string, record: StudioRecord, fields: string[]): void => {
            const key = `${resource}:${record.id}`;
            const queued = queuedWrites.get(key) ?? { resource, record, fields: new Set<string>() };
            fields.forEach((field) => queued.fields.add(field));
            queuedWrites.set(key, queued);
            const prior = delayed.get(key);
            if (prior) clearTimeout(prior);
            delayed.set(
                key,
                setTimeout(() => {
                    delayed.delete(key);
                    void flushQueuedWrite(key);
                }, 450),
            );
        };

        const undo = async (): Promise<void> => {
            const entry = history.value.pop();
            if (!entry) return;
            const record = Object.values(studio.value?.records ?? {})
                .flat()
                .find((item) => item.id === entry.id);
            if (!record) return;
            await write(entry.resource, record, entry.before, false);
            redoHistory.value.push(entry);
        };

        const redo = async (): Promise<void> => {
            const entry = redoHistory.value.pop();
            if (!entry) return;
            const record = Object.values(studio.value?.records ?? {})
                .flat()
                .find((item) => item.id === entry.id);
            if (!record) return;
            await write(entry.resource, record, entry.after, false);
            history.value.push(entry);
        };

        const addCollection = async (): Promise<void> => {
            if (!studio.value || !collectionName.value.trim()) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/studio/asset-collections`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: collectionName.value }),
                });
                collectionName.value = '';
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create the collection.';
            } finally {
                busy.value = false;
            }
        };

        const updateCollectionMembership = (collection: StudioRecord, assetId: string, checked: boolean): void => {
            const current = Array.isArray(collection.asset_ids) ? collection.asset_ids : [];
            const assetIds = checked ? [...current, assetId] : current.filter((id) => id !== assetId);
            void write('asset-collections', collection, { asset_ids: assetIds });
        };
        const collectionAssets = (collection: StudioRecord): StudioRecord[] => {
            const assetIds = Array.isArray(collection.asset_ids) ? collection.asset_ids : [];
            return activeAssets.value.filter((asset) => assetIds.includes(asset.id));
        };
        const addAssetToCollection = (collection: StudioRecord, assetId: string): void => {
            if (!assetId) return;
            updateCollectionMembership(collection, assetId, true);
            collectionAssetSelections.value = { ...collectionAssetSelections.value, [collection.id]: '' };
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
        const moveSceneStageEntry = async (moved: PresentationStageEntry): Promise<void> => {
            const entry = stageEntries.value.find(
                (record) => String(record.npc_id) === moved.npc_id && Number(record.layer_order) === moved.layer_order,
            );
            if (!entry) return;
            stageLayoutSaveState.value = 'saving';
            await write('stage-preset-entries', entry, { position_x: moved.position_x, position_y: moved.position_y });
            stageLayoutSaveState.value = saving.value === 'error' ? 'error' : 'saved';
        };

        const setFog = async (): Promise<void> => {
            if (!studio.value || !mapId.value || !fogAssetId.value) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/maps/${mapId.value}/fog-mask`, {
                    method: 'PUT',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, asset_id: fogAssetId.value }),
                });
                fogAssetId.value = '';
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to set the fog mask.';
            } finally {
                busy.value = false;
            }
        };

        const makeCueGlobal = async (cue: StudioRecord): Promise<void> => {
            await write(cueResource(cue), cue, { scene_id: null });
        };

        const chooseLibraryFile = (event: Event): void => {
            libraryFiles.value = event.target instanceof HTMLInputElement ? Array.from(event.target.files ?? []) : [];
        };
        const openReplacementModal = (asset: StudioRecord): void => {
            replacementAsset.value = asset;
            replacementFile.value = null;
        };
        const closeReplacementModal = (): void => {
            replacementAsset.value = null;
            replacementFile.value = null;
        };
        const chooseReplacementFile = (event: Event): void => {
            replacementFile.value = event.target instanceof HTMLInputElement ? (event.target.files?.[0] ?? null) : null;
        };
        const chooseMapFile = (event: Event): void => {
            mapFile.value = event.target instanceof HTMLInputElement ? (event.target.files?.[0] ?? null) : null;
        };
        const chooseCueFile = (event: Event): void => {
            cueFile.value = event.target instanceof HTMLInputElement ? (event.target.files?.[0] ?? null) : null;
        };
        const chooseBackdropFile = (event: Event): void => {
            backdropFile.value = event.target instanceof HTMLInputElement ? (event.target.files?.[0] ?? null) : null;
        };
        const uploadAsset = async (file: File, kind: 'image' | 'audio' | 'video'): Promise<string> => {
            if (!studio.value) throw new Error('Campaign studio is not ready.');
            const start = await api<ApiResponse<StudioRecord> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(
                `/api/control/v1/campaigns/${campaignId}/assets/uploads`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        original_filename: file.name,
                        kind,
                        declared_mime: file.type,
                        byte_size: file.size,
                    }),
                },
            );
            const parts = await Promise.all(
                start.upload.parts.map(async (part) => {
                    const response = await fetch(part.url, {
                        method: 'PUT',
                        body: file.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, file.size)),
                    });
                    if (!response.ok) throw new Error('A storage upload part failed.');
                    const eTag = response.headers.get('ETag');
                    return eTag ? { number: part.number, e_tag: eTag } : { number: part.number };
                }),
            );
            const done = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/assets/${start.data.id}/complete`, {
                method: 'POST',
                body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision + 1, parts }),
            });
            if (done.data.validation_error) throw new Error(String(done.data.validation_error));
            await load();
            return done.data.id;
        };
        const openCharacterArtUpload = (target: CharacterArtUploadTarget = castCreationKind.value): void => {
            characterArtUploadTarget.value = target;
            characterArtUploadFile.value = null;
            characterArtUploadNpcId.value ||= records('npcs')[0]?.id ?? '';
            characterArtUploadModalOpen.value = true;
        };
        const closeCharacterArtUpload = (): void => {
            characterArtUploadFile.value = null;
            characterArtUploadModalOpen.value = false;
        };
        const chooseCharacterArtUpload = (event: Event): void => {
            characterArtUploadFile.value = event.target instanceof HTMLInputElement ? (event.target.files?.[0] ?? null) : null;
        };
        const uploadCharacterArt = async (): Promise<void> => {
            if (!characterArtUploadFile.value) return;
            if (!characterArtUploadFile.value.type.startsWith('image/')) {
                error.value = 'Choose an image file for character art.';
                return;
            }
            if (characterArtUploadTarget.value === 'emotion' && !characterArtUploadNpcId.value) {
                error.value = 'Choose the character this emotion belongs to.';
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const assetId = await uploadAsset(characterArtUploadFile.value, 'image');
                if (characterArtUploadTarget.value === 'pc') playerCharacterDraft.value.imageAssetId = assetId;
                else if (characterArtUploadTarget.value === 'npc') npcDraft.value.imageAssetId = assetId;
                else setEmotionAsset(characterArtUploadNpcId.value, characterArtUploadEmotion.value, assetId);
                closeCharacterArtUpload();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to upload this character art.';
            } finally {
                busy.value = false;
            }
        };
        const uploadLibraryFiles = async (): Promise<void> => {
            const files = libraryFiles.value;
            if (files.length === 0) return;
            const kindFor = (file: File): 'image' | 'audio' | 'video' | null =>
                file.type.startsWith('image/') ? 'image' : file.type.startsWith('audio/') ? 'audio' : file.type.startsWith('video/') ? 'video' : null;
            const invalid = files.find((file) => kindFor(file) === null);
            if (invalid) {
                error.value = `${invalid.name} is not an image, audio, or video file.`;
                return;
            }
            busy.value = true;
            error.value = '';
            libraryUploadProgress.value = { completed: 0, total: files.length };
            try {
                for (const file of files) {
                    await uploadAsset(file, kindFor(file)!);
                    libraryUploadProgress.value.completed++;
                }
                libraryFiles.value = [];
            } catch (reason) {
                libraryFiles.value = files.slice(libraryUploadProgress.value.completed);
                const message = reason instanceof Error ? reason.message : 'Unable to upload this media.';
                error.value = `Uploaded ${libraryUploadProgress.value.completed} of ${files.length} files. ${message}`;
            } finally {
                busy.value = false;
            }
        };
        const replaceLibraryAsset = async (): Promise<void> => {
            const asset = replacementAsset.value;
            const file = replacementFile.value;
            if (!studio.value || !asset || !file) return;
            const kind = String(asset.kind) as 'image' | 'audio' | 'video';
            if (!file.type.startsWith(`${kind}/`)) {
                error.value = `Choose a ${kind} file to replace this ${kind}.`;
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const start = await api<ApiResponse<StudioRecord> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(
                    `/api/control/v1/campaigns/${campaignId}/assets/${asset.id}/replacement`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            original_filename: file.name,
                            kind,
                            declared_mime: file.type,
                            byte_size: file.size,
                        }),
                    },
                );
                const parts = await Promise.all(
                    start.upload.parts.map(async (part) => {
                        const response = await fetch(part.url, {
                            method: 'PUT',
                            body: file.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, file.size)),
                        });
                        if (!response.ok) throw new Error('A storage upload part failed.');
                        const eTag = response.headers.get('ETag');
                        return eTag ? { number: part.number, e_tag: eTag } : { number: part.number };
                    }),
                );
                const completed = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/assets/${asset.id}/replacement/complete`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision + 1, parts }),
                });
                if (completed.data.validation_error) throw new Error(String(completed.data.validation_error));
                const remainingUrls = { ...assetUrls.value };
                delete remainingUrls[asset.id];
                assetUrls.value = remainingUrls;
                closeReplacementModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to replace this media.';
            } finally {
                busy.value = false;
            }
        };
        const createMap = async (imageAssetId = mapDraft.value.imageAssetId): Promise<void> => {
            if (!studio.value || !mapDraft.value.name.trim() || !imageAssetId) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/maps`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: mapDraft.value.name,
                        image_asset_id: imageAssetId,
                    }),
                });
                mapId.value = response.data.id;
                mapDraft.value = { name: '', imageAssetId: '' };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this map.';
            } finally {
                busy.value = false;
            }
        };
        const uploadMapFile = async (): Promise<void> => {
            if (!mapFile.value || !mapDraft.value.name.trim()) return;
            if (!mapFile.value.type.startsWith('image/')) {
                error.value = 'Choose an image file for the map.';
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const assetId = await uploadAsset(mapFile.value, 'image');
                mapFile.value = null;
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/maps`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value?.campaign.draft_revision,
                        name: mapDraft.value.name,
                        image_asset_id: assetId,
                    }),
                });
                mapId.value = response.data.id;
                mapDraft.value = { name: '', imageAssetId: '' };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to upload and create this map.';
            } finally {
                busy.value = false;
            }
        };
        const createMapToken = async (): Promise<void> => {
            if (!studio.value || !mapId.value) return;
            const draft = mapTokenDraft.value;
            const valid = draft.type === 'pc' ? draft.playerCharacterId : draft.type === 'npc' ? draft.npcId : draft.assetId && draft.label.trim();
            if (!valid) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/maps/${mapId.value}/tokens`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        token_type: draft.type,
                        player_character_id: draft.type === 'pc' ? draft.playerCharacterId : null,
                        npc_id: draft.type === 'npc' ? draft.npcId : null,
                        asset_id: draft.type === 'custom' ? draft.assetId : null,
                        label: draft.type === 'custom' ? draft.label.trim() : null,
                        position_x: draft.positionX,
                        position_y: draft.positionY,
                        scale: draft.scale,
                    }),
                });
                mapTokenDraft.value = { type: draft.type, playerCharacterId: '', npcId: '', assetId: '', label: '', positionX: 0.5, positionY: 0.5, scale: 1 };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this map token.';
            } finally {
                busy.value = false;
            }
        };
        const createCharacter = async (kind: 'pc' | 'npc'): Promise<void> => {
            if (!studio.value) return;
            const draft = kind === 'pc' ? playerCharacterDraft.value : npcDraft.value;
            if (!draft.name.trim() || (kind === 'npc' && !draft.imageAssetId)) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/${kind === 'pc' ? 'player-characters' : 'npcs'}`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: draft.name,
                        pronouns: draft.pronouns || null,
                        public_description: draft.description || null,
                        control_notes: draft.controlNotes || null,
                        ...(kind === 'pc' ? { avatar_asset_id: draft.imageAssetId || null } : { normal_asset_id: draft.imageAssetId }),
                    }),
                });
                if (kind === 'pc') playerCharacterDraft.value = { name: '', pronouns: '', description: '', controlNotes: '', imageAssetId: '' };
                else npcDraft.value = { name: '', pronouns: '', description: '', controlNotes: '', imageAssetId: '' };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this character.';
            } finally {
                busy.value = false;
            }
        };
        const createCue = async (): Promise<void> => {
            if (!studio.value || !cueDraft.value.name.trim()) return;
            const draft = cueDraft.value;
            const valid = draft.type === 'dice' ? draft.expression.trim() : draft.assetId;
            if (!valid) return;
            busy.value = true;
            error.value = '';
            try {
                if (draft.type === 'dice')
                    await api(`/api/control/v1/campaigns/${campaignId}/dice-presets`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            name: draft.name,
                            expression: draft.expression,
                            default_visibility: 'public',
                            is_default: false,
                        }),
                    });
                else if (draft.type === 'video')
                    await api(`/api/control/v1/campaigns/${campaignId}/video-cues`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            name: draft.name,
                            primary_asset_id: draft.assetId,
                            scene_id: null,
                            fallback_asset_id: null,
                            completion_mode: 'restore_captured_scene',
                            target_scene_id: null,
                            concurrent_music_cue_id: null,
                            music_during: 'pause',
                            music_after: 'resume_prior',
                            embedded_audio_volume: 100,
                            embedded_audio_muted: false,
                        }),
                    });
                else
                    await api(`/api/control/v1/campaigns/${campaignId}/audio-cues`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            name: draft.name,
                            asset_id: draft.assetId,
                            scene_id: null,
                            kind: draft.type,
                            loop: draft.type === 'music',
                            default_volume: 100,
                        }),
                    });
                cueDraft.value = { type: 'music', name: '', assetId: '', expression: '' };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this cue.';
            } finally {
                busy.value = false;
            }
        };
        const uploadBackdropFile = async (): Promise<void> => {
            if (!studio.value || !selectedScene.value || !backdropFile.value) return;
            const file = backdropFile.value;
            if (!file.type.startsWith('image/')) {
                error.value = 'Choose an image file for a backdrop.';
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const start = await api<ApiResponse<StudioRecord> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(
                    `/api/control/v1/campaigns/${campaignId}/assets/uploads`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            original_filename: file.name,
                            kind: 'image',
                            declared_mime: file.type,
                            byte_size: file.size,
                        }),
                    },
                );
                const parts = await Promise.all(
                    start.upload.parts.map(async (part) => {
                        const response = await fetch(part.url, {
                            method: 'PUT',
                            body: file.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, file.size)),
                        });
                        if (!response.ok) throw new Error('A storage upload part failed.');
                        const eTag = response.headers.get('ETag');
                        return eTag ? { number: part.number, e_tag: eTag } : { number: part.number };
                    }),
                );
                const done = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/assets/${start.data.id}/complete`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision + 1, parts }),
                });
                if (done.data.validation_error) throw new Error(String(done.data.validation_error));
                await load();
                if (selectedScene.value) await write('scenes', selectedScene.value, { primary_backdrop_asset_id: done.data.id });
                await loadAssetUrl(done.data.id);
                closeBackdropUploadModal();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to upload this backdrop.';
            } finally {
                busy.value = false;
            }
        };
        const uploadCueFile = async (): Promise<void> => {
            if (!studio.value || !cueFile.value) return;
            const file = cueFile.value;
            const kind = file.type.startsWith('audio/') ? 'audio' : file.type.startsWith('video/') ? 'video' : null;
            if (!kind || (cueEditor.value.type === 'video') !== (kind === 'video')) {
                error.value = 'Choose an audio file for sound cues or a video file for video cues.';
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const start = await api<ApiResponse<StudioRecord> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(
                    `/api/control/v1/campaigns/${campaignId}/assets/uploads`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            original_filename: file.name,
                            kind,
                            declared_mime: file.type,
                            byte_size: file.size,
                        }),
                    },
                );
                const parts = await Promise.all(
                    start.upload.parts.map(async (part) => {
                        const response = await fetch(part.url, {
                            method: 'PUT',
                            body: file.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, file.size)),
                        });
                        if (!response.ok) throw new Error('A storage upload part failed.');
                        const eTag = response.headers.get('ETag');
                        return eTag ? { number: part.number, e_tag: eTag } : { number: part.number };
                    }),
                );
                const done = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/assets/${start.data.id}/complete`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision + 1, parts }),
                });
                if (done.data.validation_error) throw new Error(String(done.data.validation_error));
                cueEditor.value.assetId = done.data.id;
                cueFile.value = null;
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to upload this media.';
            } finally {
                busy.value = false;
            }
        };
        const saveCue = async (): Promise<void> => {
            if (!studio.value || !cueEditor.value.name.trim() || !cueEditor.value.assetId) return;
            if (cueEditor.value.scope === 'scene' && !cueEditor.value.sceneId) return;
            if (cueEditor.value.type === 'video' && cueEditor.value.completionMode === 'enter_target_scene' && !cueEditor.value.targetSceneId) return;
            busy.value = true;
            error.value = '';
            try {
                if (cueEditor.value.id) {
                    const cue = cueRecords.value.find((item) => item.id === cueEditor.value.id);
                    if (cue)
                        await write(
                            cueResource(cue),
                            cue,
                            cueEditor.value.type === 'video'
                                ? {
                                      name: cueEditor.value.name,
                                      scene_id: cueEditor.value.scope === 'scene' ? cueEditor.value.sceneId : null,
                                      primary_asset_id: cueEditor.value.assetId,
                                      fallback_asset_id: cueEditor.value.fallbackAssetId || null,
                                      completion_mode: cueEditor.value.completionMode,
                                      target_scene_id: cueEditor.value.completionMode === 'enter_target_scene' ? cueEditor.value.targetSceneId : null,
                                      concurrent_music_cue_id: cueEditor.value.concurrentMusicCueId || null,
                                      music_during: cueEditor.value.musicDuring,
                                      music_after: cueEditor.value.musicAfter,
                                      embedded_audio_volume: cueEditor.value.embeddedAudioVolume,
                                      embedded_audio_muted: cueEditor.value.embeddedAudioMuted,
                                  }
                                : {
                                      name: cueEditor.value.name,
                                      scene_id: cueEditor.value.scope === 'scene' ? cueEditor.value.sceneId : null,
                                      asset_id: cueEditor.value.assetId,
                                      kind: cueEditor.value.type,
                                  },
                        );
                } else if (cueEditor.value.type === 'video') {
                    await api(`/api/control/v1/campaigns/${campaignId}/video-cues`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            name: cueEditor.value.name,
                            primary_asset_id: cueEditor.value.assetId,
                            scene_id: cueEditor.value.scope === 'scene' ? cueEditor.value.sceneId : null,
                            fallback_asset_id: cueEditor.value.fallbackAssetId || null,
                            completion_mode: cueEditor.value.completionMode,
                            target_scene_id: cueEditor.value.completionMode === 'enter_target_scene' ? cueEditor.value.targetSceneId : null,
                            concurrent_music_cue_id: cueEditor.value.concurrentMusicCueId || null,
                            music_during: cueEditor.value.musicDuring,
                            music_after: cueEditor.value.musicAfter,
                            embedded_audio_volume: cueEditor.value.embeddedAudioVolume,
                            embedded_audio_muted: cueEditor.value.embeddedAudioMuted,
                        }),
                    });
                } else {
                    await api(`/api/control/v1/campaigns/${campaignId}/audio-cues`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: studio.value.campaign.draft_revision,
                            name: cueEditor.value.name,
                            asset_id: cueEditor.value.assetId,
                            scene_id: cueEditor.value.scope === 'scene' ? cueEditor.value.sceneId : null,
                            kind: cueEditor.value.type,
                            loop: cueEditor.value.type === 'music',
                            default_volume: 100,
                        }),
                    });
                }
                closeCueModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to save this cue.';
            } finally {
                busy.value = false;
            }
        };

        const setSceneStagePreset = async (presetId: string): Promise<void> => {
            const scene = selectedScene.value;
            if (!scene) return;
            await write('scenes', scene, { base_stage_preset_id: presetId || null });
            if (saving.value === 'saved') stagePresetId.value = presetId;
        };

        const saveStagePreset = async (): Promise<void> => {
            const preset = selectedStagePreset.value;
            const name = stagePresetDraft.value.name.trim();
            if (!preset || !name) {
                error.value = 'Give this stage preset a name before saving it.';
                return;
            }
            stagePresetSaveState.value = 'saving';
            await write('stage-presets', preset, {
                name,
                scene_backdrop_id: stagePresetDraft.value.backdropId || null,
                tween_duration_ms: Math.max(0, Math.min(30000, Math.round(stagePresetDraft.value.tweenDurationMs || 0))),
                tween_easing: stagePresetDraft.value.tweenEasing,
            });
            stagePresetSaveState.value = saving.value === 'error' ? 'error' : 'saved';
        };

        const removeStagePreset = async (): Promise<void> => {
            const preset = selectedStagePreset.value;
            if (!preset) return;
            const sceneCount = records('scenes').filter((scene) => scene.base_stage_preset_id === preset.id).length;
            if (
                !window.confirm(
                    `Delete “${title(preset)}”? Its ${stageEntries.value.length} staged character${stageEntries.value.length === 1 ? '' : 's'} will be removed${
                        sceneCount ? ` and it will be detached from ${sceneCount} scene${sceneCount === 1 ? '' : 's'}` : ''
                    }. Published revisions will not change.`,
                )
            )
                return;
            await remove('stage-presets', preset);
        };

        const createSceneStage = async (): Promise<string | null> => {
            if (!studio.value || !selectedScene.value) return null;
            const name = sceneStagePresetName.value.trim();
            if (!name) {
                error.value = 'Give this stage preset a name before creating it.';
                return null;
            }
            const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/stage-presets`, {
                method: 'POST',
                body: JSON.stringify({
                    command_id: commandId(),
                    expected_revision: studio.value.campaign.draft_revision,
                    name,
                    tween_duration_ms: 800,
                    tween_easing: 'ease_in_out',
                }),
            });
            await load();
            const scene = selectedScene.value;
            if (!scene) return String(response.data.id);
            await write('scenes', scene, { base_stage_preset_id: response.data.id });
            stagePresetId.value = String(response.data.id);
            sceneStagePresetName.value = '';
            return String(response.data.id);
        };

        const ensureSceneStage = async (): Promise<string | null> => {
            if (stagePresetId.value) return stagePresetId.value;
            error.value = 'Choose or create a stage preset to edit before placing a character.';
            return null;
        };

        const submitScene = async (): Promise<void> => {
            if (!studio.value || !sceneForm.value.name.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/scenes`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: sceneForm.value.name,
                        control_notes: sceneForm.value.controlNotes || null,
                        primary_backdrop_asset_id: sceneForm.value.backdropAssetId || null,
                        default_music_cue_id: sceneForm.value.musicCueId || null,
                        default_video_cue_id: sceneForm.value.videoCueId || null,
                        base_stage_preset_id: null,
                        transition: sceneForm.value.transition,
                        transition_duration_ms: 0,
                    }),
                });
                selectedSceneId.value = String(response.data.id);
                sceneForm.value = { name: '', controlNotes: '', backdropAssetId: '', musicCueId: '', videoCueId: '', transition: 'cut' };
                closeSceneModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this scene.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const submitSceneBackdrop = async (): Promise<void> => {
            const scene = selectedScene.value;
            if (!studio.value || !scene || !sceneBackdropForm.value.name.trim() || !sceneBackdropForm.value.assetId) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/scenes/${scene.id}/backdrops`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: sceneBackdropForm.value.name,
                        asset_id: sceneBackdropForm.value.assetId,
                    }),
                });
                sceneBackdropForm.value = { name: '', assetId: '' };
                closeSceneModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this backdrop.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const submitSceneStageEntry = async (): Promise<void> => {
            if (!studio.value || !sceneStageEntryForm.value.npcId) return;
            busy.value = true;
            error.value = '';
            try {
                const presetId = await ensureSceneStage();
                if (!presetId || !studio.value) return;
                await api(`/api/control/v1/campaigns/${campaignId}/stage-presets/${presetId}/entries`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        npc_id: sceneStageEntryForm.value.npcId,
                        npc_state_id: sceneStageEntryForm.value.npcStateId || null,
                        position_x: sceneStageEntryForm.value.positionX,
                        position_y: sceneStageEntryForm.value.positionY,
                        scale: sceneStageEntryForm.value.scale,
                        layer_order: stageEntries.value.length,
                        facing: sceneStageEntryForm.value.facing,
                    }),
                });
                sceneStageEntryForm.value = { npcId: '', npcStateId: '', positionX: 0.5, positionY: 0.65, scale: 1, facing: 'right' };
                closeSceneModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to place this character.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const submitSceneCharacter = async (): Promise<void> => {
            if (!studio.value || !sceneCharacterForm.value.name.trim() || !sceneCharacterForm.value.assetId) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StudioRecord>>(`/api/control/v1/campaigns/${campaignId}/npcs`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: sceneCharacterForm.value.name,
                        normal_asset_id: sceneCharacterForm.value.assetId,
                        pronouns: sceneCharacterForm.value.pronouns || null,
                        public_description: sceneCharacterForm.value.description || null,
                        control_notes: sceneCharacterForm.value.controlNotes || null,
                    }),
                });
                await load();
                if (sceneCharacterForm.value.placeOnStage) {
                    sceneStageEntryForm.value = { npcId: String(response.data.id), npcStateId: '', positionX: 0.5, positionY: 0.65, scale: 1, facing: 'right' };
                    await submitSceneStageEntry();
                }
                sceneCharacterForm.value = { name: '', assetId: '', pronouns: '', description: '', controlNotes: '', placeOnStage: true };
                closeSceneModal();
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this character.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const addNpcState = async (npcId: string): Promise<void> => {
            if (!studio.value) return;
            const draft = npcStateDraft(npcId);
            if (!draft.name.trim() || !draft.assetId) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/npcs/${npcId}/states`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: draft.name,
                        asset_id: draft.assetId,
                    }),
                });
                npcStateDrafts.value[npcId] = { name: '', assetId: '' };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this emotional state.';
            } finally {
                busy.value = false;
            }
        };

        const saveEmotionKit = async (npc: StudioRecord): Promise<void> => {
            if (!studio.value || !emotionKitComplete(npc)) return;
            busy.value = true;
            error.value = '';
            try {
                for (const emotion of emotionNames) {
                    const assetId = emotionAssetId(npc, emotion);
                    if (emotion === 'Normal') {
                        if (String(npc.normal_asset_id || '') !== assetId) await write('npcs', npc, { normal_asset_id: assetId });
                        continue;
                    }
                    const state = emotionState(npc.id, emotion);
                    if (state) {
                        if (String(state.asset_id) !== assetId || String(state.name) !== emotion)
                            await write('npc-states', state, { name: emotion, asset_id: assetId });
                    } else {
                        await api(`/api/control/v1/campaigns/${campaignId}/npcs/${npc.id}/states`, {
                            method: 'POST',
                            body: JSON.stringify({
                                command_id: commandId(),
                                expected_revision: studio.value.campaign.draft_revision,
                                name: emotion,
                                asset_id: assetId,
                            }),
                        });
                        studio.value.campaign.draft_revision += 1;
                    }
                }
                emotionDrafts.value = { ...emotionDrafts.value, [npc.id]: {} };
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to save this emotion kit.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const remove = async (resource: string, record: StudioRecord): Promise<void> => {
            if (!studio.value) return;
            const pendingKey = `${resource}:${record.id}`;
            const pendingWrite = delayed.get(pendingKey);
            if (pendingWrite) {
                clearTimeout(pendingWrite);
                delayed.delete(pendingKey);
            }
            queuedWrites.delete(pendingKey);
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/studio/${resource}/${record.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision }),
                });
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to remove this item.';
            } finally {
                busy.value = false;
            }
        };

        const archiveAsset = async (asset: StudioRecord): Promise<void> => {
            if (
                !studio.value ||
                !window.confirm(`Archive “${title(asset)}”? It will leave authoring pickers but stay available here until you permanently delete it.`)
            )
                return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/studio/assets/${asset.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision }),
                });
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive this media.';
            } finally {
                busy.value = false;
            }
        };

        const deleteAsset = async (asset: StudioRecord): Promise<void> => {
            if (!studio.value || !window.confirm(`Permanently delete “${title(asset)}”? This removes the media file and cannot be undone.`)) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/assets/${asset.id}/permanently`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision }),
                });
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to permanently delete this media.';
            } finally {
                busy.value = false;
            }
        };

        const publish = async (): Promise<void> => {
            if (!studio.value) return;
            const name = window.prompt('Name this saved revision', `${studio.value.campaign.name} revision`);
            if (!name?.trim()) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/publish`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: name.trim() }),
                });
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to publish this revision.';
            } finally {
                busy.value = false;
            }
        };

        const previewCampaign = async (): Promise<void> => {
            if (!studio.value) return;
            busy.value = true;
            error.value = '';
            try {
                const revision = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaignId}/publish`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: studio.value.campaign.draft_revision,
                        name: `Preview — ${studio.value.campaign.name}`,
                    }),
                });
                const session = await api<ApiResponse<LiveSessionRecord>>(`/api/control/v1/campaigns/${campaignId}/sessions`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        campaign_revision_id: revision.data.id,
                        progress_mode: 'fresh',
                        name: `Preview — ${studio.value.campaign.name}`,
                    }),
                });
                await router.push(`/campaigns/${campaignId}/live/${session.data.id}`);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to preview this campaign.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        onMounted(load);
        return {
            sections: studioSections,
            active,
            assets,
            activeAssets,
            archivedAssets,
            readyImages,
            readyAudio,
            readyVideos,
            stagePresets,
            stageEntries,
            sceneStageEntries,
            selectedStagePreset,
            selectedSceneId,
            selectedScene,
            selectedSceneBackdrops,
            stageEntryName,
            mapTokens,
            selectedMap,
            selectedFog,
            globalCueLibrary,
            studio,
            saving,
            error,
            busy,
            history,
            redoHistory,
            stagePresetId,
            sceneStagePresetName,
            stagePresetDraft,
            stagePresetSaveState,
            stageLayoutSaveState,
            mapId,
            fogAssetId,
            sceneModal,
            cueModalOpen,
            cueEditor,
            cueEditorAssets,
            cueFile,
            backdropFile,
            backdropUploadModalOpen,
            sceneForm,
            sceneCharacterForm,
            sceneBackdropForm,
            sceneStageEntryForm,
            cueSearch,
            cueTypeFilter,
            assetUrls,
            collectionName,
            collectionAssetSelections,
            collectionAssets,
            addAssetToCollection,
            libraryFiles,
            libraryUploadProgress,
            mapFile,
            mapDraft,
            mapTokenDraft,
            playerCharacterDraft,
            npcDraft,
            castCreationKind,
            characterArtUploadModalOpen,
            characterArtUploadFile,
            characterArtUploadTarget,
            characterArtUploadNpcId,
            characterArtUploadEmotion,
            replacementAsset,
            replacementFile,
            cueDraft,
            cueDraftAssets,
            summary,
            records,
            title,
            inputValue,
            inputChecked,
            selectValue,
            textareaValue,
            cueType,
            cueResource,
            cueScope,
            sceneCues,
            sceneBackdrops,
            npcStates,
            isNpc,
            characterArtId,
            characterInitial,
            characterKind,
            emotionNames,
            emotionAssetId,
            setEmotionAsset,
            emotionKitComplete,
            saveEmotionKit,
            npcStateDraft,
            addNpcState,
            openCharacterArtUpload,
            closeCharacterArtUpload,
            chooseCharacterArtUpload,
            uploadCharacterArt,
            openReplacementModal,
            closeReplacementModal,
            chooseReplacementFile,
            replaceLibraryAsset,
            openSceneModal,
            closeSceneModal,
            openBackdropUploadModal,
            closeBackdropUploadModal,
            openCueModal,
            openGlobalCueModal,
            closeCueModal,
            selectScene,
            setSceneStagePreset,
            saveStagePreset,
            removeStagePreset,
            createSceneStage,
            loadAssetUrl,
            queueWrite,
            undo,
            redo,
            addCollection,
            updateCollectionMembership,
            beginDrag,
            moveSceneStageEntry,
            setFog,
            chooseLibraryFile,
            chooseMapFile,
            chooseCueFile,
            chooseBackdropFile,
            uploadLibraryFiles,
            createMap,
            uploadMapFile,
            createMapToken,
            createCharacter,
            createCue,
            uploadBackdropFile,
            uploadCueFile,
            saveCue,
            makeCueGlobal,
            submitScene,
            submitSceneCharacter,
            submitSceneBackdrop,
            submitSceneStageEntry,
            remove,
            archiveAsset,
            deleteAsset,
            publish,
            previewCampaign,
            back: () => router.push('/'),
            openLegacy: (section: string) => router.push(`/campaigns/${campaignId}/${section}`),
        };
    },
    template: `
        <main v-if="studio" class="studio-shell">
            <aside class="studio-rail"><RouterLink class="studio-brand" to="/"><span>RPGays</span><strong>Control room</strong></RouterLink><nav aria-label="Campaign studio"><button v-for="section in sections" :key="section[0]" :class="{ active: active === section[0] }" @click="active = section[0]">{{ section[1] }}</button></nav><div class="studio-rail-footer"><span class="save-status" :class="saving">{{ saving === 'saving' ? 'Saving…' : saving === 'error' ? 'Save failed' : 'All changes saved' }}</span><button class="secondary" @click="back">All campaigns</button></div></aside>
            <section class="studio-main"><header class="studio-header"><div><div class="eyebrow">Campaign studio</div><h1>{{ studio.campaign.name }}</h1></div><div class="row"><button class="secondary" :disabled="history.length === 0 || saving === 'saving'" @click="undo">Undo</button><button class="secondary" :disabled="redoHistory.length === 0 || saving === 'saving'" @click="redo">Redo</button><button @click="active = 'publish'">Review & publish</button></div></header>
                <p v-if="error" class="error" role="alert">{{ error }}</p>

                <section v-if="active === 'overview'" class="studio-content stack"><div class="studio-hero"><div><div class="eyebrow">Build the show</div><h2>Everything your next session needs, in one place.</h2><p class="muted">Build the media, cast, scenes, maps, and cues; then publish a revision that stays safe during play.</p></div><button @click="active = 'library'">Start with media</button></div><div class="studio-stats"><article v-for="item in summary" :key="item[0]"><strong>{{ item[1] }}</strong><span>{{ item[0] }}</span></article></div><section class="studio-checklist"><h2>Production checklist</h2><button @click="active = 'library'" :class="{ done: activeAssets.length > 0 }">{{ activeAssets.length > 0 ? '✓' : '1' }} Add media</button><button @click="active = 'cast'" :class="{ done: records('player_characters').length + records('npcs').length > 0 }">{{ records('player_characters').length + records('npcs').length > 0 ? '✓' : '2' }} Build cast</button><button @click="active = 'scenes'" :class="{ done: records('scenes').length > 0 }">{{ records('scenes').length > 0 ? '✓' : '3' }} Compose scenes</button><button @click="active = 'publish'">4 Review & publish</button></section></section>

                <section v-if="active === 'library'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Private media</div><h2>Media library</h2><p class="muted">Upload once, then reuse images, audio, and video across the studio.</p></div></header><section class="composer-panel"><form class="upload-form" @submit.prevent="uploadLibraryFiles"><input type="file" multiple accept="image/jpeg,image/png,image/webp,audio/mpeg,audio/wav,audio/ogg,video/mp4,video/webm" aria-label="Media files" @change="chooseLibraryFile"><p v-if="libraryFiles.length" class="muted">{{ libraryFiles.length }} file{{ libraryFiles.length === 1 ? '' : 's' }} ready to upload.</p><button :disabled="busy || libraryFiles.length === 0">{{ busy ? 'Uploading ' + libraryUploadProgress.completed + ' of ' + libraryUploadProgress.total + '…' : 'Upload media' }}</button></form></section><div class="library-layout"><section class="library-assets stack"><div><div class="eyebrow">Ready to use</div><h3>Active media</h3></div><div class="asset-grid"><article v-for="asset in activeAssets" :key="asset.id" class="media-card"><div class="media-thumb" :class="asset.kind" :style="asset.kind === 'image' && assetUrls[asset.id] ? { backgroundImage: 'url(' + assetUrls[asset.id] + ')' } : {}"><span v-if="asset.kind !== 'image' || !assetUrls[asset.id]">{{ asset.kind }}</span></div><input :value="asset.label || asset.original_filename" :aria-label="'Label for ' + asset.original_filename" @input="asset.label = inputValue($event); queueWrite('assets', asset, ['label'])"><small>{{ asset.original_filename }} · {{ asset.upload_status }}</small><div class="media-actions"><button class="secondary" :disabled="busy" @click="openReplacementModal(asset)">Replace</button><button class="secondary" :disabled="busy" @click="archiveAsset(asset)">Archive</button><button class="danger" :disabled="busy" @click="deleteAsset(asset)">Delete permanently</button></div></article></div><p v-if="activeAssets.length === 0 && archivedAssets.length === 0" class="muted">Upload images, audio, or video to start your production library.</p><section v-if="archivedAssets.length" class="archived-media stack"><div><div class="eyebrow">Not used in new work</div><h3>Archived media</h3><p class="muted">Archived files stay here for review, but are unavailable in authoring pickers.</p></div><div class="asset-grid"><article v-for="asset in archivedAssets" :key="asset.id" class="media-card archived"><div class="media-thumb" :class="asset.kind" :style="asset.kind === 'image' && assetUrls[asset.id] ? { backgroundImage: 'url(' + assetUrls[asset.id] + ')' } : {}"><span v-if="asset.kind !== 'image' || !assetUrls[asset.id]">{{ asset.kind }}</span></div><input :value="asset.label || asset.original_filename" :aria-label="'Label for archived ' + asset.original_filename" @input="asset.label = inputValue($event); queueWrite('assets', asset, ['label'])"><small>Archived · {{ asset.original_filename }}</small><button class="danger" :disabled="busy" @click="deleteAsset(asset)">Delete permanently</button></article></div></section></section><aside class="studio-inspector stack"><h3>Collections</h3><form class="stack" @submit.prevent="addCollection"><input v-model="collectionName" maxlength="120" aria-label="Collection name" placeholder="e.g. Act one"><button :disabled="busy">Create collection</button></form><article v-for="collection in records('asset_collections')" :key="collection.id" class="collection-card"><input :value="collection.name" :aria-label="'Collection name ' + collection.name" @input="collection.name = inputValue($event); queueWrite('asset-collections', collection, ['name'])"><p v-if="collectionAssets(collection).length === 0" class="muted">No media in this collection yet.</p><div v-for="asset in collectionAssets(collection)" :key="asset.id" class="row"><span>{{ title(asset) }}</span><button type="button" class="secondary" :disabled="busy" @click="updateCollectionMembership(collection, asset.id, false)">Remove</button></div><label>Add media<select :value="collectionAssetSelections[collection.id] || ''" :aria-label="'Add media to ' + title(collection)" @change="addAssetToCollection(collection, selectValue($event))"><option value="">Choose media</option><option v-for="asset in activeAssets.filter((item) => !collectionAssets(collection).some((member) => member.id === item.id))" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label><button class="danger" :disabled="busy" @click="remove('asset-collections', collection)">Remove collection</button></article></aside></div></section>

                <section v-if="active === 'cast'" class="studio-content cast-studio stack">
                    <header class="section-heading cast-heading"><div><div class="eyebrow">Character authoring suite</div><h2>Cast workshop</h2><p class="muted">Build a roster people can recognize at a glance, then give stage characters the range they need in play.</p></div><div class="cast-count"><strong>{{ records('player_characters').length + records('npcs').length }}</strong><span>characters authored</span></div></header>
                    <section class="character-workbench">
                        <div class="workbench-intro"><div class="eyebrow">New character</div><h3>Start with their job in the story.</h3><p>Player characters join the map roster. NPCs also get stage art and an expression library.</p><div class="character-kind-picker" role="group" aria-label="Character type"><button type="button" :class="{ active: castCreationKind === 'pc' }" @click="castCreationKind = 'pc'"><span class="kind-icon">P</span><span><strong>Player character</strong><small>Claimable map roster</small></span></button><button type="button" :class="{ active: castCreationKind === 'npc' }" @click="castCreationKind = 'npc'"><span class="kind-icon">N</span><span><strong>Stage NPC</strong><small>Art + emotional states</small></span></button></div><p v-if="castCreationKind === 'npc'" class="orientation-note">Stage art should face right. The scene composer mirrors it when needed.</p></div>
                        <form v-if="castCreationKind === 'pc'" class="character-draft" @submit.prevent="createCharacter('pc')"><div class="draft-step"><span>01</span><div><strong>Identity</strong><small>What your players will see when they claim this character.</small></div></div><label>Name<input v-model="playerCharacterDraft.name" maxlength="120" aria-label="New PC name" placeholder="e.g. Nyx Vale"></label><div class="character-field-pair"><label>Pronouns<input v-model="playerCharacterDraft.pronouns" maxlength="120" aria-label="New PC pronouns" placeholder="e.g. they / them"></label><label>Portrait<select v-model="playerCharacterDraft.imageAssetId" aria-label="New PC avatar"><option value="">No avatar yet</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label></div><label>Public introduction<textarea v-model="playerCharacterDraft.description" maxlength="500" aria-label="New PC description" placeholder="A short, spoiler-safe read for the table."></textarea></label><label>Control-only notes<textarea v-model="playerCharacterDraft.controlNotes" maxlength="10000" aria-label="New PC control-only notes" placeholder="Secrets, motivations, and other GM-only context."></textarea></label><footer><span class="muted">You can refine this profile any time.</span><button :disabled="busy || !playerCharacterDraft.name.trim()">{{ busy ? 'Adding…' : 'Add to roster' }}</button></footer></form>
                        <form v-else class="character-draft" @submit.prevent="createCharacter('npc')"><div class="draft-step"><span>01</span><div><strong>Identity & key art</strong><small>Set the performance-ready baseline for this character.</small></div></div><label>Name<input v-model="npcDraft.name" maxlength="120" aria-label="New NPC name" placeholder="e.g. Archivist Sol"></label><div class="character-field-pair"><label>Pronouns<input v-model="npcDraft.pronouns" maxlength="120" aria-label="New NPC pronouns" placeholder="e.g. she / her"></label><label>Base portrait<select v-model="npcDraft.imageAssetId" aria-label="New NPC art"><option value="">Choose right-facing art</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label></div><label>Public introduction<textarea v-model="npcDraft.description" maxlength="500" aria-label="New NPC description" placeholder="What should the table know on first meeting?"></textarea></label><label>Control-only notes<textarea v-model="npcDraft.controlNotes" maxlength="10000" aria-label="New NPC control-only notes" placeholder="Secrets, motivations, and other GM-only context."></textarea></label><footer><span class="muted">Expressions come next, in the character profile.</span><button :disabled="busy || !npcDraft.name.trim() || !npcDraft.imageAssetId">{{ busy ? 'Adding…' : 'Create stage character' }}</button></footer></form>
                        <button type="button" class="character-upload-trigger secondary" :disabled="busy" @click="openCharacterArtUpload()">Upload {{ castCreationKind === 'pc' ? 'PC portrait' : 'NPC base art' }}</button>
                    </section>
                    <section class="cast-roster"><header><div><div class="eyebrow">Your ensemble</div><h3>Character editor</h3></div><p class="muted">Each profile keeps public table text and private Control notes together.</p></header><div class="character-roster-grid"><article v-for="record in [...records('player_characters'), ...records('npcs')]" :key="record.id" class="character-profile" :class="{ npc: isNpc(record) }"><header class="character-profile-header"><div class="character-portrait" :class="{ empty: !characterArtId(record), 'has-art': !!assetUrls[characterArtId(record)] }" :style="assetUrls[characterArtId(record)] ? { backgroundImage: 'url(' + assetUrls[characterArtId(record)] + ')' } : {}"><span>{{ characterInitial(record) }}</span><small>{{ characterArtId(record) ? 'portrait assigned' : 'no portrait' }}</small></div><div class="character-profile-title"><span class="character-kind">{{ characterKind(record) }}</span><input class="character-name-input" :value="record.name" :aria-label="'Name for ' + title(record)" @input="record.name = inputValue($event); queueWrite(isNpc(record) ? 'npcs' : 'player-characters', record, ['name'])"></div><button class="character-remove danger" :disabled="busy" @click="remove(isNpc(record) ? 'npcs' : 'player-characters', record)">Remove</button></header><div class="character-profile-body"><div class="character-field-pair"><label>Pronouns<input :value="record.pronouns || ''" :aria-label="'Pronouns for ' + title(record)" placeholder="Add pronouns" @input="record.pronouns = inputValue($event) || null; queueWrite(isNpc(record) ? 'npcs' : 'player-characters', record, ['pronouns'])"></label><label>{{ isNpc(record) ? 'Base art' : 'Avatar' }}<select :value="characterArtId(record)" :aria-label="'Portrait for ' + title(record)" @change="record[isNpc(record) ? 'normal_asset_id' : 'avatar_asset_id'] = selectValue($event) || null; queueWrite(isNpc(record) ? 'npcs' : 'player-characters', record, [isNpc(record) ? 'normal_asset_id' : 'avatar_asset_id'])"><option value="">{{ isNpc(record) ? 'Choose art' : 'No avatar' }}</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label></div><div class="character-writing-grid"><label>Table introduction<textarea :value="record.public_description || ''" :aria-label="'Public description for ' + title(record)" placeholder="Add a spoiler-safe introduction" @input="record.public_description = textareaValue($event) || null; queueWrite(isNpc(record) ? 'npcs' : 'player-characters', record, ['public_description'])"></textarea></label><label class="character-private-field"><span><strong>Control-only notes</strong><small>Never shown to participants or the live display.</small></span><textarea :value="record.control_notes || ''" :aria-label="'Control-only notes for ' + title(record)" maxlength="10000" placeholder="Secrets, motivations, and other GM-only context." @input="record.control_notes = textareaValue($event) || null; queueWrite(isNpc(record) ? 'npcs' : 'player-characters', record, ['control_notes'])"></textarea></label></div><section v-if="isNpc(record)" class="expression-suite"><header><div><span class="character-kind">Performance kit</span><h4>Emotional states</h4></div><span class="expression-count">{{ npcStates(record.id).length }}</span></header><div class="expression-reel"><span v-for="state in npcStates(record.id)" :key="state.id" class="expression-card"><i>{{ String(state.name).slice(0, 1).toUpperCase() }}</i>{{ state.name }}</span><span v-if="npcStates(record.id).length === 0" class="expression-empty">No emotional states yet; start with their default mood.</span></div><div class="expression-add"><input v-model="npcStateDraft(record.id).name" :aria-label="'Emotion name for ' + title(record)" maxlength="120" placeholder="e.g. Concerned"><select v-model="npcStateDraft(record.id).assetId" :aria-label="'Emotion art for ' + title(record)"><option value="">Choose right-facing image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button class="secondary" :disabled="busy || !npcStateDraft(record.id).name.trim() || !npcStateDraft(record.id).assetId" @click="addNpcState(record.id)">Add state</button></div></section></div></article></div><div v-if="records('player_characters').length + records('npcs').length === 0" class="cast-empty"><span>+</span><h3>Your cast is waiting in the wings.</h3><p>Choose a character type above to start building the roster.</p></div></section>
                    <section v-if="records('npcs').length" class="emotion-kit-board"><header><div><div class="eyebrow">Live emotion library</div><h3>Complete expression kits</h3><p class="muted">Every live-stage emotion has a named slot and its own required piece of art. Fill a character’s kit, then switch any staged character instantly in Play.</p></div><span class="emotion-kit-count">{{ emotionNames.length }} required</span></header><article v-for="npc in records('npcs')" :key="npc.id" class="emotion-kit"><div class="emotion-kit-title"><span>{{ characterInitial(npc) }}</span><div><strong>{{ title(npc) }}</strong><small>{{ emotionKitComplete(npc) ? 'Complete kit' : 'Art still needed' }}</small></div></div><div class="emotion-slots"><label v-for="emotion in emotionNames" :key="emotion" :class="{ complete: !!emotionAssetId(npc, emotion) }"><span>{{ emotion }}</span><select :value="emotionAssetId(npc, emotion)" :aria-label="emotion + ' art for ' + title(npc)" @change="setEmotionAsset(npc.id, emotion, selectValue($event))"><option value="">Choose art</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label></div><footer><span class="muted">{{ emotionNames.filter((emotion) => !!emotionAssetId(npc, emotion)).length }} of {{ emotionNames.length }} ready</span><button :disabled="busy || !emotionKitComplete(npc)" @click="saveEmotionKit(npc)">{{ busy ? 'Saving…' : 'Save emotion kit' }}</button></footer></article></section>
                </section>

                <section v-if="active === 'scenes'" class="studio-content stack">
                    <header class="section-heading"><div><div class="eyebrow">Compose the moment</div><h2>Scene board</h2><p class="muted">A scene is the moment your players see: its backdrop, music, transition, characters, and scene-only cues.</p></div><button @click="openSceneModal('scene')">Create scene</button></header>
                    <div class="scene-workspace">
                        <aside class="scene-selector stack"><div class="row"><h3>Your scenes</h3><span class="muted">{{ records('scenes').length }}</span></div><div class="scene-deck"><button v-for="scene in records('scenes')" :key="scene.id" class="scene-card" :class="{ active: selectedScene?.id === scene.id }" :style="assetUrls[String(scene.primary_backdrop_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(scene.primary_backdrop_asset_id)] + ')' } : {}" @click="selectScene(scene)"><strong>{{ scene.name }}</strong><span>{{ sceneCues(String(scene.id)).length }} scene cues · {{ scene.base_stage_preset_id ? 'cast placed' : 'no cast yet' }}</span></button></div><button class="secondary" @click="openSceneModal('scene')">+ Create another scene</button><p v-if="records('scenes').length === 0" class="muted">Start with a scene title and, if you have one, a backdrop. You can add the rest on the composition board.</p></aside>
                        <section v-if="selectedScene" class="scene-detail stack"><header class="scene-detail-header"><div><div class="eyebrow">Scene composition</div><input class="scene-title-input" :value="selectedScene.name" :aria-label="'Scene name ' + selectedScene.name" @input="selectedScene.name = inputValue($event); queueWrite('scenes', selectedScene, ['name'])"></div><button class="danger" :disabled="busy" @click="remove('scenes', selectedScene)">Delete scene</button></header>
                            <p class="muted">Set the look and sound, then drag characters onto the canvas to choose where they begin.</p>
                            <label>Control-only notes<textarea :value="selectedScene.control_notes || ''" :aria-label="'Control-only notes for scene ' + selectedScene.name" maxlength="10000" placeholder="Secrets, pacing reminders, and other GM-only context." @input="selectedScene.control_notes = textareaValue($event) || null; queueWrite('scenes', selectedScene, ['control_notes'])"></textarea></label>
                            <div class="scene-field-grid"><label>Backdrop<select :value="selectedScene.primary_backdrop_asset_id || ''" aria-label="Primary backdrop" @change="selectedScene.primary_backdrop_asset_id = selectValue($event) || null; queueWrite('scenes', selectedScene, ['primary_backdrop_asset_id']); loadAssetUrl(String(selectedScene.primary_backdrop_asset_id || ''))"><option value="">No backdrop yet</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button type="button" class="secondary field-upload-action" :disabled="busy" @click="openBackdropUploadModal">Upload backdrop</button></label><fieldset><legend>Cue on entry</legend><label>Music<select :value="selectedScene.default_music_cue_id || ''" aria-label="Entry cue music" @change="selectedScene.default_music_cue_id = selectValue($event) || null; queueWrite('scenes', selectedScene, ['default_music_cue_id'])"><option value="">No music</option><option v-for="cue in records('audio_cues').filter((cue) => cue.kind === 'music')" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select></label><label>Video<select :value="selectedScene.default_video_cue_id || ''" aria-label="Entry cue video" @change="selectedScene.default_video_cue_id = selectValue($event) || null; queueWrite('scenes', selectedScene, ['default_video_cue_id'])"><option value="">No video</option><option v-for="cue in records('video_cues')" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select></label></fieldset><label>How it appears<select :value="selectedScene.transition" aria-label="Transition" @change="selectedScene.transition = selectValue($event); queueWrite('scenes', selectedScene, ['transition'])"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select></label></div>
                            <section class="stage-preset-manager" aria-label="Stage preset manager"><header><div><div class="eyebrow">Stage preset manager</div><h3>Starting positions</h3><p class="muted">The composition board follows this scene’s default layout. Choose another preset below to edit it directly.</p></div><span v-if="selectedStagePreset" class="stage-preset-current" aria-live="polite">Editing: <strong>{{ title(selectedStagePreset) }}</strong></span></header><div class="stage-preset-picker"><label>Default preset for this scene<select :value="selectedScene.base_stage_preset_id || ''" aria-label="Default stage preset for scene" :disabled="busy" @change="setSceneStagePreset(selectValue($event))"><option value="">No default preset</option><option v-for="preset in stagePresets" :key="preset.id" :value="preset.id">{{ title(preset) }}</option></select></label><label>Preset to edit<select :value="stagePresetId" aria-label="Stage preset to edit" :disabled="busy" @change="stagePresetId = selectValue($event)"><option value="">Choose a preset</option><option v-for="preset in stagePresets" :key="preset.id" :value="preset.id">{{ title(preset) }}</option></select></label><div class="stage-preset-create"><input v-model="sceneStagePresetName" maxlength="120" aria-label="New stage preset name" placeholder="New preset name"><button class="secondary" :disabled="busy || !sceneStagePresetName.trim()" @click="createSceneStage">Create preset</button></div></div><form v-if="selectedStagePreset" class="stage-preset-editor" @submit.prevent="saveStagePreset"><label>Name<input v-model="stagePresetDraft.name" maxlength="120" aria-label="Stage preset name"></label><label>Backdrop when applied<select v-model="stagePresetDraft.backdropId" aria-label="Stage preset alternate backdrop"><option value="">Keep the scene’s primary backdrop</option><option v-for="backdrop in selectedSceneBackdrops" :key="backdrop.id" :value="backdrop.id">{{ backdrop.name }}</option></select></label><label>Movement duration (ms)<input v-model.number="stagePresetDraft.tweenDurationMs" type="number" min="0" max="30000" step="50" aria-label="Stage preset movement duration"></label><label>Movement style<select v-model="stagePresetDraft.tweenEasing" aria-label="Stage preset movement style"><option value="linear">Linear</option><option value="ease_in">Ease in</option><option value="ease_out">Ease out</option><option value="ease_in_out">Ease in and out</option></select></label><div class="stage-preset-editor-actions"><span class="save-status" :class="stagePresetSaveState" aria-live="polite">{{ stagePresetSaveState === 'saving' ? 'Saving preset…' : stagePresetSaveState === 'error' ? 'Preset not saved' : 'Preset saved' }}</span><button :disabled="busy || stagePresetSaveState === 'saving' || !stagePresetDraft.name.trim()">Save preset</button><button type="button" class="danger" :disabled="busy" @click="removeStagePreset">Delete preset</button></div></form><p v-else class="muted stage-preset-empty">Choose a preset to edit, or create a new reusable character layout.</p></section>
                            <div class="scene-action-row"><button class="secondary" @click="openSceneModal('character')">Add new character</button><button class="secondary" :disabled="!selectedStagePreset" @click="openSceneModal('stage-entry')">Place existing character</button><button class="secondary" @click="openSceneModal('backdrop')">Add alternate backdrop</button></div><section v-if="selectedStagePreset && stageEntries.length" class="stage-preset-members" aria-label="Characters in this preset"><div class="eyebrow">Preset characters</div><div v-for="entry in stageEntries" :key="entry.id" class="row"><span>{{ stageEntryName(entry) }}</span><button type="button" class="danger" :disabled="busy" :aria-label="'Remove ' + stageEntryName(entry) + ' from preset'" @click="remove('stage-preset-entries', entry)">Remove from preset</button></div></section>
                            <section class="scene-preview-grid"><div class="scene-backdrop-preview" :style="assetUrls[String(selectedScene.primary_backdrop_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(selectedScene.primary_backdrop_asset_id)] + ')' } : {}"><span v-if="!selectedScene.primary_backdrop_asset_id" class="muted">Choose a backdrop to preview this scene</span></div><div class="composer-panel"><div class="row"><div><div class="eyebrow">{{ selectedStagePreset ? 'Editing ' + title(selectedStagePreset) : 'No preset selected' }}</div><h3>Starting positions</h3><p class="muted">Drag characters directly on the stage to block their entrance.</p></div><div class="stage-layout-save" aria-live="polite"><span class="save-status" :class="stageLayoutSaveState">{{ stageLayoutSaveState === 'saving' ? 'Saving layout…' : stageLayoutSaveState === 'error' ? 'Layout not saved' : selectedStagePreset ? 'Layout saved' : '' }}</span><button class="secondary" :disabled="!selectedStagePreset" @click="openSceneModal('stage-entry')">Place character</button></div></div><PresentationStage v-if="sceneStageEntries.length" class="stage-composer" :backdrop-asset-id="selectedScene.primary_backdrop_asset_id || null" :entries="sceneStageEntries" :asset-urls="assetUrls" :editable="true" @move-entry="moveSceneStageEntry" /><div v-else class="stage-composer" aria-label="Scene starting positions canvas"><p class="muted">Choose or create a stage preset, then place characters to set this scene’s starting positions.</p></div></div></section>
                            <section class="cue-shelf stack">
                                <div class="row"><div><h3>Scene cues</h3><p class="muted">These sounds and videos are available only while this scene is active.</p></div><button @click="openCueModal('music')">Create cue</button></div>
                                <div class="scene-cue-list"><article v-for="cue in sceneCues(String(selectedScene.id))" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cueType(cue) }}</div></div><div class="row"><button class="secondary" :disabled="busy" @click="openCueModal('music', cue)">Edit</button><button class="secondary" :disabled="busy" @click="makeCueGlobal(cue)">Make global</button><button class="danger" :disabled="busy" @click="remove(cueResource(cue), cue)">Remove</button></div></article><p v-if="sceneCues(String(selectedScene.id)).length === 0" class="muted">No scene-only cues yet. Create one when this scene needs its own sound or video.</p></div>
                            </section>
                            <section class="cue-shelf stack"><div class="row"><h3>Alternate backdrops</h3><button class="secondary" @click="openSceneModal('backdrop')">Add alternate</button></div><article v-for="backdrop in sceneBackdrops(String(selectedScene.id))" :key="backdrop.id" class="asset"><strong>{{ backdrop.name }}</strong><span class="muted">{{ title(assets.find((asset) => asset.id === backdrop.asset_id) || backdrop) }}</span></article><p v-if="sceneBackdrops(String(selectedScene.id)).length === 0" class="muted">No alternate backdrops yet.</p></section>
                        </section>
                        <section v-else class="scene-empty-state"><h3>Select or create a scene</h3><p class="muted">Every scene gets its own visual composition board—no separate setup objects required.</p><button @click="openSceneModal('scene')">Create your first scene</button></section>
                    </div>
                </section>

                <section v-if="active === 'maps'" class="studio-content stack">
                    <header class="section-heading"><div><div class="eyebrow">Player view</div><h2>Maps</h2><p class="muted">Name the map, then upload its image here or select one from the media library.</p></div></header>
                    <section class="composer-panel stack"><form class="map-create-form" @submit.prevent="createMap()"><input v-model="mapDraft.name" maxlength="120" aria-label="Map name" placeholder="Map name"><select v-model="mapDraft.imageAssetId" aria-label="Existing map image"><option value="">Choose an existing image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button :disabled="busy || !mapDraft.name.trim() || !mapDraft.imageAssetId">Create from library</button></form><form class="map-create-form" @submit.prevent="uploadMapFile"><input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Map image file" @change="chooseMapFile"><button :disabled="busy || !mapDraft.name.trim() || !mapFile">{{ busy ? 'Uploading…' : 'Upload image and create map' }}</button></form></section>
                    <div class="studio-split"><aside class="studio-list"><button v-for="map in records('maps')" :key="map.id" :class="{ active: mapId === map.id }" @click="mapId = map.id; loadAssetUrl(String(map.image_asset_id))">{{ map.name }}</button><p v-if="records('maps').length === 0" class="muted">Your map library is empty.</p></aside>
                        <section v-if="selectedMap" class="composer-panel"><div class="map-composer" :style="assetUrls[String(selectedMap.image_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(selectedMap.image_asset_id)] + ')' } : {}"><button v-for="token in mapTokens" :key="token.id" class="map-editor-token" :style="{ left: (Number(token.position_x) * 100) + '%', top: (Number(token.position_y) * 100) + '%', transform: 'translate(-50%, -50%) scale(' + Number(token.scale) + ')' }" @pointerdown="beginDrag('map-tokens', token, $event)">{{ token.label || records('player_characters').find((pc) => pc.id === token.player_character_id)?.name || records('npcs').find((npc) => npc.id === token.npc_id)?.name || 'Token' }}</button></div>
                            <section class="map-tools"><form class="map-token-form" @submit.prevent="createMapToken"><header><div class="eyebrow">Optional map markers</div><h3>Place someone on this map</h3><p class="muted">Add a player character, NPC, or custom marker. Once placed, drag it directly on the map.</p></header><div class="map-token-fields"><label>Marker type<select v-model="mapTokenDraft.type" aria-label="Map token type"><option value="pc">Player character</option><option value="npc">NPC</option><option value="custom">Custom image</option></select></label><label v-if="mapTokenDraft.type === 'pc'">Player character<select v-model="mapTokenDraft.playerCharacterId" aria-label="Map player character"><option value="">Choose player character</option><option v-for="character in records('player_characters')" :key="character.id" :value="character.id">{{ title(character) }}</option></select></label><label v-if="mapTokenDraft.type === 'npc'">NPC<select v-model="mapTokenDraft.npcId" aria-label="Map NPC"><option value="">Choose NPC</option><option v-for="npc in records('npcs')" :key="npc.id" :value="npc.id">{{ title(npc) }}</option></select></label><template v-if="mapTokenDraft.type === 'custom'"><label>Marker name<input v-model="mapTokenDraft.label" maxlength="120" aria-label="Custom token label" placeholder="e.g. Hidden door"></label><label>Marker image<select v-model="mapTokenDraft.assetId" aria-label="Custom token image"><option value="">Choose image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label></template></div><details class="map-token-fine-tuning"><summary>Fine tune the starting position</summary><p class="muted">Most of the time, place the marker and drag it instead.</p><div class="map-token-fine-fields"><label>Horizontal position<input v-model.number="mapTokenDraft.positionX" type="number" min="0" max="1" step=".01"></label><label>Vertical position<input v-model.number="mapTokenDraft.positionY" type="number" min="0" max="1" step=".01"></label><label>Marker size<input v-model.number="mapTokenDraft.scale" type="number" min=".1" max="5" step=".1"></label></div></details><button class="map-token-submit" :disabled="busy || (mapTokenDraft.type === 'pc' ? !mapTokenDraft.playerCharacterId : mapTokenDraft.type === 'npc' ? !mapTokenDraft.npcId : !mapTokenDraft.assetId || !mapTokenDraft.label.trim())">Place marker on map</button></form>
                            <section class="map-fog-panel"><header><div class="eyebrow">Fog of war</div><h3>Reveal fog while you play</h3><p class="muted">Fog is painted during an active session so every reveal can respond to the table. Start a fresh live session from Campaigns, then use the live map editor to reveal or hide areas.</p></header><RouterLink class="button secondary" to="/">Go to Campaigns</RouterLink><p v-if="selectedFog" class="muted">This map has a legacy initial-fog asset configured. It starts hidden; use the live editor to reveal areas.</p></section></section></section>
                        <section v-else class="scene-empty-state"><h3>Create your first map</h3><p class="muted">Upload an image above and it will be ready for player-map authoring.</p></section></div></section>

                <section v-if="active === 'cues'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Live Control library</div><h2>Global cue editor</h2><p class="muted">Global cues are available anywhere in live Control. Scene-only cues stay with their scene; configure video companion tracks here.</p></div><div class="row"><button class="secondary" @click="openGlobalCueModal('music')">Add music</button><button class="secondary" @click="openGlobalCueModal('sfx')">Add sound effect</button><button class="secondary" @click="openGlobalCueModal('video')">Add video</button><button @click="openLegacy('dice')">Add dice preset</button></div></header><section class="filter-bar"><input v-model="cueSearch" aria-label="Search global cues" placeholder="Search global cues"><select v-model="cueTypeFilter" aria-label="Global cue type"><option value="all">All types</option><option value="music">Music</option><option value="sfx">Sound effects</option><option value="video">Video</option><option value="dice">Dice</option></select></section><div class="studio-card-grid"><article v-for="cue in globalCueLibrary" :key="cue.id" class="editor-card"><div class="row"><div class="eyebrow">{{ cueType(cue) }}</div><small class="muted">Global</small></div><input :value="cue.name" :aria-label="'Name for ' + cue.name" @input="cue.name = inputValue($event); queueWrite(cueResource(cue), cue, ['name'])"><p class="muted">{{ cue.expression || (cueType(cue) === 'video' ? (cue.concurrent_music_cue_id ? 'Starts companion music · ' : '') + (cue.embedded_audio_muted ? 'Video audio muted' : 'Video audio ' + cue.embedded_audio_volume + '%') + ' · ' + cue.completion_mode : (cue.loop ? 'Looping audio' : 'One-shot audio')) }}</p><div class="row"><button v-if="cueResource(cue) !== 'dice-presets'" type="button" class="secondary" :disabled="busy" @click="openCueModal(cueType(cue) === 'dice' ? 'music' : cueType(cue), cue)">Configure</button><button class="danger" :disabled="busy" @click="remove(cueResource(cue), cue)">Remove</button></div></article><p v-if="globalCueLibrary.length === 0" class="muted">No global cues match these filters. Scene-only cues are edited from their scene.</p></div></section>

                <section v-if="active === 'publish'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Freeze a performance-ready revision</div><h2>Publish review</h2></div></header><article class="review-card"><h3>Draft revision {{ studio.campaign.draft_revision }}</h3><p class="muted">Publishing snapshots your complete campaign. Start a fresh live session from Campaigns when you are ready to play.</p><button :disabled="busy || saving === 'saving'" @click="publish">Publish immutable revision</button></article><RouterLink class="button secondary" to="/">Return to Campaigns</RouterLink></section>

                <section v-if="active === 'play'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Private rehearsal</div><h2>Preview this campaign</h2><p class="muted">Open a fresh, disposable preview of the current draft. It is kept in the campaign’s session manager, where you can archive or delete it afterward.</p></div></header><article class="review-card"><h3>Preview from the current draft</h3><p class="muted">Preview starts with empty player groups and progress, just like a new live session.</p><button :disabled="busy || saving === 'saving'" @click="previewCampaign">Preview campaign</button></article></section>
            </section>
            <div v-if="sceneModal && (sceneModal === 'scene' || selectedScene)" class="modal-backdrop" role="presentation" @click.self="closeSceneModal"><section class="modal-panel stack" role="dialog" aria-modal="true"><header class="row"><div><div class="eyebrow">{{ sceneModal === 'scene' ? 'Scene board' : selectedScene?.name }}</div><h2 v-if="sceneModal === 'scene'">Create a scene</h2><h2 v-if="sceneModal === 'character'">Add character</h2><h2 v-if="sceneModal === 'stage-entry'">Place character</h2><h2 v-if="sceneModal === 'backdrop'">Add backdrop</h2></div><button class="secondary" @click="closeSceneModal">Close</button></header><form v-if="sceneModal === 'scene'" class="stack" @submit.prevent="submitScene"><p class="muted">Start with a title. Backdrop and entry music are optional and editable on the composition board.</p><input v-model="sceneForm.name" maxlength="120" aria-label="Scene name" placeholder="e.g. The moonlit archive"><select v-model="sceneForm.backdropAssetId" aria-label="Scene backdrop"><option value="">Choose a backdrop later</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><select v-model="sceneForm.musicCueId" aria-label="Scene entry music"><option value="">No entry music</option><option v-for="cue in records('audio_cues').filter((cue) => cue.kind === 'music')" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select><label>How it appears<select v-model="sceneForm.transition" aria-label="Scene transition"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select></label><button :disabled="busy || !sceneForm.name.trim()">{{ busy ? 'Creating...' : 'Create scene' }}</button></form><form v-if="sceneModal === 'character'" class="stack" @submit.prevent="submitSceneCharacter"><input v-model="sceneCharacterForm.name" maxlength="120" aria-label="Character name" placeholder="Character name"><select v-model="sceneCharacterForm.assetId" aria-label="Character image"><option value="">Choose character image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><p class="muted">NPC art must face right. Stage placement can flip it horizontally when the character needs to face left.</p><input v-model="sceneCharacterForm.pronouns" maxlength="120" aria-label="Pronouns" placeholder="Pronouns"><textarea v-model="sceneCharacterForm.description" maxlength="500" aria-label="Public description" placeholder="Public description"></textarea><label class="check-row"><input v-model="sceneCharacterForm.placeOnStage" type="checkbox"> Place in this scene’s starting positions</label><button :disabled="busy || !sceneCharacterForm.name.trim() || !sceneCharacterForm.assetId">{{ busy ? 'Adding...' : 'Add character' }}</button></form><form v-if="sceneModal === 'stage-entry'" class="stack" @submit.prevent="submitSceneStageEntry"><p class="muted">Choose where this character begins. You can drag them on the scene board afterward.</p><select v-model="sceneStageEntryForm.npcId" aria-label="Character"><option value="">Choose character</option><option v-for="npc in records('npcs')" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><select v-model="sceneStageEntryForm.npcStateId" aria-label="Character appearance"><option value="">Normal appearance</option><option v-for="state in npcStates(sceneStageEntryForm.npcId)" :key="state.id" :value="state.id">{{ state.name }}</option></select><div class="scene-field-grid"><label>Horizontal position<input v-model.number="sceneStageEntryForm.positionX" type="number" min="0" max="1" step=".01"></label><label>Vertical position<input v-model.number="sceneStageEntryForm.positionY" type="number" min="0" max="1" step=".01"></label><label>Size<input v-model.number="sceneStageEntryForm.scale" type="number" min=".1" max="5" step=".1"></label></div><div class="segmented"><label><input v-model="sceneStageEntryForm.facing" type="radio" value="left"> Facing left</label><label><input v-model="sceneStageEntryForm.facing" type="radio" value="right"> Facing right</label></div><button :disabled="busy || !sceneStageEntryForm.npcId">{{ busy ? 'Placing...' : 'Place character' }}</button></form><form v-if="sceneModal === 'backdrop'" class="stack" @submit.prevent="submitSceneBackdrop"><input v-model="sceneBackdropForm.name" maxlength="120" aria-label="Backdrop name" placeholder="Backdrop name"><select v-model="sceneBackdropForm.assetId" aria-label="Backdrop image"><option value="">Choose image</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button :disabled="busy || !sceneBackdropForm.name.trim() || !sceneBackdropForm.assetId">{{ busy ? 'Adding...' : 'Add backdrop' }}</button></form></section></div>
        </main>
        <div v-if="studio && characterArtUploadModalOpen" class="modal-backdrop" role="presentation" @click.self="closeCharacterArtUpload">
            <section class="modal-panel stack" role="dialog" aria-modal="true" aria-label="Character art uploader">
                <header class="row"><div><div class="eyebrow">Cast workshop</div><h2>Upload character art</h2></div><button type="button" class="secondary" :disabled="busy" @click="closeCharacterArtUpload">Close</button></header>
                <form class="stack" @submit.prevent="uploadCharacterArt"><p class="muted">The upload is added to this campaign and selected immediately, so you can keep authoring without visiting the media library.</p><label>Use this image for<select v-model="characterArtUploadTarget" aria-label="Character art destination"><option value="pc">New player-character portrait</option><option value="npc">New NPC base art</option><option value="emotion">An NPC emotion</option></select></label><template v-if="characterArtUploadTarget === 'emotion'"><label>Stage character<select v-model="characterArtUploadNpcId" aria-label="Emotion character"><option value="">Choose character</option><option v-for="npc in records('npcs')" :key="npc.id" :value="npc.id">{{ title(npc) }}</option></select></label><label>Emotion<select v-model="characterArtUploadEmotion" aria-label="Emotion slot"><option v-for="emotion in emotionNames" :key="emotion" :value="emotion">{{ emotion }}</option></select></label></template><label>Image file<input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Character art file" @change="chooseCharacterArtUpload"></label><button :disabled="busy || !characterArtUploadFile || (characterArtUploadTarget === 'emotion' && !characterArtUploadNpcId)">{{ busy ? 'Uploading…' : 'Upload and use this art' }}</button></form>
            </section>
        </div>
        <div v-if="studio && replacementAsset" class="modal-backdrop" role="presentation" @click.self="closeReplacementModal">
            <section class="modal-panel stack" role="dialog" aria-modal="true" aria-label="Replace media">
                <header class="row"><div><div class="eyebrow">Media library</div><h2>Replace {{ title(replacementAsset) }}</h2></div><button type="button" class="secondary" :disabled="busy" @click="closeReplacementModal">Close</button></header>
                <form class="stack" @submit.prevent="replaceLibraryAsset"><p class="muted">This preserves every existing use of this {{ replacementAsset.kind }}—no reattaching required.</p><label>New {{ replacementAsset.kind }} file<input type="file" :accept="replacementAsset.kind === 'image' ? 'image/jpeg,image/png,image/webp' : replacementAsset.kind === 'audio' ? 'audio/mpeg,audio/wav,audio/ogg' : 'video/mp4,video/webm'" aria-label="Replacement media file" @change="chooseReplacementFile"></label><button :disabled="busy || !replacementFile">{{ busy ? 'Replacing…' : 'Replace everywhere' }}</button></form>
            </section>
        </div>
        <div v-if="studio && cueModalOpen" class="modal-backdrop" role="presentation" @click.self="closeCueModal">
            <section class="modal-panel stack" role="dialog" aria-modal="true" aria-label="Cue editor">
                <header class="row"><div><div class="eyebrow">{{ cueEditor.scope === 'scene' ? (records('scenes').find((scene) => scene.id === cueEditor.sceneId)?.name || 'Scene') : 'Live Control library' }}</div><h2>{{ cueEditor.id ? 'Configure cue' : 'Create cue' }}</h2></div><button class="secondary" :disabled="busy" @click="closeCueModal">Close</button></header>
                <form class="stack" @submit.prevent="saveCue">
                    <label>Cue type<select v-model="cueEditor.type" aria-label="Cue type" :disabled="!!cueEditor.id"><option value="music">Music</option><option value="sfx">Sound effect</option><option value="video">Video</option></select></label>
                    <label>Name<input v-model="cueEditor.name" maxlength="120" aria-label="Cue name" placeholder="e.g. Archive ambience"></label>
                    <label>Availability<select v-model="cueEditor.scope" aria-label="Cue availability"><option value="global">Live Control library</option><option value="scene">A specific scene</option></select></label>
                    <label v-if="cueEditor.scope === 'scene'">Scene<select v-model="cueEditor.sceneId" aria-label="Cue scene"><option value="">Choose scene</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ title(scene) }}</option></select></label>
                    <label>Media<select v-model="cueEditor.assetId" aria-label="Cue media"><option value="">Choose {{ cueEditor.type === 'video' ? 'video' : 'audio' }}</option><option v-for="asset in cueEditorAssets" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label>
                    <section v-if="cueEditor.type === 'video'" class="cue-upload-stack"><strong>Video + audio</strong><p class="muted">Use the video’s embedded soundtrack and optionally start a global music cue at the same time. The companion track follows this video’s end policy.</p><label>Fallback video<select v-model="cueEditor.fallbackAssetId" aria-label="Fallback video"><option value="">No fallback</option><option v-for="asset in readyVideos" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select></label><label>Companion music track<select v-model="cueEditor.concurrentMusicCueId" aria-label="Video companion music"><option value="">No companion track</option><option v-for="cue in records('audio_cues').filter((cue) => cue.kind === 'music' && !cue.scene_id)" :key="cue.id" :value="cue.id">{{ title(cue) }}</option></select></label><label>When video ends<select v-model="cueEditor.completionMode" aria-label="Video completion"><option value="restore_captured_scene">Restore the captured scene</option><option value="enter_target_scene">Enter a target scene</option></select></label><label v-if="cueEditor.completionMode === 'enter_target_scene'">Target scene<select v-model="cueEditor.targetSceneId" aria-label="Video target scene"><option value="">Choose scene</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ title(scene) }}</option></select></label><label>Scene music during video<select v-model="cueEditor.musicDuring" aria-label="Music during video"><option value="continue">Continue</option><option value="pause">Pause</option><option value="stop">Stop</option></select></label><label>Music after video<select v-model="cueEditor.musicAfter" aria-label="Music after video"><option value="keep_current">Keep current state</option><option value="resume_prior">Resume prior music</option><option value="start_target_default">Start target scene default</option><option value="remain_silent">Remain silent</option></select></label><label>Embedded audio volume <input v-model.number="cueEditor.embeddedAudioVolume" type="number" min="0" max="100"></label><label class="check-row"><input v-model="cueEditor.embeddedAudioMuted" type="checkbox"> Mute the video’s embedded audio</label></section>
                    <section class="cue-upload-stack"><strong>Upload new media</strong><p class="muted">Upload a {{ cueEditor.type === 'video' ? 'video' : 'sound file' }} and use it for this cue without leaving the studio.</p><input type="file" :accept="cueEditor.type === 'video' ? 'video/mp4,video/webm' : 'audio/mpeg,audio/wav,audio/ogg'" aria-label="Cue media file" @change="chooseCueFile"><button type="button" class="secondary" :disabled="busy || !cueFile" @click="uploadCueFile">{{ busy ? 'Uploading…' : 'Upload and use this file' }}</button></section>
                    <button :disabled="busy || !cueEditor.name.trim() || !cueEditor.assetId || (cueEditor.scope === 'scene' && !cueEditor.sceneId) || (cueEditor.type === 'video' && cueEditor.completionMode === 'enter_target_scene' && !cueEditor.targetSceneId)">{{ busy ? 'Saving…' : cueEditor.id ? 'Save cue' : 'Create cue' }}</button>
                </form>
            </section>
        </div>
        <div v-if="studio && backdropUploadModalOpen && selectedScene" class="modal-backdrop" role="presentation" @click.self="closeBackdropUploadModal">
            <section class="modal-panel stack" role="dialog" aria-modal="true" aria-label="Backdrop uploader">
                <header class="row"><div><div class="eyebrow">{{ selectedScene.name }}</div><h2>Upload a backdrop</h2></div><button class="secondary" :disabled="busy" @click="closeBackdropUploadModal">Close</button></header>
                <form class="stack" @submit.prevent="uploadBackdropFile"><p class="muted">The finished image becomes this scene’s main backdrop. You can change it later from the Backdrop picker.</p><label>Image file<input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Backdrop image file" @change="chooseBackdropFile"></label><button :disabled="busy || !backdropFile">{{ busy ? 'Uploading…' : 'Upload and use as backdrop' }}</button></form>
            </section>
        </div>
        <main v-if="!studio" class="shell stack"><p v-if="error" class="error" role="alert">{{ error }}</p><p v-else class="muted">Opening campaign studio…</p></main>`,
});
