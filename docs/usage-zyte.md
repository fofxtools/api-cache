# Zyte API Client

The ZyteApiClient provides advanced web scraping and data extraction capabilities through Zyte's AI-powered API.

## Configuration

Set your API key in your `.env` file:

```env
ZYTE_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\ZyteApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'zyte';
createClientTables($clientName, false);

$client = new ZyteApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->extractBrowserHtml('https://www.fiverr.com');
$client->setUseCache(true); // Re-enable
```

## Methods

### Browser HTML Extraction

Extract browser-rendered HTML after JavaScript execution:

```php
$result = $client->extractBrowserHtml('https://www.fiverr.com/categories');
$response = $result['response'];
$json = $response->json();
```

### Article Extraction

Extract structured article data from news sites and blogs:

```php
$result = $client->extractArticle('https://www.cnn.com/2020/06/01/media/cnn-first-day-anniversary');
```

### Article Lists

Extract lists of articles from category pages:

```php
$result = $client->extractArticleList('https://www.cnn.com/us');
```

### Product Extraction

Extract product information from e-commerce sites:

```php
$result = $client->extractProduct('https://www.amazon.com/dp/B00R92CL5E');
```

### Product Lists

Extract product lists from category pages:

```php
$result = $client->extractProductList('https://www.ebay.com/deals');
```

### Screenshots

Take screenshots of web pages:

```php
$result = $client->screenshot('https://www.fiverr.com/categories', [
    'fullPage' => true,
    'format' => 'jpeg'
]);
```

### Custom AI Extraction

Extract custom data using AI with a defined schema:

```php
$customAttributes = [
    'summary' => [
        'type' => 'string',
        'description' => 'A two sentence article summary'
    ],
    'sentiment' => [
        'type' => 'string',
        'enum' => ['positive', 'negative', 'neutral']
    ]
];

$result = $client->extractCustomAttributes(
    'https://www.zyte.com/blog/intercept-network-patterns-within-zyte-api/',
    $customAttributes,
    'article'
);
```

### Search Engine Results

Extract Google search results data:

```php
$result = $client->extractSerp('https://www.google.com/search?q=speed+test&hl=en&gl=us');
```

### Common Extraction

Simplified method for common use cases:

```php
$result = $client->extractCommon(
    url: 'https://example.com',
    browserHtml: true,
    screenshot: true,
    geolocation: 'US',
    javascript: false
);
```

## Advanced Options

### Geographic Location

Specify country for request origin:

```php
$result = $client->extractBrowserHtml(
    url: 'https://example.com',
    geolocation: 'US'           // ISO 3166-1 alpha-2 country code
);
```

### Device Types

Emulate different devices (requires httpResponseBody):

```php
$result = $client->extractCommon(
    url: 'https://example.com',
    httpResponseBody: true,
    device: 'mobile'            // 'desktop' or 'mobile'
);
```

### JavaScript Control

Enable or disable JavaScript execution:

```php
$result = $client->extractBrowserHtml(
    url: 'https://example.com',
    javascript: true            // Force JS enabled/disabled
);
```

### Custom Viewport

Set browser viewport dimensions:

```php
$result = $client->screenshot(
    url: 'https://example.com',
    viewport: ['width' => 1920, 'height' => 1080]
);
```

## Screenshot Management

### Save Screenshots to Files

Save screenshot responses to local files:

```php
// Take screenshot
$result = $client->screenshot('https://example.com');

// Save to file (given responses table row ID)
$filePath = $client->saveScreenshot($rowId);
echo "Screenshot saved to: $filePath";
```

## Saving Response Bodies to Files

Save cached response bodies to files:

```php
// Save all browserHtml responses to files
$client->resetProcessed();                // Reset processed status if needed
$stats = $client->saveAllResponseBodiesToFile(attributes3: 'browserHtml', jsonKey: 'browserHtml');
print_r($stats);

// Save with custom options
$client->resetProcessed();                // Reset processed status if needed
$stats = $client->saveAllResponseBodiesToFile(
    attributes3: 'browserHtml',           // Filter by extraction type
    jsonKey: 'browserHtml',               // Extract specific JSON field
    batchSize: 10,                        // Process 10 at a time
    savePath: 'scraped',                  // Custom directory
    overwriteExisting: true               // Overwrite existing files
);
print_r($stats);
```

Files are saved as `{id}-{url-slug}.html` in the specified directory.

## All Extraction Types

The client supports these specialized extraction methods:

- `extract()` - Full flexibility with all parameters
- `extractCommon()` - Simplified method for common use cases
- `extractBrowserHtml()` - Browser-rendered HTML
- `extractArticle()` - Article content and metadata
- `extractArticleList()` - Lists of articles
- `extractArticleNavigation()` - Article navigation data
- `extractForumThread()` - Forum thread content
- `extractJobPosting()` - Job posting details
- `extractJobPostingNavigation()` - Job board navigation
- `extractPageContent()` - General page content
- `extractProduct()` - Product information
- `extractProductList()` - Product listings
- `extractProductNavigation()` - E-commerce navigation
- `extractSerp()` - Search engine results
- `extractCustomAttributes()` - AI-powered custom extraction