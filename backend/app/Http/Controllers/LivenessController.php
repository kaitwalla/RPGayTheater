<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class LivenessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'alive']);
    }
}
