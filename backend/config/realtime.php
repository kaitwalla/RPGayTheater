<?php

return [
    'max_event_bytes' => (int) env('REALTIME_MAX_EVENT_BYTES', 9_216),
    'dispatch_lease_seconds' => (int) env('REALTIME_DISPATCH_LEASE_SECONDS', 60),
    'client' => [
        'reverb' => [
            'key' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY')),
            'host' => env('VITE_REVERB_HOST'),
            'port' => env('VITE_REVERB_PORT'),
            'scheme' => env('VITE_REVERB_SCHEME'),
        ],
        'pusher' => [
            'key' => env('VITE_PUSHER_APP_KEY', env('PUSHER_APP_KEY')),
            'cluster' => env('VITE_PUSHER_APP_CLUSTER', env('PUSHER_APP_CLUSTER')),
        ],
    ],
];
