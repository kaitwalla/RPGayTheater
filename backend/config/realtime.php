<?php

return [
    'max_event_bytes' => (int) env('REALTIME_MAX_EVENT_BYTES', 9_216),
    'dispatch_lease_seconds' => (int) env('REALTIME_DISPATCH_LEASE_SECONDS', 60),
];
