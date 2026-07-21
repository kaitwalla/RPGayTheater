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

const studioSections = [
    ['overview', 'Overview'], ['library', 'Media library'], ['cast', 'Cast'], ['scenes', 'Scenes & staging'],
    ['maps', 'Maps'], ['cues', 'Sound, video & dice'], ['publish', 'Publish'], ['play', 'Play'],
] as const;

const title = (record: StudioRecord): string => String(record.name ?? record.label ?? record.original_filename ?? 'Untitled');

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
        const mapId = ref('');
        const fogAssetId = ref('');
        const sceneCueSceneId = ref('');
        const sceneCueName = ref('');
        const sceneCueAssetId = ref('');
        const sceneCueKind = ref<'music' | 'sfx'>('music');
        const assetUrls = ref<Record<string, string>>({});
        const collectionName = ref('');

        const records = (key: string): StudioRecord[] => studio.value?.records[key] ?? [];
        const assets = computed(() => records('assets'));
        const readyImages = computed(() => assets.value.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready' && asset.archived_at === null));
        const stageEntries = computed(() => records('stage_preset_entries').filter((entry) => entry.stage_preset_id === stagePresetId.value));
        const mapTokens = computed(() => records('map_tokens').filter((token) => token.map_id === mapId.value));
        const selectedMap = computed(() => records('maps').find((map) => map.id === mapId.value) ?? null);
        const selectedFog = computed(() => records('map_fog_masks').find((mask) => mask.map_id === mapId.value) ?? null);
        const readyAudio = computed(() => assets.value.filter((asset) => asset.kind === 'audio' && asset.upload_status === 'ready' && asset.archived_at === null));
        const sceneCues = computed(() => [...records('audio_cues'), ...records('video_cues')].filter((cue) => cue.scene_id === sceneCueSceneId.value));
        const summary = computed(() => [
            ['Media', assets.value.length], ['Cast', records('player_characters').length + records('npcs').length],
            ['Scenes', records('scenes').length], ['Maps', records('maps').length], ['Cues', records('audio_cues').length + records('video_cues').length],
        ]);

        const load = async (): Promise<void> => {
            try {
                const response = await api<ApiResponse<Studio>>(`/api/control/v1/campaigns/${campaignId}/studio`);
                studio.value = response.data;
                stagePresetId.value ||= records('stage_presets')[0]?.id ?? '';
                mapId.value ||= records('maps')[0]?.id ?? '';
                sceneCueSceneId.value ||= records('scenes')[0]?.id ?? '';
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

        const createSceneAudio = async (): Promise<void> => {
            if (!studio.value || !sceneCueSceneId.value || !sceneCueName.value.trim() || !sceneCueAssetId.value) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/audio-cues`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: studio.value.campaign.draft_revision, name: sceneCueName.value, asset_id: sceneCueAssetId.value, scene_id: sceneCueSceneId.value, kind: sceneCueKind.value, loop: sceneCueKind.value === 'music', default_volume: 100 }),
                });
                sceneCueName.value = '';
                sceneCueAssetId.value = '';
                await load();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this scene sound.'; }
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
        return { sections: studioSections, active, assets, readyImages, readyAudio, stageEntries, mapTokens, selectedMap, selectedFog, sceneCues, studio, saving, error, busy, history, redoHistory, stagePresetId, mapId, fogAssetId, sceneCueSceneId, sceneCueName, sceneCueAssetId, sceneCueKind, assetUrls, collectionName, summary, records, title, loadAssetUrl, queueWrite, undo, redo, addCollection, updateCollectionMembership, beginDrag, setFog, createSceneAudio, remove, publish, back: () => router.push('/'), openLegacy: (section: string) => router.push(`/campaigns/${campaignId}/${section}`), openSessions: () => router.push(`/campaigns/${campaignId}/sessions`) };
    },
    template: `
        <main v-if="studio" class="studio-shell">
            <aside class="studio-rail"><RouterLink class="studio-brand" to="/"><span>RPGays</span><strong>Control room</strong></RouterLink><nav aria-label="Campaign studio"><button v-for="section in sections" :key="section[0]" :class="{ active: active === section[0] }" @click="active = section[0]">{{ section[1] }}</button></nav><div class="studio-rail-footer"><span class="save-status" :class="saving">{{ saving === 'saving' ? 'Saving…' : saving === 'error' ? 'Save failed' : 'All changes saved' }}</span><button class="secondary" @click="back">All campaigns</button></div></aside>
            <section class="studio-main"><header class="studio-header"><div><div class="eyebrow">Campaign studio</div><h1>{{ studio.campaign.name }}</h1></div><div class="row"><button class="secondary" :disabled="history.length === 0 || saving === 'saving'" @click="undo">Undo</button><button class="secondary" :disabled="redoHistory.length === 0 || saving === 'saving'" @click="redo">Redo</button><button @click="active = 'publish'">Review & publish</button></div></header>
                <p v-if="error" class="error" role="alert">{{ error }}</p>

                <section v-if="active === 'overview'" class="studio-content stack"><div class="studio-hero"><div><div class="eyebrow">Build the show</div><h2>Everything your next session needs, in one place.</h2><p class="muted">Build the media, cast, scenes, maps, and cues; then publish a revision that stays safe during play.</p></div><button @click="active = 'library'">Start with media</button></div><div class="studio-stats"><article v-for="item in summary" :key="item[0]"><strong>{{ item[1] }}</strong><span>{{ item[0] }}</span></article></div><section class="studio-checklist"><h2>Production checklist</h2><button @click="active = 'library'" :class="{ done: assets.length > 0 }">{{ assets.length > 0 ? '✓' : '1' }} Add media</button><button @click="active = 'cast'" :class="{ done: records('player_characters').length + records('npcs').length > 0 }">{{ records('player_characters').length + records('npcs').length > 0 ? '✓' : '2' }} Build cast</button><button @click="active = 'scenes'" :class="{ done: records('scenes').length > 0 }">{{ records('scenes').length > 0 ? '✓' : '3' }} Compose scenes</button><button @click="active = 'publish'">4 Review & publish</button></section></section>

                <section v-if="active === 'library'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Private media</div><h2>Media library</h2></div><button @click="openLegacy('assets')">Upload media</button></header><div class="library-layout"><section class="asset-grid"><article v-for="asset in assets" :key="asset.id" class="media-card"><div class="media-thumb" :class="asset.kind"><span>{{ asset.kind }}</span></div><input :value="asset.label || asset.original_filename" :aria-label="'Label for ' + asset.original_filename" @input="asset.label = ($event.target as HTMLInputElement).value; queueWrite('assets', asset, ['label'])"><small>{{ asset.original_filename }} · {{ asset.upload_status }}</small><button v-if="!asset.archived_at" class="danger" :disabled="busy" @click="remove('assets', asset)">Archive</button></article><p v-if="assets.length === 0" class="muted">Upload images, audio, or video to start your production library.</p></section><aside class="studio-inspector stack"><h3>Collections</h3><form class="stack" @submit.prevent="addCollection"><input v-model="collectionName" maxlength="120" aria-label="Collection name" placeholder="e.g. Act one"><button :disabled="busy">Create collection</button></form><article v-for="collection in records('asset_collections')" :key="collection.id" class="collection-card"><input :value="collection.name" :aria-label="'Collection name ' + collection.name" @input="collection.name = ($event.target as HTMLInputElement).value; queueWrite('asset-collections', collection, ['name'])"><label v-for="asset in assets" :key="asset.id"><input type="checkbox" :checked="Array.isArray(collection.asset_ids) && collection.asset_ids.includes(asset.id)" @change="updateCollectionMembership(collection, asset.id, ($event.target as HTMLInputElement).checked)">{{ title(asset) }}</label><button class="danger" :disabled="busy" @click="remove('asset-collections', collection)">Remove collection</button></article></aside></div></section>

                <section v-if="active === 'cast'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">People on stage</div><h2>Cast</h2></div><div class="row"><button class="secondary" @click="openLegacy('pcs')">Add PC</button><button @click="openLegacy('npcs')">Add NPC</button></div></header><div class="studio-card-grid"><article v-for="record in [...records('player_characters'), ...records('npcs')]" :key="record.id" class="editor-card"><div class="media-thumb portrait"></div><div class="stack"><input :value="record.name" :aria-label="'Name for ' + title(record)" @input="record.name = ($event.target as HTMLInputElement).value; queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['name'])"><input :value="record.pronouns || ''" aria-label="Pronouns" placeholder="Pronouns" @input="record.pronouns = ($event.target as HTMLInputElement).value || null; queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['pronouns'])"><textarea :value="record.public_description || ''" aria-label="Public description" placeholder="Public description" @input="record.public_description = ($event.target as HTMLTextAreaElement).value || null; queueWrite(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record, ['public_description'])"></textarea><button class="danger" :disabled="busy" @click="remove(records('player_characters').some((item) => item.id === record.id) ? 'player-characters' : 'npcs', record)">Remove</button></div></article><p v-if="records('player_characters').length + records('npcs').length === 0" class="muted">Add PCs and NPCs, then return here to edit their public profiles in place.</p></div></section>

                <section v-if="active === 'scenes'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Cue the stage</div><h2>Scenes & staging</h2></div><div class="row"><button class="secondary" @click="openLegacy('scenes')">Add scene</button><button @click="openLegacy('presets')">New stage preset</button></div></header><div class="studio-split"><aside class="studio-list"><article v-for="scene in records('scenes')" :key="scene.id"><input :value="scene.name" :aria-label="'Scene name ' + scene.name" @input="scene.name = ($event.target as HTMLInputElement).value; queueWrite('scenes', scene, ['name'])"><small>{{ scene.transition }} · {{ scene.transition_duration_ms }}ms</small></article><p v-if="records('scenes').length === 0" class="muted">Add a scene to define its backdrop, music, and staging.</p></aside><section class="composer-panel"><div class="row"><h3>Stage composer</h3><select v-model="stagePresetId" aria-label="Stage preset"><option value="">Choose a preset</option><option v-for="preset in records('stage_presets')" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></div><div class="stage-composer" aria-label="Stage composer canvas"><button v-for="entry in stageEntries" :key="entry.id" class="stage-token" :style="{ left: (Number(entry.position_x) * 100) + '%', top: (Number(entry.position_y) * 100) + '%', transform: 'translate(-50%, -50%) scale(' + Number(entry.scale) + ')' }" @pointerdown="beginDrag('stage-preset-entries', entry, $event)">{{ records('npcs').find((npc) => npc.id === entry.npc_id)?.name || 'NPC' }}</button><p v-if="stagePresetId && stageEntries.length === 0" class="muted">Use the stage preset editor to add performers; drag them here to place them.</p></div></section></div></section>

                <section v-if="active === 'scenes'" class="studio-content stack"><section class="review-card stack"><div><div class="eyebrow">Scene-specific playback</div><h3>Sounds and videos for one scene</h3><p class="muted">These stay organized with this scene. Anything without a scene remains in the global Sound, video & dice library.</p></div><div class="row"><select v-model="sceneCueSceneId" aria-label="Scene for sounds"><option value="">Choose a scene</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><input v-model="sceneCueName" maxlength="120" aria-label="Scene sound name" placeholder="Sound or music name"><select v-model="sceneCueAssetId" aria-label="Scene audio file"><option value="">Choose audio</option><option v-for="asset in readyAudio" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><select v-model="sceneCueKind" aria-label="Scene sound type"><option value="music">Music</option><option value="sfx">Sound effect</option></select><button :disabled="busy || !sceneCueSceneId || !sceneCueName.trim() || !sceneCueAssetId" @click="createSceneAudio">Add scene sound</button></div><article v-for="cue in sceneCues" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cue.kind || 'video' }}</div></div><select :value="cue.scene_id || ''" :aria-label="'Scene for ' + cue.name" @change="cue.scene_id = ($event.target as HTMLSelectElement).value || null; queueWrite(cue.asset_id ? 'audio-cues' : 'video-cues', cue, ['scene_id'])"><option value="">Make global</option><option v-for="scene in records('scenes')" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select></article><p v-if="sceneCueSceneId && sceneCues.length === 0" class="muted">No scene-specific sounds or videos yet.</p></section></section>

                <section v-if="active === 'maps'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Player view</div><h2>Maps</h2></div><button @click="openLegacy('maps')">Add map or token</button></header><div class="studio-split"><aside class="studio-list"><button v-for="map in records('maps')" :key="map.id" :class="{ active: mapId === map.id }" @click="mapId = map.id; loadAssetUrl(String(map.image_asset_id))">{{ map.name }}</button><p v-if="records('maps').length === 0" class="muted">Add a map to start authoring its initial layout.</p></aside><section class="composer-panel"><div v-if="selectedMap" class="map-composer" :style="assetUrls[String(selectedMap.image_asset_id)] ? { backgroundImage: 'url(' + assetUrls[String(selectedMap.image_asset_id)] + ')' } : {}"><button v-for="token in mapTokens" :key="token.id" class="map-editor-token" :style="{ left: (Number(token.position_x) * 100) + '%', top: (Number(token.position_y) * 100) + '%', transform: 'translate(-50%, -50%) scale(' + Number(token.scale) + ')' }" @pointerdown="beginDrag('map-tokens', token, $event)">{{ token.label || records('player_characters').find((pc) => pc.id === token.player_character_id)?.name || records('npcs').find((npc) => npc.id === token.npc_id)?.name || 'Token' }}</button><span v-if="selectedFog" class="fog-note">Fog mask configured</span></div><div v-if="selectedMap" class="row"><select v-model="fogAssetId" aria-label="Imported fog mask"><option value="">Import a fog mask</option><option v-for="asset in readyImages" :key="asset.id" :value="asset.id">{{ title(asset) }}</option></select><button class="secondary" :disabled="busy || !fogAssetId" @click="setFog">Set fog mask</button></div></section></div></section>

                <section v-if="active === 'cues'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Reusable during any scene</div><h2>Global sound, video & dice</h2><p class="muted">Set up music, sound effects, and videos that are not tied to a particular scene. Scene-specific playback lives on the Scenes page.</p></div><div class="row"><button class="secondary" @click="openLegacy('audio')">Add audio</button><button class="secondary" @click="openLegacy('video')">Add video</button><button @click="openLegacy('dice')">Add dice preset</button></div></header><div class="studio-card-grid"><article v-for="cue in [...records('audio_cues').filter((cue) => !cue.scene_id), ...records('video_cues').filter((cue) => !cue.scene_id), ...records('dice_presets')]" :key="cue.id" class="editor-card"><div class="eyebrow">{{ cue.kind || (cue.expression ? 'dice' : 'video') }}</div><input :value="cue.name" :aria-label="'Name for ' + cue.name" @input="cue.name = ($event.target as HTMLInputElement).value; queueWrite(records('audio_cues').some((item) => item.id === cue.id) ? 'audio-cues' : records('video_cues').some((item) => item.id === cue.id) ? 'video-cues' : 'dice-presets', cue, ['name'])"><p class="muted">{{ cue.expression || cue.completion_mode || (cue.loop ? 'Looping audio' : 'One-shot audio') }}</p></article></div></section>

                <section v-if="active === 'publish'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Freeze a performance-ready revision</div><h2>Publish review</h2></div></header><article class="review-card"><h3>Draft revision {{ studio.campaign.draft_revision }}</h3><p class="muted">Publishing snapshots your complete campaign. Existing sessions stay pinned until you explicitly adopt the new revision.</p><button :disabled="busy || saving === 'saving'" @click="publish">Publish immutable revision</button></article><RouterLink class="button secondary" :to="'/campaigns/' + studio.campaign.id + '/sessions'">View revision history and sessions</RouterLink></section>

                <section v-if="active === 'play'" class="studio-content stack"><header class="section-heading"><div><div class="eyebrow">Take it live</div><h2>Session launcher</h2></div></header><article class="review-card"><h3>Ready to run the show?</h3><p class="muted">Choose a published revision, start or resume progress, then hand the player code and private display pairing token to the right people.</p><button @click="openSessions">Open session launcher</button></article></section>
            </section>
        </main>
        <main v-else class="shell stack"><p v-if="error" class="error" role="alert">{{ error }}</p><p v-else class="muted">Opening campaign studio…</p></main>`,
});
