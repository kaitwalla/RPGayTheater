<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlPasskeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Control authentication is required.');

        $passkeys = $user->passkeys()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn ($passkey): array => [
                'id' => (string) $passkey->id,
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                'created_at' => $passkey->created_at?->toIso8601String() ?? '',
            ]);

        return response()->json(['data' => $passkeys]);
    }
}
