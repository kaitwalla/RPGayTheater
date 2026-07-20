import { afterEach, describe, expect, it, vi } from 'vitest';
const apiTestState = vi.hoisted(() => ({ post: vi.fn(), contractFetch: undefined as typeof fetch | undefined }));

vi.mock('openapi-fetch', () => ({
    default: vi.fn(({ fetch }: { fetch: typeof globalThis.fetch }) => {
        apiTestState.contractFetch = fetch;

        return { POST: apiTestState.post };
    }),
}));

import { api, apiForm, loginWithControlSecret } from '../../resources/shared/api';
import type { ApiError } from '../../resources/shared/api';

describe('shared API client', () => {
    afterEach(() => {
        document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
        vi.unstubAllGlobals();
        apiTestState.post.mockReset();
    });

    it('uses same-origin credentials and attaches the decoded CSRF token to JSON mutations', async () => {
        document.cookie = 'XSRF-TOKEN=encoded%20token; Path=/';
        const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'campaign-1' } }), { status: 201 }));
        vi.stubGlobal('fetch', fetch);

        await expect(
            api<{ data: { id: string } }>('/api/control/v1/campaigns', { method: 'POST', body: JSON.stringify({ name: 'Nightwatch' }) }),
        ).resolves.toEqual({ data: { id: 'campaign-1' } });

        const [, init] = fetch.mock.calls[0];
        const headers = new Headers(init.headers);
        expect(init.credentials).toBe('same-origin');
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('X-XSRF-TOKEN')).toBe('encoded token');
    });

    it('normalizes generated contract-client mutation headers and request credentials', async () => {
        document.cookie = 'XSRF-TOKEN=contract-token; Path=/';
        const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ authenticated: true }), { status: 200 }));
        vi.stubGlobal('fetch', fetch);

        await apiTestState.contractFetch?.(new Request('https://rpgays.test/api/control/v1/auth/login', { method: 'POST' }));

        const [request, init] = fetch.mock.calls[0];
        const headers = new Headers(init.headers);
        expect(request).toBeInstanceOf(Request);
        expect(init.credentials).toBe('same-origin');
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('X-XSRF-TOKEN')).toBe('contract-token');
    });

    it('preserves multipart browser headers while protecting uploads with the CSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=upload-token; Path=/';
        const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'asset-1' } }), { status: 201 }));
        vi.stubGlobal('fetch', fetch);
        const form = new FormData();
        form.set('package', new Blob(['campaign']), 'campaign.zip');

        await apiForm('/api/control/v1/campaigns/import', form);

        const [, init] = fetch.mock.calls[0];
        const headers = new Headers(init.headers);
        expect(init.body).toBe(form);
        expect(headers.get('Content-Type')).toBeNull();
        expect(headers.get('X-XSRF-TOKEN')).toBe('upload-token');
    });

    it('turns failed API responses into status-aware errors', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Revision conflict' }), { status: 409 })));

        await expect(api('/api/control/v1/campaigns/campaign-1', { method: 'PATCH', body: '{}' })).rejects.toEqual(
            expect.objectContaining({ message: 'Revision conflict', status: 409 } satisfies Partial<ApiError>),
        );
    });

    it('does not attach mutation headers to safe reads and supports empty successful responses', async () => {
        document.cookie = 'XSRF-TOKEN=should-not-be-sent; Path=/';
        const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
        vi.stubGlobal('fetch', fetch);

        await expect(api('/api/control/v1/campaigns')).resolves.toBeUndefined();

        const [, init] = fetch.mock.calls[0];
        const headers = new Headers(init.headers);
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBeNull();
        expect(headers.get('X-XSRF-TOKEN')).toBeNull();
    });

    it('defaults missing API failure messages', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 500 }))
            .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 422 }));
        vi.stubGlobal('fetch', fetch);

        await expect(api('/api/control/v1/campaigns', { method: 'POST', body: '{}' })).rejects.toEqual(
            expect.objectContaining({ message: 'The request could not be completed.', status: 500 }),
        );
        await expect(apiForm('/api/control/v1/campaigns/import', new FormData())).rejects.toEqual(
            expect.objectContaining({ message: 'The request could not be completed.', status: 422 }),
        );
    });

    it('returns typed Control authentication data and preserves actionable contract errors', async () => {
        apiTestState.post.mockResolvedValueOnce({ data: { authenticated: true }, error: undefined, response: { ok: true, status: 200 } });
        apiTestState.post.mockResolvedValueOnce({ data: undefined, error: { message: 'Secret rejected' }, response: { ok: false, status: 401 } });
        apiTestState.post.mockResolvedValueOnce({ data: undefined, error: {}, response: { ok: false, status: 500 } });

        await expect(loginWithControlSecret('good-secret')).resolves.toEqual({ authenticated: true });
        expect(apiTestState.post).toHaveBeenCalledWith('/api/control/v1/auth/login', { body: { secret: 'good-secret' } });
        await expect(loginWithControlSecret('bad-secret')).rejects.toEqual(expect.objectContaining({ message: 'Secret rejected', status: 401 }));
        await expect(loginWithControlSecret('broken-secret')).rejects.toEqual(
            expect.objectContaining({ message: 'The request could not be completed.', status: 500 }),
        );
    });
});
