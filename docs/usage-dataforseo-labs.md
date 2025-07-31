# DataForSEO Labs Methods

## Overview

The DataForSEO Labs API provides advanced keyword research and analysis tools. All labs methods are **Live Methods** that return immediate results.

## Google Keywords For Site

### labsGoogleKeywordsForSiteLive()

Get keywords that a target domain ranks for in Google search results.

**Parameters:**
- `target` - Target domain without https:// (required)
- `locationCode` - Location code (default: 2840 for US)
- `languageCode` - Language code (default: 'en')
- `includeSerpInfo` - Include SERP data for each keyword (default: false)
- `includeSubdomains` - Include subdomains in search (default: true)
- `includeClickstreamData` - Include clickstream-based metrics (default: false, costs double)
- `limit` - Maximum number of keywords (max 1000, default: 100)
- `offset` - Offset in results array (default: 0)
- `offsetToken` - Token for subsequent requests
- `filters` - Array of filtering parameters (max 8 filters)
- `orderBy` - Results sorting rules (max 3 rules, default: relevance desc)

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->labsGoogleKeywordsForSiteLive('apple.com');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage with Filters:**
```php
// Get keywords with search volume > 100, limit to 50 results
$result = $dfs->labsGoogleKeywordsForSiteLive(
    target: 'apple.com',
    locationCode: 2840,
    languageCode: 'en',
    includeSerpInfo: true,
    limit: 50,
    filters: ['keyword_info.search_volume', '>', 100],
    orderBy: ['keyword_info.search_volume,desc']
);
```

## Google Bulk Keyword Difficulty

### labsGoogleBulkKeywordDifficultyLive()

Get keyword difficulty scores for multiple Google keywords.

**Parameters:**
- `keywords` - Array of keywords (max 1000, required)
- `locationCode` - Location code (default: 2840 for US)
- `languageCode` - Language code (default: 'en')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$keywords = ['apple iphone', 'samsung galaxy', 'google pixel'];
$result = $dfs->labsGoogleBulkKeywordDifficultyLive($keywords);
$response = $result['response'];
$json = $response->json();
```

## Amazon Methods

### labsAmazonBulkSearchVolumeLive()

Get search volume data for multiple Amazon keywords.

**Usage:**
```php
$keywords = ['vacuum cleaner', 'kitchenaid mixer', 'blender vitamix'];
$result = $dfs->labsAmazonBulkSearchVolumeLive($keywords);
```

### labsAmazonRelatedKeywordsLive()

Get related keywords for an Amazon seed keyword.

**Parameters:**
- `keyword` - Seed keyword (required)
- `depth` - Search depth level 0-4 (default: 2)
- `includeSeedKeyword` - Include seed keyword in results
- `ignoreSynonyms` - Ignore similar keywords
- `limit` - Max keywords returned (max 1000)
- `offset` - Results offset

**Usage:**
```php
$result = $dfs->labsAmazonRelatedKeywordsLive(
    keyword: 'apple iphone',
    depth: 2,
    limit: 100
);
```

**Cost Information:**
Pricing is $0.01 per task + $0.0001 per returned keyword. Cost breakdown by depth level (if max possible keywords returned):
- Depth 0: ~$0.0101 (1 keyword)
- Depth 1: ~$0.0106 (6 keywords)  
- Depth 2: ~$0.0142 (42 keywords)
- Depth 3: ~$0.0402 (258 keywords)
- Depth 4: ~$0.1702 (1554 keywords)

### labsAmazonRankedKeywordsLive()

Get keywords that an Amazon product (ASIN) ranks for.

**Parameters:**
- `asin` - Amazon product ID (required)
- `limit` - Max keywords returned (max 1000)
- `ignoreSynonyms` - Ignore similar keywords
- `filters` - Results filtering parameters
- `orderBy` - Results sorting rules
- `offset` - Results offset

**Usage:**
```php
$result = $dfs->labsAmazonRankedKeywordsLive(
    asin: 'B09B8V1LZ3',
    limit: 500
);
```