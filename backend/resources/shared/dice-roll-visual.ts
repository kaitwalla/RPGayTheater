import { computed, defineComponent, type PropType } from 'vue';

type Die = { sides: number; value: number; kept: boolean };

const findDice = (value: unknown): Die[] => {
    if (Array.isArray(value)) return value.flatMap(findDice);
    if (value === null || typeof value !== 'object') return [];
    const record = value as Record<string, unknown>;
    if (record.type === 'dice' && Array.isArray(record.dice)) {
        const sides = typeof record.sides === 'number' ? record.sides : 6;

        return record.dice.flatMap((die) => {
            if (die === null || typeof die !== 'object') return [];
            const candidate = die as Record<string, unknown>;
            if (typeof candidate.value !== 'number') return [];

            return [{ sides, value: candidate.value, kept: candidate.kept !== false }];
        });
    }

    return Object.values(record).flatMap(findDice);
};

export const DiceRollVisual = defineComponent({
    props: {
        breakdown: { type: Object as PropType<Record<string, unknown>>, required: true },
        total: { type: Number, required: true },
        label: { type: String, default: 'Roll result' },
    },
    setup(props) {
        const dice = computed(() => findDice(props.breakdown));
        const dieClass = (die: Die): string => (die.sides === 6 ? 'd6' : die.sides === 20 ? 'd20' : 'polyhedral');
        const pips = (die: Die): string[] =>
            die.sides !== 6
                ? []
                : [
                      ['center'],
                      ['top-left', 'bottom-right'],
                      ['top-left', 'center', 'bottom-right'],
                      ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
                      ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right'],
                      ['top-left', 'top-right', 'middle-left', 'middle-right', 'bottom-left', 'bottom-right'],
                  ][Math.max(1, Math.min(6, die.value)) - 1];

        return { dice, dieClass, pips };
    },
    template:
        '<section class="dice-roll-visual" :aria-label="label + \': \' + total"><div v-if="dice.length" class="dice-roll-dice"><span v-for="(die, index) in dice" :key="index" class="dice-roll-die" :class="[dieClass(die), { dropped: !die.kept }]" :style="{ animationDelay: index * 70 + \'ms\' }"><template v-if="die.sides === 6"><i v-for="pip in pips(die)" :key="pip" :class="\'pip pip-\' + pip"></i></template><strong v-else>{{ die.value }}</strong><small>d{{ die.sides }}</small></span></div><span v-else class="dice-roll-expression">No dice</span><strong class="dice-roll-total">{{ total }}</strong></section>',
});
