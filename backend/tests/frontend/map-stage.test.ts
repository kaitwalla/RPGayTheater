import { render } from '@testing-library/vue';
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
