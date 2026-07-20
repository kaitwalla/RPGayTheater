import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FogMap } from '../../resources/participant/main';

describe('Player FogMap', () => {
    afterEach(() => vi.restoreAllMocks());

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
});
