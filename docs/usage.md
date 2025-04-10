# Usage Guide

## Basic Usage

### 1. Setup Configuration

Cache TTL can be configured in two ways:
- Set to `null` for no expiry (infinite cache)
- Set to number of seconds (e.g. 3600 for 1 hour)

```php
// config/api-cache.php
return [
    'apis' => [
        'demo' => [
            'api_key' => env('DEMO_API_KEY'),
            'base_url' => env('DEMO_BASE_URL'),
            'cache_ttl' => env('DEMO_CACHE_TTL', null),
            'compression_enabled' => env('DEMO_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts' => env('DEMO_RATE_LIMIT_MAX_ATTEMPTS', 1000),
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
$predictions = $client->predictions(
    query: 'weather forecast',
    maxResults: 5
);
print_r($predictions);

// Same query returns cached result
$cached = $client->predictions(
    query: 'weather forecast',
    maxResults: 5
);
print_r($cached); // Should be same as $predictions

// Different parameters generate new request
$morePredictions = $client->predictions(
    query: 'weather forecast',
    maxResults: 10  // Different maxResults
);
print_r($morePredictions); // More results

// Retrieve reports
$reports = $client->reports(
    reportType: 'analytics',
    dataSource: 'user-interactions'
);
print_r($reports);
```

## Real API Example (OpenAI)

```php
use FOfX\ApiCache\OpenAIApiClient;

$openai = new OpenAIApiClient();

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
```

## Raw uncached request

```php
$params = [
    'messages' => [
        ['role' => 'system', 'content' => 'You are a physicist'],
        ['role' => 'user', 'content' => 'What is energy?']
    ],
    'model' => 'gpt-4o-mini',
    'temperature' => 0.7
];
$raw_response = $openai->sendRequest('chat/completions', $params, 'POST');
```

## Injecting a custom manager

You can inject a manager to the client. This way, multiple clients can share the same manager.

```php
use FOfX\ApiCache\DemoApiClient;
use FOfX\ApiCache\OpenAIApiClient;
use FOfX\ApiCache\ApiCacheManager;

$manager = app(ApiCacheManager::class);
$demo = new DemoApiClient(cacheManager: $manager);
$openai = new OpenAIApiClient(cacheManager: $manager);
```
