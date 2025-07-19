# YouTube API Client

The YouTubeApiClient provides access to YouTube Data API v3 for searching videos and retrieving video details.

## Configuration

Set your API key in your `.env` file:

```env
YOUTUBE_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\YouTubeApiClient;
use function FOfX\ApiCache\createClientTables;

// Create the responses tables if not present
$clientName = 'youtube';
createClientTables($clientName, false);

$client = new YouTubeApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->search('Laravel tutorials');
$client->setUseCache(true); // Re-enable
```

## Methods

### Search Videos

Search for YouTube content:

```php
$result = $client->search('How to cook pasta');
$response = $result['response'];
$data = json_decode($response->body(), true);
```

#### Search Options

```php
$result = $client->search(
    q: 'Laravel tutorials',
    part: 'snippet',                    // Resource parts (default: 'snippet')
    type: 'video',                      // Resource type (default: 'video')
    maxResults: 25,                     // Max results (default: 10)
    order: 'viewCount',                 // Order by (default: 'relevance')
    safeSearch: 'moderate',             // Safe search (optional)
    pageToken: null,                    // Pagination token (optional)
    publishedAfter: '2024-01-01T00:00:00Z',  // Date filter (optional)
    publishedBefore: '2024-12-31T23:59:59Z'  // Date filter (optional)
);
```

### Get Video Details

Retrieve details for specific videos:

```php
// Get video by ID
$result = $client->videos(id: 'jNQXAC9IVRw');
```

#### Video Details Options

```php
$result = $client->videos(
    id: 'jNQXAC9IVRw,dQw4w9WgXcQ',     // Comma-separated video IDs
    chart: null,                       // Chart type (mutually exclusive with id)
    part: 'snippet,statistics',        // Resource parts (optional)
    pageToken: null,                   // Pagination token (optional)
    maxResults: 50,                    // Max results (optional)
    regionCode: 'US'                   // Country code (optional)
);
```

### Get Popular Videos

Retrieve chart-based video lists:

```php
$result = $client->videos(
    id: null,                          // Must be null when using chart
    chart: 'mostPopular',              // Chart type
    regionCode: 'US'                   // Country code (optional)
);
```

## Search Parameters

- `q`: Search query string
- `part`: Resource parts (snippet, statistics, etc.)
- `type`: Resource type (video, channel, playlist)
- `maxResults`: Number of results (1-50)
- `order`: Sort order (date, rating, relevance, title, videoCount, viewCount)
- `safeSearch`: Filter level (moderate, none, strict)
- `publishedAfter`/`publishedBefore`: Date filters (RFC 3339 format)

## Video Parameters

- `id`: Comma-separated video IDs
- `chart`: Chart name (mostPopular)
- `part`: Resource parts to include
- `maxResults`: Maximum results to return
- `regionCode`: ISO 3166-1 alpha-2 country code

Note: `id` and `chart` are mutually exclusive - use one or the other, not both.

## Date Formats

Use RFC 3339 format for date parameters:
- `2024-01-01T00:00:00Z`
- `2024-12-31T23:59:59Z`