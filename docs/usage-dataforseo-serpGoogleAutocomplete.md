# DataForSEO Google Autocomplete Methods

## Overview

The DataForSEO API provides methods for retrieving Google autocomplete suggestions. These methods help you get the search suggestions that appear when users type in Google's search box.

- **Live Methods** - Get immediate results with higher costs
- **Standard Methods** - Submit tasks for processing and receive delayed results via webhooks (cheaper)
- **Task Methods** - Manually manage task submission and retrieval

## Live Methods

### serpGoogleAutocompleteLiveAdvanced()

Get immediate Google autocomplete suggestions with advanced configuration options.

**Parameters:**
- `keyword` - Search query for autocomplete suggestions (required)
- `locationName` - Location name (optional)
- `locationCode` - Location code (default: 2840 for US)
- `languageName` - Language name (optional)
- `languageCode` - Language code (default: 'en')
- `cursorPointer` - Cursor position in the search query (optional)
- `client` - Search client (optional, choices include `youtube` for YouTube autocomplete, `img` for image search, `products-cc` for shopping)

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->serpGoogleAutocompleteLiveAdvanced('apple iphone');
$response = $result['response'];
$data = $response->body();
```

**YouTube Search Autocomplete:**
```php
$result = $dfs->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'how to learn',
    client: 'youtube'
);
```

**Image Search Autocomplete:**
```php
$result = $dfs->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'cat pictures',
    client: 'img'
);
```

**Shopping Search Autocomplete:**
```php
$result = $dfs->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'wireless headphones',
    client: 'products-cc'
);
```

**Advanced Usage:**
```php
$result = $dfs->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'apple iphone',
    locationCode: 2840,
    languageCode: 'en',
    cursorPointer: 5
);
```

## Standard Methods

Standard methods check the cache first, then optionally submit tasks for processing if not cached.

### serpGoogleAutocompleteStandardAdvanced()

Standard method with advanced configuration and webhook support.

**Webhook Parameters:**
- `usePingback` - Enable pingback notifications (default: false)
- `usePostback` - Enable postback notifications (default: false)
- `postTaskIfNotCached` - Submit task if not cached (default: false)

**Basic Usage:**
```php
$result = $dfs->serpGoogleAutocompleteStandardAdvanced(
    keyword: 'apple iphone',
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Advanced Usage:**
```php
$result = $dfs->serpGoogleAutocompleteStandardAdvanced(
    keyword: 'apple iphone',
    locationCode: 2840,
    languageCode: 'en',
    cursorPointer: 5,
    usePostback: true,
    postTaskIfNotCached: true
);
```

## Task Management Methods

### serpGoogleAutocompleteTaskPost()

Manually submit an autocomplete task to the queue.

**Usage:**
```php
$result = $dfs->serpGoogleAutocompleteTaskPost(
    keyword: 'laravel framework',
    locationCode: 2840,
    priority: 2
);
```

### serpGoogleAutocompleteTaskGetAdvanced()

Retrieve results for a completed autocomplete task.

**Usage:**
```php
$result = $dfs->serpGoogleAutocompleteTaskGetAdvanced($taskId);
```

## Webhooks

For standard methods, you can enable webhooks to receive notifications when tasks complete. See [dataforseo-webhooks.md](dataforseo-webhooks.md) for detailed webhook configuration.

**Quick Setup:**
```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

## Response Item Processor

The `DataForSeoSerpGoogleAutocompleteProcessor` class processes API responses and extracts autocomplete suggestions into database tables.

### Usage

```php
$processor = new DataForSeoSerpGoogleAutocompleteProcessor();

// Process responses and extract autocomplete suggestions
$stats = $processor->processResponses(limit: 100);
```

### Features

- Extracts autocomplete suggestions into `dataforseo_serp_google_autocomplete_items` table
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
- `autocomplete_items` - Autocomplete suggestions found
- `items_inserted` - New suggestions inserted
- `items_updated` - Suggestions updated
- `items_skipped` - Suggestions skipped
- `total_items` - Total items processed
- `errors` - Errors

### Data Structure

Each autocomplete suggestion contains:
- `keyword` - Original search keyword
- `location_code` - Location code
- `language_code` - Language code
- `device` - Device type (desktop/mobile)
- `os` - Device operating system
- `rank_group` - Ranking group
- `rank_absolute` - Absolute ranking position
- `relevance` - Relevance score
- `suggestion` - Autocomplete suggestion text
- `suggestion_type` - Type of suggestion
- `search_query_url` - URL for the suggested search
- `thumbnail_url` - Thumbnail image URL (if available)
- `highlighted` - Highlighted text portions (JSON)

For testing the processor, see `examples/dfs_serp_google_autocomplete_test.php`.