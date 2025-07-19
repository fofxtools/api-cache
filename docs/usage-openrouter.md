# OpenRouter API Client

The OpenRouterApiClient provides access to OpenRouter's AI models for text completions and chat conversations.

## Configuration

Set your API key and the default model in your `.env` file:

```env
OPENROUTER_API_KEY=your_api_key_here
OPENROUTER_DEFAULT_MODEL=deepseek/deepseek-chat-v3-0324:free
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\OpenRouterApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'openrouter';
createClientTables($clientName, false);

$client = new OpenRouterApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->completions($prompt);
$client->setUseCache(true); // Re-enable
```

## Methods

### Text Completions

Generate text completions based on a prompt:

```php
$prompt = 'What is 2+2?';
$result = $client->completions($prompt);
$response = $result['response'];
$data = $response->body();
```

#### Completions Options

```php
$result = $client->completions(
    prompt: 'What is the meaning of life?',
    model: 'deepseek/deepseek-chat-v3-0324:free',     // default: from .env
    maxTokens: 100,                                   // default: 16
    n: 1,                                             // default: 1
    temperature: 0.7,                                 // default: 1.0
    topP: 0.9                                         // default: 1.0
);
```

### Chat Completions

Generate chat completions using conversation messages:

```php
$prompt = 'What is the meaning of life?';
$result = $client->chatCompletions($prompt);
```

#### Chat with Message History

```php
$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Who won the world series in 2020?'],
    ['role' => 'assistant', 'content' => 'The Los Angeles Dodgers won the World Series in 2020.'],
    ['role' => 'user', 'content' => 'Who did they beat?'],
];

$result = $client->chatCompletions($messages);
```

#### Chat Completions Options

```php
$result = $client->chatCompletions(
    messages: $messages,
    model: 'google/gemini-2.0-flash-exp:free',        // default: from .env
    maxCompletionTokens: 100,                         // default: null
    n: 1,                                             // default: 1
    temperature: 0.7,                                 // default: 1.0
    topP: 0.9                                         // default: 1.0
);
```