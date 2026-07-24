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
            'key' => config('realtime.client.pusher.key') ?? $pusherConnection['key'] ?? null,
            'cluster' => config('realtime.client.pusher.cluster') ?? $pusherOptions['cluster'] ?? null,
            'host' => null,
            'port' => null,
            'scheme' => null,
        ];
    } elseif (config('broadcasting.default') === 'reverb') {
        $reverbConnection = config('broadcasting.connections.reverb', []);
        $reverbOptions = $reverbConnection['options'] ?? [];

        $realtimeConfig = [
            'broadcaster' => 'reverb',
            'key' => config('realtime.client.reverb.key') ?? $reverbConnection['key'] ?? null,
            'cluster' => null,
            'host' => config('realtime.client.reverb.host') ?? $reverbOptions['host'] ?? null,
            'port' => config('realtime.client.reverb.port') ?? $reverbOptions['port'] ?? null,
            'scheme' => config('realtime.client.reverb.scheme') ?? $reverbOptions['scheme'] ?? null,
        ];
    }

    $realtimeConfigJson = json_encode($realtimeConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
@endphp
<meta name="rpgays-realtime-config" content="{!! e($realtimeConfigJson) !!}">
