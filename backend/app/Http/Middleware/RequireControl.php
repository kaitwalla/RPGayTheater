<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireControl
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->session()->get('control.authenticated') === true, 401, 'Control authentication is required.');

        return $next($request);
    }
}
