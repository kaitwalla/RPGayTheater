export class ApiError extends Error {
    constructor(message: string, public readonly status: number) {
        super(message);
    }
}

function csrfToken(): string | null {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body !== undefined) {
        headers.set('Content-Type', 'application/json');
        const token = csrfToken();
        if (token) headers.set('X-CSRF-TOKEN', token);
    }

    const response = await fetch(path, { ...init, credentials: 'same-origin', headers });
    if (response.status === 204) return undefined as T;

    const body = await response.json() as T & { message?: string };
    if (!response.ok) throw new ApiError(body.message ?? 'The request could not be completed.', response.status);
    return body;
}
