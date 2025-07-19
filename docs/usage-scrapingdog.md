# Scrapingdog API Client

The ScrapingdogApiClient provides web scraping capabilities through Scrapingdog's proxy service.

## Configuration

Set your API key in your `.env` file:

```env
SCRAPINGDOG_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\ScrapingdogApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'scrapingdog';
createClientTables($clientName, false);

$client = new ScrapingdogApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->scrape('https://fiverr.com');
$client->setUseCache(true); // Re-enable
```

## Methods

### Basic Scraping

Scrape any URL:

```php
$result = $client->scrape('https://fiverr.com');
$response = $result['response'];
$data = $response->body();
```

### Dynamic Scraping

Enable JavaScript rendering:

```php
$result = $client->scrape(
    url: 'https://fiverr.com',
    dynamic: true                       // Enable JS rendering (default: false)
);
```

### Premium Proxies

Use premium residential proxies:

```php
$result = $client->scrape(
    url: 'https://yahoo.com',
    premium: true                       // Use premium proxies (default: null)
);
```

### AI-Powered Extraction

Use AI to extract specific content:

```php
$result = $client->scrape(
    url: 'https://fiverr.com',
    ai_query: 'Extract the main heading and description'
);
```

### AI Extract Rules

Define extraction rules for structured data:

```php
$extractRules = [
    'header' => 'Extract the main heading',
    'first_link' => 'Extract the first link'
];

$result = $client->scrape(
    url: 'https://fiverr.com',
    ai_extract_rules: $extractRules
);
```

### Advanced Scraping

Pass additional Scrapingdog parameters:

```php
$additionalParams = [
    'custom_headers' => true,
    'session_number' => '123',
    'image' => false,
    'markdown' => true
];

$result = $client->scrape(
    url: 'https://fiverr.com',
    additionalParams: $additionalParams
);
```

## Credit Calculation

Scrapingdog uses a credit-based system. The client automatically calculates credits:

- **Basic scraping**: 1 credit
- **Dynamic rendering**: 5 credits
- **Premium proxies**: 10 credits
- **Dynamic + Premium**: 25 credits
- **Super proxy**: 75 credits
- **AI features**: +5 credits

Check required credits:

```php
$credits = $client->calculateCredits(
    dynamic: true,
    premium: false
); // Returns 5
```

## Common Parameters

For complete documentation, see [Scrapingdog request customization](https://docs.scrapingdog.com/web-scraping-api/request-customization).

- `dynamic`: Enable JavaScript rendering (true/false)
- `premium`: Use premium residential proxies (true/false)
- `custom_headers`: Allow passing custom HTTP headers (true/false)
- `wait`: Wait time in milliseconds (0-35000)
- `country`: Country code for geo-location (us, gb, etc.)
- `session_number`: Session ID for consistent proxy reuse
- `image`: Include image URLs in response (true/false)
- `markdown`: Return HTML as markdown format (true/false)
- `ai_query`: AI prompt for content extraction
- `ai_extract_rules`: Rules for structured data extraction
- `super_proxy`: Use super proxy for highest success rate