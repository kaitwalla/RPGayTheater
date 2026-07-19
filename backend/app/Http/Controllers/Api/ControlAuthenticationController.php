<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlAuthenticationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['authenticated' => $request->session()->get('control.authenticated') === true]]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'max: 4096']]);
        $secret = (string) config('control.secret');
        abort_if($secret === '', 503, 'Control authentication is not configured.');
        abort_unless(hash_equals($secret, $validated['secret']), 422, 'The supplied control secret is invalid.');

        $request->session()->regenerate();
        $request->session()->put('control.authenticated', true);

        return response()->json(['data' => ['authenticated' => true]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }
}
