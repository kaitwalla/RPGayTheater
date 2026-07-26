import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { DiceRollVisual } from '../../resources/shared/dice-roll-visual';

describe('DiceRollVisual', () => {
    it('renders every die in a nested result, including dropped dice', () => {
        const wrapper = mount(DiceRollVisual, {
            props: {
                label: 'Mara roll',
                total: 21,
                breakdown: {
                    type: 'add',
                    left: {
                        type: 'dice',
                        sides: 6,
                        dice: [
                            { value: 6, kept: true },
                            { value: 2, kept: false },
                        ],
                    },
                    right: { type: 'dice', sides: 20, dice: [{ value: 13, kept: true }] },
                },
            },
        });

        expect(wrapper.attributes('aria-label')).toBe('Mara roll: 21');
        expect(wrapper.findAll('.dice-roll-die')).toHaveLength(3);
        expect(wrapper.findAll('.dice-roll-die.dropped')).toHaveLength(1);
        expect(wrapper.findAll('.dice-roll-die.d6 .pip')).toHaveLength(8);
        expect(wrapper.get('.dice-roll-die.d20').text()).toContain('13');
        expect(wrapper.get('.dice-roll-total').text()).toBe('21');
    });
});
