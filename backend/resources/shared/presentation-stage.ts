import { computed, defineComponent, onBeforeUnmount, onMounted, ref, watch, type PropType } from 'vue';

export type PresentationStageEntry = {
    npc_id: string;
    npc_state_id: string | null;
    name: string | null;
    asset_id: string | null;
    position_x: number;
    position_y: number;
    scale: number;
    layer_order: number;
    facing: 'left' | 'right' | null;
    native_facing: 'right';
};

const logicalWidth = 1920;
const logicalHeight = 1080;

export const PresentationStage = defineComponent({
    props: {
        backdropAssetId: { type: String, default: null },
        transition: { type: String as PropType<'cut' | 'fade_black' | 'cross_dissolve'>, default: 'cut' },
        transitionDurationMs: { type: Number, default: 0 },
        stageTweenDurationMs: { type: Number, default: 0 },
        stageTweenEasing: { type: String as PropType<'linear' | 'ease_in' | 'ease_out' | 'ease_in_out'>, default: 'linear' },
        editable: { type: Boolean, default: false },
        entries: { type: Array as PropType<PresentationStageEntry[]>, required: true },
        assetUrls: { type: Object as PropType<Record<string, string>>, required: true },
    },
    emits: ['move-entry'],
    setup(props, { emit }) {
        const root = ref<HTMLElement | null>(null);
        const viewportWidth = ref(logicalWidth);
        const images = ref<Record<string, HTMLImageElement>>({});
        const displayedEntries = ref<PresentationStageEntry[]>(props.entries);
        const displayedBackdropId = ref<string | null>(props.backdropAssetId);
        const outgoingBackdropId = ref<string | null>(null);
        const transitionProgress = ref(1);
        let observer: ResizeObserver | null = null;
        let transitionFrame: number | null = null;
        let stageTweenFrame: number | null = null;
        const stage = computed(() => {
            const scale = viewportWidth.value / logicalWidth;

            return { width: viewportWidth.value, height: logicalHeight * scale, scaleX: scale, scaleY: scale };
        });
        const backdrop = computed(() => (displayedBackdropId.value === null ? null : (images.value[displayedBackdropId.value] ?? null)));
        const outgoingBackdrop = computed(() => (outgoingBackdropId.value === null ? null : (images.value[outgoingBackdropId.value] ?? null)));
        const backdropOpacity = computed(() => (props.transition === 'fade_black' ? Math.max(0, transitionProgress.value * 2 - 1) : transitionProgress.value));
        const outgoingOpacity = computed(() =>
            props.transition === 'fade_black' ? Math.max(0, 1 - transitionProgress.value * 2) : 1 - transitionProgress.value,
        );
        const entryConfigs = computed(() =>
            displayedEntries.value
                .filter((entry) => entry.asset_id !== null && images.value[entry.asset_id] !== undefined)
                .sort((left, right) => left.layer_order - right.layer_order)
                .map((entry) => {
                    const image = images.value[entry.asset_id as string];
                    const height = 720 * entry.scale;
                    const width = (image.width / image.height) * height;
                    const flip = entry.facing === 'left';

                    return {
                        id: `${entry.npc_id}:${entry.npc_state_id ?? 'normal'}:${entry.layer_order}`,
                        config: {
                            image,
                            x: entry.position_x * logicalWidth,
                            y: entry.position_y * logicalHeight,
                            width,
                            height,
                            offsetX: width / 2,
                            offsetY: height,
                            scaleX: flip ? -1 : 1,
                            draggable: props.editable,
                        },
                        entry,
                    };
                }),
        );
        const dragEnd = (entry: PresentationStageEntry, event: { target: { x: () => number; y: () => number } }): void => {
            emit('move-entry', {
                ...entry,
                position_x: Math.min(1, Math.max(0, event.target.x() / logicalWidth)),
                position_y: Math.min(1, Math.max(0, event.target.y() / logicalHeight)),
            });
        };
        const preload = async (): Promise<void> => {
            const next = { ...images.value };
            await Promise.all(
                Object.entries(props.assetUrls).map(async ([assetId, url]) => {
                    if (next[assetId]?.src === url) return;
                    const image = new Image();
                    await new Promise<void>((resolve, reject) => {
                        image.onload = () => resolve();
                        image.onerror = () => reject(new Error(`Unable to decode presentation asset ${assetId}.`));
                        image.src = url;
                    });
                    next[assetId] = image;
                }),
            );
            images.value = next;
        };
        watch(
            () => props.assetUrls,
            () => {
                void preload();
            },
            { deep: true, immediate: true },
        );
        watch(
            () => props.backdropAssetId,
            (next) => {
                if (next === displayedBackdropId.value) return;
                if (transitionFrame !== null) cancelAnimationFrame(transitionFrame);
                outgoingBackdropId.value = displayedBackdropId.value;
                displayedBackdropId.value = next;
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const duration = reducedMotion ? 0 : props.transitionDurationMs;
                if (props.transition === 'cut' || duration <= 0 || outgoingBackdropId.value === null || next === null) {
                    outgoingBackdropId.value = null;
                    transitionProgress.value = 1;

                    return;
                }
                transitionProgress.value = 0;
                const startedAt = performance.now();
                const animate = (now: number): void => {
                    transitionProgress.value = Math.min(1, (now - startedAt) / duration);
                    if (transitionProgress.value < 1) transitionFrame = requestAnimationFrame(animate);
                    else {
                        transitionFrame = null;
                        outgoingBackdropId.value = null;
                    }
                };
                transitionFrame = requestAnimationFrame(animate);
            },
        );
        const ease = (progress: number): number => {
            if (props.stageTweenEasing === 'ease_in') return progress * progress;
            if (props.stageTweenEasing === 'ease_out') return 1 - (1 - progress) * (1 - progress);
            if (props.stageTweenEasing === 'ease_in_out') return progress < 0.5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2;

            return progress;
        };
        const entryId = (entry: PresentationStageEntry): string => `${entry.npc_id}:${entry.layer_order}`;
        watch(
            () => props.entries,
            (next) => {
                if (stageTweenFrame !== null) cancelAnimationFrame(stageTweenFrame);
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const duration = reducedMotion ? 0 : props.stageTweenDurationMs;
                if (duration <= 0 || displayedEntries.value.length === 0 || next.length === 0) {
                    displayedEntries.value = next;

                    return;
                }
                const current = new Map(displayedEntries.value.map((entry) => [entryId(entry), entry]));
                const startedAt = performance.now();
                const animate = (now: number): void => {
                    const amount = ease(Math.min(1, (now - startedAt) / duration));
                    displayedEntries.value = next.map((entry) => {
                        const previous = current.get(entryId(entry));
                        if (!previous) return entry;

                        return {
                            ...entry,
                            position_x: previous.position_x + (entry.position_x - previous.position_x) * amount,
                            position_y: previous.position_y + (entry.position_y - previous.position_y) * amount,
                            scale: previous.scale + (entry.scale - previous.scale) * amount,
                        };
                    });
                    if (amount < 1) stageTweenFrame = requestAnimationFrame(animate);
                    else stageTweenFrame = null;
                };
                stageTweenFrame = requestAnimationFrame(animate);
            },
        );
        onMounted(() => {
            observer = new ResizeObserver(([entry]) => {
                viewportWidth.value = Math.max(1, entry.contentRect.width);
            });
            if (root.value) observer.observe(root.value);
        });
        onBeforeUnmount(() => {
            observer?.disconnect();
            if (transitionFrame !== null) cancelAnimationFrame(transitionFrame);
            if (stageTweenFrame !== null) cancelAnimationFrame(stageTweenFrame);
        });

        return { root, stage, backdrop, outgoingBackdrop, backdropOpacity, outgoingOpacity, entryConfigs, dragEnd, logicalWidth, logicalHeight };
    },
    template: `<div ref="root" class="presentation-stage" :class="{ 'presentation-stage-editable': editable }" :aria-label="editable ? 'Editable live presentation stage' : 'Live presentation stage'"><v-stage :config="stage"><v-layer><v-rect :config="{ width: logicalWidth, height: logicalHeight, fill: '#05070d' }" /><v-image v-if="outgoingBackdrop" :config="{ image: outgoingBackdrop, width: logicalWidth, height: logicalHeight, opacity: outgoingOpacity }" /><v-image v-if="backdrop" :config="{ image: backdrop, width: logicalWidth, height: logicalHeight, opacity: backdropOpacity }" /><v-image v-for="entry in entryConfigs" :key="entry.id" :config="entry.config" @dragend="dragEnd(entry.entry, $event)" /></v-layer></v-stage></div>`,
});
