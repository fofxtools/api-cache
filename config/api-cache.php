<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Cache Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the API Cache library.
    | Each API client has its own configuration section.
    |
    */

    'apis' => [
        /*
        |--------------------------------------------------------------------------
        | Default Cache Settings
        |--------------------------------------------------------------------------
        |
        | These are the default settings used if not specified in the API config.
        |--------------------------------------------------------------------------
        */
        'default' => [
            'api_key'                  => env('DEMO_API_KEY', 'demo-api-key'),
            'base_url'                 => env('DEMO_BASE_URL', 'http://localhost:8000/demo-api-server.php/v1'),
            'version'                  => env('DEMO_VERSION', 'v1'),
            'cache_ttl'                => env('DEMO_CACHE_TTL', null),
            'compression_enabled'      => env('DEMO_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('DEMO_RATE_LIMIT_MAX_ATTEMPTS', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        /*
        |--------------------------------------------------------------------------
        | Demo API
        |--------------------------------------------------------------------------
        */
        'demo' => [
            'api_key'                  => env('DEMO_API_KEY', 'demo-api-key'),
            'base_url'                 => env('DEMO_BASE_URL', 'http://localhost:8000/demo-api-server.php/v1'),
            'version'                  => env('DEMO_VERSION', 'v1'),
            'cache_ttl'                => env('DEMO_CACHE_TTL', null),
            'compression_enabled'      => env('DEMO_COMPRESSION_ENABLED', false),
            'default_endpoint'         => env('DEMO_DEFAULT_ENDPOINT', 'predictions'),
            'rate_limit_max_attempts'  => env('DEMO_RATE_LIMIT_MAX_ATTEMPTS', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenAI API
        |--------------------------------------------------------------------------
        */
        'openai' => [
            'api_key'                  => env('OPENAI_API_KEY', null),
            'base_url'                 => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'version'                  => env('OPENAI_VERSION', null),
            'cache_ttl'                => env('OPENAI_CACHE_TTL', null),
            'compression_enabled'      => env('OPENAI_COMPRESSION_ENABLED', false),
            'default_endpoint'         => env('OPENAI_DEFAULT_ENDPOINT', 'chat/completions'),
            'rate_limit_max_attempts'  => env('OPENAI_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'rate_limit_decay_seconds' => env('OPENAI_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | Pixabay API
        |--------------------------------------------------------------------------
        */
        'pixabay' => [
            'api_key'                  => env('PIXABAY_API_KEY', null),
            'base_url'                 => env('PIXABAY_BASE_URL', 'https://pixabay.com/api'),
            'version'                  => env('PIXABAY_VERSION', null),
            'cache_ttl'                => env('PIXABAY_CACHE_TTL', null),
            'compression_enabled'      => env('PIXABAY_COMPRESSION_ENABLED', false),
            'default_endpoint'         => env('PIXABAY_DEFAULT_ENDPOINT', 'search'),
            'rate_limit_max_attempts'  => env('PIXABAY_RATE_LIMIT_MAX_ATTEMPTS', 5000),
            'rate_limit_decay_seconds' => env('PIXABAY_RATE_LIMIT_DECAY_SECONDS', 3600),
        ],
    ],
];
