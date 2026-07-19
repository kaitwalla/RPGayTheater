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
                <article v-for="campaign in campaigns" :key="campaign.id" class="campaign"><input v-model="campaign.name" :aria-label="'Name for ' + campaign.name" maxlength="120"><RouterLink class="button secondary" :to="{ path: '/campaigns/' + campaign.id + '/assets', query: { revision: campaign.draft_revision } }">Assets</RouterLink><button class="secondary" @click="rename(campaign)">Save</button><button class="danger" @click="archive(campaign)">Archive</button></article>
            </section>
        </main>`,
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
        { path: '/login', component: LoginView },
    ],
});

createApp({ template: '<RouterView />' }).use(createPinia()).use(router).mount('#app');
