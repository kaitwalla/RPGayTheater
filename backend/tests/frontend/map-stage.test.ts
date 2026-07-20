import { render } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import { ControlMapStage } from '../../resources/shared/control-map-stage';
import { sampleBrushStroke } from '../../resources/shared/map-stage';

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
});
