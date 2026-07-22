<?php

declare(strict_types=1);

return [
    'disk' => env('ASSET_DISK', env('FILESYSTEM_DISK', 'local')),
    // The backend can use an internal Docker hostname while browsers need a
    // hostname that is reachable from the user's machine for presigned URLs.
    'public_s3_endpoint' => env('ASSET_PUBLIC_S3_ENDPOINT'),
    'signed_url_minutes' => (int) env('ASSET_SIGNED_URL_MINUTES', 10),
    'part_size_bytes' => (int) env('ASSET_PART_SIZE_BYTES', 8 * 1024 * 1024),
    'limits' => [
        'image' => (int) env('ASSET_IMAGE_MAX_BYTES', 25 * 1024 * 1024),
        'audio' => (int) env('ASSET_AUDIO_MAX_BYTES', 250 * 1024 * 1024),
        'video' => (int) env('ASSET_VIDEO_MAX_BYTES', 2 * 1024 * 1024 * 1024),
    ],
    'mime_types' => [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'audio' => ['audio/mpeg', 'audio/wav', 'audio/ogg'],
        'video' => ['video/mp4', 'video/webm'],
    ],
];
