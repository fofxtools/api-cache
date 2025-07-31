# Usage Guide

## Basic Usage

### 1. Setup Configuration

Set your API credentials and configuration in your `.env` file:

```env
# Demo API Settings
DEMO_API_KEY=your_api_key_here
DEMO_BASE_URL=http://localhost:8000/demo-api-server.php/v1
DEMO_CACHE_TTL=null
DEMO_COMPRESSION_ENABLED=false
DEMO_RATE_LIMIT_MAX_ATTEMPTS=1000
DEMO_RATE_LIMIT_DECAY_SECONDS=60
```

**Cache TTL Options:**
- Set to `null` for no expiry (infinite cache)
- Set to number of seconds (e.g. 3600 for 1 hour)

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
$json = $predictions['response']->json();
print_r($json);

// Same query returns cached result
$cached = $client->predictions(
    query: 'weather forecast',
    maxResults: 5
);
$json = $cached['response']->json();
print_r($json); // Should be same as above

// Different parameters generate new request
$morePredictions = $client->predictions(
    query: 'weather forecast',
    maxResults: 10  // Different maxResults
);
$json = $morePredictions['response']->json();
print_r($json); // More results

// Retrieve reports
$reports = $client->reports(
    reportType: 'analytics',
    dataSource: 'user-interactions'
);
$json = $reports['response']->json();
print_r($json);
```

## Real API Example (OpenAI)

```php
use FOfX\ApiCache\OpenAIApiClient;

$openai = new OpenAIApiClient();

// Simple completion
$result = $openai->chatCompletions('What is energy?', 'gpt-4o-mini');
$json = $result['response']->json();

echo $json['choices'][0]['message']['content'];
    
// Advanced completion
$result_advanced = $openai->chatCompletions(
    messages: [
        ['role' => 'system', 'content' => 'You are a physicist'],
        ['role' => 'user', 'content' => 'What is energy?']
    ],
    model: 'gpt-4o-mini',
    temperature: 0.7
);
$json = $result_advanced['response']->json();

echo $json['choices'][0]['message']['content'];
```

## Raw uncached request

Use `sendRequest()` to make an uncached request.

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
$json = $raw_response['response']->json();
echo $json['choices'][0]['message']['content'];
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
