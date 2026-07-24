@php
    $broadcastConnection = config('broadcasting.default');
    $realtimeBroadcaster = $broadcastConnection === 'pusher' ? 'pusher' : 'reverb';
    $realtimeConnection = config("broadcasting.connections.{$realtimeBroadcaster}");
    $realtimeOptions = $realtimeConnection['options'] ?? [];
    $reverbPort = env('VITE_REVERB_PORT');
@endphp
<script>
    window.RPGAYS_REALTIME_CONFIG = @json([
        'broadcaster' => $realtimeBroadcaster,
        'key' => $realtimeBroadcaster === 'pusher' ? ($realtimeConnection['key'] ?? null) : env('VITE_REVERB_APP_KEY'),
        'cluster' => $realtimeBroadcaster === 'pusher' ? ($realtimeOptions['cluster'] ?? null) : null,
        'host' => $realtimeBroadcaster === 'reverb' ? env('VITE_REVERB_HOST') : null,
        'port' => $realtimeBroadcaster === 'reverb' && $reverbPort !== null ? (int) $reverbPort : null,
        'scheme' => $realtimeBroadcaster === 'reverb' ? env('VITE_REVERB_SCHEME') : null,
    ]);
</script>
