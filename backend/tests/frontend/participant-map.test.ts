import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FogMap, ParticipantApp } from '../../resources/participant/main';

const participantTestState = vi.hoisted(() => ({
    api: vi.fn(),
    realtimeStart: vi.fn(),
    realtimeStop: vi.fn(),
    registerServiceWorker: vi.fn(),
}));

vi.mock('../../resources/shared/api', () => ({
    ApiError: class extends Error {
        constructor(
            message: string,
            public readonly status: number,
        ) {
            super(message);
        }
    },
    api: participantTestState.api,
}));

vi.mock('../../resources/shared/realtime', () => ({
    useRealtimeSnapshot: () => ({
        snapshot: { value: null },
        status: { value: 'connecting' },
        refresh: vi.fn(),
        start: participantTestState.realtimeStart,
        stop: participantTestState.realtimeStop,
    }),
}));

vi.mock('../../resources/participant/pwa', () => ({
    registerParticipantServiceWorker: participantTestState.registerServiceWorker,
}));

describe('Player FogMap', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        participantTestState.api.mockReset();
        participantTestState.realtimeStart.mockReset();
        participantTestState.realtimeStop.mockReset();
        participantTestState.registerServiceWorker.mockReset();
    });

    it('renders an accessible read-only map with fog, visible tokens, and bounded zoom', async () => {
        const context = {
            setTransform: vi.fn(),
            clearRect: vi.fn(),
            fillRect: vi.fn(),
            beginPath: vi.fn(),
            arc: vi.fn(),
            fill: vi.fn(),
            fillStyle: '',
            globalCompositeOperation: '',
        } as unknown as CanvasRenderingContext2D;
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context);
        vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 960, height: 540 } as DOMRect);

        const wrapper = mount(FogMap, {
            props: {
                imageUrl: 'https://assets.example.test/moonlit-gate.webp',
                snapshot: {
                    state: { live_session_id: 'session-1', map_id: 'map-1', revision: 3 },
                    map: { id: 'map-1', name: 'Moonlit Gate', image_asset_id: 'asset-1' },
                    progress: {
                        revision: 3,
                        fog: { default_visibility: 'hidden', brushes: [{ id: 'brush-1', mode: 'reveal', center_x: 0.5, center_y: 0.5, radius: 0.2 }] },
                        tokens: [{ source_token_id: 'token-1', label: 'Ari', position_x: 0.5, position_y: 0.5, scale: 1 }],
                    },
                },
            },
        });

        await vi.waitFor(() => expect(context.fillRect).toHaveBeenCalledWith(0, 0, 960, 540));

        expect(wrapper.get('[role="region"][aria-label="Shared map viewport"]')).toBeTruthy();
        expect(wrapper.text()).toContain('Read-only shared map');
        expect(wrapper.text()).toContain('Ari');
        expect(wrapper.get('img').attributes('src')).toBe('https://assets.example.test/moonlit-gate.webp');

        await wrapper.get('button[aria-label="Zoom in"]').trigger('click');
        expect(wrapper.text()).toContain('120%');
        await wrapper.get('button[aria-label="Zoom out"]').trigger('click');
        expect(wrapper.text()).toContain('100%');
    });

    it('does not start participant snapshot polling before join or resume establishes identity', async () => {
        participantTestState.api.mockRejectedValue(new Error('API should not be called on initial Player load.'));
        vi.stubGlobal('localStorage', {
            getItem: vi.fn().mockReturnValue(null),
            setItem: vi.fn(),
            clear: vi.fn(),
        });

        mount(ParticipantApp);

        expect(participantTestState.registerServiceWorker).toHaveBeenCalledOnce();
        expect(participantTestState.realtimeStart).not.toHaveBeenCalled();
        expect(participantTestState.api).not.toHaveBeenCalled();
    });

    it('opens one player section at a time after joining and toasts when a new poll arrives', async () => {
        vi.useFakeTimers();
        let pollRequests = 0;
        participantTestState.api.mockImplementation(async (path: string) => {
            if (path === '/api/participant/v1/join') {
                return { data: { id: 'player-1', role: 'player', display_name: 'Ari', resume_token: 'a'.repeat(64) } };
            }
            if (path === '/api/participant/v1/polls') {
                pollRequests += 1;

                return {
                    data:
                        pollRequests === 1
                            ? []
                            : [
                                  {
                                      id: 'poll-1',
                                      question: 'Take the moonlit path?',
                                      allows_multiple: false,
                                      target_type: 'all',
                                      status: 'open',
                                      result_visibility: 'none',
                                      options: [],
                                      my_option_ids: [],
                                      closed_at: null,
                                  },
                              ],
                };
            }

            if (path === '/api/participant/v1/roster') return { data: { role: 'player', characters: [] } };
            if (path === '/api/participant/v1/player-groups') return { data: [] };
            if (path === '/api/participant/v1/messages') {
                return {
                    data: [
                        {
                            id: 'message-1',
                            sender_type: 'control',
                            sender_session_participant_id: null,
                            sender_name: 'Control',
                            target_type: 'all',
                            target_session_participant_id: null,
                            session_player_group_id: null,
                            reply_to_session_message_id: null,
                            body: 'Welcome.',
                            created_at: '2026-07-25T00:00:00Z',
                        },
                    ],
                };
            }
            if (path === '/api/participant/v1/rolls') return { data: [] };
            if (path === '/api/participant/v1/roll-presets') return { data: [] };
            if (path === '/api/participant/v1/npcs') return { data: [] };
            throw new Error(`Unexpected API request: ${path}`);
        });
        vi.stubGlobal('localStorage', { getItem: vi.fn().mockReturnValue(null), setItem: vi.fn() });

        const wrapper = mount(ParticipantApp);

        expect(wrapper.find('[aria-label="Player sections"]').exists()).toBe(false);
        expect(wrapper.get('input[aria-label="Resume token"]').attributes('type')).toBe('password');

        await wrapper.get('input[aria-label="Player code"]').setValue('MOONLIT');
        await wrapper.get('input[aria-label="Display name"]').setValue('Ari');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[aria-label="Player sections"]').text()).toContain('Info');
        expect(wrapper.text()).toContain('Player menu');
        expect(wrapper.text()).not.toContain('Save your resume token');
        expect(wrapper.text()).not.toContain('No messages yet.');
        expect(wrapper.get('[aria-label="Notifications"]').text()).toBe('');

        await wrapper
            .get('[aria-label="Player sections"]')
            .findAll('button')
            .find((button) => button.text().startsWith('Chat'))!
            .trigger('click');
        expect(wrapper.find('[aria-label="Player sections"]').exists()).toBe(false);
        expect(wrapper.get('h2').text()).toBe('Chat');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === '← Menu')!
            .trigger('click');
        expect(wrapper.get('[aria-label="Player sections"]').exists()).toBe(true);

        await vi.advanceTimersByTimeAsync(5_000);
        await flushPromises();

        expect(wrapper.get('[aria-label="Notifications"]').text()).toContain('New poll');
        expect(wrapper.get('[aria-label="Notifications"]').text()).toContain('Take the moonlit path?');
        wrapper.unmount();
    });
});
