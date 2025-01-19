# Usage Guide

## Basic Usage

### 1. Setup Configuration
```php
// config/api-cache.php
return [
    'apis' => [
        'demo' => [
            'base_url' => env('DEMO_API_BASE_URL', 'http://localhost:8000/demo-api/v1'),
            'api_key' => null,
            'cache_ttl' => null, // No cache expiration
            'rate_limit' => [
                'requests_per_minute' => 1000,
            ],
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