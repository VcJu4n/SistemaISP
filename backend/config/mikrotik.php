<?php

return [
    'connection' => [
        'timeout' => env('MIKROTIK_CONNECTION_TIMEOUT', 5),
        'ssl_verify_peer' => env('MIKROTIK_SSL_VERIFY_PEER', false),
    ],

    'operations' => [
        'enabled' => env('MIKROTIK_OPERATION_PROCESSING_ENABLED', false),
        'batch_size' => env('MIKROTIK_OPERATION_BATCH_SIZE', 20),
        'max_attempts' => env('MIKROTIK_OPERATION_MAX_ATTEMPTS', 3),
        'stale_processing_minutes' => env('MIKROTIK_OPERATION_STALE_PROCESSING_MINUTES', 10),
        'simple_queue_suspend_strategy' => env('MIKROTIK_SIMPLE_QUEUE_SUSPEND_STRATEGY', 'disable_queue'),
    ],
];
