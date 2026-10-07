<?php

return [

    'push' => [
        // Attempts per decision before a push is marked failed (429/503 are retried).
        'max_attempts' => (int) env('REPRICER_PUSH_MAX_ATTEMPTS', 5),
        // Exponential backoff with equal jitter; Retry-After is always honoured as a minimum.
        'backoff_base_ms' => 500,
        'backoff_cap_ms' => 30_000,
    ],

    'listener' => [
        'batch' => 10,       // notifications pulled per receive
        'wait_ms' => 2000,   // long-poll wait when the stream is empty
    ],

];
