<?php

declare(strict_types=1);

// Fixwire for Laravel. Any option of the PHP SDK can go here too (before_send,
// error_budget, sensitive_keys, …), in the SDK's snake_case names.

return [
    // Where to send; without a DSN, Fixwire does nothing (local development, tests).
    'dsn' => env('FIXWIRE_DSN'),

    // The app's version, such as shop@1.4.0 (release health needs one).
    'release' => env('FIXWIRE_RELEASE'),

    // Where the app runs; the app's environment (APP_ENV) when not set.
    'environment' => env('FIXWIRE_ENVIRONMENT'),

    // The share of new traces kept: 0 for none, 1 for all.
    'traces_sample_rate' => (float) env('FIXWIRE_TRACES_SAMPLE_RATE', 0.0),

    // Where outgoing requests carry trace headers: a URL prefix (https://api.example.com/v2), or a
    // host (with a port if it has one) and its subdomains (example.com matches api.example.com).
    'trace_propagation_targets' => [],

    // A session per request, for crash-free sessions and users per release. It costs one more
    // request to Fixwire per request, so it is off.
    'auto_session_tracking' => (bool) env('FIXWIRE_AUTO_SESSION_TRACKING', false),

    // Send the user's email and IP address and identifying request headers.
    'send_default_pii' => false,

    // What leaves breadcrumbs (what happened before an error).
    'breadcrumbs' => [
        'logs' => true,      // log records from INFO
        'queries' => true,   // SQL, without its bindings
        'queue' => true,     // jobs processed
        'commands' => true,  // Artisan commands
    ],

    // What becomes spans of a trace (when the trace is sampled).
    'tracing' => [
        'queries' => true,
        'queue' => true,       // a job continues the trace of the code that dispatched it
        'http_client' => true, // the Http facade's requests, with trace headers to trace_propagation_targets
    ],
];
