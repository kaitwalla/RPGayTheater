const ASSET_PREFIXES = ['/build/assets/', '/build/fonts/'];

export function isPlayerShellPath(pathname) {
    return pathname === '/player' || pathname === '/player/';
}

export function isCacheablePlayerShellRequest(request, origin) {
    const url = new URL(request.url);

    return request.method === 'GET'
        && url.origin === origin
        && url.search === ''
        && (isPlayerShellPath(url.pathname) || ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix)));
}

export function isPlayerNavigationRequest(request) {
    return request.mode === 'navigate' && isPlayerShellPath(new URL(request.url).pathname);
}
