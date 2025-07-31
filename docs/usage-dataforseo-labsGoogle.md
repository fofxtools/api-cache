# DataForSEO Labs Google Methods

## Overview

The DataForSEO Labs Google methods provide keyword research and analysis data through live API calls. All methods are **Live** methods that return immediate results.

## Live Methods

### labsGoogleKeywordsForSiteLive()

Get keywords that a specific website ranks for in Google organic search results.

**Parameters:**
- `target` - Target domain (required, without https://, www allowed)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (e.g., "English") 
- `languageCode` - Language code (default: 'en')
- `includeSerpInfo` - Include SERP data for each keyword
- `includeSubdomains` - Include subdomain results
- `includeClickstreamData` - Include clickstream metrics (double cost)
- `limit` - Maximum results (max 1000)
- `offset` - Result offset
- `offsetToken` - Token for pagination
- `filters` - Array of filters (max 8)
- `orderBy` - Array of sorting rules (max 3)

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->labsGoogleKeywordsForSiteLive('example.com');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->labsGoogleKeywordsForSiteLive(
    target: 'example.com',
    includeSerpInfo: true,
    includeSubdomains: true,
    includeClickstreamData: true
);
```

### labsGoogleRelatedKeywordsLive()

Get keywords related to a target keyword based on Google search data.

**Parameters:**
- `keyword` - Target keyword (required)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)  
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')
- `depth` - Search depth (0-4, default: 1)
- `includeSeedKeyword` - Include data for the seed keyword
- `includeSerpInfo` - Include SERP data for each keyword
- `includeClickstreamData` - Include clickstream metrics (double cost)
- `ignoreSynonyms` - Ignore highly similar keywords
- `replaceWithCoreKeyword` - Return data for core keyword
- `filters` - Array of filters (max 8)
- `orderBy` - Array of sorting rules (max 3)
- `limit` - Maximum results (max 1000)
- `offset` - Result offset

**Basic Usage:**
```php
$result = $dfs->labsGoogleRelatedKeywordsLive('apple iphone');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->labsGoogleRelatedKeywordsLive(
    keyword: 'apple iphone',
    depth: 3,
    includeSeedKeyword: true,
    includeSerpInfo: true,
    includeClickstreamData: true
);
```

### labsGoogleKeywordSuggestionsLive()

Get keyword suggestions based on a seed keyword using Google's suggestion algorithm.

**Parameters:**
- `keyword` - Target keyword (required)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code
- `includeSeedKeyword` - Include data for the seed keyword
- `includeSerpInfo` - Include SERP data for each keyword
- `includeClickstreamData` - Include clickstream metrics (double cost)
- `exactMatch` - Return exact match suggestions only  
- `ignoreSynonyms` - Ignore highly similar keywords
- `filters` - Array of filters (max 8)
- `orderBy` - Array of sorting rules (max 3)
- `limit` - Maximum results (max 1000)
- `offset` - Result offset
- `offsetToken` - Token for pagination

**Basic Usage:**
```php
$result = $dfs->labsGoogleKeywordSuggestionsLive('laptop');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->labsGoogleKeywordSuggestionsLive(
    keyword: 'laptop',
    includeSeedKeyword: true,
    includeSerpInfo: true,
    includeClickstreamData: true,
    exactMatch: true,
    ignoreSynonyms: true
);
```

### labsGoogleKeywordIdeasLive()

Get keyword ideas and variations based on multiple seed keywords.

**Parameters:**
- `keywords` - Array of target keywords (required, max 200)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')
- `closelyVariants` - Search mode (phrase-match vs broad-match)
- `ignoreSynonyms` - Ignore highly similar keywords
- `includeSerpInfo` - Include SERP data for each keyword
- `includeClickstreamData` - Include clickstream metrics (double cost)
- `limit` - Maximum results (max 1000)
- `offset` - Result offset
- `offsetToken` - Token for pagination
- `filters` - Array of filters (max 8)
- `orderBy` - Array of sorting rules (max 3)

**Basic Usage:**
```php
$keywords = ['apple iphone', 'samsung galaxy', 'google pixel'];

$result = $dfs->labsGoogleKeywordIdeasLive($keywords);
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->labsGoogleKeywordIdeasLive(
    keywords: $keywords,
    closelyVariants: true,
    ignoreSynonyms: true,
    includeSerpInfo: true,
    includeClickstreamData: true
);
```

### labsGoogleBulkKeywordDifficultyLive()

Get keyword difficulty scores for multiple keywords in bulk.

**Parameters:**
- `keywords` - Array of target keywords (required, max 1000)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')

**Basic Usage:**
```php
$keywords = ['seo tools', 'keyword research', 'backlink analysis'];

$result = $dfs->labsGoogleBulkKeywordDifficultyLive($keywords);
$response = $result['response'];
$json = $response->json();
```

### labsGoogleSearchIntentLive()

Analyze search intent classification for multiple keywords.

**Parameters:**
- `keywords` - Array of target keywords (required, max 1000)
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')

**Basic Usage:**
```php
$keywords = ['buy shoes', 'how to tie shoes', 'best running shoes'];

$result = $dfs->labsGoogleSearchIntentLive($keywords);
$response = $result['response'];
$json = $response->json();
```

### labsGoogleKeywordOverviewLive()

Get comprehensive keyword metrics overview for multiple keywords.

**Parameters:**
- `keywords` - Array of target keywords (required, max 700)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')
- `includeSerpInfo` - Include SERP data for each keyword
- `includeClickstreamData` - Include clickstream metrics (double cost)

**Basic Usage:**
```php
$keywords = ['digital marketing', 'content marketing', 'email marketing'];

$result = $dfs->labsGoogleKeywordOverviewLive($keywords);
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->labsGoogleKeywordOverviewLive(
    keywords: $keywords,
    includeSerpInfo: true,
    includeClickstreamData: true
);
```

### labsGoogleHistoricalKeywordDataLive()

Get historical search volume and trend data for multiple keywords.

**Parameters:**
- `keywords` - Array of target keywords (required, max 700)
- `locationName` - Location name (e.g., "United States")
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (e.g., "English")
- `languageCode` - Language code (default: 'en')

**Basic Usage:**
```php
$keywords = ['black friday', 'cyber monday', 'christmas gifts'];

$result = $dfs->labsGoogleHistoricalKeywordDataLive($keywords);
$response = $result['response'];
$json = $response->json();
```

## Response Item Processor

The `DataForSeoLabsGoogleKeywordResearchProcessor` class processes API responses from all Labs Google endpoints and extracts keyword data into the database.

### Usage

```php
$processor = new DataForSeoLabsGoogleKeywordResearchProcessor();

// Optionally reset processed columns and clear tables
//$processor->resetProcessed();
//$processor->clearProcessedTables();

// Process responses and extract keyword data
$stats = $processor->processResponses(limit: 100);
```

### Features

- Processes responses from all 7 Labs Google endpoints
- Extracts keyword research data into `dataforseo_labs_google_keyword_research_items` table
- Handles duplicate detection and updates
- Supports monthly search volume data extraction
- Provides detailed processing statistics

### Configuration

```php
// Include/exclude sandbox responses
$processor->setSkipSandbox(false);

// Enable/disable update behavior for newer items
$processor->setUpdateIfNewer(true);

// Control monthly search data extraction
$processor->setSkipKeywordInfoMonthlySearches(false);
$processor->setSkipClickstreamKeywordInfoMonthlySearches(false);
```

### Processing Statistics

The processor returns statistics including:
- `processed_responses` - Number of responses processed
- `keyword_research_items` - Total keyword items processed
- `keyword_research_items_inserted` - New items inserted
- `keyword_research_items_updated` - Items updated
- `keyword_research_items_skipped` - Items skipped
- `total_items` - Total items processed
- `errors` - Processing errors

For testing the processor, see `examples/dfs_labs_google_keyword_research_test.php`.