import { createApp, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch, type PropType } from 'vue';
import { api, ApiError } from '../shared/api';
import { useRealtimeSnapshot } from '../shared/realtime';
import '../css/app.css';

type ApiResponse<T> = { data: T };
type Participant = { id: string; role: 'player' | 'spectator'; display_name: string; resume_token?: string };
type RosterCharacter = { id: string; name: string | null; pronouns: string | null; public_description: string | null; claimed: boolean; claimed_by_me: boolean };
type Roster = { role: 'player' | 'spectator'; characters: RosterCharacter[] };
type NpcNote = { id: string; body: string; author_name: string; created_at: string };
type RevealedNpc = { id: string; name: string | null; pronouns: string | null; public_description: string | null; revealed_at: string | null; notes: NpcNote[] };
type FogBrush = { id: string; mode: 'reveal' | 'hide'; center_x: number; center_y: number; radius: number };
type Token = { source_token_id: string; label: string | null; position_x: number; position_y: number; scale: number };
type CurrentMap = {
    state: { live_session_id: string; map_id: string | null; revision: number };
    map: { id: string; name: string; image_asset_id: string } | null;
    progress: { revision: number; fog: { default_visibility: 'hidden' | 'revealed'; brushes: FogBrush[] }; tokens: Token[] } | null;
};

const FogMap = defineComponent({
    props: { snapshot: { type: Object as PropType<CurrentMap>, required: true }, imageUrl: { type: String, default: '' } },
    setup(props) {
        const canvas = ref<HTMLCanvasElement | null>(null);
        const zoom = ref(1);
        const drawFog = (): void => {
            const element = canvas.value;
            const fog = props.snapshot.progress?.fog;
            if (!element || !fog) return;
            const bounds = element.getBoundingClientRect();
            const ratio = window.devicePixelRatio || 1;
            element.width = Math.max(1, Math.round(bounds.width * ratio));
            element.height = Math.max(1, Math.round(bounds.height * ratio));
            const context = element.getContext('2d');
            if (!context) return;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.clearRect(0, 0, bounds.width, bounds.height);
            if (fog.default_visibility === 'hidden') {
                context.fillStyle = 'rgba(8, 11, 19, .88)';
                context.fillRect(0, 0, bounds.width, bounds.height);
            }
            fog.brushes.forEach((brush) => {
                context.globalCompositeOperation = brush.mode === 'reveal' ? 'destination-out' : 'source-over';
                context.fillStyle = 'rgba(8, 11, 19, .88)';
                context.beginPath();
                context.arc(brush.center_x * bounds.width, brush.center_y * bounds.height, brush.radius * Math.min(bounds.width, bounds.height), 0, Math.PI * 2);
                context.fill();
            });
            context.globalCompositeOperation = 'source-over';
        };
        const redraw = (): void => void nextTick(drawFog);
        onMounted(() => { redraw(); window.addEventListener('resize', redraw); });
        onBeforeUnmount(() => window.removeEventListener('resize', redraw));
        watch(() => [props.snapshot.progress?.revision, props.imageUrl, zoom.value], redraw);
        return { canvas, zoom, drawFog };
    },
    template: `
        <section class="panel stack" aria-labelledby="current-map-title">
            <header class="row"><div><h2 id="current-map-title">{{ snapshot.map?.name }}</h2><p class="muted">Read-only shared map</p></div><div class="row"><button class="secondary" @click="zoom = Math.max(.6, zoom - .2)" aria-label="Zoom out">−</button><span class="muted">{{ Math.round(zoom * 100) }}%</span><button class="secondary" @click="zoom = Math.min(2, zoom + .2)" aria-label="Zoom in">+</button></div></header>
            <div class="map-viewport"><div class="map-stage" :style="{ transform: 'scale(' + zoom + ')' }">
                <img v-if="imageUrl" class="map-image" :src="imageUrl" alt="">
                <div v-else class="map-placeholder">Loading map image…</div>
                <canvas ref="canvas" class="fog-layer" aria-hidden="true"></canvas>
                <div v-for="token in snapshot.progress?.tokens || []" :key="token.source_token_id" class="map-token" :style="{ left: (token.position_x * 100) + '%', top: (token.position_y * 100) + '%', transform: 'translate(-50%, -50%) scale(' + token.scale + ')' }">{{ token.label || 'Token' }}</div>
            </div></div>
            <p class="muted">Use the viewport to pan and the controls to zoom. Hidden tokens are never sent to this device.</p>
        </section>`,
});

const ParticipantApp = defineComponent({
    components: { FogMap },
    setup() {
        const playerCode = ref(''); const displayName = ref(''); const role = ref<'player' | 'spectator'>('player');
        const resumeToken = ref(localStorage.getItem('rpgays.resume_token') ?? ''); const identity = ref<Participant | null>(null); const roster = ref<Roster | null>(null); const npcs = ref<RevealedNpc[]>([]); const noteNpcId = ref(''); const noteBody = ref(''); const error = ref(''); const busy = ref(false); const imageUrl = ref('');
        const currentMap = useRealtimeSnapshot<CurrentMap>({
            load: async () => (await api<ApiResponse<CurrentMap>>('/api/participant/v1/map')).data,
            channel: (snapshot) => [
                `player_map_states.${snapshot.state.live_session_id}`,
                ...(snapshot.state.map_id === null ? [] : [`map_progresses.${snapshot.state.live_session_id}.${snapshot.state.map_id}`]),
            ],
            revision: (snapshot) => snapshot.progress?.revision ?? snapshot.state.revision,
        });
        const loadImage = async (): Promise<void> => {
            const assetId = currentMap.snapshot.value?.map?.image_asset_id;
            if (!assetId) { imageUrl.value = ''; return; }
            try { imageUrl.value = (await api<ApiResponse<{ url: string }>>(`/api/participant/v1/map/assets/${assetId}/read`)).data.url; }
            catch { imageUrl.value = ''; }
        };
        const loadRoster = async (): Promise<void> => { roster.value = (await api<ApiResponse<Roster>>('/api/participant/v1/roster')).data; };
        const loadNpcs = async (): Promise<void> => { npcs.value = (await api<ApiResponse<RevealedNpc[]>>('/api/participant/v1/npcs')).data; };
        const connect = async (): Promise<void> => {
            error.value = '';
            try { await Promise.all([currentMap.start(), loadRoster(), loadNpcs()]); await loadImage(); }
            catch (reason) { if (!(reason instanceof ApiError && reason.status === 401)) error.value = reason instanceof Error ? reason.message : 'Unable to load your map.'; }
        };
        const join = async (): Promise<void> => {
            if (!playerCode.value.trim() || !displayName.value.trim()) return;
            busy.value = true; error.value = '';
            try {
                const response = await api<ApiResponse<Participant>>('/api/participant/v1/join', { method: 'POST', body: JSON.stringify({ player_code: playerCode.value, display_name: displayName.value, role: role.value }) });
                identity.value = response.data;
                if (response.data.resume_token) { resumeToken.value = response.data.resume_token; localStorage.setItem('rpgays.resume_token', response.data.resume_token); }
                await connect();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to join this session.'; }
            finally { busy.value = false; }
        };
        const resume = async (): Promise<void> => {
            if (!resumeToken.value.trim()) return;
            busy.value = true; error.value = '';
            try {
                identity.value = (await api<ApiResponse<Participant>>('/api/participant/v1/resume', { method: 'POST', body: JSON.stringify({ resume_token: resumeToken.value }) })).data;
                localStorage.setItem('rpgays.resume_token', resumeToken.value);
                await connect();
            } catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to resume this session.'; }
            finally { busy.value = false; }
        };
        const claim = async (character: RosterCharacter): Promise<void> => {
            if (busy.value || character.claimed || identity.value?.role !== 'player') return;
            busy.value = true; error.value = '';
            try { await api('/api/participant/v1/claim', { method: 'POST', body: JSON.stringify({ player_character_id: character.id }) }); await loadRoster(); }
            catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to claim that character.'; await loadRoster().catch(() => undefined); }
            finally { busy.value = false; }
        };
        const addNpcNote = async (): Promise<void> => {
            if (!noteNpcId.value || !noteBody.value.trim() || identity.value?.role !== 'player') return;
            busy.value = true; error.value = '';
            try { await api(`/api/participant/v1/npcs/${noteNpcId.value}/notes`, { method: 'POST', body: JSON.stringify({ command_id: crypto.randomUUID(), body: noteBody.value }) }); noteBody.value = ''; await loadNpcs(); }
            catch (reason) { error.value = reason instanceof Error ? reason.message : 'Unable to add that NPC note.'; }
            finally { busy.value = false; }
        };
        onMounted(() => void connect());
        onBeforeUnmount(currentMap.stop);
        watch(() => currentMap.snapshot.value?.map?.image_asset_id, () => void loadImage());
        return { playerCode, displayName, role, resumeToken, identity, roster, npcs, noteNpcId, noteBody, error, busy, join, resume, claim, addNpcNote, currentMap, imageUrl };
    },
    template: `
        <main class="shell stack"><header><div class="eyebrow">Theatrical RPG</div><h1>Player</h1><p v-if="currentMap.snapshot" class="muted" role="status">Realtime: {{ currentMap.status === 'live' ? 'live' : 'degraded — polling snapshots' }}</p></header>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section v-if="!currentMap.snapshot" class="panel stack" aria-labelledby="join-title"><h2 id="join-title">Join a live session</h2>
                <form class="stack" @submit.prevent="join"><input v-model="playerCode" aria-label="Player code" maxlength="12" placeholder="Player code" required><input v-model="displayName" aria-label="Display name" maxlength="120" placeholder="Display name" required><select v-model="role" aria-label="Role"><option value="player">Player</option><option value="spectator">Spectator</option></select><button :disabled="busy">{{ busy ? 'Joining…' : 'Join session' }}</button></form>
                <form class="stack" @submit.prevent="resume"><h3>Resume</h3><input v-model="resumeToken" aria-label="Resume token" minlength="64" maxlength="64" placeholder="Resume token"><button class="secondary" :disabled="busy">Resume session</button></form>
            </section>
            <section v-else-if="currentMap.snapshot.map === null" class="panel stack"><h2>Map not currently shared</h2><p class="muted">Control has hidden the Player map. This page will update automatically when a map is shared.</p></section>
            <FogMap v-else :snapshot="currentMap.snapshot" :image-url="imageUrl" />
            <section v-if="roster" class="panel stack"><h2>Character roster</h2><p v-if="roster.role === 'spectator'" class="muted">Spectators can view the roster but cannot claim a character.</p><p v-else-if="roster.characters.some((character) => character.claimed_by_me)" class="muted">You have claimed a character for this session.</p><p v-else class="muted">Choose one unclaimed character.</p><article v-for="character in roster.characters" :key="character.id" class="asset"><div><strong>{{ character.name || 'Unnamed character' }}</strong><div class="muted">{{ character.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ character.public_description }}</div></div><button v-if="character.claimed_by_me" class="secondary" disabled>Claimed by you</button><button v-else-if="character.claimed" class="secondary" disabled>Claimed</button><button v-else :disabled="busy || roster.role !== 'player'" @click="claim(character)">Claim</button></article></section>
            <section v-if="identity" class="panel stack"><h2>Revealed NPCs</h2><p v-if="npcs.length === 0" class="muted">No NPC profiles have been revealed yet.</p><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name || 'Unnamed NPC' }}</strong><div class="muted">{{ npc.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ npc.public_description }}</div><section v-if="npc.notes.length" class="stack"><h3>Shared notes</h3><p v-for="note in npc.notes" :key="note.id" class="muted"><strong>{{ note.author_name }}</strong> · {{ note.body }}</p></section></div></article><form v-if="identity.role === 'player' && npcs.length" class="stack" @submit.prevent="addNpcNote"><h3>Add a shared note</h3><select v-model="noteNpcId" aria-label="NPC for shared note"><option value="">Choose an NPC</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name || 'Unnamed NPC' }}</option></select><textarea v-model="noteBody" maxlength="2000" aria-label="Shared NPC note" placeholder="Plain-text shared note"></textarea><button :disabled="busy || !noteNpcId || !noteBody.trim()">Add note</button></form></section>
            <section v-if="identity?.resume_token" class="panel stack"><h2>Save your resume token</h2><p class="muted">Store this token somewhere safe. It is also kept on this device for convenient resumption.</p><code>{{ identity.resume_token }}</code></section>
        </main>`,
});

createApp(ParticipantApp).mount('#app');
