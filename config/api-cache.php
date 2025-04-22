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
            'rate_limit_max_attempts'  => env('OPENAI_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'rate_limit_decay_seconds' => env('OPENAI_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenRouter API
        |--------------------------------------------------------------------------
        */
        'openrouter' => [
            'api_key'                  => env('OPENROUTER_API_KEY', null),
            'base_url'                 => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'version'                  => env('OPENROUTER_VERSION', null),
            'cache_ttl'                => env('OPENROUTER_CACHE_TTL', null),
            'compression_enabled'      => env('OPENROUTER_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('OPENROUTER_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'rate_limit_decay_seconds' => env('OPENROUTER_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | Pixabay API
        |--------------------------------------------------------------------------
        */
        'pixabay' => [
            'api_key'                  => env('PIXABAY_API_KEY', null),
            'base_url'                 => env('PIXABAY_BASE_URL', 'https://pixabay.com'),
            'version'                  => env('PIXABAY_VERSION', null),
            'cache_ttl'                => env('PIXABAY_CACHE_TTL', null),
            'compression_enabled'      => env('PIXABAY_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('PIXABAY_RATE_LIMIT_MAX_ATTEMPTS', 5000),
            'rate_limit_decay_seconds' => env('PIXABAY_RATE_LIMIT_DECAY_SECONDS', 3600),
        ],

        /*
        |--------------------------------------------------------------------------
        | ScraperAPI
        |--------------------------------------------------------------------------
        */
        'scraperapi' => [
            'api_key'                  => env('SCRAPERAPI_API_KEY', null),
            'base_url'                 => env('SCRAPERAPI_BASE_URL', 'https://api.scraperapi.com'),
            'version'                  => env('SCRAPERAPI_VERSION', null),
            'cache_ttl'                => env('SCRAPERAPI_CACHE_TTL', null),
            'compression_enabled'      => env('SCRAPERAPI_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('SCRAPERAPI_RATE_LIMIT_MAX_ATTEMPTS', 1000),
            'rate_limit_decay_seconds' => env('SCRAPERAPI_RATE_LIMIT_DECAY_SECONDS', 2592000),
        ],

        /*
        |--------------------------------------------------------------------------
        | Jina AI API
        |--------------------------------------------------------------------------
        */
        'jina' => [
            'api_key'                  => env('JINA_API_KEY', null),
            'base_url'                 => env('JINA_BASE_URL', 'https://r.jina.ai'),
            'version'                  => env('JINA_VERSION', 'v1'),
            'cache_ttl'                => env('JINA_CACHE_TTL', null),
            'compression_enabled'      => env('JINA_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('JINA_RATE_LIMIT_MAX_ATTEMPTS', 200),
            'rate_limit_decay_seconds' => env('JINA_RATE_LIMIT_DECAY_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | YouTube API
        |--------------------------------------------------------------------------
        */
        'youtube' => [
            'api_key'                  => env('YOUTUBE_API_KEY', null),
            'base_url'                 => env('YOUTUBE_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
            'version'                  => env('YOUTUBE_VERSION', 'v3'),
            'cache_ttl'                => env('YOUTUBE_CACHE_TTL', null),
            'compression_enabled'      => env('YOUTUBE_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('YOUTUBE_RATE_LIMIT_MAX_ATTEMPTS', 10000),
            'rate_limit_decay_seconds' => env('YOUTUBE_RATE_LIMIT_DECAY_SECONDS', 86400),
            'video_parts'              => env('YOUTUBE_VIDEO_PARTS', 'contentDetails,id,liveStreamingDetails,localizations,paidProductPlacementDetails,player,recordingDetails,snippet,statistics,status,topicDetails'),
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenPageRank API
        |--------------------------------------------------------------------------
        */
        'openpagerank' => [
            'api_key'                  => env('OPENPAGERANK_API_KEY', null),
            'base_url'                 => env('OPENPAGERANK_BASE_URL', 'https://openpagerank.com/api/v1.0'),
            'version'                  => env('OPENPAGERANK_VERSION', null),
            'cache_ttl'                => env('OPENPAGERANK_CACHE_TTL', null),
            'compression_enabled'      => env('OPENPAGERANK_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts'  => env('OPENPAGERANK_RATE_LIMIT_MAX_ATTEMPTS', 10000),
            'rate_limit_decay_seconds' => env('OPENPAGERANK_RATE_LIMIT_DECAY_SECONDS', 3600),
        ],
    ],
];
