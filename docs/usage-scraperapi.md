# ScraperAPI Client

The ScraperApiClient provides web scraping capabilities through ScraperAPI's proxy service.

## Configuration

Set your API key in your `.env` file:

```env
SCRAPERAPI_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\ScraperApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'scraperapi';
createClientTables($clientName, false);

$client = new ScraperApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->scrape('https://www.fiverr.com');
$client->setUseCache(true); // Re-enable
```

## Methods

### Basic Scraping

Scrape any URL:

```php
$result = $client->scrape('https://www.fiverr.com');
$response = $result['response'];
$body = $response->body();
```

### Scraping with Options

```php
$result = $client->scrape(
    url: 'https://www.fiverr.com',
    autoparse: false,                   // Auto-parse JSON responses (default: false)
    outputFormat: 'markdown'            // Output format: 'text', 'markdown', etc. (optional)
);
```

### Advanced Scraping

Pass additional ScraperAPI parameters:

```php
$additionalParams = [
    'country_code' => 'US',
    'device_type' => 'desktop',
    'premium' => false,
    'session_number' => 123,
	'screenshot' => false
];

$result = $client->scrape(
    'https://www.fiverr.com',
    additionalParams: $additionalParams
);
```

For a complete list of parameters, see the [ScraperAPI documentation](https://docs.scraperapi.com/making-requests/customizing-requests).

- `country_code`: Target country for proxy (US, UK, CA, etc.)
- `device_type`: Device type (desktop, mobile, tablet)
- `premium`: Use premium proxies (true/false)
- `session_number`: Session ID for sticky sessions
- `screenshot`: Take screenshot (true/false)

## Credit Calculation

ScraperAPI uses a credit-based system. The client automatically calculates credits based on the target domain:

- **Standard websites**: 1 credit
- **E-commerce sites** (Amazon, Walmart): 5 credits  
- **Search engines** (Google, Bing): 25 credits
- **Social media** (LinkedIn, Twitter): 30 credits

Check required credits for a URL. **Note:** This method only checks select sites. For the actual costs you need to check the official documentation:

```php
$credits = $client->calculateCredits('https://www.amazon.com'); // Returns 5
```

## Saving Response Bodies to Files

Save all cached response bodies to HTML files:

```php
// Save all responses to files
$stats = $client->saveAllResponseBodiesToFile();
print_r($stats);

// Save with custom options
$stats = $client->saveAllResponseBodiesToFile(
    batchSize: 10,                    // Process 10 at a time
    endpoint: '',                     // Only '' endpoint
    savePath: 'scraped',              // Custom directory
    overwriteExisting: true           // Overwrite existing files
);
```

Files are saved as `{id}-{url-slug}.html` in the specified directory.

## Output Formats

See the [output formats documentation](https://docs.scraperapi.com/java/handling-and-processing-responses/output-formats).

- Raw HTML (default when no format specified)
- `markdown`: Markdown format
- `text`: Plain text format
- `json`: Structured JSON data (for Structured Data Extraction)
- `csv`: CSV format (for Structured Data Extraction)  

Note: `json` and `csv` are only available with Structured Data Extraction (SDE) endpoints.