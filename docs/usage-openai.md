# OpenAI API Client

The OpenAIApiClient provides access to OpenAI's text and chat completion services with automatic caching and rate limiting.

## Configuration

Set your API key in your `.env` file:

```env
OPENAI_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\OpenAIApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'openai';
createClientTables($clientName, false);

$client = new OpenAIApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->chatCompletions('Hello, world!');
$client->setUseCache(true); // Re-enable
```

## Methods

### Chat Completions (Recommended)

Generate conversational responses using modern chat models:

```php
// Simple string prompt
$result = $client->chatCompletions('What is Laravel?');
$response = $result['response'];
$json = $response->json();
```

#### With Custom Parameters

```php
$result = $client->chatCompletions(
    messages: 'Explain quantum physics simply',
    model: 'gpt-4o-mini',                    // default
    maxCompletionTokens: 150,                // default: null (no limit)
    temperature: 0.7,                        // default: 1.0
    topP: 0.9                               // default: 1.0
);
```

#### Conversation History

```php
$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Who won the world series in 2020?'],
    ['role' => 'assistant', 'content' => 'The Los Angeles Dodgers won the World Series in 2020.'],
    ['role' => 'user', 'content' => 'Who did they beat?']
];

$result = $client->chatCompletions($messages);
```

### Legacy Text Completions

For older completion models:

```php
$result = $client->completions(
    prompt: 'What is 2+2?',
    model: 'gpt-3.5-turbo-instruct',        // default
    maxTokens: 50,                          // default: 16
    temperature: 0.7,                       // default: 1.0
    topP: 1.0                              // default: 1.0
);
```