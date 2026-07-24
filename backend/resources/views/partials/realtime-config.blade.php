@php
    $realtimeConfig = [
        'broadcaster' => null,
        'key' => null,
        'cluster' => null,
        'host' => null,
        'port' => null,
        'scheme' => null,
    ];

    if (config('broadcasting.default') === 'pusher') {
        $pusherConnection = config('broadcasting.connections.pusher', []);
        $pusherOptions = $pusherConnection['options'] ?? [];

        $realtimeConfig = [
            'broadcaster' => 'pusher',
            'key' => $pusherConnection['key'] ?? null,
            'cluster' => $pusherOptions['cluster'] ?? null,
            'host' => null,
            'port' => null,
            'scheme' => null,
        ];
    }

    $realtimeConfigJson = json_encode($realtimeConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
@endphp
<meta name="rpgays-realtime-config" content="{!! e($realtimeConfigJson) !!}">
