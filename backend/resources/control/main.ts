import { computed, createApp, defineComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory, useRoute, useRouter } from 'vue-router';
import { api, apiForm, ApiError, loginWithControlSecret } from '../shared/api';
import { commandId } from '../shared/command-id';
import { Passkeys } from '@laravel/passkeys';
import { useRealtimeSnapshot } from '../shared/realtime';
import { ControlMapStage } from '../shared/control-map-stage';
import { DiceRollVisual } from '../shared/dice-roll-visual';
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
type AudioCue = { id: string; name: string; scene_id: string | null; asset_id: string; kind: 'music' | 'sfx'; loop: boolean; default_volume: number };
type VideoCue = {
    id: string;
    name: string;
    primary_asset_id: string;
    fallback_asset_id: string | null;
    completion_mode: 'restore_captured_scene' | 'enter_target_scene';
    target_scene_id: string | null;
    concurrent_music_cue_id: string | null;
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
    default_video_cue_id: string | null;
    base_stage_preset_id: string | null;
    transition: 'cut' | 'fade_black' | 'cross_dissolve';
    transition_duration_ms: number;
};
type StagePresetRecord = { id: string; scene_id: string | null; name: string; scene_backdrop_id: string | null; tween_duration_ms: number; tween_easing: string };
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
type CampaignRevision = { id: string; number: number; name: string; published_at: string; archived_at: string | null };
type PublishPreflight = { valid: boolean; issues: string[]; summary: Record<string, number> };
type Passkey = { id: string; name: string; last_used_at: string | null; created_at: string };
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
type SessionRollRecord = {
    id: string;
    session_participant_id: string | null;
    roller_name: string;
    dice_preset_name: string | null;
    expression: string;
    visibility: 'public' | 'private';
    total: number;
    breakdown: Record<string, unknown>;
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
    video_music_during?: 'continue' | 'pause' | 'stop' | null;
    stage_preset_id: string | null;
    stage_entries: PresentationStateEntry[];
};
type PresentationSnapshot = {
    revision: number;
    state: PresentationCue & { show_join_qr: boolean; standby: PresentationCue | null; standby_status: 'idle' | 'preparing' | 'ready' | 'error'; standby_error: string | null };
};
type PresentationPreviewMessage =
    | { kind: 'request-draft'; previewId: string }
    | { kind: 'draft'; cue: PresentationCue }
    | { kind: 'preview-heartbeat'; previewId: string }
    | { kind: 'preview-closed'; previewId: string };
type PinnedScene = {
    id: string;
    name: string;
    primary_backdrop_asset_id: string | null;
    default_music_cue_id: string | null;
    default_video_cue_id: string | null;
    base_stage_preset_id: string | null;
    transition: 'cut' | 'fade_black' | 'cross_dissolve';
    transition_duration_ms: number;
};
type PinnedNpc = { id: string; name: string; normal_asset_id: string; native_facing: 'right' };
type ControlNotes = { scenes: Record<string, string | null>; npcs: Record<string, string | null> };
type PinnedNpcState = { id: string; npc_id: string; asset_id: string; name: string };
type PinnedStagePresetEntry = PresentationStateEntry & { stage_preset_id: string };
type PinnedStagePreset = { id: string; scene_id: string | null; name: string; scene_backdrop_id: string | null; tween_duration_ms: number; tween_easing: 'linear' | 'ease_in' | 'ease_out' | 'ease_in_out' };
type PinnedSceneBackdrop = { id: string; scene_id: string; asset_id: string; name: string };
type PinnedAudioCue = { id: string; name: string; kind: 'music' | 'sfx'; loop: boolean; default_volume: number };
type PinnedVideoCue = {
    id: string;
    name: string;
    completion_mode: 'restore_captured_scene' | 'enter_target_scene';
    concurrent_music_cue_id: string | null;
    music_during: 'continue' | 'pause' | 'stop';
    music_after: 'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent';
};

const LoginView = defineComponent({
    setup() {
        const secret = ref('');
        const error = ref('');
        const pending = ref(false);
        const passkeySupported = Passkeys.isSupported();

        const login = async (): Promise<void> => {
            pending.value = true;
            error.value = '';
            try {
                await loginWithControlSecret(secret.value);
                window.location.replace('/control');
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
                window.location.replace('/control');
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
            const name = window.prompt('Name this saved revision', `${campaign.name} revision`);
            if (!name?.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaign.id}/publish`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision, name: name.trim() }),
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
                    body: JSON.stringify({ command_id: commandId(), expected_revision: campaign.draft_revision, name: sessionName.value.trim() }),
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

        const toggleRevisionHistory = (campaign: Campaign, event: Event): void => {
            const details = event.currentTarget as HTMLDetailsElement;
            if (details.open && revisionHistories.value[campaign.id] === undefined) void loadRevisions(campaign);
        };

        const saveRevisionName = async (campaign: Campaign, revision: CampaignRevision): Promise<void> => {
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaign.id}/revisions/${revision.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ command_id: commandId(), name: revision.name }),
                });
                Object.assign(revision, response.data);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to rename this revision.';
                await loadRevisions(campaign);
            } finally {
                busy.value = false;
            }
        };

        const archiveRevision = async (campaign: Campaign, revision: CampaignRevision): Promise<void> => {
            if (!window.confirm(`Archive revision “${revision.name}”? It will no longer be available for new sessions.`)) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<CampaignRevision>>(`/api/control/v1/campaigns/${campaign.id}/revisions/${revision.id}/archive`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                Object.assign(revision, response.data);
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to archive this revision.';
                await loadRevisions(campaign);
            } finally {
                busy.value = false;
            }
        };

        const deleteRevision = async (campaign: Campaign, revision: CampaignRevision): Promise<void> => {
            if (!window.confirm(`Delete revision “${revision.name}” permanently? This cannot be undone.`)) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/control/v1/campaigns/${campaign.id}/revisions/${revision.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId() }),
                });
                revisionHistories.value = {
                    ...revisionHistories.value,
                    [campaign.id]: (revisionHistories.value[campaign.id] ?? []).filter((item) => item.id !== revision.id),
                };
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to delete this revision.';
                await loadRevisions(campaign);
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
                link.hidden = true;
                document.body.append(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
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
            toggleRevisionHistory,
            saveRevisionName,
            archiveRevision,
            deleteRevision,
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
                <article v-for="campaign in campaigns" :key="campaign.id" class="campaign">
                    <header class="campaign-header">
                        <label class="campaign-name"><span class="sr-only">Campaign name</span><input v-model="campaign.name" :aria-label="'Name for ' + campaign.name" maxlength="120"></label>
                        <div class="campaign-primary-actions" aria-label="Campaign actions"><RouterLink class="button primary" :to="'/campaigns/' + campaign.id">Open studio</RouterLink><button :disabled="busy" @click="openLiveSession(campaign)">Start live session</button><RouterLink class="button secondary" :to="'/campaigns/' + campaign.id + '/sessions'">Manage sessions</RouterLink></div>
                    </header>
                    <div class="campaign-utility-actions" aria-label="Campaign utilities"><button class="secondary" :disabled="busy" @click="rename(campaign)">Save name</button><button class="secondary" :disabled="busy" @click="preflight(campaign)">Check publish</button><button class="secondary" :disabled="busy" @click="exportCampaign(campaign)">Export package</button><button class="campaign-archive" @click="archive(campaign)">Archive draft</button></div>
                    <div v-if="publishReports[campaign.id]" class="campaign-feedback"><p :class="publishReports[campaign.id].valid ? 'muted' : 'error'">{{ publishReports[campaign.id].valid ? 'Draft is ready to publish.' : 'Draft needs attention before publishing.' }}</p><ul v-if="!publishReports[campaign.id].valid"><li v-for="issue in publishReports[campaign.id].issues" :key="issue">{{ issue }}</li></ul></div>
                    <details class="revision-history" :aria-busy="busy && revisionHistories[campaign.id] === undefined" @toggle="toggleRevisionHistory(campaign, $event)"><summary><span><span class="eyebrow">Published snapshots</span><strong>Revision history</strong></span><span class="revision-history-meta">{{ revisionHistories[campaign.id]?.length ?? 0 }} revisions</span></summary><div class="revision-history-content"><p v-if="revisionHistories[campaign.id] === undefined" class="muted">Loading published revisions…</p><p v-else-if="revisionHistories[campaign.id].length === 0" class="muted">No published revisions yet.</p><article v-for="revision in revisionHistories[campaign.id]" :key="revision.id" class="revision-card"><div class="revision-card-heading"><label class="grow">Revision {{ revision.number }}<input v-model="revision.name" :aria-label="'Name for revision ' + revision.number" maxlength="120"></label><span class="status-pill">{{ revision.archived_at ? 'archived' : 'active' }}</span></div><div class="muted">Published {{ new Date(revision.published_at).toLocaleString() }}{{ revision.archived_at ? ' · archived ' + new Date(revision.archived_at).toLocaleString() : '' }}</div><div class="revision-card-actions"><button class="secondary" :disabled="busy" @click="saveRevisionName(campaign, revision)">Save name</button><button class="secondary" :disabled="busy" @click="downloadPackage(campaign, revision)">Export package</button><button class="secondary" :disabled="busy || !!revision.archived_at" @click="archiveRevision(campaign, revision)">Archive</button><button class="danger" :disabled="busy || !revision.archived_at" @click="deleteRevision(campaign, revision)">Delete permanently</button></div></article></div></details>
                </article>
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
        const audioCues = ref<AudioCue[]>([]);
        const scenes = ref<SceneRecord[]>([]);
        const name = ref('');
        const primary = ref('');
        const fallback = ref('');
        const completion = ref<'restore_captured_scene' | 'enter_target_scene'>('restore_captured_scene');
        const target = ref('');
        const companionMusic = ref('');
        const during = ref<'continue' | 'pause' | 'stop'>('pause');
        const after = ref<'keep_current' | 'resume_prior' | 'start_target_default' | 'remain_silent'>('resume_prior');
        const volume = ref(100);
        const muted = ref(false);
        const error = ref('');
        const busy = ref(false);
        const load = async (): Promise<void> => {
            try {
                const [videoData, media, sceneData, audioData] = await Promise.all([
                    api<ApiResponse<VideoCue[]>>(`/api/control/v1/campaigns/${id}/video-cues`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                    api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`),
                    api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`),
                ]);
                cues.value = videoData.data;
                videos.value = media.data.filter((item) => item.kind === 'video' && item.upload_status === 'ready');
                scenes.value = sceneData.data;
                audioCues.value = audioData.data;
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
                        concurrent_music_cue_id: companionMusic.value || null,
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
                companionMusic.value = '';
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
            audioCues,
            scenes,
            name,
            primary,
            fallback,
            completion,
            target,
            companionMusic,
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
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Video cues</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add fullscreen video</h2><input v-model="name" maxlength="120" placeholder="Cue name" aria-label="Video cue name"><select v-model="primary" aria-label="Primary video"><option value="">Choose ready video</option><option v-for="asset in videos" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><select v-model="fallback" aria-label="Fallback video"><option value="">No fallback</option><option v-for="asset in videos" :key="asset.id" :value="asset.id">{{ asset.original_filename }}</option></select><select v-model="companionMusic" aria-label="Video companion music"><option value="">No companion track</option><option v-for="cue in audioCues.filter((cue) => cue.kind === 'music' && !cue.scene_id)" :key="cue.id" :value="cue.id">{{ cue.name }}</option></select><select v-model="completion" aria-label="Completion behavior"><option value="restore_captured_scene">Restore captured scene</option><option value="enter_target_scene">Enter target scene</option></select><select v-if="completion === 'enter_target_scene'" v-model="target" aria-label="Target scene"><option value="">Choose target scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><select v-model="during" aria-label="Music during video"><option value="continue">Continue scene music</option><option value="pause">Pause scene music</option><option value="stop">Stop scene music</option></select><select v-model="after" aria-label="Music after video"><option value="keep_current">Keep current music</option><option value="resume_prior">Resume prior music</option><option value="start_target_default">Start target default</option><option value="remain_silent">Remain silent</option></select><label>Embedded audio volume <input v-model.number="volume" type="number" min="0" max="100"></label><label><input v-model="muted" type="checkbox"> Mute embedded video audio</label><button :disabled="busy || !primary || !name.trim() || (completion === 'enter_target_scene' && !target)" @click="create">{{ busy ? 'Creating…' : 'Create video cue' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Draft video cues</h2><p v-if="cues.length === 0" class="muted">No video cues yet.</p><article v-for="cue in cues" :key="cue.id" class="asset"><div><strong>{{ cue.name }}</strong><div class="muted">{{ cue.completion_mode }} · music {{ cue.music_during }}/{{ cue.music_after }} · video audio {{ cue.embedded_audio_muted ? 'muted' : cue.embedded_audio_volume + '%' }}</div></div></article></section></main>`,
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
        const name = ref('');
        const backdrop = ref('');
        const cue = ref('');
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
                const [sceneData, media, audio] = await Promise.all([
                    api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`),
                    api<ApiResponse<Asset[]>>(`/api/control/v1/campaigns/${id}/assets`),
                    api<ApiResponse<AudioCue[]>>(`/api/control/v1/campaigns/${id}/audio-cues`),
                ]);
                scenes.value = sceneData.data;
                images.value = media.data.filter((item) => item.kind === 'image' && item.upload_status === 'ready');
                music.value = audio.data.filter((item) => item.kind === 'music');
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
                        base_stage_preset_id: null,
                        transition: transition.value,
                        transition_duration_ms: duration.value,
                    }),
                });
                scenes.value = [...scenes.value, response.data];
                revision.value++;
                name.value = '';
                backdrop.value = '';
                cue.value = '';
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
            name,
            backdrop,
            cue,
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
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Scenes</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Add scene</h2><input v-model="name" maxlength="120" placeholder="Scene name" aria-label="Scene name"><select v-model="backdrop" aria-label="Primary backdrop"><option value="">No primary backdrop</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><select v-model="cue" aria-label="Default music"><option value="">No default music</option><option v-for="item in music" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="transition" aria-label="Transition"><option value="cut">Cut</option><option value="fade_black">Fade through black</option><option value="cross_dissolve">Cross dissolve</option></select><label>Transition duration (ms) <input v-model.number="duration" type="number" min="0" max="30000"></label><button :disabled="busy" @click="create">{{ busy ? 'Creating…' : 'Create scene' }}</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Alternate backdrops</h2><select v-model="selected" aria-label="Scene for alternate backdrops" @change="loadAlternates"><option value="">Choose scene</option><option v-for="scene in scenes" :key="scene.id" :value="scene.id">{{ scene.name }}</option></select><input v-model="alternateName" maxlength="120" placeholder="Backdrop name" aria-label="Alternate backdrop name"><select v-model="alternateAsset" aria-label="Alternate backdrop image"><option value="">Choose ready image</option><option v-for="item in images" :key="item.id" :value="item.id">{{ item.original_filename }}</option></select><button :disabled="busy || !selected" @click="addAlternate">Add alternate</button><article v-for="item in alternates" :key="item.id" class="asset"><strong>{{ item.name }}</strong></article></section><section class="panel stack"><h2>Draft scenes</h2><p v-if="scenes.length === 0" class="muted">No scenes yet.</p><article v-for="scene in scenes" :key="scene.id" class="asset"><div><strong>{{ scene.name }}</strong><div class="muted">{{ scene.transition }} · {{ scene.transition_duration_ms }}ms</div></div></article></section></main>`,
});

const StagePresetsView = defineComponent({
    setup() {
        const route = useRoute();
        const router = useRouter();
        const id = String(route.params.campaign);
        const revision = ref(Number(route.query.revision ?? 1));
        const presets = ref<StagePresetRecord[]>([]);
        const scenes = ref<SceneRecord[]>([]);
        const npcs = ref<Npc[]>([]);
        const states = ref<StagePresetNpcState[]>([]);
        const entries = ref<StagePresetEntryRecord[]>([]);
        const selected = ref('');
        const scene = ref('');
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
                const [presetData, npcData, sceneData] = await Promise.all([
                    api<ApiResponse<StagePresetRecord[]>>(`/api/control/v1/campaigns/${id}/stage-presets`),
                    api<ApiResponse<Npc[]>>(`/api/control/v1/campaigns/${id}/npcs`),
                    api<ApiResponse<SceneRecord[]>>(`/api/control/v1/campaigns/${id}/scenes`),
                ]);
                presets.value = presetData.data;
                npcs.value = npcData.data;
                scenes.value = sceneData.data;
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
            if (!name.value.trim() || !scene.value) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<StagePresetRecord>>(`/api/control/v1/campaigns/${id}/stage-presets`, {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expected_revision: revision.value,
                        scene_id: scene.value,
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
                scene.value = '';
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
            scenes,
            npcs,
            entries,
            selected,
            scene,
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
    template: `<main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign draft</div><h1>Stage presets</h1></div><button class="secondary" @click="back">Campaigns</button></header><section class="panel stack"><h2>Create stage preset</h2><select v-model="scene" aria-label="Preset scene"><option value="">Choose a scene</option><option v-for="item in scenes" :key="item.id" :value="item.id">{{ item.name }}</option></select><input v-model="name" maxlength="120" placeholder="Preset name" aria-label="Stage preset name"><label>Tween duration (ms) <input v-model.number="tweenDuration" type="number" min="0" max="30000"></label><select v-model="tweenEasing" aria-label="Stage tween easing"><option value="linear">Linear</option><option value="ease_in">Ease in</option><option value="ease_out">Ease out</option><option value="ease_in_out">Ease in and out</option></select><button :disabled="busy || !name.trim() || !scene" @click="create">Create preset</button></section><p v-if="error" class="error" role="alert">{{ error }}</p><section class="panel stack"><h2>Preset staging</h2><select v-model="selected" aria-label="Stage preset" @change="selectPreset"><option value="">Choose a preset</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ scenes.find((item) => item.id === preset.scene_id)?.name || 'Legacy scene' }} · {{ preset.name }} · {{ preset.tween_duration_ms }}ms {{ preset.tween_easing }}</option></select><template v-if="selected"><div class="row"><select v-model="npc" aria-label="NPC to place" @change="state = ''"><option value="">Choose NPC</option><option v-for="item in npcs" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="state" aria-label="NPC state"><option value="">Normal appearance</option><option v-for="item in selectableStates" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="facing" aria-label="NPC facing"><option value="right">Face right</option><option value="left">Face left</option></select></div><div class="row"><label>X (0–1) <input v-model.number="positionX" type="number" min="0" max="1" step=".01"></label><label>Y (0–1) <input v-model.number="positionY" type="number" min="0" max="1" step=".01"></label><label>Scale <input v-model.number="scale" type="number" min=".1" max="5" step=".1"></label><label>Layer <input v-model.number="layerOrder" type="number" min="0" max="65535"></label></div><button :disabled="busy || !npc" @click="addEntry">Add NPC placement</button><p v-if="entries.length === 0" class="muted">No NPC placements in this preset yet.</p><article v-for="entry in entries" :key="entry.id" class="asset"><div><strong>{{ npcs.find((item) => item.id === entry.npc_id)?.name || 'NPC' }}</strong><div class="muted">{{ entry.npc_state_id ? (states.find((item) => item.id === entry.npc_state_id)?.name || 'State') : 'Normal appearance' }} · layer {{ entry.layer_order + 1 }} · {{ Math.round(entry.position_x * 100) }}%, {{ Math.round(entry.position_y * 100) }}% · {{ entry.scale }}× · faces {{ entry.facing }}</div></div></article></template></section><section class="panel stack"><h2>Draft presets</h2><p v-if="presets.length === 0" class="muted">No stage presets yet.</p><article v-for="preset in presets" :key="preset.id" class="asset"><div><strong>{{ preset.name }}</strong><div class="muted">{{ scenes.find((item) => item.id === preset.scene_id)?.name || 'Legacy scene' }} · {{ preset.tween_duration_ms }}ms · {{ preset.tween_easing }}</div></div></article></section></main>`,
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
                    method: 'PATCH',
                    body: JSON.stringify({ command_id: commandId(), name: session.name }),
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
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId() }),
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
                    method: 'DELETE',
                    body: JSON.stringify({ command_id: commandId() }),
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
        return {
            sessions,
            error,
            busy,
            rename,
            archive,
            remove,
            back: () => router.push('/'),
            open: (session: LiveSessionRecord) => router.push(`/campaigns/${campaignId}/live/${session.id}`),
        };
    },
    template: `
        <main class="shell stack"><header class="row"><div><div class="eyebrow">Campaign live sessions</div><h1>Manage sessions</h1><p class="muted">Names are for you; player codes remain the table’s join code.</p></div><button class="secondary" @click="back">Campaigns</button></header>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <section class="panel stack"><p v-if="sessions.length === 0" class="muted">No live sessions yet. Start one from Campaigns when this draft is ready to play.</p><article v-for="session in sessions" :key="session.id" class="asset stack"><div class="row"><label class="grow">Session name<input v-model="session.name" maxlength="120" :aria-label="'Session name for ' + session.player_code"></label><span class="status-pill">{{ session.archived_at ? 'archived' : session.status }}</span></div><div class="muted">Player code: <strong>{{ session.player_code }}</strong> · created {{ new Date(session.created_at).toLocaleString() }}{{ session.archived_at ? ' · archived ' + new Date(session.archived_at).toLocaleString() : '' }}</div><div class="row"><button class="secondary" :disabled="busy" @click="rename(session)">Save name</button><button :disabled="busy || !!session.archived_at" @click="open(session)">Open controls</button><button class="secondary" :disabled="busy || !!session.archived_at" @click="archive(session)">Archive</button><button class="danger" :disabled="busy" @click="remove(session)">Delete permanently</button></div></article></section>
        </main>`,
});

const PresentationPreviewWindowView = defineComponent({
    components: { PresentationStage },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const sessionId = String(route.params.session);
        const draft = ref<PresentationCue | null>(null);
        const scenes = ref<PinnedScene[]>([]);
        const npcs = ref<PinnedNpc[]>([]);
        const npcStates = ref<PinnedNpcState[]>([]);
        const assetUrls = ref<Record<string, string>>({});
        const error = ref('');
        const previewChannel = new BroadcastChannel(`rpgays-presentation-preview:${campaignId}:${sessionId}`);
        const previewId = crypto.randomUUID();
        let previewHeartbeat: number | null = null;
        const resolveEntries = (entries: PresentationStateEntry[]): PresentationStageEntry[] =>
            entries.flatMap((entry) => {
                const npc = npcs.value.find((item) => item.id === entry.npc_id);
                if (!npc) return [];
                const state = entry.npc_state_id ? npcStates.value.find((item) => item.id === entry.npc_state_id) : undefined;

                return [{ ...entry, name: npc.name, asset_id: state?.asset_id ?? npc.normal_asset_id, native_facing: npc.native_facing }];
            });
        const previewEntries = computed(() => (draft.value ? resolveEntries(draft.value.stage_entries) : []));
        const previewScene = computed(() => scenes.value.find((scene) => scene.id === draft.value?.scene_id));
        const loadAssets = async (): Promise<void> => {
            if (!draft.value) return;
            const assetIds = [draft.value.backdrop_asset_id, ...resolveEntries(draft.value.stage_entries).map((entry) => entry.asset_id)]
                .filter((assetId): assetId is string => assetId !== null && assetUrls.value[assetId] === undefined);
            const urls = await Promise.all(
                assetIds.map(async (assetId) => [assetId, (await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${campaignId}/assets/${assetId}/read`)).data.url] as const),
            );
            assetUrls.value = { ...assetUrls.value, ...Object.fromEntries(urls) };
        };
        const applyDraft = (cue: PresentationCue): void => {
            draft.value = { ...cue, music_playback: { ...cue.music_playback }, sfx_instances: [...(cue.sfx_instances ?? [])], stage_entries: cue.stage_entries.map((entry) => ({ ...entry })) };
            void loadAssets();
        };
        const load = async (): Promise<void> => {
            try {
                const sessions = (await api<ApiResponse<LiveSessionRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions`)).data;
                const session = sessions.find((item) => item.id === sessionId);
                if (!session) throw new Error('This live session is unavailable.');
                const [revision, presentation] = await Promise.all([
                    api<ApiResponse<{ manifest: { scenes?: PinnedScene[]; npcs?: PinnedNpc[]; npc_states?: PinnedNpcState[] } }>>(`/api/control/v1/campaigns/${campaignId}/revisions/${session.campaign_revision_id}`),
                    api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${sessionId}/presentation-state`),
                ]);
                scenes.value = revision.data.manifest.scenes ?? [];
                npcs.value = revision.data.manifest.npcs ?? [];
                npcStates.value = revision.data.manifest.npc_states ?? [];
                applyDraft(presentation.data.state);
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load the draft preview.';
            }
        };
        previewChannel.onmessage = (event: MessageEvent<PresentationPreviewMessage>): void => {
            if (event.data.kind === 'draft') applyDraft(event.data.cue);
        };
        const announcePreview = (): void => {
            previewChannel.postMessage({ kind: 'preview-heartbeat', previewId } satisfies PresentationPreviewMessage);
        };
        const closePreview = (): void => {
            previewChannel.postMessage({ kind: 'preview-closed', previewId } satisfies PresentationPreviewMessage);
        };
        onMounted(() => {
            announcePreview();
            previewChannel.postMessage({ kind: 'request-draft', previewId } satisfies PresentationPreviewMessage);
            previewHeartbeat = window.setInterval(announcePreview, 1_000);
            window.addEventListener('pagehide', closePreview);
            void load();
        });
        onBeforeUnmount(() => {
            if (previewHeartbeat !== null) window.clearInterval(previewHeartbeat);
            window.removeEventListener('pagehide', closePreview);
            closePreview();
            previewChannel.close();
        });

        return { assetUrls, error, previewEntries, previewScene, draft };
    },
    template: `<main class="presentation-preview-window"><h1 class="sr-only">Draft preview: {{ previewScene?.name || 'No selected scene' }}</h1><p v-if="error" class="presentation-preview-window-error error" role="alert">{{ error }}</p><section class="presentation-preview-window-output" aria-label="Draft presentation preview"><PresentationStage v-if="draft" :backdrop-asset-id="draft.backdrop_asset_id" :transition="previewScene?.transition || 'cut'" :transition-duration-ms="previewScene?.transition_duration_ms || 0" :entries="previewEntries" :asset-urls="assetUrls" /></section></main>`,
});

const SessionsView = defineComponent({
    components: { ControlMapStage, DiceRollVisual, PresentationStage },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const campaignId = String(route.params.campaign);
        const requestedSessionId = String(route.params.session);
        const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
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
        const sessionRolls = ref<SessionRollRecord[]>([]);
        const privateRollPopover = ref<SessionRollRecord | null>(null);
        const rollExpression = ref('1d20');
        const rollVisibility = ref<'public' | 'private'>('public');
        const messageTargetType = ref<'individual' | 'player_group' | 'all_players' | 'all_spectators' | 'all'>('all');
        const messageParticipantId = ref('');
        const messageGroupId = ref('');
        const messageBody = ref('');
        const npcReveals = ref<SessionNpcRevealRecord[]>([]);
        const npcNotes = ref<SessionNpcNoteRecord[]>([]);
        const selectedSessionId = ref('');
        const playerMap = ref<PlayerMapState | null>(null);
        const progress = ref<MapProgress | null>(null);
        const presentation = ref<PresentationSnapshot | null>(null);
        const presentationDraft = ref<PresentationCue | null>(null);
        const presentationDirty = ref(false);
        const presentationAssetUrls = ref<Record<string, string>>({});
        const controlNotes = ref<ControlNotes>({ scenes: {}, npcs: {} });
        const presentationSceneId = ref('');
        const stagePresetId = ref('');
        const stageNpcId = ref('');
        const stageNpcScale = ref(1);
        const selectedStageEntryKeys = ref<string[]>([]);
        const bulkStageEmotion = ref('');
        const mapInteraction = ref<'tokens' | 'fog'>('tokens');
        const brushMode = ref<'reveal' | 'hide'>('reveal');
        const brushX = ref(0.5);
        const brushY = ref(0.5);
        const brushRadius = ref(0.1);
        const imageUrl = ref('');
        const error = ref('');
        const busy = ref(false);
        const activeLiveTab = ref<'presentation' | 'map'>('presentation');
        const activeToolTab = ref<'messages' | 'party' | 'rolls' | 'npcs'>('messages');
        const toolsCollapsed = ref(false);
        const copiedLink = ref('');
        const showControlPreview = ref(true);
        const showSceneNotes = ref(false);
        const showCharacterNotes = ref(false);
        const selectedCharacterNotesNpcId = ref('');
        const stageScaleOptions = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
        let previewChannel: BroadcastChannel | null = null;
        const selectedSession = (): LiveSessionRecord | undefined => sessions.value.find((session) => session.id === selectedSessionId.value);
        const joinUrl = (): string => `${window.location.origin}/player`;
        const previewUrl = (): string => `${window.location.origin}/control/campaigns/${campaignId}/live/${requestedSessionId}/preview`;
        const issuePresentationPairing = async (): Promise<string | null> => {
            const session = selectedSession();
            if (!session) return null;
            try {
                const response = await api<ApiResponse<LiveSessionRecord & { display_pairing_token: string }>>(
                    `/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-pairing`,
                    { method: 'POST', body: JSON.stringify({ command_id: commandId() }) },
                );
                sessions.value = sessions.value.map((item) => (item.id === session.id ? response.data : item));
                return `${window.location.origin}/presentation?pair=${encodeURIComponent(response.data.display_pairing_token)}`;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to prepare Presentation pairing.';
                return null;
            }
        };
        const copyPresentationLink = async (): Promise<void> => {
            const url = await issuePresentationPairing();
            if (url) await copyText(url, 'presentation link');
        };
        const copyPreviewLink = async (): Promise<void> => {
            await copyText(previewUrl(), 'preview link');
        };
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
        const refreshParticipants = async (): Promise<void> => {
            try {
                await loadParticipants();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to refresh session participants.';
            }
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
        const loadRolls = async (): Promise<SessionRollRecord[]> => {
            const session = selectedSession();
            const rolls = session
                ? (await api<ApiResponse<SessionRollRecord[]>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/rolls`)).data
                : [];
            sessionRolls.value = rolls;

            return rolls;
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
        watch(messageTargetType, (messageAudience) => {
            if (messageAudience === 'individual') void refreshParticipants();
        });
        const selectedMap = (): PinnedMap | undefined => maps.value.find((map) => map.id === playerMap.value?.map_id);
        const resolveEntries = (entries: PresentationStateEntry[]): PresentationStageEntry[] =>
            entries.flatMap((entry) => {
                const npc = npcs.value.find((item) => item.id === entry.npc_id);
                if (!npc) return [];
                const state = entry.npc_state_id ? npcStates.value.find((item) => item.id === entry.npc_state_id) : undefined;
                return [{ ...entry, name: npc.name, asset_id: state?.asset_id ?? npc.normal_asset_id, native_facing: npc.native_facing }];
            });
        const clonePresentationCue = (cue: PresentationCue): PresentationCue => ({
            ...cue,
            music_playback: { ...cue.music_playback },
            sfx_instances: [...(cue.sfx_instances ?? [])],
            stage_entries: cue.stage_entries.map((entry) => ({ ...entry })),
        });
        const broadcastPresentationDraft = (): void => {
            if (presentationDraft.value) previewChannel?.postMessage({ kind: 'draft', cue: clonePresentationCue(presentationDraft.value) } satisfies PresentationPreviewMessage);
        };
        const startPreviewSync = (): void => {
            previewChannel = new BroadcastChannel(`rpgays-presentation-preview:${campaignId}:${requestedSessionId}`);
            previewChannel.onmessage = (event: MessageEvent<PresentationPreviewMessage>): void => {
                if (event.data.kind === 'request-draft') {
                    broadcastPresentationDraft();
                }
            };
        };
        watch(presentationDraft, broadcastPresentationDraft, { deep: true });
        const currentPresentationCue = (): PresentationCue | null => presentationDraft.value;
        const showJoinQr = computed(() => presentation.value?.state.show_join_qr ?? false);
        const activeEntries = computed(() => {
            const cue = currentPresentationCue();

            return cue ? resolveEntries(cue.stage_entries) : [];
        });
        const stageEntryKey = (entry: Pick<PresentationStateEntry, 'npc_id' | 'layer_order'>): string => `${entry.npc_id}:${entry.layer_order}`;
        const selectedStageEntries = computed(() => {
            const selected = new Set(selectedStageEntryKeys.value);

            return activeEntries.value.filter((entry) => selected.has(stageEntryKey(entry)));
        });
        const bulkStageEmotionOptions = computed(() => {
            const selectedNpcIds = new Set(selectedStageEntries.value.map((entry) => entry.npc_id));

            return Array.from(new Set(npcStates.value.filter((state) => selectedNpcIds.has(state.npc_id)).map((state) => state.name)))
                .filter((name) => Array.from(selectedNpcIds).every((npcId) => npcStates.value.some((state) => state.npc_id === npcId && state.name === name)))
                .sort((left, right) => left.localeCompare(right));
        });
        const activeScene = computed(() => scenes.value.find((scene) => scene.id === presentationDraft.value?.scene_id));
        const charactersWithNotes = computed(() => npcs.value.filter((npc) => Boolean(controlNotes.value.npcs[npc.id])));
        const selectedCharacterWithNotes = computed(() => charactersWithNotes.value.find((npc) => npc.id === selectedCharacterNotesNpcId.value) ?? charactersWithNotes.value[0] ?? null);
        const activeScenePresets = computed(() => presets.value.filter((preset) => preset.scene_id === activeScene.value?.id));
        const activeBackdrops = computed(() => {
            const scene = activeScene.value;
            if (!scene) return [];
            const primary = scene.primary_backdrop_asset_id ? [{ id: 'primary', asset_id: scene.primary_backdrop_asset_id, name: 'Primary backdrop' }] : [];
            return [...primary, ...sceneBackdrops.value.filter((backdrop) => backdrop.scene_id === scene.id)];
        });
        const backdropForPreset = (presetId: string | null, fallback: string | null): string | null => {
            const backdropId = presets.value.find((preset) => preset.id === presetId)?.scene_backdrop_id;

            return sceneBackdrops.value.find((backdrop) => backdrop.id === backdropId)?.asset_id ?? fallback;
        };
        const loadPresentationAssets = async (): Promise<void> => {
            const cues = [presentation.value?.state, presentationDraft.value].filter((cue): cue is PresentationCue => cue !== null && cue !== undefined);
            if (cues.length === 0) return;
            const assetIds = cues
                .flatMap((cue) => [cue.backdrop_asset_id, ...resolveEntries(cue.stage_entries).map((entry) => entry.asset_id)])
                .filter((assetId): assetId is string => assetId !== null && presentationAssetUrls.value[assetId] === undefined);
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
                controlNotes.value = { scenes: {}, npcs: {} };
                showSceneNotes.value = false;
                showCharacterNotes.value = false;
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
                        control_notes?: ControlNotes;
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
            controlNotes.value = revision.data.control_notes ?? { scenes: {}, npcs: {} };
            playerMap.value = state.data;
            presentation.value = presentationState.data;
            presentationDraft.value = clonePresentationCue(presentationState.data.state);
            presentationDirty.value = false;
            stagePresetId.value = presentationDraft.value.stage_preset_id ?? '';
            presentationSceneId.value = presentationDraft.value.scene_id ?? '';
            presentationAssetUrls.value = {};
            await loadPresentationAssets();
            const map = selectedMap();
            progress.value = state.data.map_id
                ? (await api<ApiResponse<MapProgress>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/maps/${state.data.map_id}/progress`))
                      .data
                : null;
            imageUrl.value = map
                ? (await api<ApiResponse<{ url: string }>>(`/api/control/v1/campaigns/${campaignId}/assets/${map.image_asset_id}/read`)).data.url
                : '';
        };
        const loadPresentationSnapshot = async (): Promise<PresentationSnapshot> => {
            const session = selectedSession();
            if (!session) throw new Error('No live session is selected.');
            const response = await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state`);
            presentation.value = response.data;
            if (!presentationDirty.value) {
                presentationDraft.value = clonePresentationCue(response.data.state);
                stagePresetId.value = presentationDraft.value.stage_preset_id ?? '';
                presentationSceneId.value = presentationDraft.value.scene_id ?? '';
            }
            await loadPresentationAssets();

            return response.data;
        };
        const presentationRealtime = useRealtimeSnapshot<PresentationSnapshot>({
            load: loadPresentationSnapshot,
            channel: () => {
                const session = selectedSession();

                return session ? `presentation_states.${session.id}` : [];
            },
            revision: (snapshot) => snapshot.revision,
            onRevisionGap: () => void loadPresentationSnapshot(),
        });
        let hasLoadedRollsRealtime = false;
        let knownRollIds = new Set<string>();
        const rollsRealtime = useRealtimeSnapshot<SessionRollRecord[]>({
            load: async () => {
                const rolls = await loadRolls();
                const newPrivateRolls = hasLoadedRollsRealtime
                    ? rolls.filter((roll) => roll.session_participant_id !== null && roll.visibility === 'private' && !knownRollIds.has(roll.id))
                    : [];
                knownRollIds = new Set(rolls.map((roll) => roll.id));
                hasLoadedRollsRealtime = true;
                if (newPrivateRolls.length > 0) privateRollPopover.value = newPrivateRolls.at(-1) ?? null;

                return rolls;
            },
            channel: () => {
                const session = selectedSession();

                return session ? `session_rolls.${session.id}` : [];
            },
        });
        const load = async (): Promise<boolean> => {
            if (!uuidPattern.test(campaignId) || !uuidPattern.test(requestedSessionId)) {
                error.value = 'This live session is unavailable. Start a fresh session from Campaigns.';

                return false;
            }

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

                    return false;
                }
                await loadWorkspace();

                return true;
            } catch (reason) {
                if (reason instanceof ApiError && reason.status === 401) await router.replace('/login');
                else error.value = reason instanceof Error ? reason.message : 'Unable to load live sessions.';

                return false;
            }
        };
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
        const createControlRoll = async (): Promise<void> => {
            const session = selectedSession();
            if (!session || !rollExpression.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<SessionRollRecord>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/rolls`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), expression: rollExpression.value, visibility: rollVisibility.value }),
                });
                sessionRolls.value = [...sessionRolls.value, response.data];
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to roll dice.';
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
                if (privateRollPopover.value?.id === roll.id) privateRollPopover.value = null;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to reveal that roll.';
            } finally {
                busy.value = false;
            }
        };
        const openPrivateRollInHistory = (): void => {
            activeToolTab.value = 'rolls';
            toolsCollapsed.value = false;
            privateRollPopover.value = null;
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
        const loadSelectedScene = (): void => {
            const scene = scenes.value.find((item) => item.id === presentationSceneId.value);
            if (!scene) return;
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
            const videoCue = videoCues.value.find((item) => item.id === scene.default_video_cue_id);
            const companionCue = videoCue?.concurrent_music_cue_id ? audioCues.value.find((item) => item.id === videoCue.concurrent_music_cue_id) : undefined;
            const musicCue = companionCue ?? audioCues.value.find((item) => item.id === scene.default_music_cue_id);
            const musicPlayback = musicCue
                ? {
                      status: 'playing' as const,
                      position_seconds: 0,
                      position_command_id: null,
                      loop: musicCue.loop,
                      volume: musicCue.default_volume / 100,
                      fade_duration_ms: 0,
                }
                : { status: 'stopped' as const, position_seconds: 0, position_command_id: null, loop: true, volume: 1, fade_duration_ms: 0 };
            presentationDraft.value = {
                scene_id: scene.id,
                backdrop_asset_id: backdropForPreset(scene.base_stage_preset_id, scene.primary_backdrop_asset_id),
                music_cue_id: musicCue?.id ?? null,
                music_playback: musicPlayback,
                sfx_master_volume: 1,
                sfx_instances: [],
                video_cue_id: scene.default_video_cue_id,
                video_music_during: musicCue && scene.default_video_cue_id ? 'continue' : null,
                stage_preset_id: scene.base_stage_preset_id,
                stage_entries: stageEntries,
            };
            stagePresetId.value = scene.base_stage_preset_id ?? '';
            selectedStageEntryKeys.value = [];
            presentationDirty.value = true;
            void loadPresentationAssets();
        };
        const selectPresentationScene = (sceneId: string): void => {
            presentationSceneId.value = sceneId;
            loadSelectedScene();
        };
        const openCharacterNotes = (): void => {
            activeLiveTab.value = 'presentation';
            if (!charactersWithNotes.value.some((npc) => npc.id === selectedCharacterNotesNpcId.value)) {
                selectedCharacterNotesNpcId.value = charactersWithNotes.value[0]?.id ?? '';
            }
            showCharacterNotes.value = true;
        };
        const savePresentationEntries = async (
            entries: PresentationStateEntry[],
            presetId = currentPresentationCue()?.stage_preset_id ?? null,
            backdropId = currentPresentationCue()?.backdrop_asset_id ?? null,
            musicCueId = currentPresentationCue()?.music_cue_id ?? null,
            videoCueId = currentPresentationCue()?.video_cue_id ?? null,
            musicPlayback = currentPresentationCue()?.music_playback,
            sfxMasterVolume = currentPresentationCue()?.sfx_master_volume ?? 1,
            sfxInstances = currentPresentationCue()?.sfx_instances ?? [],
            videoMusicDuring = currentPresentationCue()?.video_music_during ?? null,
        ): Promise<void> => {
            const state = currentPresentationCue();
            if (!state) return;
            presentationDraft.value = {
                scene_id: state.scene_id,
                backdrop_asset_id: backdropId,
                music_cue_id: musicCueId,
                music_playback: musicPlayback,
                sfx_master_volume: sfxMasterVolume,
                sfx_instances: sfxInstances,
                video_cue_id: videoCueId,
                video_music_during: videoMusicDuring,
                stage_preset_id: presetId,
                stage_entries: entries,
            };
            stagePresetId.value = presetId ?? '';
            presentationDirty.value = true;
            void loadPresentationAssets();
        };
        const updatePresentation = async (): Promise<void> => {
            const session = selectedSession();
            const state = currentPresentationCue();
            if (!session || !presentation.value || !state) return;
            busy.value = true;
            error.value = '';
            try {
                const updated = (
                    await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state`, {
                        method: 'PUT',
                        body: JSON.stringify({ command_id: commandId(), expected_revision: presentation.value.revision, state }),
                    })
                ).data;
                presentation.value = updated;
                presentationDraft.value = clonePresentationCue(updated.state);
                presentationDirty.value = false;
                stagePresetId.value = presentationDraft.value.stage_preset_id ?? '';
                presentationSceneId.value = presentationDraft.value.scene_id ?? '';
                await loadPresentationAssets();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update the presentation.';
                await loadWorkspace();
            } finally {
                busy.value = false;
            }
        };
        const toggleJoinQr = async (): Promise<void> => {
            const session = selectedSession();
            if (!session || !presentation.value) return;
            busy.value = true;
            error.value = '';
            try {
                presentation.value = (
                    await api<ApiResponse<PresentationSnapshot>>(`/api/control/v1/campaigns/${campaignId}/sessions/${session.id}/presentation-state/join-qr`, {
                        method: 'PATCH',
                        body: JSON.stringify({ command_id: commandId(), expected_revision: presentation.value.revision, show_join_qr: !showJoinQr.value }),
                    })
                ).data;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to update the join QR code.';
                await loadPresentationSnapshot();
            } finally {
                busy.value = false;
            }
        };
        const movePresentationEntry = async (moved: PresentationStageEntry): Promise<void> => {
            const cue = currentPresentationCue();
            if (!cue) return;
            await savePresentationEntries(
                cue.stage_entries.map((entry) =>
                    stageEntryKey(entry) === stageEntryKey(moved) ? { ...entry, position_x: moved.position_x, position_y: moved.position_y } : entry,
                ),
            );
        };
        const applySelectedStageEmotion = async (): Promise<void> => {
            const cue = currentPresentationCue();
            if (!cue || selectedStageEntryKeys.value.length === 0 || !bulkStageEmotion.value) return;
            const selected = new Set(selectedStageEntryKeys.value);
            await savePresentationEntries(
                cue.stage_entries.map((entry) => {
                    if (!selected.has(stageEntryKey(entry))) return entry;
                    if (bulkStageEmotion.value === '__normal') return { ...entry, npc_state_id: null };
                    const state = npcStates.value.find((item) => item.npc_id === entry.npc_id && item.name === bulkStageEmotion.value);

                    return state ? { ...entry, npc_state_id: state.id } : entry;
                }),
            );
            bulkStageEmotion.value = '';
        };
        const addPresentationNpc = async (): Promise<void> => {
            const npc = npcs.value.find((item) => item.id === stageNpcId.value);
            const cue = currentPresentationCue();
            if (!npc || !cue) return;
            const layerOrder = Math.max(-1, ...cue.stage_entries.map((entry) => entry.layer_order)) + 1;
            const scale = stageScaleOptions.includes(stageNpcScale.value) ? stageNpcScale.value : 1;
            await savePresentationEntries([
                ...cue.stage_entries,
                {
                    npc_id: npc.id,
                    npc_state_id: null,
                    position_x: 0.5,
                    position_y: 0.85,
                    scale,
                    layer_order: layerOrder,
                    facing: npc.native_facing,
                },
            ]);
        };
        const removePresentationEntry = async (removed: PresentationStageEntry): Promise<void> => {
            const cue = currentPresentationCue();
            if (!cue) return;
            await savePresentationEntries(cue.stage_entries.filter((entry) => stageEntryKey(entry) !== stageEntryKey(removed)));
            selectedStageEntryKeys.value = selectedStageEntryKeys.value.filter((key) => key !== stageEntryKey(removed));
        };
        const setPresentationEntryFacing = async (entry: PresentationStageEntry, facing: 'left' | 'right'): Promise<void> => {
            const cue = currentPresentationCue();
            if (!cue) return;
            await savePresentationEntries(
                cue.stage_entries.map((item) => (stageEntryKey(item) === stageEntryKey(entry) ? { ...item, facing } : item)),
            );
        };
        const applyStagePreset = async (): Promise<void> => {
            if (!presentationDraft.value) return;
            if (stagePresetId.value && !activeScenePresets.value.some((preset) => preset.id === stagePresetId.value)) {
                error.value = 'Choose a stage preset from the current scene.';
                return;
            }
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
            await savePresentationEntries(entries, stagePresetId.value || null, backdropForPreset(stagePresetId.value || null, currentPresentationCue()?.backdrop_asset_id ?? null));
        };
        const resetSceneStage = async (): Promise<void> => {
            const presetId = activeScene.value?.base_stage_preset_id ?? null;
            stagePresetId.value = presetId ?? '';
            await savePresentationEntries(
                presetId
                    ? presetEntries.value
                          .filter((entry) => entry.stage_preset_id === presetId)
                          .map(({ npc_id, npc_state_id, position_x, position_y, scale, layer_order, facing }) => ({ npc_id, npc_state_id, position_x, position_y, scale, layer_order, facing }))
                    : [],
                presetId,
                backdropForPreset(presetId, activeScene.value?.primary_backdrop_asset_id ?? null),
            );
        };
        const clearPresentationStage = async (): Promise<void> => {
            stagePresetId.value = '';
            await savePresentationEntries([], null);
        };
        const setBackdrop = async (assetId: string): Promise<void> => {
            const cue = currentPresentationCue();
            if (!cue) return;
            await savePresentationEntries(cue.stage_entries, cue.stage_preset_id, assetId || null);
        };
        const selectBackdrop = (event: Event): void => {
            void setBackdrop((event.target as HTMLSelectElement).value);
        };
        const selectMusic = (event: Event): void => {
            const state = currentPresentationCue();
            if (!state) return;
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
            void savePresentationEntries(state.stage_entries, state.stage_preset_id, state.backdrop_asset_id, cue?.id ?? null, state.video_cue_id, playback);
        };
        const stopMusic = (): void => {
            const state = currentPresentationCue();
            if (!state) return;
            void savePresentationEntries(state.stage_entries, state.stage_preset_id, state.backdrop_asset_id, null, state.video_cue_id, {
                ...state.music_playback,
                status: 'stopped',
                position_seconds: 0,
                position_command_id: null,
            });
        };
        const saveMusicPlayback = (next: Partial<MusicPlayback>): void => {
            const state = currentPresentationCue();
            if (!state?.music_cue_id) return;
            void savePresentationEntries(state.stage_entries, state.stage_preset_id, state.backdrop_asset_id, state.music_cue_id, state.video_cue_id, {
                ...state.music_playback,
                ...next,
            });
        };
        const setMusicVolume = (event: Event): void => saveMusicPlayback({ volume: Number((event.target as HTMLInputElement).value) / 100 });
        const seekMusic = (positionSeconds: number): void => saveMusicPlayback({ position_seconds: positionSeconds, position_command_id: commandId() });
        const setMusicPosition = (event: Event): void => seekMusic(Number((event.target as HTMLInputElement).value));
        const setMusicLoop = (event: Event): void => saveMusicPlayback({ loop: (event.target as HTMLInputElement).checked });
        const setMusicFade = (event: Event): void => saveMusicPlayback({ fade_duration_ms: Number((event.target as HTMLInputElement).value) });
        const triggerSfx = (cueId: string): void => {
            const state = currentPresentationCue();
            if (!state) return;
            const cue = audioCues.value.find((item) => item.id === cueId);
            if (!cue) return;
            const instance: SfxInstance = { id: commandId(), cue_id: cue.id, loop: cue.loop, volume: cue.default_volume / 100 };
            void savePresentationEntries(
                state.stage_entries,
                state.stage_preset_id,
                state.backdrop_asset_id,
                state.music_cue_id,
                state.video_cue_id,
                state.music_playback,
                state.sfx_master_volume ?? 1,
                [...(state.sfx_instances ?? []), instance],
            );
        };
        const stopSfx = (instanceId: string): void => {
            const state = currentPresentationCue();
            if (!state) return;
            void savePresentationEntries(
                state.stage_entries,
                state.stage_preset_id,
                state.backdrop_asset_id,
                state.music_cue_id,
                state.video_cue_id,
                state.music_playback,
                state.sfx_master_volume ?? 1,
                (state.sfx_instances ?? []).filter((instance) => instance.id !== instanceId),
            );
        };
        const stopAllSfx = (): void => {
            const state = currentPresentationCue();
            if (!state) return;
            void savePresentationEntries(
                state.stage_entries,
                state.stage_preset_id,
                state.backdrop_asset_id,
                state.music_cue_id,
                state.video_cue_id,
                state.music_playback,
                state.sfx_master_volume ?? 1,
                [],
            );
        };
        const setSfxMasterVolume = (event: Event): void => {
            const state = currentPresentationCue();
            if (!state) return;
            void savePresentationEntries(
                state.stage_entries,
                state.stage_preset_id,
                state.backdrop_asset_id,
                state.music_cue_id,
                state.video_cue_id,
                state.music_playback,
                Number((event.target as HTMLInputElement).value) / 100,
                state.sfx_instances ?? [],
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
            const state = currentPresentationCue();
            const videoCueId = (event.target as HTMLSelectElement).value || null;
            const videoCue = videoCues.value.find((cue) => cue.id === videoCueId);
            const companionCue = videoCue?.concurrent_music_cue_id ? audioCues.value.find((cue) => cue.id === videoCue.concurrent_music_cue_id) : undefined;
            if (state) {
                const musicPlayback = companionCue
                    ? {
                          status: 'playing' as const,
                          position_seconds: 0,
                          position_command_id: null,
                          loop: companionCue.loop,
                          volume: companionCue.default_volume / 100,
                          fade_duration_ms: 0,
                      }
                    : state.music_playback;
                void savePresentationEntries(
                    state.stage_entries,
                    state.stage_preset_id,
                    state.backdrop_asset_id,
                    companionCue?.id ?? state.music_cue_id,
                    videoCueId,
                    musicPlayback,
                    state.sfx_master_volume,
                    state.sfx_instances,
                    companionCue ? 'continue' : state.video_music_during,
                );
            }
        };
        const abortVideo = (): void => {
            const state = currentPresentationCue();
            if (state) void savePresentationEntries(state.stage_entries, state.stage_preset_id, state.backdrop_asset_id, state.music_cue_id, null);
        };
        onMounted(async () => {
            if (!(await load())) return;
            startPreviewSync();
            broadcastPresentationDraft();
            void presentationRealtime.start();
            await loadParticipants();
            await loadPlayerGroups();
            await loadMessages();
            await loadRolls();
            await loadNpcReveals();
            await loadNpcNotes();
            void rollsRealtime.start();
        });
        onBeforeUnmount(() => {
            presentationRealtime.stop();
            rollsRealtime.stop();
            previewChannel?.close();
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
            sessionRolls,
            privateRollPopover,
            rollExpression,
            rollVisibility,
            messageTargetType,
            messageParticipantId,
            messageGroupId,
            messageBody,
            npcNotes,
            selectedSessionId,
            playerMap,
            progress,
            presentation,
            presentationDraft,
            presentationAssetUrls,
            currentPresentationCue,
            activeEntries,
            activeScene,
            activeScenePresets,
            activeBackdrops,
            presentationSceneId,
            presentationDirty,
            stagePresetId,
            stageNpcId,
            stageNpcScale,
            selectedStageEntryKeys,
            bulkStageEmotion,
            stageScaleOptions,
            selectedStageEntries,
            bulkStageEmotionOptions,
            stageEntryKey,
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
            toolsCollapsed,
            copiedLink,
            showControlPreview,
            controlNotes,
            showSceneNotes,
            showCharacterNotes,
            charactersWithNotes,
            selectedCharacterNotesNpcId,
            selectedCharacterWithNotes,
            selectedSession,
            joinUrl,
            copyText,
            copyPreviewLink,
            copyPresentationLink,
            selectedMap,
            loadWorkspace,
            loadParticipants,
            refreshParticipants,
            loadPlayerGroups,
            loadMessages,
            loadRolls,
            loadNpcReveals,
            loadNpcNotes,
            createPlayerGroup,
            setPlayerGroupMember,
            sendMessage,
            canPublishSpectatorReply,
            publishSpectatorReply,
            createControlRoll,
            revealRoll,
            openPrivateRollInHistory,
            setMap,
            selectMap,
            brush,
            brushStroke,
            reset,
            saveTokens,
            moveTokens,
            loadSelectedScene,
            selectPresentationScene,
            openCharacterNotes,
            updatePresentation,
            toggleJoinQr,
            movePresentationEntry,
            applySelectedStageEmotion,
            addPresentationNpc,
            removePresentationEntry,
            setPresentationEntryFacing,
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
        <p v-if="error" class="error control-error" role="alert">{{ error }}</p>
        <div class="control-grid">
            <section class="control-main stack" :class="{ 'tools-collapsed': toolsCollapsed }" aria-label="Live workspace">
                <nav class="control-tabs" aria-label="Live view tabs">
                    <button :class="{ active: activeLiveTab === 'presentation' }" @click="activeLiveTab = 'presentation'">Presentation</button>
                    <button :class="{ active: activeLiveTab === 'map' }" @click="activeLiveTab = 'map'">Map</button>
                </nav>
                <section v-if="activeLiveTab === 'presentation' && presentation" class="control-stage-card presentation-stage-card stack">
                    <header class="control-section-header"><div><h2>Presentation</h2><p class="muted">Choose a scene, then show or hide this Control-only preview.</p></div><button class="secondary" :aria-pressed="showControlPreview" @click="showControlPreview = !showControlPreview">{{ showControlPreview ? 'Hide preview' : 'Show preview' }}</button></header>
                    <nav class="presentation-scene-picker" aria-label="Presentation scene"><button v-for="scene in scenes" :key="scene.id" type="button" :class="{ active: presentationSceneId === scene.id }" :aria-pressed="presentationSceneId === scene.id" :disabled="busy" @click="selectPresentationScene(scene.id)"><strong>{{ scene.name }}</strong><small>{{ scene.transition.replaceAll('_', ' ') }}</small></button><p v-if="scenes.length === 0" class="muted">No scenes are pinned to this live session.</p></nav>
                    <div v-if="showSceneNotes" class="modal-backdrop" role="presentation" @click.self="showSceneNotes = false"><section class="modal-panel control-notes-modal stack" role="dialog" aria-modal="true" aria-labelledby="scene-notes-heading"><header class="row"><div><div class="eyebrow">Private to Control</div><h2 id="scene-notes-heading">Scene notes</h2></div><button class="secondary" @click="showSceneNotes = false">Close</button></header><p class="muted">Never shown to participants or the live display.</p><article v-if="activeScene" class="control-notes-entry"><h3>{{ activeScene.name }}</h3><p v-if="controlNotes.scenes[activeScene.id]">{{ controlNotes.scenes[activeScene.id] }}</p><p v-else class="muted">No private notes for this scene.</p></article><p v-else class="muted">Choose a scene to view its private notes.</p></section></div>
                    <div v-if="showCharacterNotes" class="modal-backdrop" role="presentation" @click.self="showCharacterNotes = false"><section class="modal-panel control-notes-modal stack" role="dialog" aria-modal="true" aria-labelledby="character-notes-heading"><header class="row"><div><div class="eyebrow">Private to Control</div><h2 id="character-notes-heading">Character notes</h2></div><button class="secondary" @click="showCharacterNotes = false">Close</button></header><p class="muted">Never shown to participants or the live display.</p><template v-if="charactersWithNotes.length"><nav class="character-note-tabs" role="tablist" aria-label="Characters with notes"><button v-for="npc in charactersWithNotes" :id="'character-note-tab-' + npc.id" :key="npc.id" class="secondary" type="button" role="tab" :class="{ active: selectedCharacterWithNotes?.id === npc.id }" :aria-selected="selectedCharacterWithNotes?.id === npc.id" @click="selectedCharacterNotesNpcId = npc.id">{{ npc.name }}</button></nav><article v-if="selectedCharacterWithNotes" class="control-notes-entry" role="tabpanel" :aria-labelledby="'character-note-tab-' + selectedCharacterWithNotes.id"><h3>{{ selectedCharacterWithNotes.name }}</h3><p>{{ controlNotes.npcs[selectedCharacterWithNotes.id] }}</p></article></template><p v-else class="muted">No private notes for the characters in this session.</p></section></div>
                    <div v-if="showControlPreview" class="presentation-preview-layout next-only">
                        <section class="presentation-preview-panel"><h3>Preview</h3><div class="presentation-preview-frame"><PresentationStage :backdrop-asset-id="currentPresentationCue()?.backdrop_asset_id || null" :transition="activeScene?.transition || 'cut'" :transition-duration-ms="activeScene?.transition_duration_ms || 0" :stage-tween-duration-ms="presets.find((preset) => preset.id === currentPresentationCue()?.stage_preset_id)?.tween_duration_ms || 0" :stage-tween-easing="presets.find((preset) => preset.id === currentPresentationCue()?.stage_preset_id)?.tween_easing || 'linear'" :entries="activeEntries" :asset-urls="presentationAssetUrls" :editable="true" @move-entry="movePresentationEntry" /></div></section>
                    </div>
                </section>
                <section v-else-if="activeLiveTab === 'map' && progress && selectedMap()" class="control-stage-card stack">
                    <header class="control-section-header"><div><h2>{{ selectedMap()?.name }}</h2><p class="muted">Revision {{ progress.revision }} · {{ progress.fog.brushes.length }} fog strokes</p></div><button class="danger" :disabled="busy" @click="reset">Reset map</button></header>
                    <ControlMapStage :image-url="imageUrl" :tokens="progress.tokens" :fog="progress.fog" :brush-mode="brushMode" :brush-radius="brushRadius" :interaction-mode="mapInteraction" :disabled="busy" @brush-stroke="brushStroke" @move-tokens="moveTokens" />
                    <div class="control-form-grid"><select v-model="mapInteraction" aria-label="Map editing mode"><option value="tokens">Move tokens</option><option value="fog">Paint fog</option></select><select v-model="brushMode" aria-label="Fog brush mode"><option value="reveal">Reveal fog</option><option value="hide">Hide with fog</option></select><label>X <input v-model.number="brushX" type="number" min="0" max="1" step=".01"></label><label>Y <input v-model.number="brushY" type="number" min="0" max="1" step=".01"></label><label>Radius <input v-model.number="brushRadius" type="number" min=".005" max="1" step=".01"></label><button :disabled="busy" @click="brush">Apply brush</button></div>
                    <details class="token-editor"><summary>Token positions</summary><article v-for="token in progress.tokens" :key="token.source_token_id" class="compact-token"><strong>{{ token.label || token.source_token_id }}</strong><label>X <input v-model.number="token.position_x" type="number" min="0" max="1" step=".01"></label><label>Y <input v-model.number="token.position_y" type="number" min="0" max="1" step=".01"></label><label>Scale <input v-model.number="token.scale" type="number" min=".1" max="5" step=".1"></label></article><button :disabled="busy" @click="saveTokens">Save tokens</button></details>
                </section>
                <section v-else class="control-stage-card empty-state"><h2>{{ selectedSessionId ? 'Nothing to preview' : 'Live session unavailable' }}</h2><p class="muted">Start a fresh live session from Campaigns, then return here to control it.</p></section>
                <section v-if="selectedSessionId" class="control-tools" :class="{ collapsed: toolsCollapsed }">
                    <header class="control-tools-header">
                        <div><h2>Session tools</h2><p class="muted">{{ activeToolTab.replaceAll('_', ' ') }}</p></div>
                        <button class="icon-button chevron-button" :class="{ expanded: !toolsCollapsed }" type="button" :aria-label="toolsCollapsed ? 'Expand session tools' : 'Collapse session tools'" :title="toolsCollapsed ? 'Expand session tools' : 'Collapse session tools'" :aria-expanded="!toolsCollapsed" @click="toolsCollapsed = !toolsCollapsed"></button>
                    </header>
                    <nav v-if="!toolsCollapsed" class="control-tabs" aria-label="Session tool tabs">
                        <button :class="{ active: activeToolTab === 'messages' }" @click="activeToolTab = 'messages'">Messages</button>
                        <button :class="{ active: activeToolTab === 'party' }" @click="activeToolTab = 'party'">Party</button>
                        <button :class="{ active: activeToolTab === 'rolls' }" @click="activeToolTab = 'rolls'">Rolls</button>
                        <button :class="{ active: activeToolTab === 'npcs' }" @click="activeToolTab = 'npcs'">NPCs</button>
                    </nav>
                    <div v-if="!toolsCollapsed" class="tool-pane">
                        <section v-if="activeToolTab === 'messages'" class="stack"><header class="row"><h2>Messages</h2><span class="status-pill">{{ sessionMessages.length }}</span></header><form class="compact-form" @submit.prevent="sendMessage"><select v-model="messageTargetType" aria-label="Message audience"><option value="all">All participants</option><option value="all_players">All Players</option><option value="all_spectators">All Spectators</option><option value="individual">Individual participant</option><option value="player_group">Named Player group</option></select><div v-if="messageTargetType === 'individual'" class="row"><select v-model="messageParticipantId" aria-label="Individual participant"><option value="">Choose participant</option><option v-if="participants.filter((item) => !item.revoked_at).length === 0" value="" disabled>No active participants yet</option><option v-for="participant in participants.filter((item) => !item.revoked_at)" :key="participant.id" :value="participant.id">{{ participant.display_name }} · {{ participant.role }}</option></select><button class="secondary" type="button" :disabled="busy" @click="refreshParticipants">Refresh</button></div><select v-if="messageTargetType === 'player_group'" v-model="messageGroupId" aria-label="Named Player group"><option value="">Choose Player group</option><option v-for="group in playerGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select><textarea v-model="messageBody" maxlength="2000" aria-label="Plain-text message" placeholder="Plain-text message"></textarea><button :disabled="busy || !messageBody.trim() || (messageTargetType === 'individual' && !messageParticipantId) || (messageTargetType === 'player_group' && !messageGroupId)">Send</button></form><p v-if="sessionMessages.length === 0" class="muted">No messages yet.</p><article v-for="message in sessionMessages" :key="message.id" class="asset"><div><strong>{{ message.sender_name }}</strong><div>{{ message.body }}</div><div class="muted">{{ message.target_type.replaceAll('_', ' ') }} · {{ new Date(message.created_at).toLocaleTimeString() }}</div></div><button v-if="canPublishSpectatorReply(message)" class="secondary" :disabled="busy" @click="publishSpectatorReply(message)">Publish</button></article></section>
                        <section v-if="activeToolTab === 'party'" class="stack"><header class="row"><h2>Participants</h2><span class="status-pill">{{ participants.length }}</span></header><p v-if="participants.length === 0" class="muted">No participants have joined this session.</p><article v-for="participant in participants" :key="participant.id" class="asset"><div><strong>{{ participant.display_name }}</strong><div class="muted">{{ participant.role }}{{ participant.player_character_id ? ' · character claimed' : '' }}{{ participant.revoked_at ? ' · revoked' : '' }}</div></div><div class="row"><button v-if="participant.player_character_id && !participant.revoked_at" class="secondary" :disabled="busy" @click="releaseClaim(participant)">Release</button><button v-if="!participant.revoked_at" class="danger" :disabled="busy" @click="revokeParticipant(participant)">Revoke</button></div></article><section class="stack compact"><h2>Player groups</h2><div class="row"><input v-model="playerGroupName" maxlength="120" aria-label="Player group name" placeholder="Group name"><button :disabled="busy || !playerGroupName.trim()" @click="createPlayerGroup">Create</button></div><article v-for="group in playerGroups" :key="group.id" class="asset"><div><strong>{{ group.name }}</strong><div class="muted">{{ group.member_participant_ids.length }} member{{ group.member_participant_ids.length === 1 ? '' : 's' }}</div></div><div class="stack"><label v-for="participant in participants.filter((item) => item.role === 'player' && !item.revoked_at)" :key="participant.id"><input :checked="group.member_participant_ids.includes(participant.id)" type="checkbox" :disabled="busy" @change="setPlayerGroupMember(group, participant, $event)"> {{ participant.display_name }}</label></div></article></section></section>
                        <section v-if="activeToolTab === 'rolls'" class="stack"><header class="row"><h2>Rolls</h2><span class="status-pill">{{ sessionRolls.length }}</span></header><form class="compact-form" @submit.prevent="createControlRoll"><input v-model="rollExpression" maxlength="200" aria-label="Dice expression" placeholder="e.g. 1d20+5" required><select v-model="rollVisibility" aria-label="Roll visibility"><option value="public">Public</option><option value="private">Private</option></select><button :disabled="busy || !rollExpression.trim()">Roll</button></form><p class="muted">Public rolls appear for participants and on the presentation. Private rolls stay in Control until revealed.</p><p v-if="sessionRolls.length === 0" class="muted">No rolls yet.</p><article v-for="roll in sessionRolls" :key="roll.id" class="asset"><div><strong>{{ roll.roller_name }}</strong><div class="muted">{{ roll.expression }} · {{ roll.visibility }}{{ roll.dice_preset_name ? ' · ' + roll.dice_preset_name : '' }}</div><DiceRollVisual :key="roll.id" :breakdown="roll.breakdown" :total="roll.total" :label="roll.roller_name + ' roll'" /></div><button v-if="roll.visibility === 'private'" class="secondary" :disabled="busy" @click="revealRoll(roll)">Reveal</button></article></section>
                        <section v-if="activeToolTab === 'npcs'" class="stack"><header class="row"><h2>NPC profiles</h2><span class="status-pill">{{ npcs.length }}</span></header><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name }}</strong><div class="muted">{{ npcIsRevealed(npc.id) ? 'Revealed to participants' : 'Hidden from participants' }}</div></div><button v-if="npcIsRevealed(npc.id)" class="danger" :disabled="busy" @click="setNpcReveal(npc.id, false)">Hide</button><button v-else :disabled="busy" @click="setNpcReveal(npc.id, true)">Reveal</button></article><section class="stack compact"><h2>Shared notes</h2><p v-if="npcNotes.length === 0" class="muted">No shared NPC notes yet.</p><article v-for="note in npcNotes" :key="note.id" class="asset"><div><strong>{{ npcs.find((npc) => npc.id === note.npc_id)?.name || 'NPC' }}</strong><div>{{ note.body }}</div><div class="muted">{{ note.author_type === 'control' ? 'Control' : participants.find((participant) => participant.id === note.session_participant_id)?.display_name || 'Player' }}</div></div><div class="row"><button class="secondary" :disabled="busy" @click="editNpcNote(note)">Edit</button><button class="danger" :disabled="busy" @click="deleteNpcNote(note)">Delete</button></div></article></section></section>
                    </div>
                </section>
            </section>
            <aside class="control-sidebar stack" aria-label="Live controls">
                <section v-if="presentation" class="control-card stack compact"><h2>Stage backdrop + preset</h2><select :value="currentPresentationCue()?.backdrop_asset_id || ''" aria-label="Scene backdrop" @change="selectBackdrop"><option value="">No backdrop</option><option v-for="backdrop in activeBackdrops" :key="backdrop.id" :value="backdrop.asset_id">{{ backdrop.name }}</option></select><button class="secondary" :disabled="busy || !activeScene" @click="setBackdrop(activeScene.primary_backdrop_asset_id || '')">Use primary backdrop</button><select v-model="stagePresetId" aria-label="Stage preset" :disabled="busy || !activeScene"><option value="">Empty stage</option><option v-for="preset in activeScenePresets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select><div class="button-grid"><button :disabled="busy || !activeScene" @click="applyStagePreset">Apply preset</button><button class="secondary" :disabled="busy || !activeScene" @click="resetSceneStage">Reset scene</button><button class="danger" :disabled="busy" @click="clearPresentationStage">Clear stage</button></div></section>
                <section v-if="presentation" class="control-card stack compact emotion-control"><header class="emotion-control-header"><div><h2>Character emotions</h2><p v-if="activeEntries.length" class="muted">Select characters, then change their shared emotion.</p></div><span v-if="activeEntries.length" class="status-pill">{{ selectedStageEntries.length }} selected</span></header><template v-if="activeEntries.length"><div class="emotion-action-row"><select v-model="bulkStageEmotion" aria-label="Selected character emotion" :disabled="busy || selectedStageEntries.length === 0"><option value="">Choose emotion</option><option value="__normal">Normal</option><option v-for="name in bulkStageEmotionOptions" :key="name" :value="name">{{ name }}</option></select><button class="secondary" :disabled="busy || selectedStageEntries.length === 0 || !bulkStageEmotion" @click="applySelectedStageEmotion">Apply</button></div><div class="emotion-target-list"><label v-for="entry in activeEntries" :key="'emotion:' + stageEntryKey(entry)" class="emotion-target" :class="{ selected: selectedStageEntryKeys.includes(stageEntryKey(entry)) }"><input v-model="selectedStageEntryKeys" type="checkbox" :value="stageEntryKey(entry)" :disabled="busy"><span>{{ entry.name }}</span><small>{{ entry.npc_state_id ? (npcStates.find((state) => state.id === entry.npc_state_id)?.name || 'Emotion') : 'Normal' }}</small></label></div></template><p v-else class="muted">Stage a character to control its emotion.</p></section>
                <section v-if="presentation" class="control-card stack compact"><h2>Stage composition</h2><select v-model="stageNpcId" aria-label="NPC to stage" :disabled="busy"><option value="">Choose a character</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name }}</option></select><select v-model="stageNpcScale" aria-label="NPC session size" :disabled="busy"><option v-for="scale in stageScaleOptions" :key="scale" :value="scale">{{ scale }}x</option></select><button :disabled="busy || !stageNpcId" @click="addPresentationNpc">Stage character</button><template v-if="activeEntries.length"><article v-for="entry in activeEntries" :key="'placement:' + stageEntryKey(entry)" class="compact-asset"><span>{{ entry.name }} · {{ entry.scale }}x · L{{ entry.layer_order + 1 }}</span><div class="row"><button class="secondary" :aria-pressed="entry.facing === 'left'" :disabled="busy" @click="setPresentationEntryFacing(entry, 'left')">Face left</button><button class="secondary" :aria-pressed="entry.facing !== 'left'" :disabled="busy" @click="setPresentationEntryFacing(entry, 'right')">Face right</button><button class="danger" :disabled="busy" @click="removePresentationEntry(entry)">Remove</button></div></article></template></section>
                <section v-if="playerMap" class="control-card stack compact">
                    <h2>Player map</h2>
                    <select :value="playerMap.map_id || ''" aria-label="Current Player map" @change="selectMap"><option value="">Hide Player map</option><option v-for="map in maps" :key="map.id" :value="map.id">{{ map.name }}</option></select>
                    <button class="secondary" :disabled="busy" @click="setMap(null)">Hide map</button>
                    <p v-if="!playerMap.map_id" class="muted">No map is shared.</p>
                </section>
                <section class="control-card stack compact">
                    <header class="row"><h2>Live session</h2><span class="status-pill">{{ selectedSession()?.status || 'unavailable' }}</span></header>
                    <div v-if="selectedSession()" class="link-actions">
                        <button class="secondary" :disabled="busy" @click="copyText(joinUrl(), 'player link')">{{ copiedLink === 'player link' ? 'Copied' : 'Players' }}</button>
                        <button class="secondary" :disabled="busy" @click="copyText(selectedSession()?.player_code || '', 'player code')">{{ copiedLink === 'player code' ? 'Copied' : 'Code' }}</button>
                        <button class="secondary" :disabled="busy" @click="copyPreviewLink">{{ copiedLink === 'preview link' ? 'Copied' : 'Preview' }}</button>
                        <button class="secondary" :disabled="busy" @click="copyPresentationLink">{{ copiedLink === 'presentation link' ? 'Copied' : 'Live display' }}</button>
                    </div>
                    <div v-if="selectedSession()" class="session-code"><span>Player code</span><strong>{{ selectedSession()?.player_code }}</strong></div>
                    <div v-if="presentation" class="button-grid"><button class="secondary" @click="activeLiveTab = 'presentation'; showSceneNotes = true">Scene notes</button><button class="secondary" @click="openCharacterNotes">Character notes</button><button class="secondary" :aria-pressed="showJoinQr" :disabled="busy" @click="toggleJoinQr">{{ showJoinQr ? 'Hide join QR' : 'Show join QR' }}</button><button :disabled="busy || !presentationDirty" @click="updatePresentation">{{ busy ? 'Updating…' : 'Update presentation' }}</button></div>
                    <button class="secondary" @click="back">Campaigns</button>
                </section>
            </aside>
        </div>
        <section v-if="privateRollPopover" class="private-roll-popover stack" role="dialog" aria-modal="false" aria-labelledby="private-roll-title">
            <header class="row"><div><div class="eyebrow">Private player roll</div><h2 id="private-roll-title">{{ privateRollPopover.roller_name }} rolled {{ privateRollPopover.expression }}</h2></div><button class="secondary" aria-label="Dismiss private roll" @click="privateRollPopover = null">Close</button></header>
            <DiceRollVisual :key="privateRollPopover.id" :breakdown="privateRollPopover.breakdown" :total="privateRollPopover.total" :label="privateRollPopover.roller_name + ' private roll'" />
            <div class="row"><button class="secondary" @click="openPrivateRollInHistory">Open rolls</button><button :disabled="busy" @click="revealRoll(privateRollPopover)">Reveal</button></div>
        </section>
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
        { path: '/campaigns/:campaign/audio', component: AudioCuesView },
        { path: '/campaigns/:campaign/video', component: VideoCuesView },
        { path: '/campaigns/:campaign/presets', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'scenes' } }) },
        { path: '/campaigns/:campaign/scenes', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'scenes' } }) },
        { path: '/campaigns/:campaign/maps', redirect: (to) => ({ path: `/campaigns/${to.params.campaign}`, query: { section: 'maps' } }) },
        { path: '/campaigns/:campaign/dice', component: DicePresetsView },
        { path: '/campaigns/:campaign/live/:session/preview', component: PresentationPreviewWindowView },
        { path: '/campaigns/:campaign/live/:session', component: SessionsView },
        { path: '/campaigns/:campaign/sessions', component: SessionManagerView },
        { path: '/login', component: LoginView },
    ],
});

createApp({ template: '<RouterView />' }).use(createPinia()).use(router).use(VueKonva).mount('#app');
