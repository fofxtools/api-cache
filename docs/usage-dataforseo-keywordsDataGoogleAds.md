# DataForSEO Keywords Data Google Ads API

## Overview

This document covers the DataForSEO Keywords Data Google Ads API methods available in the library. These methods provide keyword research data including search volume, competition metrics, bidding information, and related keywords.

- **Live Methods** - Get immediate results with higher costs
- **Standard Methods** - Submit tasks for processing and receive delayed results via webhooks (cheaper)
- **Task Methods** - Manually manage task submission and retrieval

## Live Methods

### keywordsDataGoogleAdsSearchVolumeLive()

Get search volume and competition data for specific keywords.

**Parameters:**
- `array $keywords` - Keywords to analyze (max 1000, 80 chars each)
- `string $locationName` - Location name (optional)
- `int $locationCode` - Location code (default: 2840 for US)
- `string $locationCoordinate` - Location coordinate (optional)
- `string $languageName` - Language name (optional)
- `string $languageCode` - Language code (default: 'en')
- `bool $searchPartners` - Include search partners data (default: false)
- `string $dateFrom` - Start date for data (optional)
- `string $dateTo` - End date for data (optional)
- `bool $includeAdultKeywords` - Include adult keywords (default: false)
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc. (default: 'relevance')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$keywords = ['seo tools', 'keyword research', 'backlink analysis'];
$result = $dfs->keywordsDataGoogleAdsSearchVolumeLive($keywords);
$response = $result['response'];
$json = $response->json();
```

### keywordsDataGoogleAdsKeywordsForSiteLive()

Discover keywords that a website ranks for in Google Ads.

**Parameters:**
- `string $target` - Domain or URL to analyze
- `string $targetType` - Target type: 'domain' or 'page' (default: 'page')
- `string $locationName` - Location name (optional)
- `int $locationCode` - Location code (default: 2840)
- `string $locationCoordinate` - Location coordinate (optional)
- `string $languageName` - Language name (optional)
- `string $languageCode` - Language code (default: 'en')
- `bool $searchPartners` - Include search partners data (default: false)
- `string $dateFrom` - Start date for data (optional)
- `string $dateTo` - End date for data (optional)
- `bool $includeAdultKeywords` - Include adult keywords (default: false)
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc. (default: 'relevance')

**Basic Usage:**
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteLive('example.com');
```

### keywordsDataGoogleAdsKeywordsForKeywordsLive()

Find related keywords based on seed keywords.

**Parameters:**
- `array $keywords` - Seed keywords (max 1000)
- `string $locationName` - Location name (optional)
- `int $locationCode` - Location code (default: 2840)
- `string $locationCoordinate` - Location coordinate (optional)
- `string $languageName` - Language name (optional)
- `string $languageCode` - Language code (default: 'en')
- `bool $searchPartners` - Include search partners data (default: false)
- `string $dateFrom` - Start date for data (optional)
- `string $dateTo` - End date for data (optional)
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc. (default: 'relevance')
- `bool $includeAdultKeywords` - Include adult keywords (default: false)

**Basic Usage:**
```php
$keywords = ['digital marketing', 'seo'];
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsLive($keywords);
```

### keywordsDataGoogleAdsAdTrafficByKeywordsLive()

Estimate ad traffic metrics for keywords with specific bidding strategies.

**Parameters:**
- `array $keywords` - Keywords to analyze
- `float $bid` - Bid amount (required, positive number)
- `string $match` - Match type: 'exact', 'broad', 'phrase'
- `bool $searchPartners` - Include search partners data (default: false)
- `string $locationName` - Location name (optional)
- `int $locationCode` - Location code (default: 2840)
- `string $locationCoordinate` - Location coordinate (optional)
- `string $languageName` - Language name (optional)
- `string $languageCode` - Language code (default: 'en')
- `string $dateFrom` - Start date for data (optional)
- `string $dateTo` - End date for data (optional)
- `string $dateInterval` - Time period: 'next_week', 'next_month', 'next_quarter' (default: 'next_month')
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc. (default: 'relevance')

**Basic Usage:**
```php
$keywords = ['seo software', 'keyword tool'];
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsLive(
    $keywords, 
    bid: 2.50, 
    match: 'exact'
);
```

## Standard Methods

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

### keywordsDataGoogleAdsSearchVolumeStandard()

**Webhook Parameters:**
- `usePingback` - Enable pingback notifications (default: false)
- `usePostback` - Enable postback notifications (default: false)
- `postTaskIfNotCached` - Submit task if not cached (default: false)

**Basic Usage:**
```php
$result = $dfs->keywordsDataGoogleAdsSearchVolumeStandard(
    $keywords,
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Worldwide Data:**

To get worldwide data, rather than region and language specific. Set `locationCode` and `languageCode` to `null`.

```php
$result = $dfs->keywordsDataGoogleAdsSearchVolumeStandard(
    $keywords,
    locationCode: null,
    languageCode: null,
    usePostback: true,
    postTaskIfNotCached: true
);
```

### keywordsDataGoogleAdsKeywordsForSiteStandard()

```php
$target = 'example.com';
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteStandard(
    $target,
    usePostback: true,
    postTaskIfNotCached: true);
```

### keywordsDataGoogleAdsKeywordsForKeywordsStandard()

**Parameters:**
- `array $keywords` - Seed keywords (max 1000)
- `string $target` - Target website to analyze (optional)
- `int $priority` - Task priority (optional)
- `string $locationName` - Location name (optional)
- `int $locationCode` - Location code (default: 2840)
- `string $locationCoordinate` - Location coordinate (optional)
- `string $languageName` - Language name (optional)
- `string $languageCode` - Language code (default: 'en')
- `bool $searchPartners` - Include search partners data (default: false)
- `string $dateFrom` - Start date for data (optional)
- `string $dateTo` - End date for data (optional)
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc. (default: 'relevance')
- `bool $includeAdultKeywords` - Include adult keywords (default: false)
- `bool $usePostback` - Enable postback notifications (default: false)
- `bool $usePingback` - Enable pingback notifications (default: false)
- `bool $postTaskIfNotCached` - Submit task if not cached (default: false)

```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
    $keywords,
    usePostback: true,
    postTaskIfNotCached: true);
```

### keywordsDataGoogleAdsAdTrafficByKeywordsStandard()

```php
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
    $keywords,
	bid: 2.50,
	match: 'exact',
    usePostback: true,
    postTaskIfNotCached: true);
```

## Task Management Methods

### keywordsDataGoogleAdsSearchVolumeTaskPost() / TaskGet()

```php
// Post task
$result = $dfs->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords);
$taskId = $result['response']['tasks'][0]['id'];

// Get results
$result = $dfs->keywordsDataGoogleAdsSearchVolumeTaskGet($taskId);
```

### keywordsDataGoogleAdsKeywordsForSiteTaskPost() / TaskGet()

```php
// Post task
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteTaskPost($target);
$taskId = $result['response']['tasks'][0]['id'];

// Get results
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteTaskGet($taskId);
```

### keywordsDataGoogleAdsKeywordsForKeywordsTaskPost() / TaskGet()

```php
// Post task
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost($keywords);
$taskId = $result['response']['tasks'][0]['id'];

// Get results
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsTaskGet($taskId);
```

### keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost() / TaskGet()

```php
// Post task
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost($keywords, $bid, $match);
$taskId = $result['response']['tasks'][0]['id'];

// Get results
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsTaskGet($taskId);
```

## Webhooks

For standard methods, you can enable webhooks to receive notifications when tasks complete. See [dataforseo-webhooks.md](dataforseo-webhooks.md) for detailed webhook configuration.

**Quick Setup:**
```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

## Response Item Processor

The `DataForSeoKeywordsDataGoogleAdsProcessor` class automatically processes API responses and stores them in the `dataforseo_keywords_data_google_ads_items` table.

**Configuration Options:**
- `skipSandbox` (default: true) - Skip sandbox test responses
- `skipMonthlySearches` (default: false) - Skip monthly search trends data
- `updateIfNewer` (default: true) - Update existing records with newer data

### Usage

```php
use FOfX\ApiCache\DataForSeoKeywordsDataGoogleAdsProcessor;

$processor = new DataForSeoKeywordsDataGoogleAdsProcessor();

// Optionally reset processed columns and clear tables
//$processor->resetProcessed();
//$processor->clearProcessedTables();

$stats = $processor->processResponses(100);
```

### Processing Statistics

The processor returns detailed statistics:
- `processed_responses` - Number of responses processed
- `google_ads_items` - Google Ads keyword items found
- `items_inserted` - New keyword items inserted
- `items_updated` - Existing keyword items updated
- `items_skipped` - Items skipped (duplicates/no changes)
- `total_items` - Total items processed
- `errors` - Processing errors encountered

### Data Structure

#### Keywords Data Items (`dataforseo_keywords_data_google_ads_items`)

Each keyword item contains:
- `keyword` - The keyword analyzed
- `location_code` - Location code (0 for worldwide)
- `language_code` - Language code ('none' for worldwide)
- `spell` - Spelling corrections applied
- `search_partners` - Search partners included (boolean)
- `competition` - Competition level (0.0-1.0)
- `competition_index` - Competition index (0-100)
- `search_volume` - Monthly search volume
- `low_top_of_page_bid` - Low bid estimate for top of page
- `high_top_of_page_bid` - High bid estimate for top of page
- `cpc` - Cost per click estimate
- `monthly_searches` - Monthly search trends (JSON, optional)

For testing the processor, see `examples/dfs_keywords_data_google_ads_test.php`.