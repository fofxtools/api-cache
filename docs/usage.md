# Usage Guide

## Basic Usage

### 1. Setup Configuration
```php
// config/api-cache.php
return [
    'apis' => [
        'demo' => [
            'api_key' => env('DEMO_API_KEY'),
            'base_url' => env('DEMO_BASE_URL'),
            'cache_ttl' => env('DEMO_CACHE_TTL', null),
            'compression_enabled' => env('DEMO_COMPRESSION_ENABLED', false),
            'default_endpoint' => env('DEMO_DEFAULT_ENDPOINT', 'prediction'),
            'rate_limit_requests_per_minute' => env('DEMO_RATE_LIMIT_REQUESTS_PER_MINUTE', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],
];
```

### 2. Basic API Requests
```php
use FOfX\ApiCache\DemoApiClient;

// Create client (uses config by default)
$client = new DemoApiClient();

// Retrieve predictions (automatically cached)
$predictions = $client->prediction(
    query: 'weather forecast',
    maxResults: 5
);
print_r($predictions);

// Same query returns cached result
$cached = $client->prediction(
    query: 'weather forecast',
    maxResults: 5
);
print_r($cached); // Same as $predictions

// Different parameters generate new request
$morePredictions = $client->prediction(
    query: 'weather forecast',
    maxResults: 10  // Different maxResults
);
print_r($morePredictions); // More results

// Retrieve report
$report = $client->report(
    reportType: 'analytics',
    dataSource: 'user-interactions'
);
print_r($report);
```

## Real API Example (OpenAI)

```php
use FOfX\ApiCache\OpenAIApiClient;

$openai = new OpenAIApiClient();

try {
    // Simple completion
    $response = $openai->chatCompletions('What is energy?', 'gpt-4o-mini');

    echo $response['choices'][0]['message']['content'];
    
    // Advanced completion
    $response_advanced = $openai->chatCompletions(
        messages: [
            ['role' => 'system', 'content' => 'You are a physicist'],
            ['role' => 'user', 'content' => 'What is energy?']
        ],
        model: 'gpt-4o-mini',
        temperature: 0.7
    );

    echo $response_advanced['choices'][0]['message']['content'];
} catch (ApiCacheException $e) {
    Log::error('OpenAI request failed', [
        'message' => $e->getMessage()
    ]);
}
```