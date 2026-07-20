import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, type PropType } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PresentationStage, type PresentationStageEntry } from '../../resources/shared/presentation-stage';

class LoadedImage {
    width = 400;
    height = 200;
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;

    set src(_url: string) {
        queueMicrotask(() => this.onload?.());
    }
}

const KonvaImage = defineComponent({
    props: { config: { type: Object as PropType<Record<string, unknown>>, required: true } },
    template: '<div class="konva-image" />',
});

const entries: PresentationStageEntry[] = [
    { npc_id: 'front', npc_state_id: null, name: 'Front', asset_id: 'front-asset', position_x: .8, position_y: .7, scale: 1, layer_order: 2, facing: 'left', native_facing: 'right' },
    { npc_id: 'back', npc_state_id: null, name: 'Back', asset_id: 'back-asset', position_x: .2, position_y: .3, scale: .5, layer_order: 1, facing: null, native_facing: 'left' },
];

describe('PresentationStage', () => {
    beforeEach(() => {
        vi.stubGlobal('Image', LoadedImage);
        vi.stubGlobal('ResizeObserver', class { observe(): void {} disconnect(): void {} });
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false }));
    });

    afterEach(() => vi.unstubAllGlobals());

    const mountStage = () => mount(PresentationStage, {
        props: {
            entries,
            assetUrls: { 'front-asset': '/front.png', 'back-asset': '/back.png' },
            editable: true,
        },
        global: {
            stubs: {
                'v-stage': { template: '<div><slot /></div>' },
                'v-layer': { template: '<div><slot /></div>' },
                'v-rect': true,
                'v-image': KonvaImage,
            },
        },
    });

    it('renders loaded entries in layer order with 16:9 placement and facing', async () => {
        const wrapper = mountStage();
        await flushPromises();

        const configs = wrapper.findAllComponents(KonvaImage).map((image) => image.props('config'));

        expect(configs).toHaveLength(2);
        expect(configs[0]).toMatchObject({ x: 384, y: 324, width: 720, height: 360, scaleX: 1, draggable: true });
        expect(configs[1]).toMatchObject({ x: 1536, y: 756, width: 1440, height: 720, scaleX: -1, draggable: true });
    });

    it('emits bounded normalized coordinates when an editable entry is dragged', async () => {
        const wrapper = mountStage();
        await flushPromises();

        await wrapper.findAllComponents(KonvaImage)[1].vm.$emit('dragend', { target: { x: () => 2_500, y: () => -30 } });

        expect(wrapper.emitted('move-entry')).toEqual([[
            { ...entries[0], position_x: 1, position_y: 0 },
        ]]);
    });
});
