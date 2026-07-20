import { computed, defineComponent, ref, type PropType } from 'vue';
import { useImage } from 'vue-konva';
import { normalizedPoint, sampleBrushStroke, translateTokens, type BrushPoint, type MapPoint, type StageToken } from './map-stage';

export type InteractiveToken = StageToken & { label: string | null; scale: number };
type FogState = { default_visibility: 'hidden' | 'revealed'; brushes: Array<{ id: string; mode: 'reveal' | 'hide'; center_x: number; center_y: number; radius: number }> };

export const ControlMapStage = defineComponent({
    props: {
        imageUrl: { type: String, default: '' },
        tokens: { type: Array as PropType<InteractiveToken[]>, required: true },
        fog: { type: Object as PropType<FogState>, required: true },
        brushMode: { type: String as PropType<'reveal' | 'hide'>, required: true },
        brushRadius: { type: Number, required: true },
        interactionMode: { type: String as PropType<'tokens' | 'fog'>, required: true },
        disabled: { type: Boolean, default: false },
    },
    emits: ['brush-stroke', 'move-tokens'],
    setup(props, { emit }) {
        const width = 960; const height = 540; const selected = ref(new Set<string>()); const origin = ref<MapPoint | null>(null); const marquee = ref<{ x: number; y: number; width: number; height: number } | null>(null); const dragged = ref<InteractiveToken | null>(null); const stroke = ref<MapPoint[]>([]); const [image] = useImage(() => props.imageUrl);
        const stage = computed(() => ({ width, height }));
        const tokenConfig = (token: InteractiveToken) => ({ x: token.position_x * width, y: token.position_y * height, radius: 18 * token.scale, fill: selected.value.has(token.source_token_id) ? '#e3c1ff' : '#7c5ce0', stroke: '#fff', strokeWidth: 2, draggable: props.interactionMode === 'tokens' && !props.disabled });
        const fogBrushConfig = (brush: FogState['brushes'][number]) => ({ x: brush.center_x * width, y: brush.center_y * height, radius: brush.radius * Math.min(width, height), fill: 'rgba(8, 11, 19, .68)', globalCompositeOperation: brush.mode === 'reveal' ? 'destination-out' : 'source-over' });
        const previewBrushConfig = (point: MapPoint) => ({ x: point.x * width, y: point.y * height, radius: props.brushRadius * Math.min(width, height), fill: props.brushMode === 'reveal' ? 'rgba(179, 229, 255, .42)' : 'rgba(8, 11, 19, .42)' });
        const select = (token: InteractiveToken, additive: boolean): void => { if (props.interactionMode !== 'tokens' || props.disabled) return; const next = additive ? new Set(selected.value) : new Set<string>(); if (next.has(token.source_token_id) && additive) next.delete(token.source_token_id); else next.add(token.source_token_id); selected.value = next; };
        const tokenTap = (token: InteractiveToken, event: { evt: MouseEvent }): void => select(token, event.evt.shiftKey || event.evt.metaKey || event.evt.ctrlKey);
        const dragStart = (token: InteractiveToken): void => { if (props.interactionMode !== 'tokens' || props.disabled) return; if (!selected.value.has(token.source_token_id)) selected.value = new Set([token.source_token_id]); dragged.value = token; };
        const dragEnd = (event: { target: { x: () => number; y: () => number } }): void => { if (!dragged.value) return; const point = normalizedPoint({ x: event.target.x(), y: event.target.y() }, width, height); const delta = { x: point.x - dragged.value.position_x, y: point.y - dragged.value.position_y }; emit('move-tokens', translateTokens(props.tokens, selected.value, delta)); dragged.value = null; };
        const pointFor = (event: { target: { getStage: () => { getPointerPosition: () => MapPoint | null } } }): MapPoint | null => event.target.getStage().getPointerPosition();
        const pointerDown = (event: { target: { getStage: () => { getPointerPosition: () => MapPoint | null } } }): void => { if (props.disabled) return; const point = pointFor(event); if (!point) return; if (props.interactionMode === 'fog') { stroke.value = [normalizedPoint(point, width, height)]; return; } origin.value = point; marquee.value = { x: point.x, y: point.y, width: 0, height: 0 }; };
        const pointerMove = (event: { target: { getStage: () => { getPointerPosition: () => MapPoint | null } } }): void => { const point = pointFor(event); if (!point || props.disabled) return; if (props.interactionMode === 'fog') { if (stroke.value.length > 0) stroke.value = [...stroke.value, normalizedPoint(point, width, height)]; return; } if (!origin.value) return; marquee.value = { x: Math.min(origin.value.x, point.x), y: Math.min(origin.value.y, point.y), width: Math.abs(point.x - origin.value.x), height: Math.abs(point.y - origin.value.y) }; };
        const pointerUp = (event: { target: { getStage: () => { getPointerPosition: () => MapPoint | null } } }): void => { if (props.interactionMode === 'fog') { const point = pointFor(event); if (point) stroke.value = [...stroke.value, normalizedPoint(point, width, height)]; const brushes: BrushPoint[] = sampleBrushStroke(stroke.value, props.brushRadius).map((sample) => ({ ...sample, mode: props.brushMode, radius: props.brushRadius })); if (brushes.length > 0) emit('brush-stroke', brushes); stroke.value = []; return; } const point = pointFor(event); const box = marquee.value; if (!point || !origin.value || !box) return; if (box.width >= 4 || box.height >= 4) selected.value = new Set(props.tokens.filter((token) => token.position_x * width >= box.x && token.position_x * width <= box.x + box.width && token.position_y * height >= box.y && token.position_y * height <= box.y + box.height).map((token) => token.source_token_id)); origin.value = null; marquee.value = null; };
        return { stage, image, tokenConfig, fogBrushConfig, previewBrushConfig, tokenTap, dragStart, dragEnd, pointerDown, pointerMove, pointerUp, marquee, stroke, width, height };
    },
    template: `<div class="control-map-stage" role="application" :aria-label="interactionMode === 'fog' ? 'Interactive Control map fog brush' : 'Interactive Control map token editor'"><v-stage :config="stage" @mousedown="pointerDown" @mousemove="pointerMove" @mouseup="pointerUp" @touchstart="pointerDown" @touchmove="pointerMove" @touchend="pointerUp"><v-layer><v-image v-if="image" :config="{ image, width, height }" /><v-rect v-else :config="{ width, height, fill: '#111725' }" /><v-rect v-if="fog.default_visibility === 'hidden'" :config="{ width, height, fill: 'rgba(8, 11, 19, .68)' }" /><v-circle v-for="brush in fog.brushes" :key="brush.id" :config="fogBrushConfig(brush)" /><v-circle v-for="(point, index) in stroke" :key="'preview-' + index" :config="previewBrushConfig(point)" /><v-circle v-for="token in tokens" :key="token.source_token_id" :config="tokenConfig(token)" @click.stop="tokenTap(token, $event)" @dragstart="dragStart(token)" @dragend="dragEnd($event)" /><v-rect v-if="marquee" :config="{ ...marquee, stroke: '#e3c1ff', dash: [6, 4] }" /></v-layer></v-stage></div>`,
});
