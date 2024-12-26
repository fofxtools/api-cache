return [
    'default_cache_ttl' => 3600,
    'compression' => [
        'enabled' => false,
        'level' => 6
    ],
    'clients' => [
        'openai' => [
            'class' => \FOfX\ApiCache\ApiClients\OpenAiClient::class,
            'default_endpoint' => 'chat/completions',
            'default_input_param' => 'messages',
            'rate_limits' => [
                'requests_per_minute' => 60
            ]
        ],
        'pixabay' => [
            'class' => \FOfX\ApiCache\ApiClients\PixabayClient::class,
            'default_endpoint' => 'search',
            'default_input_param' => 'q',
            'rate_limits' => [
                'requests_per_hour' => 1000
            ]
        ]
    ]
]; 