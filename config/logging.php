<?php

return [
    'default' => env('LOG_CHANNEL', 'api-cache'),

    'channels' => [
        'api-cache' => [
            'driver' => 'single',
            'path'   => storage_path('logs/api-cache.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
        ],

        // Keep the standard Laravel channels as fallbacks
        'stack' => [
            'driver'            => 'stack',
            'channels'          => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
        ],
    ],
];
