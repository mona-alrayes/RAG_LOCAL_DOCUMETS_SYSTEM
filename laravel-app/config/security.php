<?php

return [

    'document_security_scan' => [
        'enabled' => (bool) env('DOCUMENT_SECURITY_SCAN_ENABLED', true),
    ],

    'clamav' => [
        'mode' => env('CLAMAV_SCAN_MODE', 'on_demand_cli'),
        'queue' => env('CLAMAV_SCAN_QUEUE', 'security-scan'),
        'concurrency' => (int) env('CLAMAV_SCAN_CONCURRENCY', 1),
        'timeout' => (int) env('CLAMAV_SCAN_TIMEOUT', 300),
        'signature_dir' => env('CLAMAV_SIGNATURE_DIR', '/var/lib/clamav'),
        'fail_closed' => (bool) env('CLAMAV_FAIL_CLOSED', true),
    ],

    'local_heavy_resource_lock' => [
        'enabled' => (bool) env(
            'LOCAL_HEAVY_RESOURCE_LOCK_ENABLED',
            true,
        ),
        'key' => env(
            'LOCAL_HEAVY_RESOURCE_LOCK_KEY',
            'rag:local:heavy-resource',
        ),
        'timeout' => (int) env(
            'LOCAL_HEAVY_RESOURCE_LOCK_TIMEOUT',
            600,
        ),
        'wait_timeout' => (int) env(
            'LOCAL_HEAVY_RESOURCE_LOCK_WAIT_TIMEOUT',
            10,
        ),
        'retry_interval_ms' => (int) env(
            'LOCAL_HEAVY_RESOURCE_LOCK_RETRY_INTERVAL_MS',
            250,
        ),
    ],

];
