export type MapPoint = { x: number; y: number };

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
