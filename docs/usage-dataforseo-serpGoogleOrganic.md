# DataForSEO Google Organic SERP Methods

## Overview

The DataForSEO API provides two types of methods for retrieving Google organic search results:

- **Live Methods** - Get immediate results with higher costs
- **Standard Methods** - Submit tasks for processing and receive delayed results via webhooks (cheaper)
- **Task Methods** - Manually manage task submission and retrieval

## Live Methods

### serpGoogleOrganicLiveRegular()

Get immediate Google organic search results with basic configuration.

**Parameters:**
- `keyword` - Search query (required)
- `locationCode` - Location code (default: 2840 for US)
- `languageCode` - Language code (default: 'en')
- `device` - Device type: 'desktop' or 'mobile' (default: 'desktop')
- `depth` - Number of results (max 700, default: 100)

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->serpGoogleOrganicLiveRegular('apple iphone');
$response = $result['response'];
$data = $response->body();
```

### serpGoogleOrganicLiveAdvanced()

Get immediate Google organic search results with advanced configuration options.

**Optional Parameters:**
- `peopleAlsoAskClickDepth` - People Also Ask click depth (1-4, incurs extra charges)
- `loadAsyncAiOverview` - Load AI overview (incurs extra charges)
- `expandAiOverview` - Expand AI overview content
- `calculateRectangles` - Calculate pixel rankings for SERP elements
- `browserScreenWidth` - Browser screen width for pixel rankings
- `browserScreenHeight` - Browser screen height for pixel rankings

**Advanced Usage:**
```php
$result = $dfs->serpGoogleOrganicLiveAdvanced(
    keyword: 'apple iphone',
    peopleAlsoAskClickDepth: 4,
    loadAsyncAiOverview: true,
    expandAiOverview: true
);
```

## Standard Methods

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

### serpGoogleOrganicStandardRegular()

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

**Webhook Parameters:**
- `usePingback` - Enable pingback notifications (default: false)
- `usePostback` - Enable postback notifications (default: false)
- `postTaskIfNotCached` - Submit task if not cached (default: false)

**Basic Usage:**
```php
$result = $dfs->serpGoogleOrganicStandardRegular(
    keyword: 'apple iphone',
    usePostback: true,
    postTaskIfNotCached: true
);
```

### serpGoogleOrganicStandardAdvanced()

Standard method with advanced configuration options.

**Advanced Usage:**
```php
$result = $dfs->serpGoogleOrganicStandardAdvanced(
    keyword: 'apple iphone',
    peopleAlsoAskClickDepth: 4,
    loadAsyncAiOverview: true,
    expandAiOverview: true,
    usePostback: true,
    postTaskIfNotCached: true
);
```

### serpGoogleOrganicStandardHtml()

Standard method that returns HTML content instead of structured data.

**Usage:**
```php
$result = $dfs->serpGoogleOrganicStandardHtml(
    keyword: 'apple iphone',
    usePostback: true,
    postTaskIfNotCached: true
);
```

## Task Management Methods

### serpGoogleOrganicTaskPost()

Manually submit a task to the queue.

**Usage:**
```php
$result = $dfs->serpGoogleOrganicTaskPost(
    keyword: 'laravel framework',
    locationCode: 2840,
    priority: 2
);
```

### serpGoogleOrganicTaskGetRegular()

Retrieve results for a completed task.

**Usage:**
```php
$result = $dfs->serpGoogleOrganicTaskGetRegular($taskId);
```

### serpGoogleOrganicTaskGetAdvanced()

Retrieve advanced results for a completed task.

**Usage:**
```php
$result = $dfs->serpGoogleOrganicTaskGetAdvanced($taskId);
```

### serpGoogleOrganicTaskGetHtml()

Retrieve HTML results for a completed task.

**Usage:**
```php
$result = $dfs->serpGoogleOrganicTaskGetHtml($taskId);
```

## Webhooks

For standard methods, you can enable webhooks to receive notifications when tasks complete. See [dataforseo-webhooks.md](dataforseo-webhooks.md) for detailed webhook configuration.

**Quick Setup:**
```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

## Response Item Processor

The `DataForSeoSerpGoogleOrganicProcessor` class processes API responses and extracts data into database tables.

### Usage

```php
$processor = new DataForSeoSerpGoogleOrganicProcessor();

// Process responses and extract organic items
$stats = $processor->processResponses(limit: 100, processPaas: true);
```

### Features

- Extracts search-level data into `dataforseo_serp_google_organic_listings` table
- Extracts organic search results into `dataforseo_serp_google_organic_items` table
- Processes People Also Ask (PAA) items into `dataforseo_serp_google_organic_paa_items` table
- Handles duplicate detection and updates
- Provides detailed processing statistics

### Configuration

```php
// Include/exclude sandbox responses
$processor->setSkipSandbox(false);

// Enable/disable update behavior for newer items
$processor->setUpdateIfNewer(true);
```

### Processing Statistics

The processor returns detailed statistics:
- `processed_responses` - Number of responses processed
- `listings_items` - Search-level data processed
- `listings_items_inserted` - New searches inserted
- `listings_items_updated` - Searches updated
- `listings_items_skipped` - Searches skipped
- `organic_items` - Organic items found
- `organic_items_inserted` - New items inserted
- `organic_items_updated` - Items updated
- `organic_items_skipped` - Items skipped
- `paa_items` - People Also Ask items found
- `paa_items_inserted` - People Also Ask items inserted
- `paa_items_skipped` - People Also Ask items skipped
- `total_items` - Total items processed
- `errors` - Errors

### Data Structure

#### Search-Level Data (`dataforseo_serp_google_organic_listings`)

Each search query contains:
- `keyword` - Search keyword from request
- `se` - Search engine (e.g. google)
- `se_type` - Type of search (e.g. organic)
- `location_code` - Location code
- `language_code` - Language code
- `device` - Device type (desktop/mobile)
- `os` - Device operating system
- `tag` - Tag passed in request
- `result_keyword` - Keyword in result data (may be different from request search keyword due to spelling correction)
- `type` - Type of search (e.g. organic)
- `se_domain` - Search engine domain (e.g. google.com)
- `check_url` - Direct URL to search engine results
- `result_datetime` - Date and time when the result was received
- `spell` - Autocorrection of the search engine
- `refinement_chips` - Search refinement chips
- `item_types` - Types of results found (JSON)
- `se_results_count` - Total results found by search engine
- `items_count` - Number of items returned in response

#### Organic Search Results (`dataforseo_serp_google_organic_items`)

Each organic search result contains:
- `keyword` - Search keyword from result
- `se_domain` - Search engine domain (e.g. google.com)
- `location_code` - Location code
- `language_code` - Language code
- `device` - Device type (desktop/mobile)
- `os` - Device operating system
- `items_type` - Type of result (e.g. organic)
- `rank_group` - Ranking group
- `rank_absolute` - Absolute ranking position
- `domain` - Result domain
- `title` - Result title
- `description` - Result description
- `url` - Result URL
- `breadcrumb` - Breadcrumb navigation
- `is_image` - Contains images (boolean)
- `is_video` - Contains videos (boolean)
- `is_featured_snippet` - Is featured snippet (boolean)
- `is_malicious` - Flagged as malicious (boolean)
- `is_web_story` - Is web story (boolean)

#### People Also Ask Items (`dataforseo_serp_google_organic_paa_items`)

Each People Also Ask item contains:
- `keyword` - Original search keyword
- `se_domain` - Search engine domain (e.g. google.com)
- `location_code` - Location code
- `language_code` - Language code
- `device` - Device type (desktop/mobile)
- `os` - Device operating system
- `item_position` - Position within PAA section
- `type` - Type of item (e.g. people_also_ask_element)
- `title` - Question title
- `seed_question` - Original seed question
- `xpath` - The XPath of the element
- `answer_type` - Type of answer element
- `answer_featured_title` - Featured title in answer
- `answer_url` - URL of answer source
- `answer_domain` - Domain of answer source
- `answer_title` - Title of answer source
- `answer_description` - Description of answer
- `answer_images` - Images in answer (JSON)
- `answer_timestamp` - Timestamp of answer
- `answer_table` - Table data in answer (JSON)

For testing the processor, see `examples/dfs_serp_google_organic_test.php`.