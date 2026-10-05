<?php

declare(strict_types=1);

// Published with `php artisan vendor:publish --tag=fixwire-config`, then trimmed to what this
// app changes. The DSN comes from FIXWIRE_DSN.

return [
    'dsn' => env('FIXWIRE_DSN'),
    'release' => env('FIXWIRE_RELEASE', 'shop@1.0.0'),
    'traces_sample_rate' => 1.0,
    // Trace headers go to our own inventory service, nowhere else.
    'trace_propagation_targets' => [env('INVENTORY_URL', 'http://localhost:8081')],
    // Each request is a session, for release health (one more request to Fixwire per request).
    'auto_session_tracking' => true,
];
