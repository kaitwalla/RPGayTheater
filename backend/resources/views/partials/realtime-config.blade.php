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
@endphp
<script>
    window.RPGAYS_REALTIME_CONFIG = {{ Illuminate\Support\Js::from($realtimeConfig) }};
</script>
