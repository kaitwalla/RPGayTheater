<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ControlAuthenticationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['authenticated' => $request->user()?->email === config('control.user_email')]]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'max: 4096']]);
        $secret = (string) config('control.secret');
        abort_if($secret === '', 503, 'Control authentication is not configured.');
        abort_unless(hash_equals($secret, $validated['secret']), 422, 'The supplied control secret is invalid.');

        $user = User::query()->firstOrCreate(
            ['email' => (string) config('control.user_email')],
            [
                'name' => (string) config('control.user_name'),
                'password' => Hash::make(Str::random(64)),
            ],
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->markSecretConfirmed($request);

        return response()->json(['data' => ['authenticated' => true]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function confirmSecret(Request $request): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'max: 4096']]);
        $secret = (string) config('control.secret');
        abort_if($secret === '', 503, 'Control authentication is not configured.');
        abort_unless(hash_equals($secret, $validated['secret']), 422, 'The supplied control secret is invalid.');

        $this->markSecretConfirmed($request);

        return response()->json(['data' => ['confirmed_until' => now()->addSeconds((int) config('control.secret_confirmation_seconds'))->toIso8601String()]]);
    }

    private function markSecretConfirmed(Request $request): void
    {
        $request->session()->put('control.secret_confirmed_at', now()->unix());
    }
}
