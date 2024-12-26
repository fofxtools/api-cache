<?php

return [
    'clients' => [
        'openai' => [
            'api_key'             => getenv('OPENAI_API_KEY'),
            'base_url'            => getenv('OPENAI_BASE_URL'),
            'cache_ttl'           => (int)getenv('OPENAI_CACHE_TTL'),
            'compression_enabled' => filter_var(getenv('OPENAI_COMPRESSION_ENABLED'), FILTER_VALIDATE_BOOLEAN),
            'default_endpoint'    => getenv('OPENAI_DEFAULT_ENDPOINT'),
            'rate_limits'         => [
                'window_size'  => (int)getenv('OPENAI_RATE_LIMIT_WINDOW'),
                'max_requests' => (int)getenv('OPENAI_RATE_LIMIT_MAX'),
            ],
        ],
        'pixabay' => [
            'api_key'          => getenv('PIXABAY_API_KEY'),
            'base_url'         => getenv('PIXABAY_BASE_URL'),
            'cache_ttl'        => (int)getenv('PIXABAY_CACHE_TTL'),
            'default_endpoint' => getenv('PIXABAY_DEFAULT_ENDPOINT'),
            'rate_limits'      => [
                'window_size'  => (int)getenv('PIXABAY_RATE_LIMIT_WINDOW'),
                'max_requests' => (int)getenv('PIXABAY_RATE_LIMIT_MAX'),
            ],
        ],
    ],
];
