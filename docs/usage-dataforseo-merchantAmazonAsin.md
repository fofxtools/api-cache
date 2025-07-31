# DataForSEO Merchant Amazon ASIN Methods

## Overview

The DataForSEO API provides methods for retrieving Amazon product data by ASIN (Amazon Standard Identification Number):

- **Standard Methods** - Check cache first, optionally submit tasks with webhooks (recommended)
- **Task Methods** - Manually manage task submission and retrieval

## Standard Methods

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

### merchantAmazonAsinStandardAdvanced()

Get Amazon product data with advanced configuration options.

**Parameters:**
- `asin` - Amazon product ASIN (required)
- `priority` - Task priority: 1 (normal) or 2 (high) (default: null)
- `locationName` - Location name (default: null)
- `locationCode` - Location code (default: 2840 for US)
- `locationCoordinate` - Location coordinate (default: null)
- `languageName` - Language name (default: null)
- `languageCode` - Language code (default: 'en_US')
- `seDomain` - Search engine domain (default: null)
- `loadMoreLocalReviews` - Load additional local reviews (default: null)
- `localReviewsSort` - Local reviews sorting (default: null)
- `usePostback` - Enable postback notifications (default: false)
- `usePingback` - Enable pingback notifications (default: false)
- `postTaskIfNotCached` - Submit task if not cached (default: false)

**Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->merchantAmazonAsinStandardAdvanced(
    asin: 'B00R92CL5E',
    usePostback: true,
    postTaskIfNotCached: true
);
$response = $result['response'];
$json = $response->json();
```

### merchantAmazonAsinStandardHtml()

Get Amazon product data in HTML format instead of structured data.

**Usage:**
```php
$result = $dfs->merchantAmazonAsinStandardHtml(
    asin: 'B09B8V1LZ3',
    usePostback: true,
    postTaskIfNotCached: true
);
```

## Task Management Methods

### merchantAmazonAsinTaskPost()

Manually submit an Amazon ASIN task to the queue.

**Parameters:**
- `asin` - Amazon product ASIN (required)
- `priority` - Task priority: 1 (normal) or 2 (high) (default: null)
- `locationName` - Location name (default: null)
- `locationCode` - Location code (default: 2840 for US)
- `locationCoordinate` - Location coordinate (default: null)
- `languageName` - Language name (default: null)
- `languageCode` - Language code (default: 'en_US')
- `seDomain` - Search engine domain (default: null)
- `loadMoreLocalReviews` - Load additional local reviews (default: null)
- `localReviewsSort` - Local reviews sorting (default: null)
- `postbackUrl` - Postback URL for notifications (default: null)
- `postbackData` - Additional postback data (default: null)
- `pingbackUrl` - Pingback URL for notifications (default: null)

**Usage:**
```php
$result = $dfs->merchantAmazonAsinTaskPost(
    asin: 'B0BZWRLRLK',
    priority: 2,
    locationCode: 2840
);
$taskId = $result['response']['tasks'][0]['id'];
```

### merchantAmazonAsinTaskGetAdvanced()

Retrieve advanced results for a completed task.

**Parameters:**
- `id` - Task ID to retrieve (required)

**Usage:**
```php
$result = $dfs->merchantAmazonAsinTaskGetAdvanced($taskId);
```

### merchantAmazonAsinTaskGetHtml()

Retrieve HTML results for a completed task.

**Parameters:**
- `id` - Task ID to retrieve (required)

**Usage:**
```php
$result = $dfs->merchantAmazonAsinTaskGetHtml($taskId);
```

## Webhooks

For standard methods, enable webhooks to receive notifications when tasks complete. See [usage-dataforseo-webhooks.md](usage-dataforseo-webhooks.md) for detailed webhook configuration.

**Quick Setup:**
```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

## Response Item Processor

The `DataForSeoMerchantAmazonAsinProcessor` class processes API responses and extracts Amazon product data into the `dataforseo_merchant_amazon_asins` table.

### Usage

```php
$processor = new DataForSeoMerchantAmazonAsinProcessor();

// Optionally reset processed columns and clear tables
//$processor->resetProcessed();
//$processor->clearProcessedTables();

// Process responses and extract Amazon product items
$stats = $processor->processResponses(limit: 100);
```

### Features

- Extracts Amazon product data into `dataforseo_merchant_amazon_asins` table
- Handles duplicate detection and updates
- Supports review and product information filtering
- Provides detailed processing statistics

### Configuration

```php
// Include/exclude sandbox responses
$processor->setSkipSandbox(false);

// Enable/disable update behavior for newer items
$processor->setUpdateIfNewer(true);

// Skip reviews to reduce data size
$processor->setSkipReviews(true);

// Skip product information to reduce data size
$processor->setSkipProductInformation(true);
```

### Processing Statistics

The processor returns detailed statistics:
- `processed_responses` - Number of responses processed
- `items_processed` - Amazon product items found
- `items_inserted` - New items inserted
- `items_updated` - Items updated
- `items_skipped` - Items skipped
- `total_items` - Total items processed
- `errors` - Processing errors

### Data Structure

#### Amazon Product Data (`dataforseo_merchant_amazon_asins`)

Each Amazon product contains:
- `asin` - Original ASIN from request
- `result_asin` - ASIN from response data
- `se` - Search engine (amazon)
- `se_type` - Type of search (products)
- `location_code` - Location code
- `language_code` - Language code
- `device` - Device type (desktop/mobile)
- `os` - Device operating system
- `title` - Product title
- `details` - Product details
- `image_url` - Main product image URL
- `author` - Product author/brand
- `data_asin` - Data ASIN identifier
- `parent_asin` - Parent ASIN for variations
- `product_asins` - Related product ASINs (JSON)
- `price_from` - Minimum price
- `price_to` - Maximum price
- `currency` - Price currency
- `is_amazon_choice` - Amazon's Choice status
- `rating_value` - Average rating value
- `rating_votes_count` - Number of reviews
- `rating_rating_max` - Maximum rating scale
- `is_newer_model_available` - Newer model availability
- `categories` - Product categories (JSON)
- `product_information` - Detailed product specs (JSON)
- `product_images_list` - Additional product images (JSON)
- `product_videos_list` - Product videos (JSON)
- `description` - Product description (JSON)
- `is_available` - Product availability status
- `top_local_reviews` - Top local reviews (JSON)
- `top_global_reviews` - Top global reviews (JSON)

For testing the processor, see `examples/dfs_merchant_amazon_asin_test.php`.