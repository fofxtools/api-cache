# DataForSEO API Setup

The DataForSEO API client provides access to various DataForSEO services including SERP, Keywords, and Backlinks data.

## Configuration

Set your API credentials in your `.env` file:

```env
DATAFORSEO_LOGIN=your_login_here
DATAFORSEO_PASSWORD=your_password_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\DataForSeoApiClient;

use function FOfX\ApiCache\createClientTables;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_listings_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_items_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_organic_paa_items_table;
use function FOfX\ApiCache\create_dataforseo_serp_google_autocomplete_items_table;
use function FOfX\ApiCache\create_dataforseo_keywords_data_google_ads_items_table;
use function FOfX\ApiCache\create_dataforseo_backlinks_bulk_items_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_products_listings_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_products_items_table;
use function FOfX\ApiCache\create_dataforseo_merchant_amazon_asins_table;
use function FOfX\ApiCache\create_dataforseo_labs_google_keyword_research_items_table;

$schema = app('db')->connection()->getSchemaBuilder();

// Create the responses tables if not present
$clientName = 'dataforseo';
createClientTables($clientName, false);

// Create DataForSEO-specific tables based on your needs
create_dataforseo_serp_google_organic_listings_table($schema, 'dataforseo_serp_google_organic_listings', false, false);
create_dataforseo_serp_google_organic_items_table($schema, 'dataforseo_serp_google_organic_items', false, false);
create_dataforseo_serp_google_organic_paa_items_table($schema, 'dataforseo_serp_google_organic_paa_items', false, false);
create_dataforseo_serp_google_autocomplete_items_table($schema, 'dataforseo_serp_google_autocomplete_items', false, false);
create_dataforseo_keywords_data_google_ads_items_table($schema, 'dataforseo_keywords_data_google_ads_items', false, false);
create_dataforseo_backlinks_bulk_items_table($schema, 'dataforseo_backlinks_bulk_items', false, false);
create_dataforseo_merchant_amazon_products_listings_table($schema, 'dataforseo_merchant_amazon_products_listings', false, false);
create_dataforseo_merchant_amazon_products_items_table($schema, 'dataforseo_merchant_amazon_products_items', false, false);
create_dataforseo_merchant_amazon_asins_table($schema, 'dataforseo_merchant_amazon_asins', false, false);
create_dataforseo_labs_google_keyword_research_items_table($schema, 'dataforseo_labs_google_keyword_research_items', false, false);

$client = new DataForSeoApiClient();
```

## Available Tables

DataForSEO uses multiple specialized tables for different data types:

- `dataforseo_serp_google_organic_listings` - SERP organic listings
- `dataforseo_serp_google_organic_items` - SERP organic results
- `dataforseo_serp_google_organic_paa_items` - People Also Ask results  
- `dataforseo_serp_google_autocomplete_items` - Autocomplete suggestions
- `dataforseo_keywords_data_google_ads_items` - Google Ads keyword data
- `dataforseo_backlinks_bulk_items` - Backlinks bulk data
- `dataforseo_merchant_amazon_products_listings` - Amazon merchant product listings
- `dataforseo_merchant_amazon_products_items` - Amazon merchant product items
- `dataforseo_merchant_amazon_asins` - Amazon ASINs data
- `dataforseo_labs_google_keyword_research_items` - Google keyword research data

You can create only the tables you need for your specific use case.