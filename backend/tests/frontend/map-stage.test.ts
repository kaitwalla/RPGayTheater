import { render } from '@testing-library/vue';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { describe, expect, it } from 'vitest';
import { ControlMapStage } from '../../resources/shared/control-map-stage';
import { normalizedPoint, sampleBrushStroke, translateTokens } from '../../resources/shared/map-stage';

describe('map geometry', () => {
    it('normalizes stage coordinates and clamps points outside the stage', () => {
        expect(normalizedPoint({ x: 480, y: 270 }, 960, 540)).toEqual({ x: .5, y: .5 });
        expect(normalizedPoint({ x: -12, y: 600 }, 960, 540)).toEqual({ x: 0, y: 1 });
    });

    it('moves only the selected tokens and keeps their normalized positions in bounds', () => {
        const tokens = [
            { source_token_id: 'left', position_x: .1, position_y: .2 },
            { source_token_id: 'right', position_x: .9, position_y: .8 },
        ];

        const moved = translateTokens(tokens, new Set(['left', 'right']), { x: .25, y: -.5 });

        expect(moved[0]).toEqual({ source_token_id: 'left', position_x: .35, position_y: 0 });
        expect(moved[1].position_x).toBe(1);
        expect(moved[1].position_y).toBeCloseTo(.3);
        expect(translateTokens(tokens, new Set(['left']), { x: .25, y: 0 })[1]).toBe(tokens[1]);
    });
});

describe('sampleBrushStroke', () => {
    it('keeps the stroke endpoints while coalescing crowded pointer samples', () => {
        expect(sampleBrushStroke([
            { x: .1, y: .1 },
            { x: .11, y: .1 },
            { x: .2, y: .1 },
            { x: .21, y: .1 },
        ], .1)).toEqual([
            { x: .1, y: .1 },
            { x: .2, y: .1 },
            { x: .21, y: .1 },
        ]);
    });
});

describe('ControlMapStage', () => {
    const Circle = defineComponent({ props: ['config'], template: '<div class="circle" />' });
    const Stage = defineComponent({ props: ['config'], template: '<div><slot /></div>' });
    const stageOptions = {
        global: {
            stubs: {
                'v-stage': Stage,
                'v-layer': { template: '<div><slot /></div>' },
                'v-image': true,
                'v-rect': true,
                'v-circle': Circle,
            },
        },
    };

    it('exposes an accessible fog-painting mode', () => {
        const screen = render(ControlMapStage, {
            props: {
                tokens: [],
                fog: { default_visibility: 'hidden', brushes: [] },
                brushMode: 'reveal',
                brushRadius: .1,
                interactionMode: 'fog',
            },
            global: {
                stubs: {
                    'v-stage': { template: '<div><slot /></div>' },
                    'v-layer': { template: '<div><slot /></div>' },
                    'v-image': true,
                    'v-rect': true,
                    'v-circle': true,
                },
            },
        });

        expect(screen.getByRole('application', { name: 'Interactive Control map fog brush' })).toBeTruthy();
    });

    it('moves a multi-selected token group through drag events', async () => {
        const wrapper = mount(ControlMapStage, {
            props: {
                tokens: [
                    { source_token_id: 'first', label: 'First', position_x: .2, position_y: .3, scale: 1 },
                    { source_token_id: 'second', label: 'Second', position_x: .4, position_y: .5, scale: 1 },
                ],
                fog: { default_visibility: 'revealed', brushes: [] },
                brushMode: 'reveal',
                brushRadius: .1,
                interactionMode: 'tokens',
            },
            ...stageOptions,
        });
        const circles = wrapper.findAllComponents(Circle);

        const click = (shiftKey: boolean) => ({
            stopPropagation: () => undefined,
            evt: { shiftKey, metaKey: false, ctrlKey: false },
        });

        await circles[0].vm.$emit('click', click(false));
        await circles[1].vm.$emit('click', click(true));
        await circles[0].vm.$emit('dragstart');
        await circles[0].vm.$emit('dragend', { target: { x: () => 288, y: () => 270 } });

        expect(wrapper.emitted('move-tokens')).toEqual([[
            [
                { source_token_id: 'first', label: 'First', position_x: .3, position_y: .5, scale: 1 },
                { source_token_id: 'second', label: 'Second', position_x: .5, position_y: .7, scale: 1 },
            ],
        ]]);
    });

    it('supports keyboard token selection and nudging', async () => {
        const wrapper = mount(ControlMapStage, {
            props: {
                tokens: [
                    { source_token_id: 'first', label: 'First', position_x: .2, position_y: .3, scale: 1 },
                    { source_token_id: 'second', label: 'Second', position_x: .4, position_y: .5, scale: 1 },
                ],
                fog: { default_visibility: 'revealed', brushes: [] },
                brushMode: 'reveal', brushRadius: .1, interactionMode: 'tokens',
            },
            ...stageOptions,
        });

        const stage = wrapper.get('[role="application"]');
        expect(stage.attributes('tabindex')).toBe('0');
        expect(stage.text()).toContain('Alt + arrow keys nudge selected tokens');

        await stage.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.get('[role="status"]').text()).toBe('Focused token: Second.');
        await stage.trigger('keydown', { key: ' ' });
        await stage.trigger('keydown', { key: 'ArrowRight', altKey: true });

        const moved = wrapper.emitted('move-tokens')?.[0]?.[0] as Array<{ source_token_id: string; position_x: number; position_y: number }>;
        expect(moved).toMatchObject([
            { source_token_id: 'first', position_x: .2, position_y: .3 },
            { source_token_id: 'second', position_y: .5 },
        ]);
        expect(moved[1].position_x).toBeCloseTo(.41);
    });

    it('samples an interactive fog pointer stroke before emitting brushes', async () => {
        const wrapper = mount(ControlMapStage, {
            props: { tokens: [], fog: { default_visibility: 'hidden', brushes: [] }, brushMode: 'reveal', brushRadius: .1, interactionMode: 'fog' },
            ...stageOptions,
        });
        const stage = wrapper.findComponent(Stage);
        const pointer = (x: number, y: number) => ({ target: { getStage: () => ({ getPointerPosition: () => ({ x, y }) }) } });

        await stage.vm.$emit('mousedown', pointer(96, 54));
        await stage.vm.$emit('mousemove', pointer(106, 54));
        await stage.vm.$emit('mousemove', pointer(192, 54));
        await stage.vm.$emit('mouseup', pointer(202, 54));

        expect(wrapper.emitted('brush-stroke')).toEqual([[
            [
                { x: .1, y: .1, mode: 'reveal', radius: .1 },
                { x: .2, y: .1, mode: 'reveal', radius: .1 },
                { x: 202 / 960, y: .1, mode: 'reveal', radius: .1 },
            ],
        ]]);
    });

    it('does not emit drag updates while disabled', async () => {
        const wrapper = mount(ControlMapStage, {
            props: { tokens: [{ source_token_id: 'first', label: 'First', position_x: .2, position_y: .3, scale: 1 }], fog: { default_visibility: 'revealed', brushes: [] }, brushMode: 'reveal', brushRadius: .1, interactionMode: 'tokens', disabled: true },
            ...stageOptions,
        });
        const circle = wrapper.findComponent(Circle);

        await circle.vm.$emit('dragstart');
        await circle.vm.$emit('dragend', { target: { x: () => 288, y: () => 270 } });

        expect(wrapper.emitted('move-tokens')).toBeUndefined();
    });
});
