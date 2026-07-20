<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureResponseHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $scriptSources = ["'self'"];
        $connectSources = ["'self'", 'https:', 'wss:'];
        if (app()->environment('local')) {
            $scriptSources[] = "'unsafe-eval'";
            $scriptSources[] = 'http://localhost:5173';
            $scriptSources[] = 'http://127.0.0.1:5173';
            $connectSources[] = 'http://localhost:5173';
            $connectSources[] = 'http://127.0.0.1:5173';
            $connectSources[] = 'ws:';
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            'connect-src '.implode(' ', $connectSources),
            "font-src 'self' data: https:",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "object-src 'none'",
            'script-src '.implode(' ', $scriptSources),
            "style-src 'self' 'unsafe-inline' https:",
        ]);
    }
}
