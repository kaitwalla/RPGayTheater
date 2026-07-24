import { computed, createApp, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory, useRoute, useRouter } from 'vue-router';
import { api, apiForm, ApiError, loginWithControlSecret } from '../shared/api';
import { commandId } from '../shared/command-id';
import { Passkeys } from '@laravel/passkeys';
import { useRealtimeSnapshot } from '../shared/realtime';
import { ControlMapStage } from '../shared/control-map-stage';
import { PresentationStage, type PresentationStageEntry } from '../shared/presentation-stage';
import { CampaignStudioView } from './studio';
import VueKonva from 'vue-konva';
import '../css/app.css';

type Campaign = {
    id: string;
    name: string;
    draft_revision: number;
    archived_at: string | null;
    updated_at: string;
};

type ApiResponse<T> = { data: T; meta?: { replayed: boolean } };
type Asset = {
    id: string;
    original_filename: string;
    kind: string;
    declared_mime: string;
    byte_size: number;
    upload_status: string;
    metadata: Record<string, number> | null;
    archived_at: string | null;
    validation_error: string | null;
};
type PlayerCharacter = { id: string; name: string; pronouns: string | null; public_description: string | null; avatar_asset_id: string | null };
type Npc = { id: string; name: string; pronouns: string | null; public_description: string | null; normal_asset_id: string; native_facing: 'right' };
type NpcState = { id: string; name: string; asset_id: string; sort_order: number };
type AudioCue = { id: string; name: string; asset_id: string; kind: 'music' | 'sfx'; loop: boolean; default_volume: number };
type VideoCue = {
    id: string;
    name: string;
    primary_asset_id: string;
    fallback_asset_id: string | null;
    completion_mode: 'restore_captured_scene' | 'enter_target_scene';
    target_scene_id: string | null;
    music_during: 'continue' | 'pause' | 'stop';
    music_after: 'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent';
    embedded_audio_volume: number;
    embedded_audio_muted: boolean;
};
type SceneRecord = {
    id: string;
    name: string;
    primary_backdrop_asset_id: string | null;
    default_music_cue_id: string | null;
    base_stage_preset_id: string | null;
    transition: 'cut' | 'fade_black' | 'cross_dissolve';
    transition_duration_ms: number;
};
type StagePresetRecord = { id: string; name: string; tween_duration_ms: number; tween_easing: string };
type StagePresetEntryRecord = {
    id: string;
    stage_preset_id: string;
    npc_id: string;
    npc_state_id: string | null;
    position_x: number;
    position_y: number;
    scale: number;
    layer_order: number;
    facing: 'left' | 'right';
};
type StagePresetNpcState = NpcState & { npc_id: string };
type DicePresetRecord = { id: string; name: string; expression: string; default_visibility: 'public' | 'private'; is_default: boolean };
type CampaignMapRecord = { id: string; name: string; image_asset_id: string; sort_order: number };
type MapFogMaskRecord = { id: string; map_id: string; asset_id: string };
type DraftMapTokenRecord = {
    id: string;
    map_id: string;
    token_type: 'pc' | 'npc' | 'custom';
    player_character_id: string | null;
    npc_id: string | null;
    asset_id: string | null;
    label: string | null;
    position_x: number;
    position_y: number;
    scale: number;
    sort_order: number;
};
type CampaignRevision = { id: string; number: number; published_at: string };
type PublishPreflight = { valid: boolean; issues: string[]; summary: Record<string, number> };
type Passkey = { id: string; name: string; last_used_at: string | null; created_at: string };
type SessionRevisionPreflight = {
    from_revision_id: string;
    to_revision_id: string;
    compatible: boolean;
    blockers: Array<{ type: string; player_character_id?: string; map_id?: string; reference_type?: string; reference_id?: string }>;
    changes: Record<string, { added: string[]; removed: string[]; changed: string[] }>;
};
type LiveSessionRecord = {
    id: string;
    campaign_revision_id: string;
    name: string;
    progress_mode: 'fresh' | 'resume';
    player_code: string;
    status: string;
    archived_at: string | null;
    created_at: string;
    display_pairing_token?: string;
};
type SessionParticipantRecord = {
    id: string;
    role: 'player' | 'spectator';
    display_name: string;
    player_character_id: string | null;
    revoked_at: string | null;
};
type SessionPlayerGroupRecord = { id: string; name: string; member_participant_ids: string[] };
type SessionMessageRecord = {
    id: string;
    sender_type: 'control' | 'participant';
    sender_session_participant_id: string | null;
    sender_name: string;
    target_type: 'control' | 'individual' | 'player_group' | 'all_players' | 'all_spectators' | 'all';
    target_session_participant_id: string | null;
    session_player_group_id: string | null;
    reply_to_session_message_id: string | null;
    body: string;
    created_at: string;
};
type SessionPollRecord = {
    id: string;
    question: string;
    allows_multiple: boolean;
    target_type: string;
    status: 'open' | 'closed';
    result_visibility: 'none' | 'live' | 'final';
    options: Array<{ id: string; body: string; votes: number | null }>;
};
type SessionRollRecord = {
    id: string;
    roller_name: string;
    dice_preset_name: string | null;
    expression: string;
    visibility: 'public' | 'private';
    total: number;
    breakdown: { type: string };
    revealed_at: string | null;
    created_at: string;
};
type SessionNpcRevealRecord = { id: string; npc_id: string; is_revealed: boolean; revealed_at: string | null };
type SessionNpcNoteRecord = {
    id: string;
    npc_id: string;
    author_type: 'participant' | 'control';
    session_participant_id: string | null;
    body: string;
    created_at: string;
};
type PinnedMap = { id: string; name: string; image_asset_id: string };
type PlayerMapState = { map_id: string | null; revision: number };
type MapToken = { source_token_id: string; label: string | null; position_x: number; position_y: number; scale: number; sort_order: number };
type MapProgress = {
    revision: number;
    tokens: MapToken[];
    fog: {
        default_visibility: 'hidden' | 'revealed';
        brushes: Array<{ id: string; mode: 'reveal' | 'hide'; center_x: number; center_y: number; radius: number }>;
    };
};
type PresentationStateEntry = {
    npc_id: string;
    npc_state_id: string | null;
    position_x: number;
    position_y: number;
    scale: number;
    layer_order: number;
    facing: 'left' | 'right';
};
type MusicPlayback = {
    status: 'playing' | 'paused' | 'stopped';
    position_seconds: number;
    position_command_id: string | null;
    loop: boolean;
    volume: number;
    fade_duration_ms: number;
};
type SfxInstance = { id: string; cue_id: string; loop: boolean; volume: number };
type PresentationCue = {
    scene_id: string | null;
    backdrop_asset_id: string | null;
    music_cue_id: string | null;
    music_playback: MusicPlayback;
    sfx_master_volume: number;
    sfx_instances: SfxInstance[];
    video_cue_id: string | null;
    stage_preset_id: string | null;
    stage_entries: PresentationStateEntry[];
};
type PresentationSnapshot = {
    revision: number;
    state: PresentationCue & { standby: PresentationCue | null; standby_status: 'idle' | 'preparing' | 'ready' | 'error'; standby_error: string | null };
};
type PinnedScene = {
    id: string;
    name: string;
    primary_backdrop_asset_id: string | null;
    default_music_cue_id: string | null;
    base_stage_preset_id: string | null;
    transition: 'cut' | 'fade_black' | 'cross_dissolve';
    transition_duration_ms: number;
};
type PinnedNpc = { id: string; name: string; normal_asset_id: string; native_facing: 'right' };
type PinnedNpcState = { id: string; npc_id: string; asset_id: string; name: string };
type PinnedStagePresetEntry = PresentationStateEntry & { stage_preset_id: string };
type PinnedStagePreset = { id: string; name: string; tween_duration_ms: number; tween_easing: 'linear' | 'ease_in' | 'ease_out' | 'ease_in_out' };
type PinnedSceneBackdrop = { id: string; scene_id: string; asset_id: string; name: string };
type PinnedAudioCue = { id: string; name: string; kind: 'music' | 'sfx'; loop: boolean; default_volume: number };
type PinnedVideoCue = {
    id: string;
    name: string;
    completion_mode: 'restore_captured_scene' | 'enter_target_scene';
    music_during: 'continue' | 'pause' | 'stop';
    music_after: 'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent';
};

const LoginView = defineComponent({
    setup() {
        const router = useRouter();
        const secret = ref('');
        const error = ref('');
        const pending = ref(false);
        const passkeySupported = Passkeys.isSupported();

        const login = async (): Promise<void> => {
            pending.value = true;
            error.value = '';
            try {
                await loginWithControlSecret(secret.value);
                await router.replace('/');
            } catch (reason) {
                error.value = reason instanceof ApiError ? reason.message : 'Unable to contact Control.';
            } finally {
                pending.value = false;
            }
        };

        const loginWithPasskey = async (): Promise<void> => {
            pending.value = true;
            error.value = '';
            try {
                await Passkeys.verify({ routes: { options: '/api/control/v1/passkeys/login/options', submit: '/api/control/v1/passkeys/login' } });
                await router.replace('/');
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to sign in with a passkey.';
            } finally {
                pending.value = false;
            }
        };

        return { secret, error, pending, login, loginWithPasskey, passkeySupported };
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
            <button v-if="passkeySupported" class="secondary" :disabled="pending" @click="loginWithPasskey">Sign in with passkey</button>
        </section></main>`,
});

const PasskeysView = defineComponent({
    setup() {
        const router = useRouter();
        const passkeys = ref<Passkey[]>([]);
        const label = ref('');
        const secret = ref('');
        const confirmedUntil = ref<string | null>(null);
        const error = ref('');
        const busy = ref(false);
        const supported = Passkeys.isSupported();

        const load = async (): Promise<void> => {
            try {
                passkeys.value = (await api<ApiResponse<Passkey[]>>('/api/control/v1/passkeys')).data;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load passkeys.';
            }
        };

        const confirmSecret = async (): Promise<void> => {
            if (!secret.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<{ confirmed_until: string }>>('/api/control/v1/auth/confirm-secret', {
                    method: 'POST',
                    body: JSON.stringify({ secret: secret.value }),
                });
                confirmedUntil.value = response.data.confirmed_until;
                secret.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to confirm the Control secret.';
            } finally {
                busy.value = false;
            }
        };

        const register = async (): Promise<void> => {
            if (!label.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                await Passkeys.register({
                    name: label.value.trim(),
                    routes: { options: '/api/control/v1/user/passkeys/options', submit: '/api/control/v1/user/passkeys' },
                });
                label.value = '';
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to register this passkey.';
            } finally {
                busy.value = false;
            }
        };

        const remove = async (passkey: Passkey): Promise<void> => {
            if (!window.confirm(`Revoke the passkey “${passkey.name}”? This cannot be undone.`)) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/user/passkeys/${passkey.id}`, { method: 'DELETE' });
                await load();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to revoke this passkey.';
            } finally {
                busy.value = false;
            }
        };

        const logout = async (): Promise<void> => {
            await api<void>('/api/control/v1/auth/logout', { method: 'POST', body: JSON.stringify({}) });
            await router.replace('/login');
        };

        onMounted(load);
        return { passkeys, label, secret, confirmedUntil, error, busy, supported, confirmSecret, register, remove, logout, back: () => router.push('/') };
    },
    template: `
        <main class="shell stack"><header class="row"><div><div class="eyebrow">Control security</div><h1>Passkeys</h1></div><div class="row"><button class="secondary" @click="back">Campaigns</button><button class="secondary" @click="logout">Sign out</button></div></header>
            <section class="panel stack"><h2>Confirm Control secret</h2><p class="muted">A recent environment-secret confirmation is required before adding or revoking a passkey. It expires after 15 minutes.</p><p v-if="confirmedUntil" class="muted">Confirmed until {{ new Date(confirmedUntil).toLocaleTimeString() }}.</p><form class="row" @submit.prevent="confirmSecret"><input v-model="secret" type="password" autocomplete="current-password" aria-label="Control secret" placeholder="Control secret" required><button :disabled="busy">Confirm secret</button></form></section>
            <section class="panel stack"><h2>Add passkey</h2><p v-if="!supported" class="error">This browser does not support passkeys.</p><p v-else class="muted">Use a clear label such as “Studio MacBook” or “YubiKey”.</p><form class="row" @submit.prevent="register"><input v-model="label" maxlength="120" aria-label="Passkey label" placeholder="Passkey label" required><button :disabled="busy || !supported">Add passkey</button></form></section>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section class="panel stack"><h2>Registered passkeys</h2><p v-if="passkeys.length === 0" class="muted">No passkeys are registered. Keep the environment secret available for recovery.</p><article v-for="passkey in passkeys" :key="passkey.id" class="asset"><div><strong>{{ passkey.name }}</strong><div class="muted">Added {{ new Date(passkey.created_at).toLocaleString() }}{{ passkey.last_used_at ? ' · last used ' + new Date(passkey.last_used_at).toLocaleString() : '' }}</div></div><button class="danger" :disabled="busy" @click="remove(passkey)">Revoke</button></article></section>
        </main>`,
});

export const CampaignsView = defineComponent({
    setup() {
        const router = useRouter();
        const campaigns = ref<Campaign[]>([]);
        const campaignName = ref('');
        const publishReports = ref<Record<string, PublishPreflight>>({});
        const publishedRevisions = ref<Record<string, number>>({});
        const revisionHistories = ref<Record<string, CampaignRevision[]>>({});
        const packageFile = ref<File | null>(null);
        const importModalOpen = ref(false);
        const launchCampaign = ref<Campaign | null>(null);
        const sessionName = ref('');
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
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), name: campaignName.value }),
                });
                campaigns.value = [...campaigns.value, response.data].sort((a, b) => a.name.localeCompare(b.name));
                campaignName.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create campaign.';
            } finally {
                busy.value = false;
            }
        };

        const rename = async (campaign: Campaign): Promise<void> => {
            try {
                const response = await api<ApiResponse<Campaign>>(`/api/control/v1/campaigns/${campaign.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision, name: campaign.name }),
                });
                Object.assign(campaign, response.data);
            } catch (reason) {
                error.value =
                    reason instanceof ApiError && reason.status === 409
                        ? 'This campaign changed elsewhere. The current state has been reloaded.'
                        : reason instanceof Error
                          ? reason.message
                          : 'Unable to rename campaign.';
                await load();
            }
        };

        const archive = async (campaign: Campaign): Promise<void> => {
            if (!window.confirm(`Archive “${campaign.name}”?`)) return;
            try {
                await api<ApiResponse<Campaign>>(`/api/control/v1/campaigns/${campaign.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision }),
                });
                campaigns.value = campaigns.value.filter(({ id }) => id !== campaign.id);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive campaign.';
                await load();
            }
        };

        const preflight = async (campaign: Campaign): Promise<PublishPreflight | null> => {
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<PublishPreflight>>(`/api/control/v1/campaigns/${campaign.id}/publish-preflight`);
                publishReports.value = { ...publishReports.value, [campaign.id]: response.data };

                return response.data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to validate this draft.';

                return null;
            } finally {
                busy.value = false;
            }
        };

        const publish = async (campaign: Campaign): Promise<void> => {
            const report = await preflight(campaign);
            if (!report?.valid) return;
            if (!window.confirm(`Publish “${campaign.name}” as an immutable revision?`)) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaign.id}/publish`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision }),
                });
                publishedRevisions.value = { ...publishedRevisions.value, [campaign.id]: response.data.number };
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to publish this draft.';
                await preflight(campaign);
            } finally {
                busy.value = false;
            }
        };

        const openLiveSession = (campaign: Campaign): void => {
            launchCampaign.value = campaign;
            sessionName.value = campaign.name;
        };

        const startLiveSession = async (): Promise<void> => {
            const campaign = launchCampaign.value;
            if (!campaign || !sessionName.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const revision = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaign.id}/publish`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision }),
                });
                const session = await api<ApiResponse<LiveSessionRecord>>(`/api/control/v1/campaigns/${campaign.id}/sessions`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), campaign_revision_id: revision.data.id, progress_mode: 'fresh', name: sessionName.value }),
                });
                await router.push(`/campaigns/${campaign.id}/live/${session.data.id}`);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to start a fresh live session.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const choosePackage = (event: Event): void => {
            packageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
        };

        const importPackage = async (): Promise<void> => {
            if (packageFile.value === null) return;
            busy.value = true;
            error.value = '';
            try {
                const form = new FormData();
                form.append('command_id', commandId());
                form.append('package', packageFile.value);
                const response = await apiForm<ApiResponse<Campaign>>('/api/control/v1/campaigns/import', form);
                campaigns.value = [...campaigns.value, response.data].sort((left, right) => left.name.localeCompare(right.name));
                packageFile.value = null;
                importModalOpen.value = false;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to import this campaign package.';
            } finally {
                busy.value = false;
            }
        };

        const loadRevisions = async (campaign: Campaign): Promise<void> => {
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignRevision[]>>(`/api/control/v1/campaigns/${campaign.id}/revisions`);
                revisionHistories.value = { ...revisionHistories.value, [campaign.id]: response.data };
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to load revision history.';
            } finally {
                busy.value = false;
            }
        };

        const exportCampaign = async (campaign: Campaign): Promise<void> => {
            busy.value = true;
            error.value = '';
            try {
                const revisions = (await api<ApiResponse<CampaignRevision[]>>(`/api/control/v1/campaigns/${campaign.id}/revisions`)).data;
                if (!revisions[0]) throw new Error('Publish this campaign before exporting it.');
                await downloadPackage(campaign, revisions[0]);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to export this campaign.';
            } finally {
                busy.value = false;
            }
        };

        const downloadPackage = async (campaign: Campaign, revision: CampaignRevision): Promise<void> => {
            busy.value = true;
            error.value = '';
            try {
                const response = await fetch(`/api/control/v1/campaigns/${campaign.id}/revisions/${revision.id}/package`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/zip' },
                });
                if (!response.ok) throw new ApiError('Unable to export this revision package.', response.status);
                const url = URL.createObjectURL(await response.blob());
                const link = document.createElement('a');
                link.href = url;
                link.download = `campaign-${campaign.id}-revision-${revision.number}.zip`;
                link.click();
                URL.revokeObjectURL(url);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to export this revision package.';
            } finally {
                busy.value = false;
            }
        };

        const logout = async (): Promise<void> => {
            await api<void>('/api/control/v1/auth/logout', { method: 'POST', body: JSON.stringify({}) });
            await router.replace('/login');
        };

        const realtime = useRealtimeSnapshot({
            load: async () => {
                await load();
                return campaigns.value;
            },
            channel: () => 'control.campaigns',
        });
        onMounted(() => void realtime.start());
        onBeforeUnmount(realtime.stop);
        return {
            campaigns,
            campaignName,
            publishReports,
            publishedRevisions,
            revisionHistories,
            packageFile,
            importModalOpen,
            launchCampaign,
            sessionName,
            error,
            busy,
            createCampaign,
            rename,
            archive,
            preflight,
            publish,
            choosePackage,
            importPackage,
            loadRevisions,
            exportCampaign,
            downloadPackage,
            openLiveSession,
            startLiveSession,
            logout,
            realtimeStatus: realtime.status,
        };
    },
    template: `
        <main class="shell stack"><header class="row"><div><div class="eyebrow">Theatrical RPG</div><h1>Campaign drafts</h1><p class="muted" role="status">Realtime: {{ realtimeStatus === 'live' ? 'live' : realtimeStatus === 'degraded' ? 'degraded — polling snapshots' : 'connecting' }}</p></div><div class="row"><button class="secondary" @click="importModalOpen = true">Import campaign</button><RouterLink class="button secondary" to="/passkeys">Passkeys</RouterLink><button class="secondary" @click="logout">Sign out</button></div></header>
            <section class="panel stack" aria-labelledby="new-campaign-title"><h2 id="new-campaign-title">New campaign</h2>
                <form class="row" @submit.prevent="createCampaign"><input v-model="campaignName" aria-label="Campaign name" maxlength="120" required placeholder="Campaign name"><button :disabled="busy">Create campaign</button></form>
            </section>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section class="panel stack" aria-labelledby="campaign-list-title"><h2 id="campaign-list-title">Active drafts</h2>
                <p v-if="campaigns.length === 0" class="muted">No campaign drafts yet.</p>
                <article v-for="campaign in campaigns" :key="campaign.id" class="campaign"><input v-model="campaign.name" :aria-label="'Name for ' + campaign.name" maxlength="120"><RouterLink class="button" :to="'/campaigns/' + campaign.id">Open studio</RouterLink><button :disabled="busy" @click="openLiveSession(campaign)">Start live session</button><RouterLink class="button secondary" :to="'/campaigns/' + campaign.id + '/sessions'">Manage sessions</RouterLink><button class="secondary" :disabled="busy" @click="exportCampaign(campaign)">Export package</button><button class="secondary" :disabled="busy" @click="preflight(campaign)">Check publish</button><button class="secondary" :disabled="busy" @click="loadRevisions(campaign)">Revision history</button><button class="secondary" @click="rename(campaign)">Save</button><button class="danger" @click="archive(campaign)">Archive</button><div v-if="publishReports[campaign.id]" class="stack"><p :class="publishReports[campaign.id].valid ? 'muted' : 'error'">{{ publishReports[campaign.id].valid ? 'Draft is ready to publish.' : 'Draft needs attention before publishing.' }}</p><ul v-if="!publishReports[campaign.id].valid"><li v-for="issue in publishReports[campaign.id].issues" :key="issue">{{ issue }}</li></ul></div><div v-if="revisionHistories[campaign.id]" class="stack"><article v-for="revision in revisionHistories[campaign.id]" :key="revision.id" class="asset"><div><strong>Revision {{ revision.number }}</strong><div class="muted">{{ new Date(revision.published_at).toLocaleString() }}</div></div><button class="secondary" :disabled="busy" @click="downloadPackage(campaign, revision)">Export package</button></article></div></article>
            </section>
            <div v-if="launchCampaign" class="modal-backdrop" role="presentation" @click.self="launchCampaign = null"><section class="modal-panel stack" role="dialog" aria-modal="true" aria-labelledby="live-session-title"><header class="row"><div><div class="eyebrow">Fresh live session</div><h2 id="live-session-title">Start {{ launchCampaign.name }}</h2></div><button class="secondary" :disabled="busy" @click="launchCampaign = null">Close</button></header><p class="muted">This starts a new, empty playthrough pinned to the current campaign draft. Player groups and progress are not reused.</p><label>Session name<input v-model="sessionName" maxlength="120" aria-label="Session name" required></label><button :disabled="busy || !sessionName.trim()" @click="startLiveSession">Start live session</button></section></div>
            <div v-if="importModalOpen" class="modal-backdrop" role="presentation" @click.self="importModalOpen = false"><section class="modal-panel stack" role="dialog" aria-modal="true" aria-labelledby="import-campaign-title"><header class="row"><div><div class="eyebrow">Campaign package</div><h2 id="import-campaign-title">Import campaign</h2></div><button class="secondary" :disabled="busy" @click="importModalOpen = false">Close</button></header><p class="muted">Importing a revision package creates a new editable campaign draft with remapped private media.</p><input aria-label="Campaign package" type="file" accept="application/zip,.zip" @change="choosePackage"><button :disabled="busy || !packageFile" @click="importPackage">Import package</button></section></div>
        </main>`,
});

const PlayerCharactersView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const characters = ref<PlayerCharacter[]>([]);
        const assets = ref<Asset[]>([]);
        const name = ref('');
        const pronouns = ref('');
        const description = ref('');
        const avatar = ref('');
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                const [pcs, media] = await Promise.all([
                    api<ApiResponse<PlayerCharacter[]>>(`/api/control/v1/campaigns/${id}/player-characters`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                ]);
                characters.value = pcs.data;
                assets.value = media.data.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready');
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load characters.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<PlayerCharacter>>(`/api/control/v1/campaigns/${id}/player-characters`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        pronouns: pronouns.value || null,
                        public_description: description.value || null,
                        avatar_asset_id: avatar.value || null,
                    }),
                });
                characters.value = [...characters.value, response.data];
                revision.value++;
                name.value = '';
                pronouns.value = '';
                description.value = '';
                avatar.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this PC.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return { characters, assets, name, pronouns, description, avatar, error, busy, create, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Player characters</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add player character</h2><input v-model="name" maxlength="120" required placeholder="Character name" aria-label="Character name"><input v-model="pronouns" maxlength="120" placeholder="Pronouns" aria-label="Pronouns"><input v-model="description" maxlength="500" placeholder="Short public description" aria-label="Public description"><select v-model="avatar" aria-label="Avatar image"><option value="">No avatar</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create PC' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft roster</h2><p v-if="characters.length === 0" class="muted">No player characters yet.</p><article v-for="character in characters" :key="character.id" class="asset"><div><strong>{{ character.name }}</strong><div class="muted">{{ character.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ character.public_description }}</div></div></article></section></main>`,
});

const NpcsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const npcs = ref<Npc[]>([]);
        const assets = ref<Asset[]>([]);
        const states = ref<NpcState[]>([]);
        const name = ref('');
        const pronouns = ref('');
        const description = ref('');
        const normal = ref('');
        const selected = ref('');
        const stateName = ref('');
        const stateAsset = ref('');
        const error = ref('');
        const busy = ref(false);
        const loadStates = async (): Promise<void> => {
            if (!selected.value) {
                states.value = [];
                return;
            }
            states.value = (await api<ApiResponse<NpcState[]>>(`/api/control/v1/campaigns/${id}/npcs/${selected.value}/states`)).data;
        };
        const load = async (): Promise<void> => {
            try {
                const [characters, media] = await Promise.all([
                    api<ApiResponse<Npc[]>>(`/api/control/v1/campaigns/${id}/npcs`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                ]);
                npcs.value = characters.data;
                assets.value = media.data.filter((asset) => asset.kind === 'image' && asset.upload_status === 'ready');
                if (selected.value) await loadStates();
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load NPCs.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim() || !normal.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<Npc>>(`/api/control/v1/campaigns/${id}/npcs`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        pronouns: pronouns.value || null,
                        public_description: description.value || null,
                        normal_asset_id: normal.value,
                    }),
                });
                npcs.value = [...npcs.value, response.data];
                revision.value++;
                name.value = '';
                pronouns.value = '';
                description.value = '';
                normal.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this NPC.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const addState = async (): Promise<void> => {
            if (!selected.value || !stateName.value.trim() || !stateAsset.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<NpcState>>(`/api/control/v1/campaigns/${id}/npcs/${selected.value}/states`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: stateName.value, asset_id: stateAsset.value }),
                });
                states.value = [...states.value, response.data];
                revision.value++;
                stateName.value = '';
                stateAsset.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this state.';
                await loadStates();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return {
            npcs,
            assets,
            states,
            name,
            pronouns,
            description,
            normal,
            selected,
            stateName,
            stateAsset,
            error,
            busy,
            create,
            addState,
            loadStates,
            back: () => router.push('/'),
        };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>NPCs</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add NPC</h2><p class="muted">Use right-facing source art. The stage mirrors it automatically whenever the NPC faces left.</p><input v-model="name" maxlength="120" required placeholder="NPC name" aria-label="NPC name"><input v-model="pronouns" maxlength="120" placeholder="Pronouns" aria-label="NPC pronouns"><input v-model="description" maxlength="500" placeholder="Short public description" aria-label="NPC description"><select v-model="normal" aria-label="Normal portrait"><option value="">Choose normal portrait</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create NPC' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Emotional states</h2><p class="muted">Add a right-facing image for each stage-ready emotion.</p><select v-model="selected" aria-label="NPC for states" @change="loadStates"><option value="">Choose NPC</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><input v-model="stateName" maxlength="120" placeholder="State name" aria-label="State name"><select v-model="stateAsset" aria-label="State image"><option value="">Choose right-facing image</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><button :disabled="busy || !selected" @click="addState">Add emotion</button><p v-if="selected && states.length === 0" class="muted">No emotional states for this NPC yet.</p><article v-for="state in states" :key="state.id" class="asset"><strong>{{ state.name }}</strong></article></section><section class="panel stack"><h2>Draft NPCs</h2><p v-if="npcs.length === 0" class="muted">No NPCs yet.</p><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name }}</strong><div class="muted">Right-facing source art · {{ npc.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ npc.public_description }}</div></div></article></section></main>`,
});

const AudioCuesView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const cues = ref<AudioCue[]>([]);
        const assets = ref<Asset[]>([]);
        const name = ref('');
        const asset = ref('');
        const kind = ref<'music' | 'sfx'>('music');
        const loop = ref(false);
        const volume = ref(100);
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                const [audio, media] = await Promise.all([
                    api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                ]);
                cues.value = audio.data;
                assets.value = media.data.filter((item) => item.kind === 'audio' && item.upload_status === 'ready');
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load audio cues.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim() || !asset.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<AudioCue>>(`/api/control/v1/campaigns/${id}/audio-cues`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        asset_id: asset.value,
                        kind: kind.value,
                        loop: loop.value,
                        default_volume: volume.value,
                    }),
                });
                cues.value = [...cues.value, response.data];
                revision.value++;
                name.value = '';
                asset.value = '';
                loop.value = false;
                volume.value = 100;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this audio cue.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return { cues, assets, name, asset, kind, loop, volume, error, busy, create, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Audio cues</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add cue</h2><input v-model="name" maxlength="120" placeholder="Cue name" aria-label="Cue name"><select v-model="asset" aria-label="Audio asset"><option value="">Choose ready audio</option><option v-for="item in assets" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><select v-model="kind" aria-label="Cue type"><option value="music">Music</option><option value="sfx">Sound effect</option></select><label><input v-model="loop" type="checkbox"> Loop</label><label>Default volume <input v-model.number="volume" type="number" min="0" max="100"></label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create cue' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft cues</h2><p v-if="cues.length === 0" class="muted">No audio cues yet.</p><article v-for="cue in cues" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cue.kind }} · {{ cue.loop ? 'looping' : 'one shot' }} · {{ cue.default_volume }}%</div></div></article></section></main>`,
});

const VideoCuesView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const cues = ref<VideoCue[]>([]);
        const videos = ref<Asset[]>([]);
        const scenes = ref<SceneRecord[]>([]);
        const name = ref('');
        const primary = ref('');
        const fallback = ref('');
        const completion = ref<'restore_captured_scene' | 'enter_target_scene'>('restore_captured_scene');
        const target = ref('');
        const during = ref<'continue' | 'pause' | 'stop'>('pause');
        const after = ref<'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent'>('resume_prior');
        const volume = ref(100);
        const muted = ref(false);
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                const [videoData, media, sceneData] = await Promise.all([
                    api<ApiResponse<VideoCue[]>>(`/api/control/v1/campaigns/${id}/video-cues`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                    api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`),
                ]);
                cues.value = videoData.data;
                videos.value = media.data.filter((item) => item.kind === 'video' && item.upload_status === 'ready');
                scenes.value = sceneData.data;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load video cues.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim() || !primary.value || (completion.value === 'enter_target_scene' && !target.value)) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<VideoCue>>(`/api/control/v1/campaigns/${id}/video-cues`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        primary_asset_id: primary.value,
                        fallback_asset_id: fallback.value || null,
                        completion_mode: completion.value,
                        target_scene_id: completion.value === 'enter_target_scene' ? target.value : null,
                        music_during: during.value,
                        music_after: after.value,
                        embedded_audio_volume: volume.value,
                        embedded_audio_muted: muted.value,
                    }),
                });
                cues.value = [...cues.value, response.data];
                revision.value++;
                name.value = '';
                primary.value = '';
                fallback.value = '';
                target.value = '';
                volume.value = 100;
                muted.value = false;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this video cue.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return {
            cues,
            videos,
            scenes,
            name,
            primary,
            fallback,
            completion,
            target,
            during,
            after,
            volume,
            muted,
            error,
            busy,
            create,
            back: () => router.push('/'),
        };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Video cues</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add fullscreen video</h2><input v-model="name" maxlength="120" placeholder="Cue name" aria-label="Video cue name"><select v-model="primary" aria-label="Primary video"><option value="">Choose ready video</option><option v-for="asset in videos" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><select v-model="fallback" aria-label="Fallback video"><option value="">No fallback</option><option v-for="asset in videos" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><select v-model="completion" aria-label="Completion behavior"><option value="restore_captured_scene">Restore captured scene</option><option value="enter_target_scene">Enter target scene</option></select><select v-if="completion === 'enter_target_scene'" v-model="target" aria-label="Target scene"><option value="">Choose target scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><select v-model="during" aria-label="Music during video"><option value="continue">Continue scene music</option><option value="pause">Pause scene music</option><option value="stop">Stop scene music</option></select><select v-model="after" aria-label="Music after video"><option value="keep_current">Keep current music</option><option value="resume_prior">Resume prior music</option><option value="start_target_default">Start target default</option><option value="remain_silent">Remain silent</option></select><label>Embedded audio volume <input v-model.number="volume" type="number" min="0" max="100"></label><label><input v-model="muted" type="checkbox"> Mute embedded video audio</label><button :disabled="busy || !primary || !name.trim() || (completion === 'enter_target_scene' && !target)" @click="create">{{ busy ? 'Creating…' : 'Create video cue' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft video cues</h2><p v-if="cues.length === 0" class="muted">No video cues yet.</p><article v-for="cue in cues" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cue.completion_mode }} · music {{ cue.music_during }}/{{ cue.music_after }} · video audio {{ cue.embedded_audio_muted ? 'muted' : cue.embedded_audio_volume + '%' }}</div></div></article></section></main>`,
});

const ScenesView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const scenes = ref<SceneRecord[]>([]);
        const images = ref<Asset[]>([]);
        const music = ref<AudioCue[]>([]);
        const presets = ref<StagePresetRecord[]>([]);
        const name = ref('');
        const backdrop = ref('');
        const cue = ref('');
        const preset = ref('');
        const transition = ref<'cut' | 'fade_black' | 'cross_dissolve'>('cut');
        const duration = ref(0);
        const selected = ref('');
        const alternates = ref<Array<{ id: string; name: string; asset_id: string }>>([]);
        const alternateName = ref('');
        const alternateAsset = ref('');
        const error = ref('');
        const busy = ref(false);
        const loadAlternates = async (): Promise<void> => {
            if (!selected.value) {
                alternates.value = [];
                return;
            }
            alternates.value = (
                await api<ApiResponse<Array<{ id: string; name: string; asset_id: string }>>>(
                    `/api/control/v1/campaigns/${id}/scenes/${selected.value}/backdrops`,
                )
            ).data;
        };
        const load = async (): Promise<void> => {
            try {
                const [sceneData, media, audio, stage] = await Promise.all([
                    api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                    api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`),
                    api<ApiResponse<StagePresetRecord[]>>(`/api/control/v1/campaigns/${id}/stage-presets`),
                ]);
                scenes.value = sceneData.data;
                images.value = media.data.filter((item) => item.kind === 'image' && item.upload_status === 'ready');
                music.value = audio.data.filter((item) => item.kind === 'music');
                presets.value = stage.data;
                if (selected.value) await loadAlternates();
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load scenes.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<SceneRecord>>(`/api/control/v1/campaigns/${id}/scenes`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        primary_backdrop_asset_id: backdrop.value || null,
                        default_music_cue_id: cue.value || null,
                        base_stage_preset_id: preset.value || null,
                        transition: transition.value,
                        transition_duration_ms: duration.value,
                    }),
                });
                scenes.value = [...scenes.value, response.data];
                revision.value++;
                name.value = '';
                backdrop.value = '';
                cue.value = '';
                preset.value = '';
                duration.value = 0;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this scene.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const addAlternate = async (): Promise<void> => {
            if (!selected.value || !alternateName.value.trim() || !alternateAsset.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<{ id: string; name: string; asset_id: string }>>(
                    `/api/control/v1/campaigns/${id}/scenes/${selected.value}/backdrops`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: revision.value,
                            name: alternateName.value,
                            asset_id: alternateAsset.value,
                        }),
                    },
                );
                alternates.value = [...alternates.value, response.data];
                revision.value++;
                alternateName.value = '';
                alternateAsset.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add alternate backdrop.';
                await loadAlternates();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return {
            scenes,
            images,
            music,
            presets,
            name,
            backdrop,
            cue,
            preset,
            transition,
            duration,
            selected,
            alternates,
            alternateName,
            alternateAsset,
            error,
            busy,
            create,
            addAlternate,
            loadAlternates,
            back: () => router.push('/'),
        };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Scenes</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add scene</h2><input v-model="name" maxlength="120" placeholder="Scene name" aria-label="Scene name"><select v-model="backdrop" aria-label="Primary backdrop"><option value="">No primary backdrop</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><select v-model="cue" aria-label="Default music"><option value="">No default music</option><option v-for="item in music" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="preset" aria-label="Base stage preset"><option value="">Empty stage</option><option v-for="item in presets" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="transition" aria-label="Transition"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select><label>Transition duration (ms) <input v-model.number="duration" type="number" min="0" max="30000"></label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create scene' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Alternate backdrops</h2><select v-model="selected" aria-label="Scene for alternate backdrops" @change="loadAlternates"><option value="">Choose scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><input v-model="alternateName" maxlength="120" placeholder="Backdrop name" aria-label="Alternate backdrop name"><select v-model="alternateAsset" aria-label="Alternate backdrop image"><option value="">Choose ready image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><button :disabled="busy || !selected" @click="addAlternate">Add alternate</button><article v-for="item in alternates" :key="item.id" class="asset"><strong>{{ item.name }}</strong></article></section><section class="panel stack"><h2>Draft scenes</h2><p v-if="scenes.length === 0" class="muted">No scenes yet.</p><article v-for="scene in scenes" :key="scene.id" class="asset"><div><strong>{{ scene.name }}</strong><div class="muted">{{ scene.transition }} · {{ scene.transition_duration_ms }}ms</div></div></article></section></main>`,
});

const StagePresetsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const presets = ref<StagePresetRecord[]>([]);
        const npcs = ref<Npc[]>([]);
        const states = ref<StagePresetNpcState[]>([]);
        const entries = ref<StagePresetEntryRecord[]>([]);
        const selected = ref('');
        const name = ref('');
        const tweenDuration = ref(0);
        const tweenEasing = ref<'linear' | 'ease_in' | 'ease_out' | 'ease_in_out'>('linear');
        const npc = ref('');
        const state = ref('');
        const positionX = ref(0.5);
        const positionY = ref(0.8);
        const scale = ref(1);
        const layerOrder = ref(0);
        const facing = ref<'left' | 'right'>('right');
        const error = ref('');
        const busy = ref(false);
        const selectableStates = computed(() => states.value.filter((item) => item.npc_id === npc.value));
        const loadEntries = async (): Promise<void> => {
            entries.value = selected.value
                ? (await api<ApiResponse<StagePresetEntryRecord[]>>(`/api/control/v1/campaigns/${id}/stage-presets/${selected.value}/entries`)).data
                : [];
            layerOrder.value = entries.value.length;
        };
        const selectPreset = async (): Promise<void> => {
            try {
                error.value = '';
                await loadEntries();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to load stage entries.';
            }
        };
        const load = async (): Promise<void> => {
            try {
                const [presetData, npcData] = await Promise.all([
                    api<ApiResponse<StagePresetRecord[]>>(`/api/control/v1/campaigns/${id}/stage-presets`),
                    api<ApiResponse<Npc[]>>(`/api/control/v1/campaigns/${id}/npcs`),
                ]);
                presets.value = presetData.data;
                npcs.value = npcData.data;
                states.value = (
                    await Promise.all(
                        npcData.data.map(async (item) =>
                            (await api<ApiResponse<NpcState[]>>(`/api/control/v1/campaigns/${id}/npcs/${item.id}/states`)).data.map((npcState) => ({
                                ...npcState,
                                npc_id: item.id,
                            })),
                        ),
                    )
                ).flat();
                if (selected.value) await loadEntries();
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load stage presets.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StagePresetRecord>>(`/api/control/v1/campaigns/${id}/stage-presets`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        tween_duration_ms: tweenDuration.value,
                        tween_easing: tweenEasing.value,
                    }),
                });
                presets.value = [...presets.value, response.data].sort((left, right) => left.name.localeCompare(right.name));
                revision.value++;
                selected.value = response.data.id;
                entries.value = [];
                layerOrder.value = 0;
                name.value = '';
                tweenDuration.value = 0;
                tweenEasing.value = 'linear';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this stage preset.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const addEntry = async (): Promise<void> => {
            if (!selected.value || !npc.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StagePresetEntryRecord>>(`/api/control/v1/campaigns/${id}/stage-presets/${selected.value}/entries`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        npc_id: npc.value,
                        npc_state_id: state.value || null,
                        position_x: positionX.value,
                        position_y: positionY.value,
                        scale: scale.value,
                        layer_order: layerOrder.value,
                        facing: facing.value,
                    }),
                });
                entries.value = [...entries.value, response.data].sort((left, right) => left.layer_order - right.layer_order);
                revision.value++;
                layerOrder.value++;
                state.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this stage entry.';
                await loadEntries();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return {
            presets,
            npcs,
            entries,
            selected,
            name,
            tweenDuration,
            tweenEasing,
            npc,
            state,
            selectableStates,
            positionX,
            positionY,
            scale,
            layerOrder,
            facing,
            error,
            busy,
            create,
            addEntry,
            selectPreset,
            back: () => router.push('/'),
        };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Stage presets</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Create stage preset</h2><input v-model="name" maxlength="120" placeholder="Preset name" aria-label="Stage preset name"><label>Tween duration (ms) <input v-model.number="tweenDuration" type="number" min="0" max="30000"></label><select v-model="tweenEasing" aria-label="Stage tween easing"><option value="linear">Linear</option><option value="ease_in">Ease in</option><option value="ease_out">Ease out</option><option value="ease_in_out">Ease in and out</option></select><button :disabled="busy || !name.trim()" @click="create">Create preset</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Preset staging</h2><select v-model="selected" aria-label="Stage preset" @change="selectPreset"><option value="">Choose a preset</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }} · {{ preset.tween_duration_ms }}ms {{ preset.tween_easing }}</option></select><template v-if="selected"><div class="row"><select v-model="npc" aria-label="NPC to place" @change="state = ''"><option value="">Choose NPC</option><option v-for="item in npcs" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="state" aria-label="NPC state"><option value="">Normal appearance</option><option v-for="item in selectableStates" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="facing" aria-label="NPC facing"><option value="right">Face right</option><option value="left">Face left</option></select></div><div class="row"><label>X (0–1) <input v-model.number="positionX" type="number" min="0" max="1" step=".01"></label><label>Y (0–1) <input v-model.number="positionY" type="number" min="0" max="1" step=".01"></label><label>Scale <input v-model.number="scale" type="number" min=".1" max="5" step=".1"></label><label>Layer <input v-model.number="layerOrder" type="number" min="0" max="65535"></label></div><button :disabled="busy || !npc" @click="addEntry">Add NPC placement</button><p v-if="entries.length === 0" class="muted">No NPC placements in this preset yet.</p><article v-for="entry in entries" :key="entry.id" class="asset"><div><strong>{{ npcs.find((item) => item.id === entry.npc_id)?.name || 'NPC' }}</strong><div class="muted">{{ entry.npc_state_id ? (states.find((item) => item.id === entry.npc_state_id)?.name || 'State') : 'Normal appearance' }} · layer {{ entry.layer_order + 1 }} · {{ Math.round(entry.position_x * 100) }}%, {{ Math.round(entry.position_y * 100) }}% · {{ entry.scale }}× · faces {{ entry.facing }}</div></div></article></template></section><section class="panel stack"><h2>Draft presets</h2><p v-if="presets.length === 0" class="muted">No stage presets yet.</p><article v-for="preset in presets" :key="preset.id" class="asset"><div><strong>{{ preset.name }}</strong><div class="muted">{{ preset.tween_duration_ms }}ms · {{ preset.tween_easing }}</div></div></article></section></main>`,
});

const MapsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const maps = ref<CampaignMapRecord[]>([]);
        const images = ref<Asset[]>([]);
        const characters = ref<PlayerCharacter[]>([]);
        const npcs = ref<Npc[]>([]);
        const selected = ref('');
        const fogMask = ref<MapFogMaskRecord | null>(null);
        const tokens = ref<DraftMapTokenRecord[]>([]);
        const name = ref('');
        const image = ref('');
        const fogAsset = ref('');
        const tokenType = ref<'pc' | 'npc' | 'custom'>('pc');
        const playerCharacter = ref('');
        const npc = ref('');
        const customAsset = ref('');
        const label = ref('');
        const positionX = ref(0.5);
        const positionY = ref(0.5);
        const scale = ref(1);
        const error = ref('');
        const busy = ref(false);
        const loadMapDetails = async (): Promise<void> => {
            if (!selected.value) {
                fogMask.value = null;
                tokens.value = [];
                return;
            }
            const [fog, tokenData] = await Promise.all([
                api<ApiResponse<MapFogMaskRecord | null>>(`/api/control/v1/campaigns/${id}/maps/${selected.value}/fog-mask`),
                api<ApiResponse<DraftMapTokenRecord[]>>(`/api/control/v1/campaigns/${id}/maps/${selected.value}/tokens`),
            ]);
            fogMask.value = fog.data;
            tokens.value = tokenData.data;
        };
        const selectMap = async (): Promise<void> => {
            try {
                error.value = '';
                await loadMapDetails();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to load map details.';
            }
        };
        const load = async (): Promise<void> => {
            try {
                const [mapData, assetData, characterData, npcData] = await Promise.all([
                    api<ApiResponse<CampaignMapRecord[]>>(`/api/control/v1/campaigns/${id}/maps`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                    api<ApiResponse<PlayerCharacter[]>>(`/api/control/v1/campaigns/${id}/player-characters`),
                    api<ApiResponse<Npc[]>>(`/api/control/v1/campaigns/${id}/npcs`),
                ]);
                maps.value = mapData.data;
                images.value = assetData.data.filter((item) => item.kind === 'image' && item.upload_status === 'ready' && item.archived_at === null);
                characters.value = characterData.data;
                npcs.value = npcData.data;
                if (selected.value) await loadMapDetails();
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load maps.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim() || !image.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignMapRecord>>(`/api/control/v1/campaigns/${id}/maps`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, name: name.value, image_asset_id: image.value }),
                });
                maps.value = [...maps.value, response.data].sort((left, right) => left.sort_order - right.sort_order);
                revision.value++;
                selected.value = response.data.id;
                fogMask.value = null;
                tokens.value = [];
                name.value = '';
                image.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this map.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const setFogMask = async (): Promise<void> => {
            if (!selected.value || !fogAsset.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<MapFogMaskRecord>>(`/api/control/v1/campaigns/${id}/maps/${selected.value}/fog-mask`, {
                    method: 'PUT',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value, asset_id: fogAsset.value }),
                });
                fogMask.value = response.data;
                revision.value++;
                fogAsset.value = '';
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to set the initial fog mask.';
                await loadMapDetails();
            } finally {
                busy.value = false;
            }
        };
        const setTokenType = (): void => {
            playerCharacter.value = '';
            npc.value = '';
            customAsset.value = '';
            label.value = '';
        };
        const canAddToken = computed(
            () =>
                selected.value !== '' &&
                ((tokenType.value === 'pc' && playerCharacter.value !== '') ||
                    (tokenType.value === 'npc' && npc.value !== '') ||
                    (tokenType.value === 'custom' && customAsset.value !== '' && label.value.trim() !== '')),
        );
        const addToken = async (): Promise<void> => {
            if (!canAddToken.value || !selected.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<DraftMapTokenRecord>>(`/api/control/v1/campaigns/${id}/maps/${selected.value}/tokens`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        token_type: tokenType.value,
                        player_character_id: tokenType.value === 'pc' ? playerCharacter.value : null,
                        npc_id: tokenType.value === 'npc' ? npc.value : null,
                        asset_id: tokenType.value === 'custom' ? customAsset.value : null,
                        label: tokenType.value === 'custom' ? label.value : null,
                        position_x: positionX.value,
                        position_y: positionY.value,
                        scale: scale.value,
                    }),
                });
                tokens.value = [...tokens.value, response.data].sort((left, right) => left.sort_order - right.sort_order);
                revision.value++;
                positionX.value = 0.5;
                positionY.value = 0.5;
                scale.value = 1;
                setTokenType();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add this map token.';
                await loadMapDetails();
            } finally {
                busy.value = false;
            }
        };
        const tokenDescription = (token: DraftMapTokenRecord): string =>
            token.token_type === 'pc'
                ? characters.value.find((item) => item.id === token.player_character_id)?.name || 'Player character'
                : token.token_type === 'npc'
                  ? npcs.value.find((item) => item.id === token.npc_id)?.name || 'NPC'
                  : token.label || 'Custom token';
        onMounted(load);
        return {
            maps,
            images,
            characters,
            npcs,
            selected,
            fogMask,
            tokens,
            name,
            image,
            fogAsset,
            tokenType,
            playerCharacter,
            npc,
            customAsset,
            label,
            positionX,
            positionY,
            scale,
            error,
            busy,
            canAddToken,
            create,
            setFogMask,
            setTokenType,
            addToken,
            selectMap,
            tokenDescription,
            back: () => router.push('/'),
        };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Maps</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Create map</h2><input v-model="name" maxlength="120" placeholder="Map name" aria-label="Map name"><select v-model="image" aria-label="Map image"><option value="">Choose ready image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><button :disabled="busy || !name.trim() || !image" @click="create">Create map</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Initial map layout</h2><select v-model="selected" aria-label="Map to edit" @change="selectMap"><option value="">Choose a map</option><option v-for="map in maps" :key="map.id" :value="map.id">{{ map.name }}</option></select><template v-if="selected"><div class="stack"><h3>Initial fog</h3><p class="muted">{{ fogMask ? 'A fog mask is configured. Choose another image to replace it.' : 'No fog mask: this map begins fully revealed.' }}</p><select v-model="fogAsset" aria-label="Initial fog mask"><option value="">Choose ready fog image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><button class="secondary" :disabled="busy || !fogAsset" @click="setFogMask">Set initial fog mask</button></div><div class="stack"><h3>Initial tokens</h3><select v-model="tokenType" aria-label="Token type" @change="setTokenType"><option value="pc">Player character</option><option value="npc">NPC</option><option value="custom">Custom image</option></select><select v-if="tokenType === 'pc'" v-model="playerCharacter" aria-label="Player character token"><option value="">Choose player character</option><option v-for="item in characters" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-if="tokenType === 'npc'" v-model="npc" aria-label="NPC token"><option value="">Choose NPC</option><option v-for="item in npcs" :key="item.id" :value="item.id">{{ item.name }}</option></select><template v-if="tokenType === 'custom'"><input v-model="label" maxlength="120" placeholder="Token label" aria-label="Custom token label"><select v-model="customAsset" aria-label="Custom token image"><option value="">Choose ready image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select></template><div class="row"><label>X (0–1) <input v-model.number="positionX" type="number" min="0" max="1" step=".01"></label><label>Y (0–1) <input v-model.number="positionY" type="number" min="0" max="1" step=".01"></label><label>Scale <input v-model.number="scale" type="number" min=".1" max="5" step=".1"></label></div><button :disabled="busy || !canAddToken" @click="addToken">Add token</button><p v-if="tokens.length === 0" class="muted">No initial tokens yet.</p><article v-for="token in tokens" :key="token.id" class="asset"><div><strong>{{ tokenDescription(token) }}</strong><div class="muted">{{ token.token_type }} · {{ Math.round(token.position_x * 100) }}%, {{ Math.round(token.position_y * 100) }}% · {{ token.scale }}×</div></div></article></div></template></section><section class="panel stack"><h2>Draft maps</h2><p v-if="maps.length === 0" class="muted">No maps yet.</p><article v-for="map in maps" :key="map.id" class="asset"><div><strong>{{ map.name }}</strong><div class="muted">Sort order {{ map.sort_order + 1 }}</div></div></article></section></main>`,
});

const DicePresetsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const presets = ref<DicePresetRecord[]>([]);
        const name = ref('');
        const expression = ref('');
        const visibility = ref<'public' | 'private'>('public');
        const isDefault = ref(false);
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                presets.value = (await api<ApiResponse<DicePresetRecord[]>>(`/api/control/v1/campaigns/${id}/dice-presets`)).data;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load dice presets.';
            }
        };
        const create = async (): Promise<void> => {
            if (!name.value.trim() || !expression.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<DicePresetRecord>>(`/api/control/v1/campaigns/${id}/dice-presets`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        name: name.value,
                        expression: expression.value,
                        default_visibility: visibility.value,
                        is_default: isDefault.value,
                    }),
                });
                presets.value = [...presets.value.map((item) => ({ ...item, is_default: isDefault.value ? false : item.is_default })), response.data];
                revision.value++;
                name.value = '';
                expression.value = '';
                isDefault.value = false;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create this preset.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        onMounted(load);
        return { presets, name, expression, visibility, isDefault, error, busy, create, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Dice presets</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add preset</h2><input v-model="name" maxlength="120" placeholder="Preset name" aria-label="Preset name"><input v-model="expression" maxlength="200" placeholder="4d6kh3 + 2" aria-label="Dice expression"><select v-model="visibility" aria-label="Default visibility"><option value="public">Public</option><option value="private">Private</option></select><label><input v-model="isDefault" type="checkbox"> Campaign default</label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create preset' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft presets</h2><p v-if="presets.length === 0" class="muted">No dice presets yet.</p><article v-for="preset in presets" :key="preset.id" class="asset"><div><strong>{{ preset.name }}</strong><div class="muted">{{ preset.expression }} · {{ preset.default_visibility }}{{ preset.is_default ? ' · default' : '' }}</div></div></article></section></main>`,
});

const SessionManagerView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const sessions = ref<LiveSessionRecord[]>([]);
        const error = ref('');
        const busy = ref(false);

        const load = async (): Promise<void> => {
            busy.value = true;
            error.value = '';
            try {
                sessions.value = (await api<ApiResponse<LiveSessionRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions`)).data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to load live sessions.';
            } finally {
                busy.value = false;
            }
        };

        const rename = async (session: LiveSessionRecord): Promise<void> => {
            if (!session.name.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<LiveSessionRecord>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}`, {
                    method: 'PATCH', body: JSON.stringify({ command_id: commandId(), name: session.name }),
                });
                Object.assign(session, response.data);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to rename this live session.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const archive = async (session: LiveSessionRecord): Promise<void> => {
            if (!window.confirm(`Archive “${session.name}”? Players will no longer be able to join it.`)) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<LiveSessionRecord>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/archive`, {
                    method: 'POST', body: JSON.stringify({ command_id: commandId() }),
                });
                Object.assign(session, response.data);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive this live session.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        const remove = async (session: LiveSessionRecord): Promise<void> => {
            if (!window.confirm(`Delete “${session.name}” permanently? This removes its player data, activity, and presentation state.`)) return;
            busy.value = true;
            error.value = '';
            try {
                await api<ApiResponse<{ id: string; deleted: boolean }>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}`, {
                    method: 'DELETE', body: JSON.stringify({ command_id: commandId() }),
                });
                sessions.value = sessions.value.filter((candidate) => candidate.id !== session.id);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to delete this live session.';
                await load();
            } finally {
                busy.value = false;
            }
        };

        onMounted(load);
        return { sessions, error, busy, rename, archive, remove, back: () => router.push('/'), open: (session: LiveSessionRecord) => router.push(`/campaigns/${campaignId}/live/${session.id}`) };
    },
    template: `
        <main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign live sessions</div><h1>Manage sessions</h1><p class="muted">Names are for you; player codes remain the table’s join code.</p></div><button class="secondary" @click="back">Campaigns</button></header>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section class="panel stack"><p v-if="sessions.length === 0" class="muted">No live sessions yet. Start one from Campaigns when this draft is ready to play.</p><article v-for="session in sessions" :key="session.id" class="asset stack"><div class="row"><label class="grow">Session name<input v-model="session.name" maxlength="120" :aria-label="'Session name for ' + session.player_code"></label><span class="status-pill">{{ session.archived_at ? 'archived' : session.status }}</span></div><div class="muted">Player code: <strong>{{ session.player_code }}</strong> · created {{ new Date(session.created_at).toLocaleString() }}{{ session.archived_at ? ' · archived ' + new Date(session.archived_at).toLocaleString() : '' }}</div><div class="row"><button class="secondary" :disabled="busy" @click="rename(session)">Save name</button><button :disabled="busy || !!session.archived_at" @click="open(session)">Open controls</button><button class="secondary" :disabled="busy || !!session.archived_at" @click="archive(session)">Archive</button><button class="danger" :disabled="busy" @click="remove(session)">Delete permanently</button></div></article></section>
        </main>`,
});

const SessionsView = defineComponent({
    components: { ControlMapStage, PresentationStage },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const requestedSessionId = String(route.params.session);
        const sessions = ref<LiveSessionRecord[]>([]);
        const revisions = ref<CampaignRevision[]>([]);
        const maps = ref<PinnedMap[]>([]);
        const scenes = ref<PinnedScene[]>([]);
        const sceneBackdrops = ref<PinnedSceneBackdrop[]>([]);
        const audioCues = ref<PinnedAudioCue[]>([]);
        const videoCues = ref<PinnedVideoCue[]>([]);
        const npcs = ref<PinnedNpc[]>([]);
        const npcStates = ref<PinnedNpcState[]>([]);
        const presets = ref<PinnedStagePreset[]>([]);
        const presetEntries = ref<PinnedStagePresetEntry[]>([]);
        const participants = ref<SessionParticipantRecord[]>([]);
        const playerGroups = ref<SessionPlayerGroupRecord[]>([]);
        const playerGroupName = ref('');
        const sessionMessages = ref<SessionMessageRecord[]>([]);
        const polls = ref<SessionPollRecord[]>([]);
        const sessionRolls = ref<SessionRollRecord[]>([]);
        const pollQuestion = ref('');
        const pollOptions = ref('');
        const pollMultiple = ref(false);
        const pollAudience = ref<'individual' | 'player_group' | 'all' | 'all_players' | 'all_spectators'>('all');
        const pollParticipantId = ref('');
        const pollGroupId = ref('');
        const messageTargetType = ref<'individual' | 'player_group' | 'all_players' | 'all_spectators' | 'all'>('all');
        const messageParticipantId = ref('');
        const messageGroupId = ref('');
        const messageBody = ref('');
        const npcReveals = ref<SessionNpcRevealRecord[]>([]);
        const npcNotes = ref<SessionNpcNoteRecord[]>([]);
        const selectedSessionId = ref('');
        const adoptionRevisionId = ref('');
        const adoptionPreflight = ref<SessionRevisionPreflight | null>(null);
        const playerMap = ref<PlayerMapState | null>(null);
        const progress = ref<MapProgress | null>(null);
        const presentation = ref<PresentationSnapshot | null>(null);
        const presentationAssetUrls = ref<Record<string, string>>({});
        const standbySceneId = ref('');
        const stagePresetId = ref('');
        const stageNpcId = ref('');
        const stageNpcStateId = ref('');
        const mapInteraction = ref<'tokens' | 'fog'>('tokens');
        const brushMode = ref<'reveal' | 'hide'>('reveal');
        const brushX = ref(0.5);
        const brushY = ref(0.5);
        const brushRadius = ref(0.1);
        const imageUrl = ref('');
        const error = ref('');
        const busy = ref(false);
        const activeLiveTab = ref<'presentation' | 'map'>('presentation');
        const activeToolTab = ref<'messages' | 'polls' | 'party' | 'rolls' | 'npcs' | 'revision'>('messages');
        const copiedLink = ref('');
        const selectedSession = (): LiveSessionRecord | undefined => sessions.value.find((session) => session.id === selectedSessionId.value);
        const joinUrl = (): string => `${window.location.origin}/player`;
        const presentationUrl = (): string => `${window.location.origin}/presentation`;
        const copyText = async (value: string, label: string): Promise<void> => {
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
            } catch {
                const fallback = document.createElement('textarea');
                fallback.value = value;
                fallback.setAttribute('readonly', 'true');
                fallback.style.position = 'fixed';
                fallback.style.opacity = '0';
                document.body.append(fallback);
                fallback.select();
                document.execCommand('copy');
                fallback.remove();
            }
            copiedLink.value = label;
            window.setTimeout(() => {
                if (copiedLink.value === label) copiedLink.value = '';
            }, 1800);
        };
        const loadParticipants = async (): Promise<void> => {
            const session = selectedSession();
            participants.value = session
                ? (await api<ApiResponse<SessionParticipantRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/participants`)).data
                : [];
        };
        const loadPlayerGroups = async (): Promise<void> => {
            const session = selectedSession();
            playerGroups.value = session
                ? (await api<ApiResponse<SessionPlayerGroupRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/player-groups`)).data
                : [];
        };
        const loadMessages = async (): Promise<void> => {
            const session = selectedSession();
            sessionMessages.value = session
                ? (await api<ApiResponse<SessionMessageRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/messages`)).data
                : [];
        };
        const loadPolls = async (): Promise<void> => {
            const session = selectedSession();
            polls.value = session
                ? (await api<ApiResponse<SessionPollRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/polls`)).data
                : [];
        };
        const loadRolls = async (): Promise<void> => {
            const session = selectedSession();
            sessionRolls.value = session
                ? (await api<ApiResponse<SessionRollRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/rolls`)).data
                : [];
        };
        const loadNpcReveals = async (): Promise<void> => {
            const session = selectedSession();
            npcReveals.value = session
                ? (await api<ApiResponse<SessionNpcRevealRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/npc-reveals`)).data
                : [];
        };
        const loadNpcNotes = async (): Promise<void> => {
            const session = selectedSession();
            npcNotes.value = session
                ? (await api<ApiResponse<SessionNpcNoteRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/npc-notes`)).data
                : [];
        };
        const selectedMap = (): PinnedMap | undefined => maps.value.find((map) => map.id === playerMap.value?.map_id);
        const resolveEntries = (entries: PresentationStateEntry[]): PresentationStageEntry[] =>
            entries.flatMap((entry) => {
                const npc = npcs.value.find((item) => item.id === entry.npc_id);
                if (!npc) return [];
                const state = entry.npc_state_id ? npcStates.value.find((item) => item.id === entry.npc_state_id) : undefined;
                return [{ ...entry, name: npc.name, asset_id: state?.asset_id ?? npc.normal_asset_id, native_facing: npc.native_facing }];
            });
        const activeEntries = computed(() => (presentation.value ? resolveEntries(presentation.value.state.stage_entries) : []));
        const activeScene = computed(() => scenes.value.find((scene) => scene.id === presentation.value?.state.scene_id));
        const activeBackdrops = computed(() => {
            const scene = activeScene.value;
            if (!scene) return [];
            const primary = scene.primary_backdrop_asset_id ? [{ id: 'primary', asset_id: scene.primary_backdrop_asset_id, name: 'Primary backdrop' }] : [];
            return [...primary, ...sceneBackdrops.value.filter((backdrop) => backdrop.scene_id === scene.id)];
        });
        const selectableNpcStates = computed(() => npcStates.value.filter((state) => state.npc_id === stageNpcId.value));
        const loadPresentationAssets = async (): Promise<void> => {
            const active = presentation.value?.state;
            if (!active) return;
            const assetIds = [active.backdrop_asset_id, ...activeEntries.value.map((entry) => entry.asset_id)].filter(
                (assetId): assetId is string => assetId !== null && presentationAssetUrls.value[assetId] === undefined,
            );
            const urls = await Promise.all(
                assetIds.map(
                    async (assetId) =>
                        [
                            assetId,
                            (await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${campaignId}/assets/${assetId}/read`)).data.url,
                        ] as const,
                ),
            );
            presentationAssetUrls.value = { ...presentationAssetUrls.value, ...Object.fromEntries(urls) };
        };
        const loadWorkspace = async (): Promise<void> => {
            const session = selectedSession();
            if (!session) {
                maps.value = [];
                scenes.value = [];
                sceneBackdrops.value = [];
                audioCues.value = [];
                videoCues.value = [];
                npcs.value = [];
                npcStates.value = [];
                presets.value = [];
                presetEntries.value = [];
                playerMap.value = null;
                progress.value = null;
                presentation.value = null;
                presentationAssetUrls.value = {};
                imageUrl.value = '';
                return;
            }
            const [revision, state, presentationState] = await Promise.all([
                api<
                    ApiResponse<{
                        manifest: {
                            maps?: PinnedMap[];
                            scenes?: PinnedScene[];
                            scene_backdrops?: PinnedSceneBackdrop[];
                            audio_cues?: PinnedAudioCue[];
                            video_cues?: PinnedVideoCue[];
                            npcs?: PinnedNpc[];
                            npc_states?: PinnedNpcState[];
                            stage_presets?: PinnedStagePreset[];
                            stage_preset_entries?: PinnedStagePresetEntry[];
                        };
                    }>
                >(`/api/control/v1/campaigns/${campaignId}/revisions/${session.campaign_revision_id}`),
                api<ApiResponse<PlayerMapState>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/player-map`),
                api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state`),
            ]);
            maps.value = revision.data.manifest.maps ?? [];
            scenes.value = revision.data.manifest.scenes ?? [];
            sceneBackdrops.value = revision.data.manifest.scene_backdrops ?? [];
            audioCues.value = revision.data.manifest.audio_cues ?? [];
            videoCues.value = revision.data.manifest.video_cues ?? [];
            npcs.value = revision.data.manifest.npcs ?? [];
            npcStates.value = revision.data.manifest.npc_states ?? [];
            presets.value = revision.data.manifest.stage_presets ?? [];
            presetEntries.value = revision.data.manifest.stage_preset_entries ?? [];
            playerMap.value = state.data;
            presentation.value = presentationState.data;
            stagePresetId.value = presentationState.data.state.stage_preset_id ?? '';
            presentationAssetUrls.value = {};
            await loadPresentationAssets();
            standbySceneId.value ||= scenes.value[0]?.id ?? '';
            const map = selectedMap();
            progress.value = state.data.map_id
                ? (await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${state.data.map_id}/progress`))
                      .data
                : null;
            imageUrl.value = map
                ? (await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${campaignId}/assets/${map.image_asset_id}/read`)).data.url
                : '';
        };
        const load = async (): Promise<void> => {
            try {
                const [sessionData, revisionData] = await Promise.all([
                    api<ApiResponse<LiveSessionRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions`),
                    api<ApiResponse<CampaignRevision[]>>(`/api/control/v1/campaigns/${campaignId}/revisions`),
                ]);
                sessions.value = sessionData.data;
                revisions.value = revisionData.data;
                selectedSessionId.value = sessions.value.some((session) => session.id === requestedSessionId) ? requestedSessionId : '';
                if (!selectedSessionId.value) {
                    error.value = 'This live session is unavailable. Start a fresh session from Campaigns.';

                    return;
                }
                await loadWorkspace();
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load live sessions.';
            }
        };
        const selectSession = async (): Promise<void> => {
            adoptionRevisionId.value = '';
            adoptionPreflight.value = null;
            await Promise.all([
                loadWorkspace(),
                loadParticipants(),
                loadPlayerGroups(),
                loadMessages(),
                loadPolls(),
                loadRolls(),
                loadNpcReveals(),
                loadNpcNotes(),
            ]);
        };
        const preflightRevisionAdoption = async (): Promise<SessionRevisionPreflight | null> => {
            const session = selectedSession();
            if (!session || !adoptionRevisionId.value) return null;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<SessionRevisionPreflight>>(
                    `/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/revisions/${adoptionRevisionId.value}/preflight`,
                );
                adoptionPreflight.value = response.data;
                return response.data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to preflight this revision.';
                return null;
            } finally {
                busy.value = false;
            }
        };
        const adoptRevision = async (): Promise<void> => {
            const session = selectedSession();
            const preflight = await preflightRevisionAdoption();
            if (!session || !preflight?.compatible || !adoptionRevisionId.value) return;
            if (!window.confirm('Adopt this compatible published revision for the active session?')) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<LiveSessionRecord> & { preflight: SessionRevisionPreflight }>(
                    `/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/adopt-revision`,
                    { method: 'POST', body: JSON.stringify({ command_id: commandId(), campaign_revision_id: adoptionRevisionId.value }) },
                );
                sessions.value = sessions.value.map((item) => (item.id === response.data.id ? response.data : item));
                adoptionPreflight.value = response.preflight;
                adoptionRevisionId.value = '';
                await selectSession();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to adopt this revision.';
            } finally {
                busy.value = false;
            }
        };
        const changeSummary = (change: { added: string[]; removed: string[]; changed: string[] }): string =>
            `${change.added.length} added · ${change.removed.length} removed · ${change.changed.length} changed`;
        const createPlayerGroup = async (): Promise<void> => {
            const session = selectedSession();
            if (!session || !playerGroupName.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/player-groups`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), name: playerGroupName.value }),
                });
                playerGroupName.value = '';
                await loadPlayerGroups();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create the Player group.';
            } finally {
                busy.value = false;
            }
        };
        const setPlayerGroupMember = async (group: SessionPlayerGroupRecord, participant: SessionParticipantRecord, event: Event): Promise<void> => {
            const session = selectedSession();
            if (!session || participant.role !== 'player' || participant.revoked_at) return;
            const included = (event.target as HTMLInputElement).checked;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/player-groups/${group.id}/members/${participant.id}`, {
                    method: included ? 'PUT' : 'DELETE',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                await loadPlayerGroups();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update Player group membership.';
            } finally {
                busy.value = false;
            }
        };
        const sendMessage = async (): Promise<void> => {
            const session = selectedSession();
            if (
                !session ||
                !messageBody.value.trim() ||
                (messageTargetType.value === 'individual' && !messageParticipantId.value) ||
                (messageTargetType.value === 'player_group' && !messageGroupId.value)
            )
                return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/messages`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        target_type: messageTargetType.value,
                        target_session_participant_id: messageTargetType.value === 'individual' ? messageParticipantId.value : null,
                        session_player_group_id: messageTargetType.value === 'player_group' ? messageGroupId.value : null,
                        body: messageBody.value,
                    }),
                });
                messageBody.value = '';
                await loadMessages();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to send that message.';
            } finally {
                busy.value = false;
            }
        };
        const canPublishSpectatorReply = (message: SessionMessageRecord): boolean =>
            message.sender_type === 'participant' &&
            message.target_type === 'control' &&
            participants.value.some((participant) => participant.id === message.sender_session_participant_id && participant.role === 'spectator');
        const publishSpectatorReply = async (message: SessionMessageRecord): Promise<void> => {
            const session = selectedSession();
            if (!session || !canPublishSpectatorReply(message)) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/messages/${message.id}/publish-spectator-reply`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                await loadMessages();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to publish that Spectator reply.';
            } finally {
                busy.value = false;
            }
        };
        const createPoll = async (): Promise<void> => {
            const session = selectedSession();
            const options = pollOptions.value
                .split('\n')
                .map((option) => option.trim())
                .filter(Boolean);
            if (
                !session ||
                !pollQuestion.value.trim() ||
                options.length < 2 ||
                (pollAudience.value === 'individual' && !pollParticipantId.value) ||
                (pollAudience.value === 'player_group' && !pollGroupId.value)
            )
                return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/polls`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        question: pollQuestion.value,
                        options,
                        allows_multiple: pollMultiple.value,
                        target_type: pollAudience.value,
                        target_session_participant_id: pollAudience.value === 'individual' ? pollParticipantId.value : null,
                        session_player_group_id: pollAudience.value === 'player_group' ? pollGroupId.value : null,
                    }),
                });
                pollQuestion.value = '';
                pollOptions.value = '';
                pollMultiple.value = false;
                pollParticipantId.value = '';
                pollGroupId.value = '';
                await loadPolls();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to create that poll.';
            } finally {
                busy.value = false;
            }
        };
        const pollAction = async (poll: SessionPollRecord, action: 'close' | 'live' | 'final'): Promise<void> => {
            const session = selectedSession();
            if (!session) return;
            busy.value = true;
            error.value = '';
            try {
                await api(
                    `/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/polls/${poll.id}/${action === 'close' ? 'close' : 'publish-results'}`,
                    {
                        method: 'POST',
                        body: JSON.stringify(action === 'close' ? { command_id: commandId() } : { command_id: commandId(), visibility: action }),
                    },
                );
                await loadPolls();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update that poll.';
            } finally {
                busy.value = false;
            }
        };
        const revealRoll = async (roll: SessionRollRecord): Promise<void> => {
            const session = selectedSession();
            if (!session || roll.visibility === 'public') return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/rolls/${roll.id}/reveal`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                await loadRolls();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to reveal that roll.';
            } finally {
                busy.value = false;
            }
        };
        const setMap = async (mapId: string | null): Promise<void> => {
            const session = selectedSession();
            if (!session || !playerMap.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<PlayerMapState>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/player-map`, {
                    method: 'PUT',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: playerMap.value.revision, map_id: mapId }),
                });
                playerMap.value = response.data;
                progress.value = mapId
                    ? (await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${mapId}/progress`)).data
                    : null;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update the Player map.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const selectMap = (event: Event): void => {
            void setMap((event.target as HTMLSelectElement).value || null);
        };
        const applyBrush = async (point: { x: number; y: number; mode: 'reveal' | 'hide'; radius: number }): Promise<boolean> => {
            const session = selectedSession();
            const map = selectedMap();
            if (!session || !map || !progress.value) return false;
            busy.value = true;
            error.value = '';
            try {
                progress.value = (
                    await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${map.id}/progress/fog`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: progress.value.revision,
                            mode: point.mode,
                            center_x: point.x,
                            center_y: point.y,
                            radius: point.radius,
                        }),
                    })
                ).data;
                return true;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to apply this fog brush.';
                await loadWorkspace();
                return false;
            } finally {
                busy.value = false;
            }
        };
        const brush = async (): Promise<void> => {
            await applyBrush({ x: brushX.value, y: brushY.value, mode: brushMode.value, radius: brushRadius.value });
        };
        const reset = async (): Promise<void> => {
            const session = selectedSession();
            const map = selectedMap();
            if (!session || !map || !progress.value || !window.confirm('Reset this map to its authored fog and token layout?')) return;
            busy.value = true;
            try {
                progress.value = (
                    await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${map.id}/progress/reset`, {
                        method: 'POST',
                        body: JSON.stringify({ command_id: commandId(), expected_revision: progress.value.revision }),
                    })
                ).data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to reset this map.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const saveTokens = async (): Promise<void> => {
            const session = selectedSession();
            const map = selectedMap();
            if (!session || !map || !progress.value) return;
            busy.value = true;
            try {
                progress.value = (
                    await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${map.id}/progress`, {
                        method: 'PUT',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: progress.value.revision,
                            tokens: progress.value.tokens.map(({ source_token_id, position_x, position_y, scale, sort_order }) => ({
                                source_token_id,
                                position_x,
                                position_y,
                                scale,
                                sort_order,
                            })),
                        }),
                    })
                ).data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to save token positions.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const brushStroke = async (points: Array<{ x: number; y: number; mode: 'reveal' | 'hide'; radius: number }>): Promise<void> => {
            for (const point of points) {
                if (!(await applyBrush(point))) break;
            }
        };
        const moveTokens = async (tokens: MapToken[]): Promise<void> => {
            if (progress.value) progress.value.tokens = tokens;
            await saveTokens();
        };
        const standby = async (): Promise<void> => {
            const session = selectedSession();
            const scene = scenes.value.find((item) => item.id === standbySceneId.value);
            if (!session || !scene || !presentation.value) return;
            const stageEntries = scene.base_stage_preset_id
                ? presetEntries.value
                      .filter((entry) => entry.stage_preset_id === scene.base_stage_preset_id)
                      .map(({ npc_id, npc_state_id, position_x, position_y, scale, layer_order, facing }) => ({
                          npc_id,
                          npc_state_id,
                          position_x,
                          position_y,
                          scale,
                          layer_order,
                          facing,
                      }))
                : [];
            const cue = audioCues.value.find((item) => item.id === scene.default_music_cue_id);
            const musicPlayback = cue
                ? {
                      status: 'playing' as const,
                      position_seconds: 0,
                      position_command_id: null,
                      loop: cue.loop,
                      volume: cue.default_volume / 100,
                      fade_duration_ms: 0,
                  }
                : { status: 'stopped' as const, position_seconds: 0, position_command_id: null, loop: true, volume: 1, fade_duration_ms: 0 };
            busy.value = true;
            try {
                presentation.value = (
                    await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state/standby`, {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: presentation.value.revision,
                            state: {
                                scene_id: scene.id,
                                backdrop_asset_id: scene.primary_backdrop_asset_id,
                                music_cue_id: scene.default_music_cue_id,
                                music_playback: musicPlayback,
                                video_cue_id: null,
                                stage_preset_id: scene.base_stage_preset_id,
                                stage_entries: stageEntries,
                            },
                        }),
                    })
                ).data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to request standby.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const go = async (): Promise<void> => {
            const session = selectedSession();
            if (!session || !presentation.value) return;
            busy.value = true;
            try {
                presentation.value = (
                    await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state/go`, {
                        method: 'POST',
                        body: JSON.stringify({ command_id: commandId(), expected_revision: presentation.value.revision }),
                    })
                ).data;
                await loadPresentationAssets();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to Go.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const savePresentationEntries = async (
            entries: PresentationStateEntry[],
            presetId = presentation.value?.state.stage_preset_id ?? null,
            backdropId = presentation.value?.state.backdrop_asset_id ?? null,
            musicCueId = presentation.value?.state.music_cue_id ?? null,
            videoCueId = presentation.value?.state.video_cue_id ?? null,
            musicPlayback = presentation.value?.state.music_playback,
            sfxMasterVolume = presentation.value?.state.sfx_master_volume ?? 1,
            sfxInstances = presentation.value?.state.sfx_instances ?? [],
        ): Promise<void> => {
            const session = selectedSession();
            if (!session || !presentation.value) return;
            const state = presentation.value.state;
            busy.value = true;
            try {
                presentation.value = (
                    await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state`, {
                        method: 'PUT',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: presentation.value.revision,
                            state: {
                                scene_id: state.scene_id,
                                backdrop_asset_id: backdropId,
                                music_cue_id: musicCueId,
                                music_playback: musicPlayback,
                                sfx_master_volume: sfxMasterVolume,
                                sfx_instances: sfxInstances,
                                video_cue_id: videoCueId,
                                stage_preset_id: presetId,
                                stage_entries: entries,
                            },
                        }),
                    })
                ).data;
                stagePresetId.value = presetId ?? '';
                await loadPresentationAssets();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update staged NPCs.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const movePresentationEntry = async (moved: PresentationStageEntry): Promise<void> => {
            if (!presentation.value) return;
            await savePresentationEntries(
                presentation.value.state.stage_entries.map((entry) =>
                    entry.npc_id === moved.npc_id && entry.npc_state_id === moved.npc_state_id && entry.layer_order === moved.layer_order
                        ? { ...entry, position_x: moved.position_x, position_y: moved.position_y }
                        : entry,
                ),
            );
        };
        const setPresentationEntryEmotion = (entry: PresentationStageEntry, event: Event): void => {
            if (!presentation.value) return;
            const npcStateId = (event.target as HTMLSelectElement).value || null;
            void savePresentationEntries(
                presentation.value.state.stage_entries.map((item) =>
                    item.npc_id === entry.npc_id && item.layer_order === entry.layer_order ? { ...item, npc_state_id: npcStateId } : item,
                ),
            );
        };
        const addPresentationNpc = async (): Promise<void> => {
            const npc = npcs.value.find((item) => item.id === stageNpcId.value);
            if (!npc || !presentation.value) return;
            const layerOrder = Math.max(-1, ...presentation.value.state.stage_entries.map((entry) => entry.layer_order)) + 1;
            await savePresentationEntries([
                ...presentation.value.state.stage_entries,
                {
                    npc_id: npc.id,
                    npc_state_id: stageNpcStateId.value || null,
                    position_x: 0.5,
                    position_y: 0.85,
                    scale: 1,
                    layer_order: layerOrder,
                    facing: npc.native_facing,
                },
            ]);
        };
        const removePresentationEntry = async (removed: PresentationStageEntry): Promise<void> => {
            if (!presentation.value) return;
            await savePresentationEntries(
                presentation.value.state.stage_entries.filter(
                    (entry) => !(entry.npc_id === removed.npc_id && entry.npc_state_id === removed.npc_state_id && entry.layer_order === removed.layer_order),
                ),
            );
        };
        const applyStagePreset = async (): Promise<void> => {
            if (!presentation.value) return;
            const entries = stagePresetId.value
                ? presetEntries.value
                      .filter((entry) => entry.stage_preset_id === stagePresetId.value)
                      .map(({ npc_id, npc_state_id, position_x, position_y, scale, layer_order, facing }) => ({
                          npc_id,
                          npc_state_id,
                          position_x,
                          position_y,
                          scale,
                          layer_order,
                          facing,
                      }))
                : [];
            await savePresentationEntries(entries, stagePresetId.value || null);
        };
        const resetSceneStage = async (): Promise<void> => {
            const presetId = activeScene.value?.base_stage_preset_id ?? null;
            stagePresetId.value = presetId ?? '';
            await applyStagePreset();
        };
        const clearPresentationStage = async (): Promise<void> => {
            stagePresetId.value = '';
            await savePresentationEntries([], null);
        };
        const setBackdrop = async (assetId: string): Promise<void> => {
            if (!presentation.value) return;
            await savePresentationEntries(presentation.value.state.stage_entries, presentation.value.state.stage_preset_id, assetId || null);
        };
        const selectBackdrop = (event: Event): void => {
            void setBackdrop((event.target as HTMLSelectElement).value);
        };
        const selectMusic = (event: Event): void => {
            if (!presentation.value) return;
            const cue = audioCues.value.find((item) => item.id === (event.target as HTMLSelectElement).value);
            const playback = cue
                ? {
                      status: 'playing' as const,
                      position_seconds: 0,
                      position_command_id: null,
                      loop: cue.loop,
                      volume: cue.default_volume / 100,
                      fade_duration_ms: 0,
                  }
                : { status: 'stopped' as const, position_seconds: 0, position_command_id: null, loop: true, volume: 1, fade_duration_ms: 0 };
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                cue?.id ?? null,
                presentation.value.state.video_cue_id,
                playback,
            );
        };
        const stopMusic = (): void => {
            if (!presentation.value) return;
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                null,
                presentation.value.state.video_cue_id,
                { ...presentation.value.state.music_playback, status: 'stopped', position_seconds: 0, position_command_id: null },
            );
        };
        const saveMusicPlayback = (next: Partial<MusicPlayback>): void => {
            if (!presentation.value || !presentation.value.state.music_cue_id) return;
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                presentation.value.state.music_cue_id,
                presentation.value.state.video_cue_id,
                { ...presentation.value.state.music_playback, ...next },
            );
        };
        const setMusicVolume = (event: Event): void => saveMusicPlayback({ volume: Number((event.target as HTMLInputElement).value) / 100 });
        const seekMusic = (positionSeconds: number): void => saveMusicPlayback({ position_seconds: positionSeconds, position_command_id: commandId() });
        const setMusicPosition = (event: Event): void => seekMusic(Number((event.target as HTMLInputElement).value));
        const setMusicLoop = (event: Event): void => saveMusicPlayback({ loop: (event.target as HTMLInputElement).checked });
        const setMusicFade = (event: Event): void => saveMusicPlayback({ fade_duration_ms: Number((event.target as HTMLInputElement).value) });
        const triggerSfx = (cueId: string): void => {
            if (!presentation.value) return;
            const cue = audioCues.value.find((item) => item.id === cueId);
            if (!cue) return;
            const instance: SfxInstance = { id: commandId(), cue_id: cue.id, loop: cue.loop, volume: cue.default_volume / 100 };
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                presentation.value.state.music_cue_id,
                presentation.value.state.video_cue_id,
                presentation.value.state.music_playback,
                presentation.value.state.sfx_master_volume ?? 1,
                [...(presentation.value.state.sfx_instances ?? []), instance],
            );
        };
        const stopSfx = (instanceId: string): void => {
            if (!presentation.value) return;
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                presentation.value.state.music_cue_id,
                presentation.value.state.video_cue_id,
                presentation.value.state.music_playback,
                presentation.value.state.sfx_master_volume ?? 1,
                (presentation.value.state.sfx_instances ?? []).filter((instance) => instance.id !== instanceId),
            );
        };
        const stopAllSfx = (): void => {
            if (!presentation.value) return;
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                presentation.value.state.music_cue_id,
                presentation.value.state.video_cue_id,
                presentation.value.state.music_playback,
                presentation.value.state.sfx_master_volume ?? 1,
                [],
            );
        };
        const setSfxMasterVolume = (event: Event): void => {
            if (!presentation.value) return;
            void savePresentationEntries(
                presentation.value.state.stage_entries,
                presentation.value.state.stage_preset_id,
                presentation.value.state.backdrop_asset_id,
                presentation.value.state.music_cue_id,
                presentation.value.state.video_cue_id,
                presentation.value.state.music_playback,
                Number((event.target as HTMLInputElement).value) / 100,
                presentation.value.state.sfx_instances ?? [],
            );
        };
        const releaseClaim = async (participant: SessionParticipantRecord): Promise<void> => {
            const session = selectedSession();
            if (!session) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/participants/${participant.id}/claim`, { method: 'DELETE' });
                await loadParticipants();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to release the character claim.';
            } finally {
                busy.value = false;
            }
        };
        const revokeParticipant = async (participant: SessionParticipantRecord): Promise<void> => {
            const session = selectedSession();
            if (!session || !window.confirm(`Revoke ${participant.display_name} from this session?`)) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/participants/${participant.id}`, { method: 'DELETE' });
                await loadParticipants();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to revoke this participant.';
            } finally {
                busy.value = false;
            }
        };
        const npcIsRevealed = (npcId: string): boolean => npcReveals.value.some((reveal) => reveal.npc_id === npcId && reveal.is_revealed);
        const setNpcReveal = async (npcId: string, isRevealed: boolean): Promise<void> => {
            const session = selectedSession();
            if (!session) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<SessionNpcRevealRecord>>(
                    `/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/npc-reveals/${npcId}`,
                    { method: 'PUT', body: JSON.stringify({ command_id: commandId(), is_revealed: isRevealed }) },
                );
                npcReveals.value = [...npcReveals.value.filter((reveal) => reveal.npc_id !== npcId), response.data];
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update this NPC reveal.';
                await loadNpcReveals();
            } finally {
                busy.value = false;
            }
        };
        const editNpcNote = async (note: SessionNpcNoteRecord): Promise<void> => {
            const session = selectedSession();
            const body = window.prompt('Edit shared NPC note', note.body);
            if (!session || body === null || !body.trim()) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/npc-notes/${note.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ command_id: commandId(), body }),
                });
                await loadNpcNotes();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to moderate this note.';
            } finally {
                busy.value = false;
            }
        };
        const deleteNpcNote = async (note: SessionNpcNoteRecord): Promise<void> => {
            const session = selectedSession();
            if (!session || !window.confirm('Delete this shared NPC note?')) return;
            busy.value = true;
            try {
                await api(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/npc-notes/${note.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                await loadNpcNotes();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to delete this note.';
            } finally {
                busy.value = false;
            }
        };
        const selectVideo = (event: Event): void => {
            if (presentation.value)
                void savePresentationEntries(
                    presentation.value.state.stage_entries,
                    presentation.value.state.stage_preset_id,
                    presentation.value.state.backdrop_asset_id,
                    presentation.value.state.music_cue_id,
                    (event.target as HTMLSelectElement).value || null,
                );
        };
        const abortVideo = (): void => {
            if (presentation.value)
                void savePresentationEntries(
                    presentation.value.state.stage_entries,
                    presentation.value.state.stage_preset_id,
                    presentation.value.state.backdrop_asset_id,
                    presentation.value.state.music_cue_id,
                    null,
                );
        };
        onMounted(async () => {
            await load();
            await loadParticipants();
            await loadPlayerGroups();
            await loadMessages();
            await loadPolls();
            await loadRolls();
            await loadNpcReveals();
            await loadNpcNotes();
        });
        return {
            sessions,
            revisions,
            maps,
            scenes,
            audioCues,
            videoCues,
            npcs,
            npcStates,
            presets,
            participants,
            playerGroups,
            playerGroupName,
            sessionMessages,
            polls,
            sessionRolls,
            pollQuestion,
            pollOptions,
            pollMultiple,
            pollAudience,
            pollParticipantId,
            pollGroupId,
            messageTargetType,
            messageParticipantId,
            messageGroupId,
            messageBody,
            npcNotes,
            selectedSessionId,
            adoptionRevisionId,
            adoptionPreflight,
            playerMap,
            progress,
            presentation,
            presentationAssetUrls,
            activeEntries,
            activeScene,
            activeBackdrops,
            selectableNpcStates,
            standbySceneId,
            stagePresetId,
            stageNpcId,
            stageNpcStateId,
            mapInteraction,
            brushMode,
            brushX,
            brushY,
            brushRadius,
            imageUrl,
            error,
            busy,
            activeLiveTab,
            activeToolTab,
            copiedLink,
            selectedSession,
            joinUrl,
            presentationUrl,
            copyText,
            selectedMap,
            loadWorkspace,
            loadParticipants,
            loadPlayerGroups,
            loadMessages,
            loadPolls,
            loadRolls,
            loadNpcReveals,
            loadNpcNotes,
            selectSession,
            preflightRevisionAdoption,
            adoptRevision,
            changeSummary,
            createPlayerGroup,
            setPlayerGroupMember,
            sendMessage,
            canPublishSpectatorReply,
            publishSpectatorReply,
            createPoll,
            pollAction,
            revealRoll,
            setMap,
            selectMap,
            brush,
            brushStroke,
            reset,
            saveTokens,
            moveTokens,
            standby,
            go,
            movePresentationEntry,
            setPresentationEntryEmotion,
            addPresentationNpc,
            removePresentationEntry,
            applyStagePreset,
            resetSceneStage,
            clearPresentationStage,
            setBackdrop,
            selectBackdrop,
            selectMusic,
            stopMusic,
            saveMusicPlayback,
            seekMusic,
            setMusicVolume,
            setMusicPosition,
            setMusicLoop,
            setMusicFade,
            triggerSfx,
            stopSfx,
            stopAllSfx,
            setSfxMasterVolume,
            releaseClaim,
            revokeParticipant,
            npcIsRevealed,
            setNpcReveal,
            editNpcNote,
            deleteNpcNote,
            selectVideo,
            abortVideo,
            back: () => router.push('/'),
        };
    },
    template: `<main class="control-workspace">
        <header class="control-topbar">
            <div class="control-title"><div class="eyebrow">Live session</div><h1>{{ selectedSession()?.name || 'Playthrough control' }}</h1><p class="muted">{{ selectedSession() ? selectedSession()?.player_code + ' · ' + selectedSession()?.status : 'No active session selected' }}</p></div>
            <div class="control-top-actions"><button class="secondary" @click="back">Campaigns</button></div>
        </header>
        <p v-if="error" class="error control-error" role="alert">{{ error }}</p>
        <div class="control-grid">
            <aside class="control-sidebar stack" aria-label="Session controls">
                <section class="control-card stack compact">
                    <header class="row"><h2>Live session</h2><span class="status-pill">{{ selectedSession()?.status || 'unavailable' }}</span></header>
                    <div v-if="selectedSession()" class="link-actions">
                        <button class="secondary" :disabled="busy" @click="copyText(joinUrl(), 'player link')">{{ copiedLink === 'player link' ? 'Copied' : 'Copy player link' }}</button>
                        <button class="secondary" :disabled="busy" @click="copyText(selectedSession()?.player_code || '', 'player code')">{{ copiedLink === 'player code' ? 'Copied' : 'Copy player code' }}</button>
                        <button class="secondary" :disabled="busy" @click="copyText(presentationUrl(), 'presentation link')">{{ copiedLink === 'presentation link' ? 'Copied' : 'Copy presentation link' }}</button>
                        <button v-if="selectedSession()?.display_pairing_token" class="secondary" :disabled="busy" @click="copyText(selectedSession()?.display_pairing_token || '', 'display token')">{{ copiedLink === 'display token' ? 'Copied' : 'Copy display token' }}</button>
                    </div>
                    <div v-if="selectedSession()" class="session-code"><span>Player code</span><strong>{{ selectedSession()?.player_code }}</strong></div>
                    <button class="secondary" @click="back">Campaigns</button>
                </section>
                <section v-if="playerMap" class="control-card stack compact">
                    <h2>Player map</h2>
                    <select :value="playerMap.map_id || ''" aria-label="Current Player map" @change="selectMap"><option value="">Hide Player map</option><option v-for="map in maps" :key="map.id" :value="map.id">{{ map.name }}</option></select>
                    <button class="secondary" :disabled="busy" @click="setMap(null)">Hide map</button>
                    <p v-if="!playerMap.map_id" class="muted">No map is shared.</p>
                </section>
            </aside>
            <section class="control-main stack" aria-label="Live workspace">
                <nav class="control-tabs" aria-label="Live view tabs">
                    <button :class="{ active: activeLiveTab === 'presentation' }" @click="activeLiveTab = 'presentation'">Presentation</button>
                    <button :class="{ active: activeLiveTab === 'map' }" @click="activeLiveTab = 'map'">Map</button>
                </nav>
                <section v-if="activeLiveTab === 'presentation' && presentation" class="control-stage-card presentation-stage-card stack">
                    <header class="control-section-header"><div><h2>Presentation preview</h2><p class="muted">{{ activeScene?.name || 'No active scene' }} · {{ activeEntries.length }} staged</p></div><div class="row"><select v-model="standbySceneId" aria-label="Standby scene"><option value="">Choose scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><button :disabled="busy || !standbySceneId" @click="standby">Standby</button><button :disabled="busy || presentation.state.standby_status !== 'ready'" @click="go">Go</button></div></header>
                    <div class="presentation-preview-frame"><PresentationStage :backdrop-asset-id="presentation.state.backdrop_asset_id" :transition="activeScene?.transition || 'cut'" :transition-duration-ms="activeScene?.transition_duration_ms || 0" :stage-tween-duration-ms="presets.find((preset) => preset.id === presentation.state.stage_preset_id)?.tween_duration_ms || 0" :stage-tween-easing="presets.find((preset) => preset.id === presentation.state.stage_preset_id)?.tween_easing || 'linear'" :entries="activeEntries" :asset-urls="presentationAssetUrls" editable @move-entry="movePresentationEntry" /></div>
                    <section class="presentation-preview-controls control-form-grid" aria-label="Presentation preview controls"><select :value="presentation.state.backdrop_asset_id || ''" aria-label="Scene backdrop" @change="selectBackdrop"><option value="">No backdrop</option><option v-for="backdrop in activeBackdrops" :key="backdrop.id" :value="backdrop.asset_id">{{ backdrop.name }}</option></select><button class="secondary" :disabled="busy || !activeScene" @click="setBackdrop(activeScene.primary_backdrop_asset_id || '')">Use primary backdrop</button><select v-model="stagePresetId" aria-label="Stage preset"><option value="">Empty stage</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select><button :disabled="busy" @click="applyStagePreset">Apply preset</button><button class="secondary" :disabled="busy || !activeScene" @click="resetSceneStage">Reset scene stage</button><button class="danger" :disabled="busy" @click="clearPresentationStage">Clear stage</button></section>
                </section>
                <section v-else-if="activeLiveTab === 'map' && progress && selectedMap()" class="control-stage-card stack">
                    <header class="control-section-header"><div><h2>{{ selectedMap()?.name }}</h2><p class="muted">Revision {{ progress.revision }} · {{ progress.fog.brushes.length }} fog strokes</p></div><button class="danger" :disabled="busy" @click="reset">Reset map</button></header>
                    <ControlMapStage :image-url="imageUrl" :tokens="progress.tokens" :fog="progress.fog" :brush-mode="brushMode" :brush-radius="brushRadius" :interaction-mode="mapInteraction" :disabled="busy" @brush-stroke="brushStroke" @move-tokens="moveTokens" />
                    <div class="control-form-grid"><select v-model="mapInteraction" aria-label="Map editing mode"><option value="tokens">Move tokens</option><option value="fog">Paint fog</option></select><select v-model="brushMode" aria-label="Fog brush mode"><option value="reveal">Reveal fog</option><option value="hide">Hide with fog</option></select><label>X <input v-model.number="brushX" type="number" min="0" max="1" step=".01"></label><label>Y <input v-model.number="brushY" type="number" min="0" max="1" step=".01"></label><label>Radius <input v-model.number="brushRadius" type="number" min=".005" max="1" step=".01"></label><button :disabled="busy" @click="brush">Apply brush</button></div>
                    <details class="token-editor"><summary>Token positions</summary><article v-for="token in progress.tokens" :key="token.source_token_id" class="compact-token"><strong>{{ token.label || token.source_token_id }}</strong><label>X <input v-model.number="token.position_x" type="number" min="0" max="1" step=".01"></label><label>Y <input v-model.number="token.position_y" type="number" min="0" max="1" step=".01"></label><label>Scale <input v-model.number="token.scale" type="number" min=".1" max="5" step=".1"></label></article><button :disabled="busy" @click="saveTokens">Save token positions</button></details>
                </section>
                <section v-else class="control-stage-card empty-state"><h2>{{ selectedSessionId ? 'Nothing to preview' : 'Live session unavailable' }}</h2><p class="muted">Start a fresh live session from Campaigns, then return here to control it.</p></section>
                <section v-if="selectedSessionId" class="control-tools">
                    <nav class="control-tabs" aria-label="Session tool tabs">
                        <button :class="{ active: activeToolTab === 'messages' }" @click="activeToolTab = 'messages'">Messages</button>
                        <button :class="{ active: activeToolTab === 'polls' }" @click="activeToolTab = 'polls'">Polls</button>
                        <button :class="{ active: activeToolTab === 'party' }" @click="activeToolTab = 'party'">Party</button>
                        <button :class="{ active: activeToolTab === 'rolls' }" @click="activeToolTab = 'rolls'">Rolls</button>
                        <button :class="{ active: activeToolTab === 'npcs' }" @click="activeToolTab = 'npcs'">NPCs</button>
                        <button :class="{ active: activeToolTab === 'revision' }" @click="activeToolTab = 'revision'">Revision</button>
                    </nav>
                    <div class="tool-pane">
                        <section v-if="activeToolTab === 'messages'" class="stack"><header class="row"><h2>Messages</h2><span class="status-pill">{{ sessionMessages.length }}</span></header><form class="compact-form" @submit.prevent="sendMessage"><select v-model="messageTargetType" aria-label="Message audience"><option value="all">All participants</option><option value="all_players">All Players</option><option value="all_spectators">All Spectators</option><option value="individual">Individual participant</option><option value="player_group">Named Player group</option></select><select v-if="messageTargetType === 'individual'" v-model="messageParticipantId" aria-label="Individual participant"><option value="">Choose participant</option><option v-for="participant in participants.filter((item) => !item.revoked_at)" :key="participant.id" :value="participant.id">{{ participant.display_name }} · {{ participant.role }}</option></select><select v-if="messageTargetType === 'player_group'" v-model="messageGroupId" aria-label="Named Player group"><option value="">Choose Player group</option><option v-for="group in playerGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select><textarea v-model="messageBody" maxlength="2000" aria-label="Plain-text message" placeholder="Plain-text message"></textarea><button :disabled="busy || !messageBody.trim() || (messageTargetType === 'individual' && !messageParticipantId) || (messageTargetType === 'player_group' && !messageGroupId)">Send</button></form><p v-if="sessionMessages.length === 0" class="muted">No messages yet.</p><article v-for="message in sessionMessages" :key="message.id" class="asset"><div><strong>{{ message.sender_name }}</strong><div>{{ message.body }}</div><div class="muted">{{ message.target_type.replaceAll('_', ' ') }} · {{ new Date(message.created_at).toLocaleTimeString() }}</div></div><button v-if="canPublishSpectatorReply(message)" class="secondary" :disabled="busy" @click="publishSpectatorReply(message)">Publish to Presentation</button></article></section>
                        <section v-if="activeToolTab === 'polls'" class="stack"><header class="row"><h2>Polls</h2><span class="status-pill">{{ polls.length }}</span></header><form class="compact-form" @submit.prevent="createPoll"><input v-model="pollQuestion" maxlength="500" aria-label="Poll question" placeholder="Poll question"><textarea v-model="pollOptions" maxlength="6000" aria-label="Poll options" placeholder="One option per line"></textarea><select v-model="pollAudience" aria-label="Poll audience"><option value="all">All participants</option><option value="all_players">All Players</option><option value="all_spectators">All Spectators</option><option value="individual">Individual participant</option><option value="player_group">Named Player group</option></select><select v-if="pollAudience === 'individual'" v-model="pollParticipantId" aria-label="Poll participant"><option value="">Choose participant</option><option v-for="participant in participants.filter((item) => !item.revoked_at)" :key="participant.id" :value="participant.id">{{ participant.display_name }} · {{ participant.role }}</option></select><select v-if="pollAudience === 'player_group'" v-model="pollGroupId" aria-label="Poll Player group"><option value="">Choose Player group</option><option v-for="group in playerGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select><label class="check-row"><input v-model="pollMultiple" type="checkbox"> Multiple choices</label><button :disabled="busy || !pollQuestion.trim() || pollOptions.split('\\n').filter((option) => option.trim()).length < 2 || (pollAudience === 'individual' && !pollParticipantId) || (pollAudience === 'player_group' && !pollGroupId)">Create poll</button></form><p v-if="polls.length === 0" class="muted">No polls yet.</p><article v-for="poll in polls" :key="poll.id" class="asset"><div><strong>{{ poll.question }}</strong><div class="muted">{{ poll.status }} · results {{ poll.result_visibility }}</div><div v-for="option in poll.options" :key="option.id">{{ option.body }} · {{ option.votes }}</div></div><div class="row"><button v-if="poll.status === 'open'" class="danger" :disabled="busy" @click="pollAction(poll, 'close')">Close</button><button class="secondary" :disabled="busy" @click="pollAction(poll, 'live')">Publish live</button><button v-if="poll.status === 'closed'" class="secondary" :disabled="busy" @click="pollAction(poll, 'final')">Publish final</button></div></article></section>
                        <section v-if="activeToolTab === 'party'" class="stack"><header class="row"><h2>Participants</h2><span class="status-pill">{{ participants.length }}</span></header><p v-if="participants.length === 0" class="muted">No participants have joined this session.</p><article v-for="participant in participants" :key="participant.id" class="asset"><div><strong>{{ participant.display_name }}</strong><div class="muted">{{ participant.role }}{{ participant.player_character_id ? ' · character claimed' : '' }}{{ participant.revoked_at ? ' · revoked' : '' }}</div></div><div class="row"><button v-if="participant.player_character_id && !participant.revoked_at" class="secondary" :disabled="busy" @click="releaseClaim(participant)">Release claim</button><button v-if="!participant.revoked_at" class="danger" :disabled="busy" @click="revokeParticipant(participant)">Revoke</button></div></article><section class="stack compact"><h2>Player groups</h2><div class="row"><input v-model="playerGroupName" maxlength="120" aria-label="Player group name" placeholder="Group name"><button :disabled="busy || !playerGroupName.trim()" @click="createPlayerGroup">Create group</button></div><article v-for="group in playerGroups" :key="group.id" class="asset"><div><strong>{{ group.name }}</strong><div class="muted">{{ group.member_participant_ids.length }} member{{ group.member_participant_ids.length === 1 ? '' : 's' }}</div></div><div class="stack"><label v-for="participant in participants.filter((item) => item.role === 'player' && !item.revoked_at)" :key="participant.id"><input :checked="group.member_participant_ids.includes(participant.id)" type="checkbox" :disabled="busy" @change="setPlayerGroupMember(group, participant, $event)"> {{ participant.display_name }}</label></div></article></section></section>
                        <section v-if="activeToolTab === 'rolls'" class="stack"><header class="row"><h2>Rolls</h2><span class="status-pill">{{ sessionRolls.length }}</span></header><p v-if="sessionRolls.length === 0" class="muted">No rolls yet.</p><article v-for="roll in sessionRolls" :key="roll.id" class="asset"><div><strong>{{ roll.roller_name }} rolled {{ roll.total }}</strong><div class="muted">{{ roll.expression }} · {{ roll.visibility }}{{ roll.dice_preset_name ? ' · ' + roll.dice_preset_name : '' }}</div></div><button v-if="roll.visibility === 'private'" class="secondary" :disabled="busy" @click="revealRoll(roll)">Reveal publicly</button></article></section>
                        <section v-if="activeToolTab === 'npcs'" class="stack"><header class="row"><h2>NPC profiles</h2><span class="status-pill">{{ npcs.length }}</span></header><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name }}</strong><div class="muted">{{ npcIsRevealed(npc.id) ? 'Revealed to participants' : 'Hidden from participants' }}</div></div><button v-if="npcIsRevealed(npc.id)" class="danger" :disabled="busy" @click="setNpcReveal(npc.id, false)">Hide profile</button><button v-else :disabled="busy" @click="setNpcReveal(npc.id, true)">Reveal profile</button></article><section class="stack compact"><h2>Shared notes</h2><p v-if="npcNotes.length === 0" class="muted">No shared NPC notes yet.</p><article v-for="note in npcNotes" :key="note.id" class="asset"><div><strong>{{ npcs.find((npc) => npc.id === note.npc_id)?.name || 'NPC' }}</strong><div>{{ note.body }}</div><div class="muted">{{ note.author_type === 'control' ? 'Control' : participants.find((participant) => participant.id === note.session_participant_id)?.display_name || 'Player' }}</div></div><div class="row"><button class="secondary" :disabled="busy" @click="editNpcNote(note)">Edit</button><button class="danger" :disabled="busy" @click="deleteNpcNote(note)">Delete</button></div></article></section></section>
                        <section v-if="activeToolTab === 'revision'" class="stack"><h2>Adopt published revision</h2><p class="muted">Preflight protects claims, presentation state, maps, fog, and references before switching this live session.</p><select v-model="adoptionRevisionId" aria-label="Revision to adopt" @change="adoptionPreflight = null"><option value="">Choose a published revision</option><option v-for="revision in revisions.filter((item) => item.id !== selectedSession()?.campaign_revision_id)" :key="revision.id" :value="revision.id">Revision {{ revision.number }}</option></select><div class="row"><button class="secondary" :disabled="busy || !adoptionRevisionId" @click="preflightRevisionAdoption">Check compatibility</button><button :disabled="busy || !adoptionPreflight?.compatible" @click="adoptRevision">Adopt revision</button></div><template v-if="adoptionPreflight"><p :class="adoptionPreflight.compatible ? 'muted' : 'error'">{{ adoptionPreflight.compatible ? 'This revision is compatible with the current live state.' : 'This revision cannot preserve the current live state.' }}</p><ul v-if="!adoptionPreflight.compatible"><li v-for="blocker in adoptionPreflight.blockers" :key="blocker.type + ':' + (blocker.reference_id || blocker.player_character_id || blocker.map_id || '')">{{ blocker.type.replaceAll('_', ' ') }}{{ blocker.reference_type ? ' · ' + blocker.reference_type : '' }}</li></ul><article v-for="(change, collection) in adoptionPreflight.changes" :key="collection" v-show="change.added.length || change.removed.length || change.changed.length" class="asset"><strong>{{ collection.replaceAll('_', ' ') }}</strong><div class="muted">{{ changeSummary(change) }}</div></article></template></section>
                    </div>
                </section>
            </section>
            <aside class="control-sidebar stack" aria-label="Presentation controls">
                <section v-if="presentation" class="control-card stack compact"><header class="row"><h2>Scene music</h2><button class="danger" :disabled="busy || !presentation.state.music_cue_id" @click="stopMusic">Stop music</button></header><select :value="presentation.state.music_cue_id || ''" aria-label="Scene music" @change="selectMusic"><option value="">Choose music</option><option v-for="cue in audioCues.filter((cue) => cue.kind === 'music')" :key="cue.id" :value="cue.id">{{ cue.name }}</option></select><template v-if="presentation.state.music_cue_id"><div class="button-grid"><button class="secondary" :disabled="busy" @click="saveMusicPlayback({ status: 'playing' })">Play</button><button class="secondary" :disabled="busy" @click="saveMusicPlayback({ status: 'paused' })">Pause</button><button class="secondary" :disabled="busy" @click="seekMusic(0)">Restart</button></div><label>Seek <input :value="presentation.state.music_playback.position_seconds" type="number" min="0" step=".1" @change="setMusicPosition"></label><label>Volume <input :value="Math.round(presentation.state.music_playback.volume * 100)" type="number" min="0" max="100" @change="setMusicVolume"></label><label>Fade <input :value="presentation.state.music_playback.fade_duration_ms" type="number" min="0" max="30000" step="100" @change="setMusicFade"></label><label class="check-row"><input :checked="presentation.state.music_playback.loop" type="checkbox" @change="setMusicLoop"> Loop</label></template></section>
                <section v-if="presentation" class="control-card stack compact"><header class="row"><h2>SFX</h2><button class="danger" :disabled="busy || !(presentation.state.sfx_instances || []).length" @click="stopAllSfx">Stop all</button></header><label>Master volume <input :value="Math.round((presentation.state.sfx_master_volume ?? 1) * 100)" type="number" min="0" max="100" @change="setSfxMasterVolume"></label><div class="sfx-grid"><button v-for="cue in audioCues.filter((cue) => cue.kind === 'sfx')" :key="cue.id" :disabled="busy" @click="triggerSfx(cue.id)">{{ cue.name }}</button></div><p v-if="audioCues.filter((cue) => cue.kind === 'sfx').length === 0" class="muted">No sound effects pinned.</p><article v-for="instance in presentation.state.sfx_instances || []" :key="instance.id" class="compact-asset"><span>{{ audioCues.find((cue) => cue.id === instance.cue_id)?.name || 'Sound effect' }}</span><button class="danger" :disabled="busy" @click="stopSfx(instance.id)">Stop</button></article></section>
                <section v-if="presentation" class="control-card stack compact"><h2>Video</h2><select :value="presentation.state.video_cue_id || ''" aria-label="Fullscreen video" @change="selectVideo"><option value="">No active video</option><option v-for="cue in videoCues" :key="cue.id" :value="cue.id">{{ cue.name }} · {{ cue.completion_mode }}</option></select><button v-if="presentation.state.video_cue_id" class="danger" :disabled="busy" @click="abortVideo">Abort video</button></section>
                <section v-if="presentation && activeEntries.length" class="control-card stack compact"><h2>Stage expressions</h2><article v-for="entry in activeEntries" :key="'emotion:' + entry.npc_id + ':' + entry.layer_order" class="compact-asset"><span>{{ entry.name }}</span><select :value="entry.npc_state_id || ''" :aria-label="'Live emotion for ' + entry.name" :disabled="busy" @change="setPresentationEntryEmotion(entry, $event)"><option value="">Normal</option><option v-for="state in npcStates.filter((state) => state.npc_id === entry.npc_id)" :key="state.id" :value="state.id">{{ state.name }}</option></select></article></section>
                <section v-if="presentation" class="control-card stack compact"><h2>Add to stage</h2><select v-model="stageNpcId" aria-label="NPC to stage" @change="stageNpcStateId = ''"><option value="">Add an NPC</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><select v-model="stageNpcStateId" aria-label="NPC state"><option value="">Normal appearance</option><option v-for="state in selectableNpcStates" :key="state.id" :value="state.id">{{ state.name }}</option></select><button :disabled="busy || !stageNpcId" @click="addPresentationNpc">Add NPC</button><article v-for="entry in activeEntries" :key="entry.npc_id + ':' + entry.npc_state_id + ':' + entry.layer_order" class="compact-asset"><span>{{ entry.name }} · L{{ entry.layer_order + 1 }}</span><button class="danger" :disabled="busy" @click="removePresentationEntry(entry)">Remove</button></article></section>
            </aside>
        </div>
    </main>`,

});

const AssetsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const assets = ref<Asset[]>([]);
        const file = ref<File | null>(null);
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                assets.value = (await api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`)).data;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = 'Unable to load this asset library.';
            }
        };
        const choose = (event: Event): void => {
            file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
        };
        const kindFor = (mime: string): 'image' | 'audio' | 'video' | null =>
            mime.startsWith('image/') ? 'image' : mime.startsWith('audio/') ? 'audio' : mime.startsWith('video/') ? 'video' : null;
        const upload = async (): Promise<void> => {
            if (!file.value) return;
            const selected = file.value;
            const kind = kindFor(selected.type);
            if (!kind) {
                error.value = 'Choose a supported image, audio, or video file.';
                return;
            }
            busy.value = true;
            error.value = '';
            try {
                const start = await api<ApiResponse<Asset> & { upload: { part_size: number; parts: Array<{ number: number; url: string }> } }>(
                    `/api/control/v1/campaigns/${id}/assets/uploads`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            command_id: commandId(),
                            expected_revision: revision.value,
                            original_filename: selected.name,
                            kind,
                            declared_mime: selected.type,
                            byte_size: selected.size,
                        }),
                    },
                );
                const parts = await Promise.all(
                    start.upload.parts.map(async (part) => {
                        const body = selected.slice((part.number - 1) * start.upload.part_size, Math.min(part.number * start.upload.part_size, selected.size));
                        const response = await fetch(part.url, { method: 'PUT', body });
                        const eTag = response.headers.get('ETag');
                        if (!response.ok || !eTag) throw new Error('A storage upload part failed.');
                        return { number: part.number, e_tag: eTag };
                    }),
                );
                const done = await api<ApiResponse<Asset>>(`/api/control/v1/campaigns/${id}/assets/${start.data.id}/complete`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value + 1, parts }),
                });
                revision.value += 2;
                assets.value = [done.data, ...assets.value.filter((asset) => asset.id !== done.data.id)];
                file.value = null;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to upload this asset.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const open = async (asset: Asset): Promise<void> => {
            try {
                window.open(
                    (await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${id}/assets/${asset.id}/read`)).data.url,
                    '_blank',
                    'noopener',
                );
            } catch {
                error.value = 'This asset is not ready to open.';
            }
        };
        const archive = async (asset: Asset): Promise<void> => {
            if (!window.confirm(`Archive ${asset.original_filename}? Archived media cannot be selected for new content.`)) return;
            busy.value = true;
            error.value = '';
            try {
                const result = await api<ApiResponse<Asset>>(`/api/control/v1/campaigns/${id}/assets/${asset.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: revision.value }),
                });
                revision.value++;
                assets.value = assets.value.map((item) => (item.id === asset.id ? result.data : item));
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive this asset.';
                await load();
            } finally {
                busy.value = false;
            }
        };
        const metadata = (asset: Asset): string => {
            const data = asset.metadata ?? {};
            return asset.kind === 'image' && data.width && data.height
                ? `${data.width} × ${data.height}`
                : asset.kind !== 'image' && data.duration_seconds
                  ? `${data.duration_seconds}s`
                  : '';
        };
        onMounted(load);
        return { assets, file, error, busy, choose, upload, open, archive, metadata, back: () => router.push('/') };
    },
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Asset library</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Upload media</h2><p class="muted">Images, audio, and video upload directly to private storage and are validated before use.</p><input aria-label="Asset file" type="file" accept="image/jpeg,image/png,image/webp,audio/mpeg,audio/wav,audio/ogg,video/mp4,video/webm" @change="choose"><button :disabled="!file || busy" @click="upload">{{ busy ? 'Uploading…' : 'Upload asset' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Private assets</h2><p v-if="assets.length === 0" class="muted">No assets uploaded yet.</p><article v-for="asset in assets" :key="asset.id" class="asset"><div><strong>{{ asset.original_filename }}</strong><div class="muted">{{ asset.kind }} · {{ asset.upload_status }}{{ asset.archived_at ? ' · archived' : '' }}{{ metadata(asset) ? ' · ' + metadata(asset) : '' }}</div><div v-if="asset.validation_error" class="error">{{ asset.validation_error }}</div></div><div class="row"><button v-if="asset.upload_status === 'ready'" class="secondary" @click="open(asset)">Open</button><button v-if="!asset.archived_at && asset.upload_status !== 'initiated'" class="danger" :disabled="busy" @click="archive(asset)">Archive</button></div></article></section></main>`,
});

void [PlayerCharactersView, NpcsView, AudioCuesView, VideoCuesView, ScenesView, StagePresetsView, MapsView, DicePresetsView, AssetsView];

const router = createRouter({
    history: createWebHistory('/control'),
    routes: [
        { path: '/', component: CampaignsView },
        { path: '/passkeys', component: PasskeysView },
        { path: '/campaigns/:campaign', component: CampaignStudioView },
        { path: '/campaigns/:campaign/assets', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'library' } }) },
        { path: '/campaigns/:campaign/pcs', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'cast' } }) },
        { path: '/campaigns/:campaign/npcs', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'cast' } }) },
        { path: '/campaigns/:campaign/audio', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'cues' } }) },
        { path: '/campaigns/:campaign/video', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'cues' } }) },
        { path: '/campaigns/:campaign/presets', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'scenes' } }) },
        { path: '/campaigns/:campaign/scenes', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'scenes' } }) },
        { path: '/campaigns/:campaign/maps', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'maps' } }) },
        { path: '/campaigns/:campaign/dice', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'cues' } }) },
        { path: '/campaigns/:campaign/live/:session', component: SessionsView },
        { path: '/campaigns/:campaign/sessions', component: SessionManagerView },
        { path: '/login', component: LoginView },
    ],
});

createApp({ template: '<RouterView />' }).use(createPinia()).use(router).use(VueKonva).mount('#app');
