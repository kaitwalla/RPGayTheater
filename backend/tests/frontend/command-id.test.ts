import { afterEach, describe, expect, it, vi } from 'vitest';
import { commandId } from '../../resources/shared/command-id';

describe('commandId', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('uses the platform UUID when it is available', () => {
        vi.stubGlobal('crypto', { randomUUID: vi.fn().mockReturnValue('11111111-1111-4111-8111-111111111111') });

        expect(commandId()).toBe('11111111-1111-4111-8111-111111111111');
    });

    it('creates a valid version-four UUID when randomUUID is unavailable', () => {
        vi.stubGlobal('crypto', undefined);

        expect(commandId()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });

    it('uses crypto random values before falling back to Math.random', () => {
        const getRandomValues = vi.fn((bytes: Uint8Array) => bytes.fill(0xab));
        vi.stubGlobal('crypto', { getRandomValues });

        expect(commandId()).toBe('abababab-abab-4bab-abab-abababababab');
        expect(getRandomValues).toHaveBeenCalledOnce();
    });
});
