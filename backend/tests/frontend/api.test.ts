import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, apiForm, ApiError } from '../../resources/shared/api';

describe('shared API client', () => {
    afterEach(() => {
        document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
        vi.unstubAllGlobals();
    });

    it('uses same-origin credentials and attaches the decoded CSRF token to JSON mutations', async () => {
        document.cookie = 'XSRF-TOKEN=encoded%20token; Path=/';
        const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'campaign-1' } }), { status: 201 }));
        vi.stubGlobal('fetch', fetch);

        await expect(api<{ data: { id: string } }>('/api/control/v1/campaigns', { method: 'POST', body: JSON.stringify({ name: 'Nightwatch' }) }))
            .resolves.toEqual({ data: { id: 'campaign-1' } });

        const [, init] = fetch.mock.calls[0];
        const headers = new Headers(init.headers);
        expect(init.credentials).toBe('same-origin');
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('X-XSRF-TOKEN')).toBe('encoded token');
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

        await expect(api('/api/control/v1/campaigns/campaign-1', { method: 'PATCH', body: '{}' }))
            .rejects.toEqual(expect.objectContaining({ message: 'Revision conflict', status: 409 } satisfies Partial<ApiError>));
    });
});
