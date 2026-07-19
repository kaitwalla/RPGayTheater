import { createApp, defineComponent, onMounted, ref } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory, useRoute, useRouter } from 'vue-router';
import { api, ApiError } from '../shared/api';
import '../css/app.css';

type Campaign = {
    id: string;
    name: string;
    draft_revision: number;
    archived_at: string | null;
    updated_at: string;
};

type ApiResponse<T> = { data: T; meta?: { replayed: boolean } };
type Asset = { id: string; original_filename: string; kind: string; declared_mime: string; byte_size: number; upload_status: string; validation_error: string | null };
type PlayerCharacter = { id: string; name: string; pronouns: string | null; public_description: string | null; avatar_asset_id: string | null };
type Npc = { id: string; name: string; pronouns: string | null; public_description: string | null; normal_asset_id: string; native_facing: 'left' | 'right' };
type NpcState = { id: string; name: string; asset_id: string; sort_order: number };
type AudioCue = { id: string; name: string; asset_id: string; kind: 'music' | 'sfx'; loop: boolean; default_volume: number };
type SceneRecord = { id: string; name: string; primary_backdrop_asset_id: string | null; default_music_cue_id: string | null; base_stage_preset_id: string | null; transition: 'cut' | 'fade_black' | 'cross_dissolve'; transition_duration_ms: number };
type StagePresetRecord = { id: string; name: string; tween_duration_ms: number; tween_easing: string };

const commandId = (): string => crypto.randomUUID();

const LoginView = defineComponent({
    setup() {
        const router = useRouter();
        const secret = ref('');
        const error = ref('');
        const pending = ref(false);

        const login = async (): Promise<void> => {
            pending.value = true;
            error.value = '';
            try {
                await api<ApiResponse<{ authenticated: boolean }>>('/api/control/v1/auth/login', {
                    method: 'POST', body: JSON.stringify({ secret: secret.value }),
                });
                await router.replace('/');
            } catch (reason) {
                error.value = reason instanceof ApiError ? reason.message : 'Unable to contact Control.';
            } finally {
                pending.value = false;
            }
        };

        return { secret, error, pending, login };
    },
    template: `
        <main class="shell"><section class="panel stack" aria-labelledby="control-login-title">
            <div><div class="eyebrow">Theatrical RPG</div><h1 id="control-login-title">Control</h1></div>
            <p class="muted">Enter the environment-held Control secret to manage campaign drafts.</p>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <form class="stack" @submit.prevent="login">
                <label for="control-secret">Control secret</label>
                <input id="control-secret" v-model="secret" type="password" autocomplete="current-password" required autofocus>
                <button :disabled="pending">{{ pending ? 'Signing in…' : 'Sign in' }}</button>
            </form>
        </section></main>`,
});

const CampaignsView = defineComponent({
    setup() {
        const router = useRouter();
        const campaigns = ref<Campaign[]>([]);
        const campaignName = ref('');
        const error = ref('');
        const busy = ref(false);

        const load = async (): Promise<void> => {
            try {
                campaigns.value = (await api<ApiResponse<Campaign[]>>('/api/control/v1/campaigns')).data;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load campaigns.';
            }
        };

        const createCampaign = async (): Promise<void> => {
            if (!campaignName.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<Campaign>>('/api/control/v1/campaigns', {
                    method: 'POST', body: JSON.stringify({ command_id: commandId(), name: campaignName.value }),
                });
                campaigns.value = [...campaigns.value, response.data].sort((a, b) => a.name.localeCompare(b.name));
                campaignName.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create campaign.';
            } finally { busy.value = false; }
        };

        const rename = async (campaign: Campaign): Promise<void> => {
            try {
                const response = await api<ApiResponse<Campaign>>(`/api/control/v1/campaigns/${campaign.id}`, {
                    method: 'PATCH', body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision, name: campaign.name }),
                });
                Object.assign(campaign, response.data);
            } catch (reason) {
                error.value = reason instanceof ApiError && reason.status === 409
                    ? 'This campaign changed elsewhere. The current state has been reloaded.'
                    : reason instanceof Error ? reason.message : 'Unable to rename campaign.';
                await load();
            }
        };

        const archive = async (campaign: Campaign): Promise<void> => {
            if (!window.confirm(`Archive “${campaign.name}”?`)) return;
            try {
                await api<ApiResponse<Campaign>>(`/api/control/v1/campaigns/${campaign.id}`, {
                    method: 'DELETE', body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision }),
                });
                campaigns.value = campaigns.value.filter(({ id }) => id !== campaign.id);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive campaign.';
                await load();
            }
        };

        const logout = async (): Promise<void> => {
            await api<void>('/api/control/v1/auth/logout', { method: 'POST', body: JSON.stringify({}) });
            await router.replace('/login');
        };

        onMounted(load);
        return { campaigns, campaignName, error, busy, createCampaign, rename, archive, logout };
    },
    template: `
        <main class="shell stack"><header class="row"><div><div class="eyebrow">Theatrical RPG</div><h1>Campaign drafts</h1></div><button class="secondary" @click="logout">Sign out</button></header>
            <section class="panel stack" aria-labelledby="new-campaign-title"><h2 id="new-campaign-title">New campaign</h2>
                <form class="row" @submit.prevent="createCampaign"><input v-model="campaignName" aria-label="Campaign name" maxlength="120" required placeholder="Campaign name"><button :disabled="busy">Create campaign</button></form>
            </section>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section class="panel stack" aria-labelledby="campaign-list-title"><h2 id="campaign-list-title">Active drafts</h2>
                <p v-if="campaigns.length === 0" class="muted">No campaign drafts yet.</p>
                <article v-for="campaign in campaigns" :key="campaign.id" class="campaign"><input v-model="campaign.name" :aria-label="'Name for ' + campaign.name" maxlength="120"><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/assets', query: { revision: campaign.draft_revision } }">Assets</RouterLink><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/pcs', query: { revision: campaign.draft_revision } }">PCs</RouterLink><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/npcs', query: { revision: campaign.draft_revision } }">NPCs</RouterLink><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/audio', query: { revision: campaign.draft_revision } }">Audio</RouterLink><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/scenes', query: { revision: campaign.draft_revision } }">Scenes</RouterLink><button class="secondary" @click="rename(campaign)">Save</button><button class="danger" @click="archive(campaign)">Archive</button></article>
            </section>
        </main>`,
});

const PlayerCharactersView = defineComponent({
    setup() {
        const route = useRoute(); const router = useRouter(); const id = String(route.params.campaign); const revision = ref(Number(route.query.revision ?? 1));
        const characters = ref<PlayerCharacter[]>([]); const assets = ref<Asset[]>([]); const name = ref(''); const pronouns = ref(''); const description = ref(''); const avatar = ref(''); const error = ref(''); const busy = ref(false);
        const load = async (): Promise<void> => { try { const [pcs, media] = await Promise.all([api<ApiResponse<PlayerCharacter[]>>(`/api/control/v1/campaigns/${id}/player-characters`), api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`)]); characters.value = pcs.data; assets.value = media.data.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready'); } catch (reason) { if (reason instanceof ApiError && reason.status === 401) await router.replace('/login'); else error.value = 'Unable to load characters.'; } };
        const create = async (): Promise<void> => { if (!name.value.trim()) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<PlayerCharacter>>(`/api/control/v1/campaigns/${id}/player-characters`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: name.value, pronouns: pronouns.value || null, public_description: description.value || null, avatar_asset_id: avatar.value || null }) }); characters.value = [...characters.value, response.data]; revision.value++; name.value = ''; pronouns.value = ''; description.value = ''; avatar.value = ''; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this PC.'; await load(); } finally { busy.value = false; } };
        onMounted(load); return { characters, assets, name, pronouns, description, avatar, error, busy, create, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Player characters</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add player character</h2><input v-model="name" maxlength="120" required placeholder="Character name" aria-label="Character name"><input v-model="pronouns" maxlength="120" placeholder="Pronouns" aria-label="Pronouns"><input v-model="description" maxlength="500" placeholder="Short public description" aria-label="Public description"><select v-model="avatar" aria-label="Avatar image"><option value="">No avatar</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create PC' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft roster</h2><p v-if="characters.length === 0" class="muted">No player characters yet.</p><article v-for="character in characters" :key="character.id" class="asset"><div><strong>{{ character.name }}</strong><div class="muted">{{ character.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ character.public_description }}</div></div></article></section></main>`,
});

const NpcsView = defineComponent({
    setup() {
        const route = useRoute(); const router = useRouter(); const id = String(route.params.campaign); const revision = ref(Number(route.query.revision ?? 1));
        const npcs = ref<Npc[]>([]); const assets = ref<Asset[]>([]); const states = ref<NpcState[]>([]); const name = ref(''); const pronouns = ref(''); const description = ref(''); const normal = ref(''); const facing = ref<'left' | 'right'>('right'); const selected = ref(''); const stateName = ref(''); const stateAsset = ref(''); const error = ref(''); const busy = ref(false);
        const loadStates = async (): Promise<void> => { if (!selected.value) { states.value = []; return; } states.value = (await api<ApiResponse<NpcState[]>>(`/api/control/v1/campaigns/${id}/npcs/${selected.value}/states`)).data; };
        const load = async (): Promise<void> => { try { const [characters, media] = await Promise.all([api<ApiResponse<Npc[]>>(`/api/control/v1/campaigns/${id}/npcs`), api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`)]); npcs.value = characters.data; assets.value = media.data.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready'); if (selected.value) await loadStates(); } catch (reason) { if (reason instanceof ApiError && reason.status === 401) await router.replace('/login'); else error.value = 'Unable to load NPCs.'; } };
        const create = async (): Promise<void> => { if (!name.value.trim() || !normal.value) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<Npc>>(`/api/control/v1/campaigns/${id}/npcs`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: name.value, pronouns: pronouns.value || null, public_description: description.value || null, normal_asset_id: normal.value, native_facing: facing.value }) }); npcs.value = [...npcs.value, response.data]; revision.value++; name.value = ''; pronouns.value = ''; description.value = ''; normal.value = ''; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this NPC.'; await load(); } finally { busy.value = false; } };
        const addState = async (): Promise<void> => { if (!selected.value || !stateName.value.trim() || !stateAsset.value) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<NpcState>>(`/api/control/v1/campaigns/${id}/npcs/${selected.value}/states`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: stateName.value, asset_id: stateAsset.value }) }); states.value = [...states.value, response.data]; revision.value++; stateName.value = ''; stateAsset.value = ''; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to add this state.'; await loadStates(); } finally { busy.value = false; } };
        onMounted(load); return { npcs, assets, states, name, pronouns, description, normal, facing, selected, stateName, stateAsset, error, busy, create, addState, loadStates, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>NPCs</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add NPC</h2><input v-model="name" maxlength="120" required placeholder="NPC name" aria-label="NPC name"><input v-model="pronouns" maxlength="120" placeholder="Pronouns" aria-label="NPC pronouns"><input v-model="description" maxlength="500" placeholder="Short public description" aria-label="NPC description"><select v-model="normal" aria-label="Normal portrait"><option value="">Choose normal portrait</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><select v-model="facing" aria-label="Native facing"><option value="right">Faces right</option><option value="left">Faces left</option></select><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create NPC' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Optional emotional states</h2><select v-model="selected" aria-label="NPC for states" @change="loadStates"><option value="">Choose NPC</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><input v-model="stateName" maxlength="120" placeholder="State name" aria-label="State name"><select v-model="stateAsset" aria-label="State image"><option value="">Choose state image</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><button :disabled="busy || !selected" @click="addState">Add state</button><p v-if="selected && states.length === 0" class="muted">No states for this NPC.</p><article v-for="state in states" :key="state.id" class="asset"><strong>{{ state.name }}</strong></article></section><section class="panel stack"><h2>Draft NPCs</h2><p v-if="npcs.length === 0" class="muted">No NPCs yet.</p><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name }}</strong><div class="muted">Faces {{ npc.native_facing }} · {{ npc.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ npc.public_description }}</div></div></article></section></main>`,
});

const AudioCuesView = defineComponent({
    setup() {
        const route = useRoute(); const router = useRouter(); const id = String(route.params.campaign); const revision = ref(Number(route.query.revision ?? 1));
        const cues = ref<AudioCue[]>([]); const assets = ref<Asset[]>([]); const name = ref(''); const asset = ref(''); const kind = ref<'music' | 'sfx'>('music'); const loop = ref(false); const volume = ref(100); const error = ref(''); const busy = ref(false);
        const load = async (): Promise<void> => { try { const [audio, media] = await Promise.all([api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`), api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`)]); cues.value = audio.data; assets.value = media.data.filter((item) => item.kind === 'audio' && item.upload_status === 'ready'); } catch (reason) { if (reason instanceof ApiError && reason.status === 401) await router.replace('/login'); else error.value = 'Unable to load audio cues.'; } };
        const create = async (): Promise<void> => { if (!name.value.trim() || !asset.value) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<AudioCue>>(`/api/control/v1/campaigns/${id}/audio-cues`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: name.value, asset_id: asset.value, kind: kind.value, loop: loop.value, default_volume: volume.value }) }); cues.value = [...cues.value, response.data]; revision.value++; name.value = ''; asset.value = ''; loop.value = false; volume.value = 100; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this audio cue.'; await load(); } finally { busy.value = false; } };
        onMounted(load); return { cues, assets, name, asset, kind, loop, volume, error, busy, create, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Audio cues</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add cue</h2><input v-model="name" maxlength="120" placeholder="Cue name" aria-label="Cue name"><select v-model="asset" aria-label="Audio asset"><option value="">Choose ready audio</option><option v-for="item in assets" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><select v-model="kind" aria-label="Cue type"><option value="music">Music</option><option value="sfx">Sound effect</option></select><label><input v-model="loop" type="checkbox"> Loop</label><label>Default volume <input v-model.number="volume" type="number" min="0" max="100"></label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create cue' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft cues</h2><p v-if="cues.length === 0" class="muted">No audio cues yet.</p><article v-for="cue in cues" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cue.kind }} · {{ cue.loop ? 'looping' : 'one shot' }} · {{ cue.default_volume }}%</div></div></article></section></main>`,
});

const ScenesView = defineComponent({
    setup() {
        const route = useRoute(); const router = useRouter(); const id = String(route.params.campaign); const revision = ref(Number(route.query.revision ?? 1));
        const scenes = ref<SceneRecord[]>([]); const images = ref<Asset[]>([]); const music = ref<AudioCue[]>([]); const presets = ref<StagePresetRecord[]>([]); const name = ref(''); const backdrop = ref(''); const cue = ref(''); const preset = ref(''); const transition = ref<'cut' | 'fade_black' | 'cross_dissolve'>('cut'); const duration = ref(0); const selected = ref(''); const alternates = ref<Array<{ id: string; name: string; asset_id: string }>>([]); const alternateName = ref(''); const alternateAsset = ref(''); const error = ref(''); const busy = ref(false);
        const loadAlternates = async (): Promise<void> => { if (!selected.value) { alternates.value = []; return; } alternates.value = (await api<ApiResponse<Array<{ id: string; name: string; asset_id: string }>>>(`/api/control/v1/campaigns/${id}/scenes/${selected.value}/backdrops`)).data; };
        const load = async (): Promise<void> => { try { const [sceneData, media, audio, stage] = await Promise.all([api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`), api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`), api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`), api<ApiResponse<StagePresetRecord[]>>(`/api/control/v1/campaigns/${id}/stage-presets`)]); scenes.value = sceneData.data; images.value = media.data.filter((item) => item.kind === 'image' && item.upload_status === 'ready'); music.value = audio.data.filter((item) => item.kind === 'music'); presets.value = stage.data; if (selected.value) await loadAlternates(); } catch (reason) { if (reason instanceof ApiError && reason.status === 401) await router.replace('/login'); else error.value = 'Unable to load scenes.'; } };
        const create = async (): Promise<void> => { if (!name.value.trim()) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<SceneRecord>>(`/api/control/v1/campaigns/${id}/scenes`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: name.value, primary_backdrop_asset_id: backdrop.value || null, default_music_cue_id: cue.value || null, base_stage_preset_id: preset.value || null, transition: transition.value, transition_duration_ms: duration.value }) }); scenes.value = [...scenes.value, response.data]; revision.value++; name.value = ''; backdrop.value = ''; cue.value = ''; preset.value = ''; duration.value = 0; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to create this scene.'; await load(); } finally { busy.value = false; } };
        const addAlternate = async (): Promise<void> => { if (!selected.value || !alternateName.value.trim() || !alternateAsset.value) return; busy.value = true; error.value = ''; try { const response = await api<ApiResponse<{ id: string; name: string; asset_id: string }>>(`/api/control/v1/campaigns/${id}/scenes/${selected.value}/backdrops`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: alternateName.value, asset_id: alternateAsset.value }) }); alternates.value = [...alternates.value, response.data]; revision.value++; alternateName.value = ''; alternateAsset.value = ''; } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to add alternate backdrop.'; await loadAlternates(); } finally { busy.value = false; } };
        onMounted(load); return { scenes, images, music, presets, name, backdrop, cue, preset, transition, duration, selected, alternates, alternateName, alternateAsset, error, busy, create, addAlternate, loadAlternates, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Scenes</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add scene</h2><input v-model="name" maxlength="120" placeholder="Scene name" aria-label="Scene name"><select v-model="backdrop" aria-label="Primary backdrop"><option value="">No primary backdrop</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><select v-model="cue" aria-label="Default music"><option value="">No default music</option><option v-for="item in music" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="preset" aria-label="Base stage preset"><option value="">Empty stage</option><option v-for="item in presets" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="transition" aria-label="Transition"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select><label>Transition duration (ms) <input v-model.number="duration" type="number" min="0" max="30000"></label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create scene' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Alternate backdrops</h2><select v-model="selected" aria-label="Scene for alternate backdrops" @change="loadAlternates"><option value="">Choose scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><input v-model="alternateName" maxlength="120" placeholder="Backdrop name" aria-label="Alternate backdrop name"><select v-model="alternateAsset" aria-label="Alternate backdrop image"><option value="">Choose ready image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><button :disabled="busy || !selected" @click="addAlternate">Add alternate</button><article v-for="item in alternates" :key="item.id" class="asset"><strong>{{ item.name }}</strong></article></section><section class="panel stack"><h2>Draft scenes</h2><p v-if="scenes.length === 0" class="muted">No scenes yet.</p><article v-for="scene in scenes" :key="scene.id" class="asset"><div><strong>{{ scene.name }}</strong><div class="muted">{{ scene.transition }} · {{ scene.transition_duration_ms }}ms</div></div></article></section></main>`,
});

const AssetsView = defineComponent({
    setup() {
        const route = useRoute(); const router = useRouter();
        const id = String(route.params.campaign); const revision = ref(Number(route.query.revision ?? 1));
        const assets = ref<Asset[]>([]); const file = ref<File | null>(null); const error = ref(''); const busy = ref(false);
        const load = async (): Promise<void> => {
            try { assets.value = (await api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`)).data; }
            catch (reason) { if (reason instanceof ApiError && reason.status === 401) await router.replace('/login'); else error.value = 'Unable to load this asset library.'; }
        };
        const choose = (event: Event): void => { file.value = (event.target as HTMLInputElement).files?.[0] ?? null; };
        const kindFor = (mime: string): 'image' | 'audio' | 'video' | null => mime.startsWith('image/') ? 'image' : mime.startsWith('audio/') ? 'audio' : mime.startsWith('video/') ? 'video' : null;
        const upload = async (): Promise<void> => {
            if (!file.value) return; const selected = file.value; const kind = kindFor(selected.type); if (!kind) { error.value = 'Choose a supported image, audio, or video file.'; return; }
            busy.value = true; error.value = '';
            try {
                const start = await api<ApiResponse<Asset> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(`/api/control/v1/campaigns/${id}/assets/uploads`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, original_filename: selected.name, kind, declared_mime: selected.type, byte_size: selected.size }) });
                const parts = await Promise.all(start.upload.parts.map(async (part) => {
                    const body = selected.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, selected.size));
                    const response = await fetch(part.url, { method: 'PUT', body }); const eTag = response.headers.get('ETag'); if (!response.ok || !eTag) throw new Error('A storage upload part failed.'); return { number: part.number, e_tag: eTag };
                }));
                const done = await api<ApiResponse<Asset>>(`/api/control/v1/campaigns/${id}/assets/${start.data.id}/complete`, { method: 'POST', body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value + 1, parts }) });
                revision.value += 2; assets.value = [done.data, ...assets.value.filter((asset) => asset.id !== done.data.id)]; file.value = null;
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to upload this asset.'; await load(); } finally { busy.value = false; }
        };
        const open = async (asset: Asset): Promise<void> => { try { window.open((await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${id}/assets/${asset.id}/read`)).data.url, '_blank', 'noopener'); } catch { error.value = 'This asset is not ready to open.'; } };
        onMounted(load); return { assets, file, error, busy, choose, upload, open, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Asset library</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Upload media</h2><p class="muted">Images, audio, and video upload directly to private storage and are validated before use.</p><input aria-label="Asset file" type="file" accept="image/jpeg,image/png,image/webp,audio/mpeg,audio/wav,audio/ogg,video/mp4,video/webm" @change="choose"><button :disabled="!file || busy" @click="upload">{{ busy ? 'Uploading…' : 'Upload asset' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Private assets</h2><p v-if="assets.length === 0" class="muted">No assets uploaded yet.</p><article v-for="asset in assets" :key="asset.id" class="asset"><div><strong>{{ asset.original_filename }}</strong><div class="muted">{{ asset.kind }} · {{ asset.upload_status }}</div><div v-if="asset.validation_error" class="error">{{ asset.validation_error }}</div></div><button v-if="asset.upload_status === 'ready'" class="secondary" @click="open(asset)">Open</button></article></section></main>`,
});

const router = createRouter({
    history: createWebHistory('/control'),
    routes: [
        { path: '/', component: CampaignsView },
        { path: '/campaigns/:campaign/assets', component: AssetsView },
        { path: '/campaigns/:campaign/pcs', component: PlayerCharactersView },
        { path: '/campaigns/:campaign/npcs', component: NpcsView },
        { path: '/campaigns/:campaign/audio', component: AudioCuesView },
        { path: '/campaigns/:campaign/scenes', component: ScenesView },
        { path: '/login', component: LoginView },
    ],
});

createApp({ template: '<RouterView />' }).use(createPinia()).use(router).mount('#app');
