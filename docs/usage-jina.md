# Jina API Client

The JinaApiClient provides access to Jina AI's services including Reader, Search (SERP), and Reranking capabilities.

## Configuration

Set your API key in your `.env` file:

```env
JINA_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\JinaApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'jina';
createClientTables($clientName, false);

$client = new JinaApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->reader($url);
$client->setUseCache(true); // Re-enable
```

## Methods

### Reader API

Read and parse content from any URL:

```php
$url = 'https://www.fiverr.com/categories';
$result = $client->reader($url);
$response = $result['response'];
$json = $response->json();
```

### Search API (SERP)

Search the web using Jina's search engine:

```php
$query = 'Laravel PHP framework';
$result = $client->serp($query);
```

### Rerank API

Rerank documents based on relevance to a query:

```php
$query = 'What is Laravel?';
$documents = [
    'Laravel is a web application framework with expressive, elegant syntax.',
    'Symfony is another PHP framework.',
    'React is a JavaScript library for building user interfaces.',
];

$result = $client->rerank($query, $documents);
```

#### Rerank Options

```php
$result = $client->rerank(
    query: $query,
    documents: $documents,
    model: 'jina-reranker-v2-base-multilingual', // default
    topN: 3,                                     // default: count of documents
    returnDocuments: true                        // default: false
);
```

### Token Balance

Check your remaining token balance:

```php
$balance = $client->getTokenBalance();
echo "Remaining tokens: {$balance}";
```