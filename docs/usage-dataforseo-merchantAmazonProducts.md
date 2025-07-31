# DataForSEO Amazon Products Methods

## Overview

The DataForSEO API provides methods for retrieving Amazon product information through two main approaches:

- **Standard Methods** - Check cache first, then optionally submit tasks for processing via webhooks (recommended)
- **Task Methods** - Manually manage task submission and retrieval

## Standard Methods

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

### merchantAmazonProductsStandardAdvanced()

Standard method that returns structured Amazon product data.

**Parameters:**
- `keyword` - Product search query (required, max 700 characters)
- `url` - Direct URL of the search query
- `priority` - Task priority: 1 (normal) or 2 (high)
- `locationName` - Location name (e.g., "HA1,England,United Kingdom")
- `locationCode` - Location code (default: 2840 for US)
- `locationCoordinate` - Location coordinates in format "latitude,longitude,radius"
- `languageName` - Language name (e.g., "English (United Kingdom)")
- `languageCode` - Language code (default: 'en_US')
- `seDomain` - Search engine domain (e.g., "amazon.com", "amazon.co.uk")
- `depth` - Number of results to retrieve (default: 100, max: 700)
- `maxCrawlPages` - Page crawl limit (max: 7)
- `department` - Amazon product department
- `searchParam` - Additional parameters of the search query
- `priceMin` - Minimum product price
- `priceMax` - Maximum product price
- `sortBy` - Results sorting rules (relevance, price_low_to_high, etc.)
- `usePostback` - Enable postback webhook (default: false)
- `usePingback` - Enable pingback webhook (default: false)
- `postTaskIfNotCached` - Submit task if not cached (default: false)

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->merchantAmazonProductsStandardAdvanced(
    keyword: 'wireless headphones',
    usePostback: true,
    postTaskIfNotCached: true
);
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->merchantAmazonProductsStandardAdvanced(
    keyword: 'gaming keyboard',
    locationCode: 2840,
    languageCode: 'en_US',
    seDomain: 'amazon.com',
    depth: 200,
    department: 'Electronics',
    priceMin: 50,
    priceMax: 200,
    sortBy: 'price_low_to_high',
    usePostback: true,
    postTaskIfNotCached: true
);
```

### merchantAmazonProductsStandardHtml()

Standard method that returns HTML content instead of structured data.

**Usage:**
```php
$result = $dfs->merchantAmazonProductsStandardHtml(
    keyword: 'wireless headphones',
    usePostback: true,
    postTaskIfNotCached: true
);
```

## Task Management Methods

### merchantAmazonProductsTaskPost()

Manually submit a task to the queue.

**Parameters:**
- `keyword` - Product search query (required, max 700 characters)
- `url` - Direct URL of the search query
- `priority` - Task priority: 1 (normal) or 2 (high)
- `locationName` - Location name
- `locationCode` - Location code (default: 2840 for US)
- `locationCoordinate` - Location coordinates
- `languageName` - Language name
- `languageCode` - Language code (default: 'en_US')
- `seDomain` - Search engine domain
- `depth` - Number of results to retrieve (default: 100, max: 700)
- `maxCrawlPages` - Page crawl limit (max: 7)
- `department` - Amazon product department
- `searchParam` - Additional search parameters
- `priceMin` - Minimum product price
- `priceMax` - Maximum product price
- `sortBy` - Results sorting rules
- `postbackUrl` - Notification URL for task completion
- `postbackData` - Additional data for postback
- `pingbackUrl` - Notification URL for task status updates

**Usage:**
```php
$result = $dfs->merchantAmazonProductsTaskPost(
    keyword: 'bluetooth speaker',
    locationCode: 2840,
    priority: 2
);
$taskId = $result['response']['tasks'][0]['id'];
```

### merchantAmazonProductsTaskGetAdvanced()

Retrieve structured results for a completed task.

**Usage:**
```php
$result = $dfs->merchantAmazonProductsTaskGetAdvanced($taskId);
```

### merchantAmazonProductsTaskGetHtml()

Retrieve HTML results for a completed task.

**Usage:**
```php
$result = $dfs->merchantAmazonProductsTaskGetHtml($taskId);
```

## Webhooks

For standard methods, you can enable webhooks to receive notifications when tasks complete. See [dataforseo-webhooks.md](dataforseo-webhooks.md) for detailed webhook configuration.

**Quick Setup:**
```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

## Response Item Processor

The `DataForSeoMerchantAmazonProductsProcessor` class processes API responses and extracts data into database tables.

### Usage

```php
$processor = new DataForSeoMerchantAmazonProductsProcessor();

// Optionally reset processed columns and clear tables
//$processor->resetProcessed();
//$processor->clearProcessedTables();

// Process responses and extract Amazon product data
$stats = $processor->processResponses(limit: 100);
```

### Features

- Extracts search-level data into `dataforseo_merchant_amazon_products_listings` table
- Extracts individual product items into `dataforseo_merchant_amazon_products_items` table
- Handles duplicate detection and updates
- Provides detailed processing statistics

### Configuration

```php
// Include/exclude sandbox responses
$processor->setSkipSandbox(false);

// Enable/disable update behavior for newer items
$processor->setUpdateIfNewer(true);

// Skip nested items processing
$processor->setSkipNestedItems(false);
```

### Processing Statistics

The processor returns detailed statistics:
- `processed_responses` - Number of responses processed
- `listings_items` - Search-level data processed
- `listings_items_inserted` - New searches inserted
- `listings_items_updated` - Searches updated
- `listings_items_skipped` - Searches skipped
- `product_items` - Product items found
- `product_items_inserted` - New items inserted
- `product_items_updated` - Items updated
- `product_items_skipped` - Items skipped
- `total_items` - Total items processed
- `errors` - Errors encountered

### Data Structure

#### Search-Level Data (`dataforseo_merchant_amazon_products_listings`)

Each search query contains:
- `keyword` - Search keyword from request
- `se` - Search engine (amazon)
- `se_type` - Type of search (products)
- `location_code` - Location code
- `language_code` - Language code
- `se_domain` - Amazon domain (e.g. amazon.com)
- `depth` - Number of results requested
- `result_keyword` - Keyword from search results
- `check_url` - Direct URL to Amazon search results
- `result_datetime` - Date and time when the result was received
- `items_count` - Number of product items returned

#### Product Items (`dataforseo_merchant_amazon_products_items`)

Each Amazon product contains:
- `keyword` - Search keyword from result
- `se_domain` - Amazon domain (e.g. amazon.com)
- `location_code` - Location code
- `language_code` - Language code
- `items_type` - Type of result (e.g. amazon_product)
- `rank_group` - Ranking group
- `rank_absolute` - Absolute ranking position
- `domain` - Result domain (usually amazon.com)
- `title` - Product title
- `url` - Product URL
- `image_url` - Product image URL
- `price_current` - Current price
- `price_regular` - Regular price
- `price_max` - Maximum price
- `currency` - Price currency
- `is_best_seller` - Is Amazon's Choice (boolean)
- `is_amazon_choice` - Is Amazon's Choice (boolean)
- `rating` - Customer rating (1-5 stars)
- `rating_count` - Number of ratings
- `is_newer_model_available` - Has newer model available (boolean)
- `delivery_info` - Delivery information (JSON)
- `shop_info` - Shop information (JSON)

For testing the processor, see `examples/dfs_merchant_amazon_products_test.php`.