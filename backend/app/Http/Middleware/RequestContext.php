<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request->header('X-Request-Id'));
        $request->attributes->set('request_id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'request_method' => $request->method(),
            'request_path' => '/'.$request->path(),
        ]);

        try {
            $response = $next($request);
        } finally {
            Log::flushSharedContext();
        }

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function requestId(?string $provided): string
    {
        if ($provided !== null && preg_match('/\\A[a-zA-Z0-9._-]{1,128}\\z/', $provided) === 1) {
            return $provided;
        }

        return (string) Str::uuid7();
    }
}
