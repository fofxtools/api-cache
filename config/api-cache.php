<?php

return [
    'debug' => env('API_CACHE_DEBUG', false),

    'clients' => [
        'demo_api' => [
            'cache_ttl' => (int)env('DEMO_API_CACHE_TTL', 3600),

            'compression' => [
                'enabled' => (bool)env('DEMO_API_COMPRESSION_ENABLED', false),
                'level'   => (int)env('DEMO_API_COMPRESSION_LEVEL', 6),
            ],

            'rate_limits' => [
                'window_size'  => (int)env('DEMO_API_RATE_LIMIT_WINDOW', 60),
                'max_requests' => (int)env('DEMO_API_RATE_LIMIT_MAX', 5),
            ],
        ],
    ],
];
