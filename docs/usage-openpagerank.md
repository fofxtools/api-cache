# OpenPageRank API Client

The OpenPageRankApiClient provides access to OpenPageRank's domain authority and PageRank data.

## Configuration

Set your API key in your `.env` file:

```env
OPENPAGERANK_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\OpenPageRankApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'openpagerank';
createClientTables($clientName, false);

$client = new OpenPageRankApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->getPageRank(['google.com']);
$client->setUseCache(true); // Re-enable
```

## Methods

### Get PageRank Data

Get PageRank data for one or more domains:

```php
// Single domain
$result = $client->getPageRank(['google.com']);

// Multiple domains (max 100 per request)
$result = $client->getPageRank(['google.com', 'apple.com', 'example.com']);

$response = $result['response'];
$data = $response->body();
```

**Parameters:**
- `domains` (array): Array of domain names to check (max 100 domains per request)

#### Example Response

```php
$result = $client->getPageRank(['cnn.com', 'britannica.com', 'nasa.gov']);
$response = $result['response'];
$data = json_decode($response->body(), true);

// Access PageRank data
foreach ($data['response'] as $domain) {
    echo "Domain: " . $domain['domain'] . "\n";
    echo "PageRank: " . $domain['page_rank_integer'] . "\n";
    echo "Rank: " . $domain['rank'] . "\n";
}
```