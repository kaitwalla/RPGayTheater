<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentControlSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('control.secret_confirmed_at', 0);
        $expiresAt = $confirmedAt + (int) config('control.secret_confirmation_seconds');

        abort_if($confirmedAt === 0 || $expiresAt < now()->unix(), 403, 'Re-enter the Control secret before changing passkeys.');

        return $next($request);
    }
}
