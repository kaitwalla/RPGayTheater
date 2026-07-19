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
    native_facing: 'left' | 'right';
};

const logicalWidth = 1920;
const logicalHeight = 1080;

export const PresentationStage = defineComponent({
    props: {
        backdropAssetId: { type: String, default: null },
        entries: { type: Array as PropType<PresentationStageEntry[]>, required: true },
        assetUrls: { type: Object as PropType<Record<string, string>>, required: true },
    },
    setup(props) {
        const root = ref<HTMLElement | null>(null);
        const viewportWidth = ref(logicalWidth);
        const images = ref<Record<string, HTMLImageElement>>({});
        let observer: ResizeObserver | null = null;
        const stage = computed(() => {
            const scale = viewportWidth.value / logicalWidth;

            return { width: viewportWidth.value, height: logicalHeight * scale, scaleX: scale, scaleY: scale };
        });
        const backdrop = computed(() => props.backdropAssetId === null ? null : images.value[props.backdropAssetId] ?? null);
        const entryConfigs = computed(() => props.entries
            .filter((entry) => entry.asset_id !== null && images.value[entry.asset_id] !== undefined)
            .sort((left, right) => left.layer_order - right.layer_order)
            .map((entry) => {
                const image = images.value[entry.asset_id as string];
                const height = 720 * entry.scale;
                const width = image.width / image.height * height;
                const flip = entry.facing !== null && entry.facing !== entry.native_facing;

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
                    },
                };
            }));
        const preload = async (): Promise<void> => {
            const next = { ...images.value };
            await Promise.all(Object.entries(props.assetUrls).map(async ([assetId, url]) => {
                if (next[assetId]?.src === url) return;
                const image = new Image();
                await new Promise<void>((resolve, reject) => { image.onload = () => resolve(); image.onerror = () => reject(new Error(`Unable to decode presentation asset ${assetId}.`)); image.src = url; });
                next[assetId] = image;
            }));
            images.value = next;
        };
        watch(() => props.assetUrls, () => { void preload(); }, { deep: true, immediate: true });
        onMounted(() => {
            observer = new ResizeObserver(([entry]) => { viewportWidth.value = Math.max(1, entry.contentRect.width); });
            if (root.value) observer.observe(root.value);
        });
        onBeforeUnmount(() => observer?.disconnect());

        return { root, stage, backdrop, entryConfigs, logicalWidth, logicalHeight };
    },
    template: `<div ref="root" class="presentation-stage" aria-label="Live presentation stage"><v-stage :config="stage"><v-layer><v-rect :config="{ width: logicalWidth, height: logicalHeight, fill: '#05070d' }" /><v-image v-if="backdrop" :config="{ image: backdrop, width: logicalWidth, height: logicalHeight }" /><v-image v-for="entry in entryConfigs" :key="entry.id" :config="entry.config" /></v-layer></v-stage></div>`,
});
