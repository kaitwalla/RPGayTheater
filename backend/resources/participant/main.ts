import { computed, createApp, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch, type PropType } from 'vue';
import { api, ApiError } from '../shared/api';
import { commandId } from '../shared/command-id';
import { DiceRollVisual } from '../shared/dice-roll-visual';
import { useRealtimeSnapshot } from '../shared/realtime';
import { registerParticipantServiceWorker } from './pwa';
import '../css/app.css';

type ApiResponse<T> = { data: T };
type Participant = { id: string; role: 'player' | 'spectator'; display_name: string; resume_token?: string };
type RosterCharacter = {
    id: string;
    name: string | null;
    pronouns: string | null;
    public_description: string | null;
    claimed: boolean;
    claimed_by_me: boolean;
};
type Roster = { role: 'player' | 'spectator'; characters: RosterCharacter[] };
type PlayerGroup = { id: string; name: string };
type SessionMessage = {
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
type SessionRoll = {
    id: string;
    roller_name: string;
    dice_preset_id: string | null;
    dice_preset_name: string | null;
    expression: string;
    visibility: 'public' | 'private';
    total: number;
    breakdown: Record<string, unknown>;
    revealed_at: string | null;
    created_at: string;
};
type DicePreset = { id: string; name: string; expression: string; default_visibility: 'public' | 'private'; is_default: boolean };
type NpcNote = { id: string; body: string; author_name: string; session_participant_id: string | null; created_at: string };
type RevealedNpc = {
    id: string;
    name: string | null;
    pronouns: string | null;
    public_description: string | null;
    revealed_at: string | null;
    notes: NpcNote[];
};
type FogBrush = { id: string; mode: 'reveal' | 'hide'; center_x: number; center_y: number; radius: number };
type Token = { source_token_id: string; label: string | null; position_x: number; position_y: number; scale: number };
type CurrentMap = {
    state: { live_session_id: string; map_id: string | null; revision: number };
    map: { id: string; name: string; image_asset_id: string } | null;
    progress: { revision: number; fog: { default_visibility: 'hidden' | 'revealed'; brushes: FogBrush[] }; tokens: Token[] } | null;
};
type ParticipantSection = 'map' | 'character' | 'chat' | 'rolls' | 'info';
type ParticipantToast = { id: number; title: string; body: string };

export const FogMap = defineComponent({
    props: { snapshot: { type: Object as PropType<CurrentMap>, required: true }, imageUrl: { type: String, default: '' } },
    setup(props) {
        const canvas = ref<HTMLCanvasElement | null>(null);
        const viewport = ref<HTMLElement | null>(null);
        const zoom = ref(1);
        const translateX = ref(0);
        const translateY = ref(0);
        const pointers = new Map<number, { x: number; y: number }>();
        let panStart: { pointerId: number; x: number; y: number; translateX: number; translateY: number } | null = null;
        let pinchStart: { distance: number; midpointX: number; midpointY: number; zoom: number; translateX: number; translateY: number } | null = null;
        const point = (event: PointerEvent): { x: number; y: number } => {
            const bounds = viewport.value?.getBoundingClientRect();

            return { x: event.clientX - (bounds?.left ?? 0), y: event.clientY - (bounds?.top ?? 0) };
        };
        const pinchPoints = (): [{ x: number; y: number }, { x: number; y: number }] | null => {
            const active = [...pointers.values()];

            return active.length >= 2 ? [active[0], active[1]] : null;
        };
        const beginPinch = (): void => {
            const active = pinchPoints();
            if (active === null) return;
            const [first, second] = active;
            const midpointX = (first.x + second.x) / 2;
            const midpointY = (first.y + second.y) / 2;
            pinchStart = {
                distance: Math.hypot(second.x - first.x, second.y - first.y),
                midpointX,
                midpointY,
                zoom: zoom.value,
                translateX: translateX.value,
                translateY: translateY.value,
            };
            panStart = null;
        };
        const zoomAt = (nextZoom: number, focalX?: number, focalY?: number): void => {
            const next = Math.min(4, Math.max(.5, nextZoom));
            const bounds = viewport.value?.getBoundingClientRect();
            const x = focalX ?? (bounds?.width ?? 0) / 2;
            const y = focalY ?? (bounds?.height ?? 0) / 2;
            const scale = next / zoom.value;
            translateX.value = x - (x - translateX.value) * scale;
            translateY.value = y - (y - translateY.value) * scale;
            zoom.value = next;
        };
        const onPointerDown = (event: PointerEvent): void => {
            pointers.set(event.pointerId, point(event));
            viewport.value?.setPointerCapture?.(event.pointerId);
            if (pointers.size === 2) {
                beginPinch();

                return;
            }
            panStart = { pointerId: event.pointerId, ...point(event), translateX: translateX.value, translateY: translateY.value };
        };
        const onPointerMove = (event: PointerEvent): void => {
            if (!pointers.has(event.pointerId)) return;
            pointers.set(event.pointerId, point(event));
            if (pointers.size >= 2 && pinchStart !== null) {
                const active = pinchPoints();
                if (active === null || pinchStart.distance === 0) return;
                const [first, second] = active;
                const midpointX = (first.x + second.x) / 2;
                const midpointY = (first.y + second.y) / 2;
                const scale = Math.hypot(second.x - first.x, second.y - first.y) / pinchStart.distance;
                const nextZoom = Math.min(4, Math.max(.5, pinchStart.zoom * scale));
                const contentX = (pinchStart.midpointX - pinchStart.translateX) / pinchStart.zoom;
                const contentY = (pinchStart.midpointY - pinchStart.translateY) / pinchStart.zoom;
                zoom.value = nextZoom;
                translateX.value = midpointX - contentX * nextZoom;
                translateY.value = midpointY - contentY * nextZoom;

                return;
            }
            if (panStart?.pointerId !== event.pointerId) return;
            const current = point(event);
            translateX.value = panStart.translateX + current.x - panStart.x;
            translateY.value = panStart.translateY + current.y - panStart.y;
        };
        const onPointerEnd = (event: PointerEvent): void => {
            pointers.delete(event.pointerId);
            if (viewport.value?.hasPointerCapture?.(event.pointerId)) viewport.value.releasePointerCapture(event.pointerId);
            if (pointers.size >= 2) {
                beginPinch();

                return;
            }
            pinchStart = null;
            const remaining = [...pointers.entries()][0];
            panStart = remaining === undefined ? null : { pointerId: remaining[0], ...remaining[1], translateX: translateX.value, translateY: translateY.value };
        };
        const onWheel = (event: WheelEvent): void => {
            const bounds = viewport.value?.getBoundingClientRect();
            zoomAt(zoom.value * (event.deltaY < 0 ? 1.12 : 1 / 1.12), event.clientX - (bounds?.left ?? 0), event.clientY - (bounds?.top ?? 0));
        };
        const onKeydown = (event: KeyboardEvent): void => {
            const movement = 32;
            if (event.key === '+' || event.key === '=') zoomAt(zoom.value * 1.12);
            else if (event.key === '-') zoomAt(zoom.value / 1.12);
            else if (event.key === 'ArrowLeft') translateX.value += movement;
            else if (event.key === 'ArrowRight') translateX.value -= movement;
            else if (event.key === 'ArrowUp') translateY.value += movement;
            else if (event.key === 'ArrowDown') translateY.value -= movement;
            else return;
            event.preventDefault();
        };
        const resetViewport = (): void => {
            zoom.value = 1;
            translateX.value = 0;
            translateY.value = 0;
        };
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
                context.arc(
                    brush.center_x * bounds.width,
                    brush.center_y * bounds.height,
                    brush.radius * Math.min(bounds.width, bounds.height),
                    0,
                    Math.PI * 2,
                );
                context.fill();
            });
            context.globalCompositeOperation = 'source-over';
        };
        const redraw = (): void => void nextTick(drawFog);
        onMounted(() => {
            redraw();
            window.addEventListener('resize', redraw);
        });
        onBeforeUnmount(() => { pointers.clear(); window.removeEventListener('resize', redraw); });
        watch(() => [props.snapshot.progress?.revision, props.imageUrl, zoom.value], redraw);
        return { canvas, viewport, zoom, translateX, translateY, drawFog, zoomAt, onPointerDown, onPointerMove, onPointerEnd, onWheel, onKeydown, resetViewport };
    },
    template: `
        <section class="panel stack" aria-labelledby="current-map-title">
            <header class="row"><div><h2 id="current-map-title">{{ snapshot.map?.name || 'Current map' }}</h2><p class="muted">Read-only shared map</p></div><div class="row"><span class="muted">{{ Math.round(zoom * 100) }}%</span><button class="secondary" type="button" @click="resetViewport" aria-label="Reset map view">Reset view</button></div></header>
            <div ref="viewport" class="map-viewport" tabindex="0" role="region" aria-label="Shared map viewport" @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerEnd" @pointercancel="onPointerEnd" @wheel.prevent="onWheel" @keydown="onKeydown"><div class="map-stage" :style="{ transform: 'translate(' + translateX + 'px, ' + translateY + 'px) scale(' + zoom + ')' }">
                <img v-if="imageUrl" class="map-image" :src="imageUrl" alt="">
                <div v-else class="map-placeholder">Loading map image…</div>
                <canvas ref="canvas" class="fog-layer" aria-hidden="true"></canvas>
                <div v-for="token in snapshot.progress?.tokens || []" :key="token.source_token_id" class="map-token" :style="{ left: (token.position_x * 100) + '%', top: (token.position_y * 100) + '%', transform: 'translate(-50%, -50%) scale(' + token.scale + ')' }">{{ token.label || 'Token' }}</div>
            </div></div>
            <p class="muted">Drag to move the map, or pinch and spread to zoom. Mouse-wheel and keyboard controls are also supported. Hidden tokens are never sent to this device.</p>
        </section>`,
});

export const ParticipantApp = defineComponent({
    components: { DiceRollVisual, FogMap },
    setup() {
        const playerCode = ref(new URLSearchParams(window.location.search).get('code') ?? '');
        const displayName = ref('');
        const role = ref<'player' | 'spectator'>('player');
        const online = ref(navigator.onLine);
        const writesDisabled = computed(() => busy.value || !online.value);
        const resumeToken = ref(localStorage.getItem('rpgays.resume_token') ?? '');
        const identity = ref<Participant | null>(null);
        const roster = ref<Roster | null>(null);
        const playerGroups = ref<PlayerGroup[]>([]);
        const messages = ref<SessionMessage[]>([]);
        const rolls = ref<SessionRoll[]>([]);
        const rollPresets = ref<DicePreset[]>([]);
        const rollExpression = ref('');
        const rollPresetId = ref('');
        const rollVisibility = ref<'public' | 'private'>('public');
        const messageTarget = ref<'control' | 'player_group'>('control');
        const messageGroupId = ref('');
        const messageBody = ref('');
        const replyToMessageId = ref('');
        const npcs = ref<RevealedNpc[]>([]);
        const noteNpcId = ref('');
        const noteBody = ref('');
        const error = ref('');
        const busy = ref(false);
        const imageUrl = ref('');
        const activeSection = ref<ParticipantSection | null>(null);
        const characterSelectionRequired = computed(
            () => identity.value?.role === 'player' && roster.value !== null && !roster.value.characters.some((character) => character.claimed_by_me),
        );
        const selectedCharacter = computed(() => roster.value?.characters.find((character) => character.claimed_by_me) ?? null);
        const sections: Array<{ id: ParticipantSection; label: string; description: string }> = [
            { id: 'map', label: 'Map', description: 'View the shared map' },
            { id: 'character', label: 'Character', description: 'Choose and view your character' },
            { id: 'chat', label: 'Chat', description: 'Messages with Control and your group' },
            { id: 'rolls', label: 'Rolls', description: 'See and make dice rolls' },
            { id: 'info', label: 'Info', description: 'Revealed NPCs and shared notes' },
        ];
        const toasts = ref<ParticipantToast[]>([]);
        const notificationsPrimed = ref(false);
        let nextToastId = 0;
        let knownMessageIds = new Set<string>();
        let notificationPollingTimer: number | null = null;
        const rollExpiryTimers = new Map<string, number>();
        const clearRollExpiryTimers = (): void => {
            rollExpiryTimers.forEach((timer) => window.clearTimeout(timer));
            rollExpiryTimers.clear();
        };
        const scheduleRollExpiry = (nextRolls: SessionRoll[]): void => {
            clearRollExpiryTimers();
            const now = Date.now();
            rolls.value = nextRolls.filter((roll) => Date.parse(roll.created_at) + 5_000 > now);
            rolls.value.forEach((roll) => {
                const delay = Math.max(0, Date.parse(roll.created_at) + 5_000 - now);
                rollExpiryTimers.set(roll.id, window.setTimeout(() => {
                    rolls.value = rolls.value.filter((candidate) => candidate.id !== roll.id);
                    rollExpiryTimers.delete(roll.id);
                }, delay));
            });
        };
        const dismissToast = (id: number): void => {
            toasts.value = toasts.value.filter((toast) => toast.id !== id);
        };
        const notify = (title: string, body: string): void => {
            const id = nextToastId++;
            toasts.value = [...toasts.value, { id, title, body }];
            window.setTimeout(() => dismissToast(id), 6_000);
        };
        const resetNotificationTracking = (): void => {
            notificationsPrimed.value = false;
            knownMessageIds = new Set();
        };
        const refreshNotifications = async (): Promise<void> => {
            await loadMessages().catch(() => undefined);
        };
        const startNotificationPolling = (): void => {
            if (notificationPollingTimer !== null) return;
            notificationPollingTimer = window.setInterval(() => void refreshNotifications(), 5_000);
        };
        const stopNotificationPolling = (): void => {
            if (notificationPollingTimer !== null) window.clearInterval(notificationPollingTimer);
            notificationPollingTimer = null;
        };
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
            if (!assetId) {
                imageUrl.value = '';
                return;
            }
            try {
                imageUrl.value = (await api<ApiResponse<{ url: string }>>(`/api/participant/v1/map/assets/${assetId}/read`)).data.url;
            } catch {
                imageUrl.value = '';
            }
        };
        const loadRoster = async (): Promise<void> => {
            roster.value = (await api<ApiResponse<Roster>>('/api/participant/v1/roster')).data;
        };
        const loadPlayerGroups = async (): Promise<void> => {
            playerGroups.value = (await api<ApiResponse<PlayerGroup[]>>('/api/participant/v1/player-groups')).data;
        };
        const loadMessages = async (): Promise<void> => {
            messages.value = (await api<ApiResponse<SessionMessage[]>>('/api/participant/v1/messages')).data;
        };
        const loadRolls = async (): Promise<void> => {
            scheduleRollExpiry((await api<ApiResponse<SessionRoll[]>>('/api/participant/v1/rolls')).data);
        };
        const loadRollPresets = async (): Promise<void> => {
            rollPresets.value = (await api<ApiResponse<DicePreset[]>>('/api/participant/v1/roll-presets')).data;
        };
        const loadNpcs = async (): Promise<void> => {
            npcs.value = (await api<ApiResponse<RevealedNpc[]>>('/api/participant/v1/npcs')).data;
        };
        const connect = async (): Promise<void> => {
            if (identity.value === null) return;
            error.value = '';
            try {
                await Promise.all([
                    currentMap.start(),
                    loadRoster(),
                    loadPlayerGroups(),
                    loadMessages(),
                    loadRolls(),
                    loadRollPresets(),
                    loadNpcs(),
                ]);
                await loadImage();
                notificationsPrimed.value = true;
                startNotificationPolling();
            } catch (reason) {
                if (!(reason instanceof ApiError && reason.status === 401)) error.value = reason instanceof Error ? reason.message : 'Unable to load your map.';
            }
        };
        const join = async (): Promise<void> => {
            if (!playerCode.value.trim() || !displayName.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const response = await api<ApiResponse<Participant>>('/api/participant/v1/join', {
                    method: 'POST',
                    body: JSON.stringify({ player_code: playerCode.value, display_name: displayName.value, role: role.value }),
                });
                resetNotificationTracking();
                identity.value = response.data;
                if (response.data.resume_token) {
                    resumeToken.value = response.data.resume_token;
                    localStorage.setItem('rpgays.resume_token', response.data.resume_token);
                }
                await connect();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to join this session.';
            } finally {
                busy.value = false;
            }
        };
        const resume = async (): Promise<void> => {
            if (!resumeToken.value.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                const participant = (
                    await api<ApiResponse<Participant>>('/api/participant/v1/resume', {
                        method: 'POST',
                        body: JSON.stringify({ resume_token: resumeToken.value }),
                    })
                ).data;
                if (identity.value?.id !== participant.id) resetNotificationTracking();
                identity.value = participant;
                localStorage.setItem('rpgays.resume_token', resumeToken.value);
                await connect();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to resume this session.';
            } finally {
                busy.value = false;
            }
        };
        const claim = async (character: RosterCharacter): Promise<void> => {
            if (busy.value || character.claimed || identity.value?.role !== 'player') return;
            busy.value = true;
            error.value = '';
            try {
                await api('/api/participant/v1/claim', { method: 'POST', body: JSON.stringify({ player_character_id: character.id }) });
                await Promise.all([loadRoster(), loadPlayerGroups()]);
                activeSection.value = null;
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to claim that character.';
                await loadRoster().catch(() => undefined);
            } finally {
                busy.value = false;
            }
        };
        const sendMessage = async (): Promise<void> => {
            if (!messageBody.value.trim() || (messageTarget.value === 'player_group' && !messageGroupId.value)) return;
            busy.value = true;
            error.value = '';
            try {
                await api('/api/participant/v1/messages', {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        target_type: messageTarget.value,
                        session_player_group_id: messageTarget.value === 'player_group' ? messageGroupId.value : null,
                        reply_to_session_message_id: replyToMessageId.value || null,
                        body: messageBody.value,
                    }),
                });
                messageBody.value = '';
                replyToMessageId.value = '';
                await loadMessages();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to send that message.';
            } finally {
                busy.value = false;
            }
        };
        const replyTo = (message: SessionMessage): void => {
            messageTarget.value = 'control';
            replyToMessageId.value = message.id;
        };
        const selectRollPreset = (): void => {
            const preset = rollPresets.value.find((item) => item.id === rollPresetId.value);
            if (preset) rollVisibility.value = preset.default_visibility;
        };
        const roll = async (): Promise<void> => {
            if (identity.value?.role !== 'player' || (!rollPresetId.value && !rollExpression.value.trim())) return;
            busy.value = true;
            error.value = '';
            try {
                await api('/api/participant/v1/rolls', {
                    method: 'POST',
                    body: JSON.stringify({
                        command_id: commandId(),
                        expression: rollPresetId.value ? null : rollExpression.value,
                        dice_preset_id: rollPresetId.value || null,
                        visibility: rollVisibility.value,
                    }),
                });
                rollExpression.value = '';
                await loadRolls();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to roll dice.';
            } finally {
                busy.value = false;
            }
        };
        const addNpcNote = async (): Promise<void> => {
            if (!noteNpcId.value || !noteBody.value.trim() || identity.value?.role !== 'player') return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/participant/v1/npcs/${noteNpcId.value}/notes`, {
                    method: 'POST',
                    body: JSON.stringify({ command_id: commandId(), body: noteBody.value }),
                });
                noteBody.value = '';
                await loadNpcs();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to add that NPC note.';
            } finally {
                busy.value = false;
            }
        };
        const editNpcNote = async (note: NpcNote): Promise<void> => {
            const body = window.prompt('Edit shared note', note.body);
            if (body === null || !body.trim()) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/participant/v1/npc-notes/${note.id}`, { method: 'PATCH', body: JSON.stringify({ command_id: commandId(), body }) });
                await loadNpcs();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to edit that NPC note.';
            } finally {
                busy.value = false;
            }
        };
        const deleteNpcNote = async (note: NpcNote): Promise<void> => {
            if (!window.confirm('Delete this shared note?')) return;
            busy.value = true;
            error.value = '';
            try {
                await api(`/api/participant/v1/npc-notes/${note.id}`, { method: 'DELETE', body: JSON.stringify({ command_id: commandId() }) });
                await loadNpcs();
            } catch (reason) {
                error.value = reason instanceof Error ? reason.message : 'Unable to delete that NPC note.';
            } finally {
                busy.value = false;
            }
        };
        const updateNetwork = (): void => {
            online.value = navigator.onLine;
            if (online.value) void connect();
        };
        onMounted(() => {
            registerParticipantServiceWorker();
            window.addEventListener('online', updateNetwork);
            window.addEventListener('offline', updateNetwork);
            if (online.value && resumeToken.value.trim()) void resume();
        });
        onBeforeUnmount(() => {
            currentMap.stop();
            stopNotificationPolling();
            clearRollExpiryTimers();
            window.removeEventListener('online', updateNetwork);
            window.removeEventListener('offline', updateNetwork);
        });
        watch(
            () => currentMap.snapshot.value?.map?.image_asset_id,
            () => void loadImage(),
        );
        watch(
            messages,
            (nextMessages) => {
                const nextIds = new Set(nextMessages.map((message) => message.id));
                if (notificationsPrimed.value) {
                    nextMessages
                        .filter((message) => !knownMessageIds.has(message.id) && message.sender_session_participant_id !== identity.value?.id)
                        .forEach((message) => notify(`New message from ${message.sender_name}`, message.body));
                }
                knownMessageIds = nextIds;
            },
            { deep: false, flush: 'sync' },
        );
        return {
            playerCode,
            displayName,
            role,
            resumeToken,
            identity,
            roster,
            playerGroups,
            messages,
            rolls,
            rollPresets,
            rollExpression,
            rollPresetId,
            rollVisibility,
            messageTarget,
            messageGroupId,
            messageBody,
            replyToMessageId,
            npcs,
            noteNpcId,
            noteBody,
            error,
            busy,
            online,
            writesDisabled,
            currentMapSnapshot: currentMap.snapshot,
            currentMapStatus: currentMap.status,
            join,
            resume,
            claim,
            sendMessage,
            replyTo,
            selectRollPreset,
            roll,
            addNpcNote,
            editNpcNote,
            deleteNpcNote,
            imageUrl,
            activeSection,
            characterSelectionRequired,
            selectedCharacter,
            sections,
            toasts,
            dismissToast,
        };
    },
    template: `
        <main class="shell stack"><header><div class="eyebrow">Theatrical RPG</div><h1>Player</h1><p v-if="!online" class="offline" role="alert">Offline — reconnect to refresh the session. All controls are disabled while this device is offline.</p><p v-else-if="currentMapSnapshot" class="muted" role="status">Realtime: {{ currentMapStatus === 'live' ? 'live' : 'degraded — polling snapshots' }}</p></header>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <fieldset class="participant-content stack" :disabled="writesDisabled">
            <section v-if="!identity" class="panel stack" aria-labelledby="join-title"><h2 id="join-title">Join a live session</h2>
                <form class="stack" @submit.prevent="join"><input v-model="playerCode" aria-label="Player code" maxlength="12" placeholder="Player code" required><input v-model="displayName" aria-label="Display name" maxlength="120" placeholder="Display name" required><select v-model="role" aria-label="Role"><option value="player">Player</option><option value="spectator">Spectator</option></select><button :disabled="busy">{{ busy ? 'Joining…' : 'Join session' }}</button></form>
                <form class="stack" @submit.prevent="resume"><h3>Resume</h3><input v-model="resumeToken" type="password" aria-label="Resume token" autocomplete="off" minlength="64" maxlength="64" placeholder="Resume token"><button class="secondary" :disabled="busy">Resume session</button></form>
            </section>
            <template v-else>
                <section v-if="activeSection === null && !characterSelectionRequired" class="panel participant-section-menu" aria-labelledby="player-sections-title"><div><h2 id="player-sections-title">Player menu</h2><p class="muted">Choose what you want to do.</p></div><nav class="participant-section-cards" aria-label="Player sections"><button v-for="section in sections.filter((section) => section.id !== 'character' || !selectedCharacter)" :key="section.id" type="button" @click="activeSection = section.id"><strong>{{ section.label }}</strong><span>{{ section.description }}</span></button></nav></section>
                <header v-else-if="activeSection !== null && !characterSelectionRequired" class="participant-section-header"><button class="secondary" type="button" @click="activeSection = null">← Menu</button><strong>{{ sections.find((section) => section.id === activeSection)?.label }}</strong></header>
                <section v-if="activeSection === 'map' && !characterSelectionRequired && !currentMapSnapshot" class="panel stack"><h2>Loading map</h2><p class="muted">Connecting to the shared session…</p></section>
                <section v-else-if="activeSection === 'map' && !characterSelectionRequired && currentMapSnapshot.map === null" class="panel stack"><h2>Map not currently shared</h2><p class="muted">Control has hidden the Player map. This page will update automatically when a map is shared.</p></section>
                <FogMap v-else-if="activeSection === 'map' && !characterSelectionRequired && currentMapSnapshot" :snapshot="currentMapSnapshot" :image-url="imageUrl" />
                <section v-if="(activeSection === 'character' || characterSelectionRequired) && roster" class="panel stack"><h2>{{ characterSelectionRequired ? 'Choose your character' : 'Character' }}</h2><p v-if="characterSelectionRequired" class="muted">Choose an unclaimed character before using the rest of the session.</p><p v-else-if="roster.role === 'spectator'" class="muted">Spectators can view the roster but cannot claim a character.</p><p v-else-if="roster.characters.some((character) => character.claimed_by_me)" class="muted">You have claimed a character for this session.</p><p v-else class="muted">Choose one unclaimed character.</p><article v-for="character in roster.characters" :key="character.id" class="asset"><div><strong>{{ character.name || 'Unnamed character' }}</strong><div class="muted">{{ character.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ character.public_description }}</div></div><button v-if="character.claimed_by_me" class="secondary" disabled>Claimed by you</button><button v-else-if="character.claimed" class="secondary" disabled>Claimed</button><button v-else :disabled="busy || roster.role !== 'player'" @click="claim(character)">Claim</button></article><template v-if="identity.role === 'player' && !characterSelectionRequired"><h3>Your groups</h3><p v-if="playerGroups.length === 0" class="muted">You are not in a named Player group yet.</p><article v-for="group in playerGroups" :key="group.id" class="asset"><strong>{{ group.name }}</strong></article></template></section>
                <section v-if="activeSection === 'chat' && !characterSelectionRequired" class="panel stack"><h2>Chat</h2><p class="muted">Group chats include only the members who received each message. Broadcast replies go privately to Control.</p><p v-if="messages.length === 0" class="muted">No messages yet.</p><article v-for="message in messages" :key="message.id" class="asset"><div><strong>{{ message.sender_name }}</strong><div>{{ message.body }}</div><div class="muted">{{ message.target_type.replaceAll('_', ' ') }} · {{ new Date(message.created_at).toLocaleTimeString() }}</div></div><button v-if="message.sender_type === 'control' && ['all_players', 'all_spectators', 'all'].includes(message.target_type)" class="secondary" :disabled="busy" @click="replyTo(message)">Reply privately</button></article><form class="stack" @submit.prevent="sendMessage"><h3>{{ replyToMessageId ? 'Reply to broadcast' : 'Send a message' }}</h3><select v-model="messageTarget" aria-label="Message recipient"><option value="control">Control</option><option v-if="identity.role === 'player'" value="player_group">My Player group</option></select><select v-if="messageTarget === 'player_group'" v-model="messageGroupId" aria-label="Player group"><option value="">Choose a group</option><option v-for="group in playerGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select><textarea v-model="messageBody" maxlength="2000" aria-label="Plain-text message" placeholder="Plain-text message"></textarea><button :disabled="busy || !messageBody.trim() || (messageTarget === 'player_group' && !messageGroupId)">Send</button><button v-if="replyToMessageId" class="secondary" type="button" :disabled="busy" @click="replyToMessageId = ''">Cancel reply</button></form></section>
                <section v-if="activeSection === 'rolls' && !characterSelectionRequired" class="panel stack"><h2>Rolls</h2><p v-if="rolls.length === 0" class="muted">No rolls from the last five seconds.</p><article v-for="roll in rolls" :key="roll.id" class="asset"><div><strong>{{ roll.roller_name }}</strong><div class="muted">{{ roll.expression }} · {{ roll.visibility }}{{ roll.revealed_at ? ' · revealed by Control' : '' }}</div><DiceRollVisual :key="roll.id" :breakdown="roll.breakdown" :total="roll.total" :label="roll.roller_name + ' roll'" /></div></article><form v-if="identity.role === 'player'" class="stack" @submit.prevent="roll"><h3>Roll dice</h3><select v-model="rollPresetId" aria-label="Dice preset" @change="selectRollPreset"><option v-for="preset in rollPresets" :key="preset.id" :value="preset.id">{{ preset.name }} · {{ preset.expression }}</option><option value="">Custom expression</option></select><input v-if="!rollPresetId" v-model="rollExpression" maxlength="200" aria-label="Dice expression" placeholder="4d6kh3 + 2"><select v-model="rollVisibility" aria-label="Roll visibility"><option value="public">Public</option><option value="private">Private to you and Control</option></select><button :disabled="busy || (!rollPresetId && !rollExpression.trim())">Roll</button></form></section>
                <section v-if="activeSection === 'info' && !characterSelectionRequired" class="panel stack"><h2>Info</h2><p v-if="npcs.length === 0" class="muted">No NPC profiles have been revealed yet.</p><article v-for="npc in npcs" :key="npc.id" class="asset"><div><strong>{{ npc.name || 'Unnamed NPC' }}</strong><div class="muted">{{ npc.pronouns || 'Pronouns not set' }}</div><div class="muted">{{ npc.public_description }}</div><section v-if="npc.notes.length" class="stack"><h3>Shared notes</h3><div v-for="note in npc.notes" :key="note.id" class="row"><p class="muted"><strong>{{ note.author_name }}</strong> · {{ note.body }}</p><template v-if="identity.role === 'player' && note.session_participant_id === identity.id"><button class="secondary" :disabled="busy" @click="editNpcNote(note)">Edit</button><button class="danger" :disabled="busy" @click="deleteNpcNote(note)">Delete</button></template></div></section></div></article><form v-if="identity.role === 'player' && npcs.length" class="stack" @submit.prevent="addNpcNote"><h3>Add a shared note</h3><select v-model="noteNpcId" aria-label="NPC for shared note"><option value="">Choose an NPC</option><option v-for="npc in npcs" :key="npc.id" :value="npc.id">{{ npc.name || 'Unnamed NPC' }}</option></select><textarea v-model="noteBody" maxlength="2000" aria-label="Shared NPC note" placeholder="Plain-text shared note"></textarea><button :disabled="busy || !noteNpcId || !noteBody.trim()">Add note</button></form></section>
            </template>
            </fieldset>
            <aside class="participant-toasts" aria-live="polite" aria-label="Notifications"><article v-for="toast in toasts" :key="toast.id" class="participant-toast"><div><strong>{{ toast.title }}</strong><p>{{ toast.body }}</p></div><button class="secondary" type="button" aria-label="Dismiss notification" @click="dismissToast(toast.id)">×</button></article></aside>
        </main>`,
});

const mountTarget = document.querySelector('#app');
if (mountTarget) createApp(ParticipantApp).mount(mountTarget);
