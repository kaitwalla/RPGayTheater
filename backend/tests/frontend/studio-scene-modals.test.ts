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
});
