export type MapPoint = { x: number; y: number };

export type BrushPoint = MapPoint & { mode: 'reveal' | 'hide'; radius: number };

export type StageToken = {
    source_token_id: string;
    position_x: number;
    position_y: number;
};

export function clampUnit(value: number): number {
    return Math.min(1, Math.max(0, value));
}

export function normalizedPoint(point: MapPoint, width: number, height: number): MapPoint {
    return { x: clampUnit(point.x / width), y: clampUnit(point.y / height) };
}

export function translateTokens<T extends StageToken>(tokens: T[], selectedIds: Set<string>, delta: MapPoint): T[] {
    return tokens.map((token) => selectedIds.has(token.source_token_id)
        ? { ...token, position_x: clampUnit(token.position_x + delta.x), position_y: clampUnit(token.position_y + delta.y) }
        : token);
}

export function sampleBrushStroke(points: MapPoint[], radius: number): MapPoint[] {
    if (points.length === 0) return [];

    const minimumDistance = Math.max(.005, radius / 2);
    const sampled = [points[0]];

    points.slice(1).forEach((point) => {
        const previous = sampled.at(-1);
        if (previous === undefined) return;
        const x = point.x - previous.x;
        const y = point.y - previous.y;
        if (Math.hypot(x, y) >= minimumDistance) sampled.push(point);
    });

    const finalPoint = points.at(-1);
    const previous = sampled.at(-1);
    if (finalPoint !== undefined && previous !== undefined && (finalPoint.x !== previous.x || finalPoint.y !== previous.y)) sampled.push(finalPoint);

    return sampled;
}
