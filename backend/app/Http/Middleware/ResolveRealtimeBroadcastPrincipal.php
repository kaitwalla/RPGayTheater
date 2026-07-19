<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\RealtimeChannelAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRealtimeBroadcastPrincipal
{
    public function __construct(private readonly RealtimeChannelAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->setUserResolver(fn () => $this->authorizer->principal($request));

        return $next($request);
    }
}
