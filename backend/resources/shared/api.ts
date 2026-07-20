import createClient from 'openapi-fetch';
import type { components, paths } from './generated/api';

export class ApiError extends Error {
    constructor(message: string, public readonly status: number) {
        super(message);
    }
}

function csrfToken(): string | null {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? null : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

const contractFetch: typeof fetch = async (input, init = {}) => {
    const request = input instanceof Request ? input : new Request(input, init);
    const headers = new Headers(request.headers);
    headers.set('Accept', 'application/json');
    if (!['GET', 'HEAD'].includes(request.method)) {
        headers.set('Content-Type', 'application/json');
        const token = csrfToken();
        if (token) headers.set('X-XSRF-TOKEN', token);
    }

    return fetch(request, { credentials: 'same-origin', headers });
};

export const contractApi = createClient<paths>({ baseUrl: '', fetch: contractFetch });
export type ControlAuthenticationResponse = components['schemas']['ControlAuthenticationResponse'];

export async function loginWithControlSecret(secret: string): Promise<ControlAuthenticationResponse> {
    const { data, error, response } = await contractApi.POST('/api/control/v1/auth/login', { body: { secret } });
    if (!response.ok || data === undefined) {
        const message = typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string' ? error.message : 'The request could not be completed.';
        throw new ApiError(message, response.status);
    }

    return data;
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body !== undefined) {
        headers.set('Content-Type', 'application/json');
        const token = csrfToken();
        if (token) headers.set('X-XSRF-TOKEN', token);
    }

    const response = await fetch(path, { ...init, credentials: 'same-origin', headers });
    if (response.status === 204) return undefined as T;

    const body = await response.json() as T & { message?: string };
    if (!response.ok) throw new ApiError(body.message ?? 'The request could not be completed.', response.status);
    return body;
}

export async function apiForm<T>(path: string, form: FormData): Promise<T> {
    const headers = new Headers({ Accept: 'application/json' });
    const token = csrfToken();
    if (token) headers.set('X-XSRF-TOKEN', token);

    const response = await fetch(path, { method: 'POST', body: form, credentials: 'same-origin', headers });
    const body = await response.json() as T & { message?: string };
    if (!response.ok) throw new ApiError(body.message ?? 'The request could not be completed.', response.status);

    return body;
}
