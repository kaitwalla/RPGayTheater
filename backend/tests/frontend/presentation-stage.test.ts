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

    afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

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

    it('fades between preloaded backdrops and removes the outgoing image when complete', async () => {
        const frames: FrameRequestCallback[] = [];
        vi.stubGlobal('requestAnimationFrame', vi.fn((callback: FrameRequestCallback) => { frames.push(callback); return frames.length; }));
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
        vi.spyOn(performance, 'now').mockReturnValue(1_000);
        const wrapper = mount(PresentationStage, {
            props: {
                backdropAssetId: 'backdrop-a', transition: 'fade_black', transitionDurationMs: 100,
                entries: [], assetUrls: { 'backdrop-a': '/a.png', 'backdrop-b': '/b.png' },
            },
            global: { stubs: { 'v-stage': { template: '<div><slot /></div>' }, 'v-layer': { template: '<div><slot /></div>' }, 'v-rect': true, 'v-image': KonvaImage } },
        });
        await flushPromises();

        await wrapper.setProps({ backdropAssetId: 'backdrop-b' });
        frames.shift()?.(1_050);
        await wrapper.vm.$nextTick();
        expect(wrapper.findAllComponents(KonvaImage).map((image) => image.props('config'))).toEqual(expect.arrayContaining([
            expect.objectContaining({ width: 1920, height: 1080, opacity: 0 }),
            expect.objectContaining({ width: 1920, height: 1080, opacity: 0 }),
        ]));

        frames.shift()?.(1_100);
        await wrapper.vm.$nextTick();
        expect(wrapper.findAllComponents(KonvaImage).map((image) => image.props('config')).filter((config) => config.width === 1920)).toEqual([
            expect.objectContaining({ opacity: 1 }),
        ]);
    });

    it('interpolates stage entry presets with the configured easing duration', async () => {
        const frames: FrameRequestCallback[] = [];
        vi.stubGlobal('requestAnimationFrame', vi.fn((callback: FrameRequestCallback) => { frames.push(callback); return frames.length; }));
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
        vi.spyOn(performance, 'now').mockReturnValue(2_000);
        const wrapper = mount(PresentationStage, {
            props: { entries: [entries[0]], assetUrls: { 'front-asset': '/front.png' }, stageTweenDurationMs: 100 },
            global: { stubs: { 'v-stage': { template: '<div><slot /></div>' }, 'v-layer': { template: '<div><slot /></div>' }, 'v-rect': true, 'v-image': KonvaImage } },
        });
        await flushPromises();

        await wrapper.setProps({ entries: [{ ...entries[0], position_x: .4, position_y: .3, scale: .5 }] });
        frames.shift()?.(2_050);
        await wrapper.vm.$nextTick();
        const config = wrapper.findComponent(KonvaImage).props('config') as Record<string, number>;

        expect(config.x).toBeCloseTo(1_152);
        expect(config.y).toBeCloseTo(540);
        expect(config.width).toBeCloseTo(1_080);
        expect(config.height).toBeCloseTo(540);
    });
});
