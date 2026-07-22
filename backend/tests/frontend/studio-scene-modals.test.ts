import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CampaignStudioView } from '../../resources/control/studio';
import { api } from '../../resources/shared/api';

vi.mock('vue-router', () => ({
    useRoute: () => ({ params: { campaign: 'campaign-1' } }),
    useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

vi.mock('../../resources/shared/command-id', () => ({
    commandId: vi.fn(() => `command-${Math.random().toString(16).slice(2)}`),
}));

vi.mock('../../resources/shared/api', () => ({
    ApiError: class ApiError extends Error {
        status: number;

        constructor(message: string, status: number) {
            super(message);
            this.status = status;
        }
    },
    api: vi.fn(),
}));

const mockedApi = vi.mocked(api);

const baseStudio = (revision = 1, stagePresetId: string | null = null) => ({
    data: {
        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: revision },
        records: {
            assets: [
                { id: 'asset-portrait', kind: 'image', upload_status: 'ready', archived_at: null, original_filename: 'portrait.png' },
                { id: 'asset-backdrop', kind: 'image', upload_status: 'ready', archived_at: null, original_filename: 'backdrop.png' },
                { id: 'asset-ambience', kind: 'audio', upload_status: 'ready', archived_at: null, original_filename: 'archive-ambience.ogg' },
            ],
            player_characters: [],
            npcs: [{ id: 'npc-existing', name: 'Guard', normal_asset_id: 'asset-portrait', native_facing: 'right' }],
            npc_states: [{ id: 'state-alert', npc_id: 'npc-existing', asset_id: 'asset-portrait', name: 'Alert' }],
            scenes: [
                {
                    id: 'scene-1',
                    name: 'Library',
                    primary_backdrop_asset_id: null,
                    default_music_cue_id: null,
                    base_stage_preset_id: stagePresetId,
                    transition: 'cut',
                    transition_duration_ms: 0,
                },
            ],
            scene_backdrops: [],
            stage_presets: stagePresetId ? [{ id: stagePresetId, name: 'Library stage' }] : [],
            stage_preset_entries: [],
            maps: [],
            map_fog_masks: [],
            map_tokens: [],
            audio_cues: [],
            video_cues: [],
            dice_presets: [],
            asset_collections: [],
        },
    },
});

const mountStudio = async () => {
    const wrapper = mount(CampaignStudioView, {
        global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    });
    await flushPromises();
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Scenes')
        ?.trigger('click');

    return wrapper;
};

describe('CampaignStudioView scene modals', () => {
    afterEach(() => {
        mockedApi.mockReset();
        vi.unstubAllGlobals();
    });

    it('adds a scene backdrop without leaving the composition board', async () => {
        mockedApi.mockResolvedValue(baseStudio());
        const wrapper = await mountStudio();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add alternate backdrop')
            ?.trigger('click');
        await wrapper.get('input[aria-label="Backdrop name"]').setValue('Secret door');
        await wrapper.get('select[aria-label="Backdrop image"]').setValue('asset-backdrop');
        await wrapper.get('.modal-panel form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/scenes/scene-1/backdrops', {
            method: 'POST',
            body: expect.stringContaining('"name":"Secret door"'),
        });
        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio');
    });

    it('creates a scene from the scene board and opens it for composition', async () => {
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(2);
            if (url === '/api/control/v1/campaigns/campaign-1/scenes' && init?.method === 'POST') return { data: { id: 'scene-2' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Create scene')
            ?.trigger('click');
        await wrapper.get('input[aria-label="Scene name"]').setValue('Moonlit archive');
        await wrapper.get('select[aria-label="Scene backdrop"]').setValue('asset-backdrop');
        await wrapper.get('.modal-panel form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/scenes', {
            method: 'POST',
            body: expect.stringContaining('"name":"Moonlit archive"'),
        });
        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio');
    });

    it('uses one modal to create a scene-specific cue and keeps upload in that modal', async () => {
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(2);
            if (url === '/api/control/v1/campaigns/campaign-1/audio-cues' && init?.method === 'POST') return { data: { id: 'cue-1' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        expect(wrapper.find('[aria-label^="Attach existing cue"]').exists()).toBe(false);
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Create cue')
            ?.trigger('click');

        expect(wrapper.get('[aria-label="Scene cue editor"]').exists()).toBe(true);
        expect(wrapper.get('input[aria-label="Cue media file"]').attributes('type')).toBe('file');
        await wrapper.get('input[aria-label="Cue name"]').setValue('Library ambience');
        await wrapper.get('select[aria-label="Cue media"]').setValue('asset-ambience');
        await wrapper.get('[aria-label="Scene cue editor"] form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/audio-cues', {
            method: 'POST',
            body: expect.stringContaining('"scene_id":"scene-1"'),
        });
    });

    it('uploads media from the cue modal and selects the completed asset', async () => {
        let completed = false;
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') {
                const response = baseStudio(5);
                if (completed)
                    response.data.records.assets.push({
                        id: 'asset-new',
                        kind: 'audio',
                        upload_status: 'ready',
                        archived_at: null,
                        original_filename: 'archive.ogg',
                    });
                return response;
            }
            if (url === '/api/control/v1/campaigns/campaign-1/assets/uploads' && init?.method === 'POST') {
                return { data: { id: 'asset-uploading' }, upload: { part_size: 10, parts: [{ number: 1, url: 'https://storage.test/part-1' }] } };
            }
            if (url === '/api/control/v1/campaigns/campaign-1/assets/asset-uploading/complete' && init?.method === 'POST') {
                completed = true;
                return { data: { id: 'asset-new' } };
            }
            throw new Error(`Unexpected API call: ${url}`);
        });
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, headers: { get: () => 'part-etag' } });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = await mountStudio();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Create cue')
            ?.trigger('click');
        const fileInput = wrapper.get('input[aria-label="Cue media file"]');
        Object.defineProperty(fileInput.element, 'files', { value: [new File(['ambient'], 'archive.ogg', { type: 'audio/ogg' })] });
        await fileInput.trigger('change');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Upload and use this file')
            ?.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('https://storage.test/part-1', expect.objectContaining({ method: 'PUT' }));
        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/assets/asset-uploading/complete', {
            method: 'POST',
            body: expect.stringContaining('"expected_revision":6'),
        });
        expect((wrapper.get('select[aria-label="Cue media"]').element as HTMLSelectElement).value).toBe('asset-new');
    });

    it('uploads a backdrop from the scene editor and makes it the primary backdrop', async () => {
        let completed = false;
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') {
                const response = baseStudio(completed ? 8 : 5);
                if (completed)
                    response.data.records.assets.push({
                        id: 'asset-new-backdrop',
                        kind: 'image',
                        upload_status: 'ready',
                        archived_at: null,
                        original_filename: 'library.jpg',
                    });
                return response;
            }
            if (url === '/api/control/v1/campaigns/campaign-1/assets/uploads' && init?.method === 'POST') {
                return { data: { id: 'asset-uploading' }, upload: { part_size: 10, parts: [{ number: 1, url: 'https://storage.test/backdrop-part-1' }] } };
            }
            if (url === '/api/control/v1/campaigns/campaign-1/assets/asset-uploading/complete' && init?.method === 'POST') {
                completed = true;
                return { data: { id: 'asset-new-backdrop' } };
            }
            if (url === '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1' && init?.method === 'PATCH') {
                return {
                    data: {
                        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 9 },
                        record: { ...baseStudio(9).data.records.scenes[0], primary_backdrop_asset_id: 'asset-new-backdrop' },
                    },
                };
            }
            if (url === '/api/control/v1/campaigns/campaign-1/assets/asset-new-backdrop/read') return { data: { url: 'https://storage.test/library.jpg' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, headers: { get: () => 'part-etag' } }));
        const wrapper = await mountStudio();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Upload backdrop')
            ?.trigger('click');
        const fileInput = wrapper.get('input[aria-label="Backdrop image file"]');
        Object.defineProperty(fileInput.element, 'files', { value: [new File(['backdrop'], 'library.jpg', { type: 'image/jpeg' })] });
        await fileInput.trigger('change');
        await wrapper.get('[aria-label="Backdrop uploader"] form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1', {
            method: 'PATCH',
            body: expect.stringContaining('"primary_backdrop_asset_id":"asset-new-backdrop"'),
        });
        expect((wrapper.get('select[aria-label="Primary backdrop"]').element as HTMLSelectElement).value).toBe('asset-new-backdrop');
    });

    it('creates a character, creates its scene layout when needed, and places the character on it', async () => {
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(1);
            if (url === '/api/control/v1/campaigns/campaign-1/npcs' && init?.method === 'POST') return { data: { id: 'npc-new' } };
            if (url === '/api/control/v1/campaigns/campaign-1/stage-presets' && init?.method === 'POST') return { data: { id: 'stage-new' } };
            if (url === '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1' && init?.method === 'PATCH')
                return {
                    data: {
                        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 2 },
                        record: { ...baseStudio(2, 'stage-new').data.records.scenes[0] },
                    },
                };
            if (url === '/api/control/v1/campaigns/campaign-1/stage-presets/stage-new/entries' && init?.method === 'POST') return { data: { id: 'entry-new' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add new character')
            ?.trigger('click');
        await wrapper.get('input[aria-label="Character name"]').setValue('Archivist');
        await wrapper.get('select[aria-label="Character image"]').setValue('asset-portrait');
        await wrapper.get('.modal-panel form').trigger('submit');
        await flushPromises();

        const calls = mockedApi.mock.calls.map(([url, init]) => [url, init?.method, init?.body ? JSON.parse(String(init.body)) : null]);
        expect(calls).toEqual(
            expect.arrayContaining([
                ['/api/control/v1/campaigns/campaign-1/npcs', 'POST', expect.objectContaining({ name: 'Archivist', normal_asset_id: 'asset-portrait' })],
                ['/api/control/v1/campaigns/campaign-1/stage-presets', 'POST', expect.objectContaining({ name: 'Library staging layout' })],
                [
                    '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1',
                    'PATCH',
                    expect.objectContaining({ patch: { base_stage_preset_id: 'stage-new' } }),
                ],
                [
                    '/api/control/v1/campaigns/campaign-1/stage-presets/stage-new/entries',
                    'POST',
                    expect.objectContaining({ npc_id: 'npc-new', position_x: 0.5, position_y: 0.65 }),
                ],
            ]),
        );
    });

    it('adds right-facing emotion art to NPCs without exposing it for PCs', async () => {
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(3);
            if (url === '/api/control/v1/campaigns/campaign-1/npcs/npc-existing/states' && init?.method === 'POST') return { data: { id: 'state-concerned' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = mount(CampaignStudioView, {
            global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
        });
        await flushPromises();
        await wrapper.findAll('button').find((button) => button.text() === 'Cast')?.trigger('click');

        expect(wrapper.text()).toContain('PCs are map-only.');
        expect(wrapper.text()).toContain('NPC source art and every emotional-state image should face right');
        await wrapper.get('input[aria-label="Emotion name for Guard"]').setValue('Concerned');
        await wrapper.get('select[aria-label="Emotion art for Guard"]').setValue('asset-portrait');
        await wrapper.findAll('button').find((button) => button.text() === 'Add emotion')?.trigger('click');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/npcs/npc-existing/states', {
            method: 'POST',
            body: expect.stringContaining('"name":"Concerned"'),
        });
    });
});
