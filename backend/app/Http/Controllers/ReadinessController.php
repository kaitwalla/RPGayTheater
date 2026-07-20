<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        foreach ([
            'database' => static fn (): mixed => DB::select('select 1'),
            'cache' => static fn (): mixed => Cache::store()->get('__readiness_probe__'),
            'queue' => static fn (): mixed => Queue::connection()->size('realtime'),
            'storage' => static fn (): mixed => Storage::disk()->exists('__readiness_probe__'),
        ] as $name => $check) {
            try {
                $check();
                $checks[$name] = 'ok';
            } catch (Throwable) {
                $checks[$name] = 'unavailable';
            }
        }

        $ready = ! in_array('unavailable', $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'degraded', 'checks' => $checks], $ready ? 200 : 503);
    }
}
