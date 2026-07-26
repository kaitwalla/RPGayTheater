import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CampaignStudioView } from '../../resources/control/studio';
import { api } from '../../resources/shared/api';

const routerPush = vi.hoisted(() => vi.fn());

vi.mock('vue-router', () => ({
    useRoute: () => ({ params: { campaign: 'campaign-1' }, query: {} }),
    useRouter: () => ({ push: routerPush, replace: vi.fn() }),
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
        global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, PresentationStage: true } },
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
        vi.useRealTimers();
        mockedApi.mockReset();
        routerPush.mockReset();
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
        await wrapper.get('textarea[aria-label="New scene control-only notes"]').setValue('The curator is lying about the missing folio.');
        await wrapper.get('select[aria-label="Scene backdrop"]').setValue('asset-backdrop');
        await wrapper.get('.modal-panel form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/scenes', {
            method: 'POST',
            body: expect.stringContaining('"control_notes":"The curator is lying about the missing folio."'),
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

        expect(wrapper.get('[aria-label="Cue editor"]').exists()).toBe(true);
        expect(wrapper.get('input[aria-label="Cue media file"]').attributes('type')).toBe('file');
        await wrapper.get('input[aria-label="Cue name"]').setValue('Library ambience');
        await wrapper.get('select[aria-label="Cue media"]').setValue('asset-ambience');
        await wrapper.get('[aria-label="Cue editor"] form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/audio-cues', {
            method: 'POST',
            body: expect.stringContaining('"scene_id":"scene-1"'),
        });
    });

    it('manages global video cues and lets them start a global music companion', async () => {
        const studio = baseStudio();
        studio.data.records.assets.push({
            id: 'asset-video',
            kind: 'video',
            upload_status: 'ready',
            archived_at: null,
            original_filename: 'arrival.mp4',
        });
        studio.data.records.audio_cues.push({
            id: 'cue-score',
            name: 'Arrival score',
            scene_id: null,
            asset_id: 'asset-ambience',
            kind: 'music',
            loop: true,
            default_volume: 100,
        });
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return studio;
            if (url === '/api/control/v1/campaigns/campaign-1/video-cues' && init?.method === 'POST') return { data: { id: 'cue-video' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper.get('nav[aria-label="Campaign studio"] button:nth-child(6)').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add video')
            ?.trigger('click');

        expect(wrapper.text()).toContain('Global cue editor');
        await wrapper.get('input[aria-label="Cue name"]').setValue('Arrival');
        await wrapper.get('select[aria-label="Cue media"]').setValue('asset-video');
        await wrapper.get('select[aria-label="Video companion music"]').setValue('cue-score');
        await wrapper.get('[aria-label="Cue editor"] form').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/video-cues', {
            method: 'POST',
            body: expect.stringContaining('"concurrent_music_cue_id":"cue-score"'),
        });
    });

    it('removes a cue without letting a pending autosave make the delete stale', async () => {
        vi.useFakeTimers();
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        const response = baseStudio();
        response.data.records.audio_cues.push({
            id: 'cue-1',
            name: 'Library ambience',
            scene_id: null,
            asset_id: 'asset-ambience',
            kind: 'music',
            loop: true,
        });
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return response;
            if (url === '/api/control/v1/campaigns/campaign-1/studio/audio-cues/cue-1' && init?.method === 'DELETE') return { data: {} };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper.get('nav[aria-label="Campaign studio"] button:nth-child(6)').trigger('click');
        await flushPromises();
        await wrapper.get('[aria-label="Name for Library ambience"]').setValue('Edited ambience');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Remove')
            ?.trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(500);

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/audio-cues/cue-1', {
            method: 'DELETE',
            body: expect.stringContaining('"expected_revision":1'),
        });
        expect(
            mockedApi.mock.calls.some(([url, init]) => url === '/api/control/v1/campaigns/campaign-1/studio/audio-cues/cue-1' && init?.method === 'PATCH'),
        ).toBe(false);
    });

    it('keeps newer character text while an earlier autosave is still in flight', async () => {
        vi.useFakeTimers();
        const studio = baseStudio();
        const character = {
            id: 'pc-1',
            name: 'Ari',
            pronouns: null,
            public_description: '',
            control_notes: null,
            avatar_asset_id: null,
        };
        studio.data.records.player_characters.push(character);
        let resolveSave: ((value: { data: { campaign: { id: string; name: string; draft_revision: number }; record: typeof character } }) => void) | undefined;
        mockedApi.mockImplementation((url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return Promise.resolve(studio);
            if (url === '/api/control/v1/campaigns/campaign-1/studio/player-characters/pc-1' && init?.method === 'PATCH')
                return new Promise((resolve) => {
                    resolveSave = resolve;
                });
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();
        await wrapper.get('nav[aria-label="Campaign studio"] button:nth-child(3)').trigger('click');
        const introduction = wrapper.get('textarea[aria-label="Public description for Ari"]');

        await introduction.setValue('The first sentence');
        vi.advanceTimersByTime(450);
        expect(mockedApi).toHaveBeenCalledWith(
            '/api/control/v1/campaigns/campaign-1/studio/player-characters/pc-1',
            expect.objectContaining({ method: 'PATCH' }),
        );

        await introduction.setValue('The first sentence keeps growing');
        resolveSave?.({
            data: {
                campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 2 },
                record: { ...character, public_description: 'The first sentence' },
            },
        });
        await flushPromises();

        expect((introduction.element as HTMLTextAreaElement).value).toBe('The first sentence keeps growing');
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

    it('creates a named default stage preset before placing a character on it', async () => {
        let presetCreated = false;
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return presetCreated ? baseStudio(2, 'stage-new') : baseStudio(1);
            if (url === '/api/control/v1/campaigns/campaign-1/stage-presets' && init?.method === 'POST') {
                presetCreated = true;
                return { data: { id: 'stage-new' } };
            }
            if (url === '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1' && init?.method === 'PATCH')
                return {
                    data: {
                        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 3 },
                        record: { ...baseStudio(3, 'stage-new').data.records.scenes[0] },
                    },
                };
            if (url === '/api/control/v1/campaigns/campaign-1/stage-presets/stage-new/entries' && init?.method === 'POST') return { data: { id: 'entry-new' } };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        expect(
            wrapper
                .findAll('button')
                .find((button) => button.text() === 'Place existing character')
                ?.attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('input[aria-label="New stage preset name"]').setValue('Library entrance');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Create preset')
            ?.trigger('click');
        await flushPromises();

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Place existing character')
            ?.trigger('click');
        await wrapper.get('select[aria-label="Character"]').setValue('npc-existing');
        await wrapper.get('.modal-panel form').trigger('submit');
        await flushPromises();

        const calls = mockedApi.mock.calls.map(([url, init]) => [url, init?.method, init?.body ? JSON.parse(String(init.body)) : null]);
        expect(calls).toEqual(
            expect.arrayContaining([
                ['/api/control/v1/campaigns/campaign-1/stage-presets', 'POST', expect.objectContaining({ name: 'Library entrance' })],
                [
                    '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1',
                    'PATCH',
                    expect.objectContaining({ patch: { base_stage_preset_id: 'stage-new' } }),
                ],
                [
                    '/api/control/v1/campaigns/campaign-1/stage-presets/stage-new/entries',
                    'POST',
                    expect.objectContaining({ npc_id: 'npc-existing', position_x: 0.5, position_y: 0.65 }),
                ],
            ]),
        );
    });

    it('resets the composition board to the scene default when it changes', async () => {
        const studio = baseStudio(1, 'stage-1');
        studio.data.records.stage_presets.push({ id: 'stage-2', name: 'Battle layout' });
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return studio;
            if (url === '/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1' && init?.method === 'PATCH') {
                return {
                    data: {
                        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 2 },
                        record: { ...studio.data.records.scenes[0], base_stage_preset_id: 'stage-2' },
                    },
                };
            }
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper.get('select[aria-label="Default stage preset for scene"]').setValue('stage-2');
        await flushPromises();

        expect((wrapper.get('select[aria-label="Stage preset to edit"]').element as HTMLSelectElement).value).toBe('stage-2');
        expect(wrapper.get('[aria-label="Stage preset manager"]').text()).toContain('Editing: Battle layout');
        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/scenes/scene-1', {
            method: 'PATCH',
            body: expect.stringContaining('"base_stage_preset_id":"stage-2"'),
        });
    });

    it('clears the staged layout when switching to a scene without a default preset', async () => {
        const studio = baseStudio(1, 'stage-1');
        studio.data.records.scenes.push({
            id: 'scene-2',
            name: 'Courtyard',
            primary_backdrop_asset_id: null,
            default_music_cue_id: null,
            base_stage_preset_id: null,
            transition: 'cut',
            transition_duration_ms: 0,
        });
        mockedApi.mockResolvedValue(studio);
        const wrapper = await mountStudio();

        await wrapper.findAll('.scene-card')[1].trigger('click');

        expect((wrapper.get('select[aria-label="Stage preset to edit"]').element as HTMLSelectElement).value).toBe('');
        expect(wrapper.get('[aria-label="Scene starting positions canvas"]').text()).toContain('Choose or create a stage preset');
    });

    it('removes a character placement from the current preset without removing the character', async () => {
        const studio = baseStudio(2, 'stage-1');
        studio.data.records.stage_preset_entries.push({
            id: 'entry-1',
            stage_preset_id: 'stage-1',
            npc_id: 'npc-existing',
            npc_state_id: null,
            position_x: 0.5,
            position_y: 0.65,
            scale: 1,
            layer_order: 0,
            facing: 'right',
        });
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return studio;
            if (url === '/api/control/v1/campaigns/campaign-1/studio/stage-preset-entries/entry-1' && init?.method === 'DELETE') return { data: {} };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        await wrapper.get('[aria-label="Remove Guard from preset"]').trigger('click');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/stage-preset-entries/entry-1', {
            method: 'DELETE',
            body: expect.stringContaining('"expected_revision":2'),
        });
    });

    it('manages the selected stage preset with explicit save and delete actions', async () => {
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(2, 'stage-1');
            if (url === '/api/control/v1/campaigns/campaign-1/studio/stage-presets/stage-1' && init?.method === 'PATCH')
                return {
                    data: {
                        campaign: { id: 'campaign-1', name: 'Dungeon Crawl', draft_revision: 2 },
                        record: { id: 'stage-1', name: 'Library opening', tween_duration_ms: 450, tween_easing: 'ease_out' },
                    },
                };
            if (url === '/api/control/v1/campaigns/campaign-1/studio/stage-presets/stage-1' && init?.method === 'DELETE') return { data: {} };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = await mountStudio();

        expect(wrapper.get('[aria-label="Stage preset manager"]').text()).toContain('Editing: Library stage');
        await wrapper.get('[aria-label="Stage preset name"]').setValue('Library opening');
        await wrapper.get('[aria-label="Stage preset movement duration"]').setValue('450');
        await wrapper.get('[aria-label="Stage preset movement style"]').setValue('ease_out');
        await wrapper.get('.stage-preset-editor').trigger('submit');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/stage-presets/stage-1', {
            method: 'PATCH',
            body: expect.stringContaining('"name":"Library opening"'),
        });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Delete preset')
            ?.trigger('click');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/studio/stage-presets/stage-1', {
            method: 'DELETE',
            body: expect.stringContaining('"expected_revision":2'),
        });
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
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cast')
            ?.trigger('click');

        expect(wrapper.text()).toContain('Player characters join the map roster.');
        expect(wrapper.text()).toContain('Choose right-facing image');
        await wrapper.get('input[aria-label="Emotion name for Guard"]').setValue('Concerned');
        await wrapper.get('select[aria-label="Emotion art for Guard"]').setValue('asset-portrait');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add state')
            ?.trigger('click');
        await flushPromises();

        expect(mockedApi).toHaveBeenCalledWith('/api/control/v1/campaigns/campaign-1/npcs/npc-existing/states', {
            method: 'POST',
            body: expect.stringContaining('"name":"Concerned"'),
        });
    });

    it('previews the current draft by snapshotting and starting a fresh session', async () => {
        mockedApi.mockImplementation(async (url, init) => {
            if (url === '/api/control/v1/campaigns/campaign-1/studio') return baseStudio(7);
            if (url === '/api/control/v1/campaigns/campaign-1/publish' && init?.method === 'POST')
                return { data: { id: 'revision-preview', number: 3, published_at: '2026-07-23T00:00:00Z' } };
            if (url === '/api/control/v1/campaigns/campaign-1/sessions' && init?.method === 'POST')
                return {
                    data: {
                        id: 'session-preview',
                        campaign_revision_id: 'revision-preview',
                        progress_mode: 'fresh',
                        player_code: 'PREVIEW1',
                        status: 'active',
                        created_at: '2026-07-23T00:00:00Z',
                    },
                };
            throw new Error(`Unexpected API call: ${url}`);
        });
        const wrapper = mount(CampaignStudioView, {
            global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
        });
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Preview')
            ?.trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Preview campaign')
            ?.trigger('click');
        await flushPromises();

        const publishCall = mockedApi.mock.calls.find(([url]) => url === '/api/control/v1/campaigns/campaign-1/publish');
        const sessionCall = mockedApi.mock.calls.find(([url]) => url === '/api/control/v1/campaigns/campaign-1/sessions');
        expect(publishCall?.[1]).toMatchObject({ method: 'POST' });
        expect(JSON.parse(String(publishCall?.[1]?.body))).toMatchObject({ expected_revision: 7 });
        expect(sessionCall?.[1]).toMatchObject({ method: 'POST' });
        expect(JSON.parse(String(sessionCall?.[1]?.body))).toMatchObject({
            campaign_revision_id: 'revision-preview',
            progress_mode: 'fresh',
            name: 'Preview — Dungeon Crawl',
        });
        expect(routerPush).toHaveBeenCalledWith('/campaigns/campaign-1/live/session-preview');
    });
});
