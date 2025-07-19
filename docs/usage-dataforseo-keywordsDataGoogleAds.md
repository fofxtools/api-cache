# DataForSEO Keywords Data Google Ads API

## Overview

This document covers the DataForSEO Keywords Data Google Ads API methods available in the library. These methods provide keyword research data including search volume, competition metrics, bidding information, and related keywords.

- **Live Methods** - Get immediate results with higher costs
- **Standard Methods** - Submit tasks for processing and receive delayed results via webhooks (cheaper)
- **Task Methods** - Manually manage task submission and retrieval

## Available Endpoints

### Search Volume

Get search volume and competition data for specific keywords.

#### Live Method
```php
$result = $dfs->keywordsDataGoogleAdsSearchVolumeLive($keywords);
```

**Parameters:**
- `array $keywords` - Keywords to analyze (max 1000, 80 chars each)
- `int $locationCode` - Location code (default: 2840 for US)
- `string $languageCode` - Language code (default: 'en')
- `bool $searchPartners` - Include search partners data

**Basic Usage:**
```php
$keywords = ['seo tools', 'keyword research', 'backlink analysis'];
$result = $dfs->keywordsDataGoogleAdsSearchVolumeLive($keywords);
```

#### Standard Method

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

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

#### Task Methods
```php
// Post task
$taskId = $dfs->keywordsDataGoogleAdsSearchVolumeTaskPost($keywords);

// Get results
$result = $dfs->keywordsDataGoogleAdsSearchVolumeTaskGet($taskId);
```

### Keywords For Site

Discover keywords that a website ranks for in Google Ads.

#### Live Method
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteLive($target);
```

**Parameters:**
- `string $target` - Domain or URL to analyze
- `int $locationCode` - Location code (default: 2840)
- `string $languageCode` - Language code (default: 'en')

**Basic Usage:**
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteLive('example.com');
```

#### Standard Method
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteStandard(
    $target,
    usePostback: true,
    postTaskIfNotCached: true);
```

#### Task Methods
```php
$taskId = $dfs->keywordsDataGoogleAdsKeywordsForSiteTaskPost($target);
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteTaskGet($taskId);
```

### Keywords For Keywords

Find related keywords based on seed keywords.

#### Live Method
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsLive($keywords);
```

**Parameters:**
- `array $keywords` - Seed keywords (max 1000)
- `string $sortBy` - Sort order: 'relevance', 'search_volume', 'competition_index', etc.

**Basic Usage:**
```php
$keywords = ['digital marketing', 'seo'];
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsLive($keywords);
```

#### Standard Method
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
    $keywords,
    usePostback: true,
    postTaskIfNotCached: true);
```

#### Task Methods
```php
$taskId = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsTaskPost($keywords);
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsTaskGet($taskId);
```

### Ad Traffic By Keywords

Estimate ad traffic metrics for keywords with specific bidding strategies.

#### Live Method
```php
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsLive($keywords, $bid);
```

**Parameters:**
- `array $keywords` - Keywords to analyze
- `float $bid` - Bid amount (required, positive number)
- `string $match` - Match type: 'exact', 'broad', 'phrase'
- `bool $searchPartners` - Include search partners data
- `string $dateInterval` - Time period: 'next_week', 'next_month', 'next_quarter'

**Basic Usage:**
```php
$keywords = ['seo software', 'keyword tool'];
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsLive(
    $keywords, 
    bid: 2.50, 
    match: 'exact'
);
```

#### Standard Method
```php
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
    $keywords,
	bid: 2.50,
	match: 'exact',
    usePostback: true,
    postTaskIfNotCached: true);
```

#### Task Methods
```php
$taskId = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsTaskPost($keywords, $bid);
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
use YourNamespace\DataForSeoKeywordsDataGoogleAdsProcessor;

$processor = new DataForSeoKeywordsDataGoogleAdsProcessor();

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