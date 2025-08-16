# DataForSEO OnPage Methods

## Overview

The DataForSEO OnPage API provides methods for website auditing and analysis:

- **Live Methods** - Get immediate results with higher costs
- **Task Methods** - Submit tasks for processing and retrieve results

## Live Methods

### onPageInstantPages()

Get immediate onPage analysis results for a single URL.

**Parameters:**
- `url` - Target URL (required, must be absolute URL)
- `customUserAgent` - Custom user agent string
- `browserPreset` - Browser preset ('desktop', 'mobile', 'tablet')
- `browserScreenWidth` - Browser screen width (240-9999 pixels)
- `browserScreenHeight` - Browser screen height (240-9999 pixels)
- `browserScreenScaleFactor` - Browser screen scale factor (min: 0.5, max: 3)
- `storeRawHtml` - Store HTML content (default: false)
- `acceptLanguage` - Language header for request
- `loadResources` - Load images, stylesheets, scripts (default: false)
- `enableJavascript` - Enable JavaScript execution (default: false)
- `enableBrowserRendering` - Enable browser rendering for Core Web Vitals (default: false)
- `disableCookiePopup` - Disable cookie consent popup (default: false)
- `returnDespiteTimeout` - Return data despite timeout (default: false)
- `enableXhr` - Enable XMLHttpRequest (default: false)
- `customJs` - Custom JavaScript code (max 2000 chars)
- `validateMicromarkup` - Enable microdata validation (default: false)
- `checkSpell` - Check spelling using Hunspell (default: false)
- `checksThreshold` - Custom threshold values for checks
- `switchPool` - Use additional proxy pools (default: false)
- `ipPoolForScan` - Proxy pool location ('us', 'de')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->onPageInstantPages('https://example.com');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->onPageInstantPages(
    url: 'https://example.com',
    browserPreset: 'desktop',
    enableJavascript: true,
    enableBrowserRendering: true,
    loadResources: true,
    validateMicromarkup: true
);
```

## Task Methods

### onPageTaskPost()

Submit a task for comprehensive website crawling and analysis.

**Parameters:**
- `target` - Target domain (required, without https:// and www.)
- `maxCrawlPages` - Number of pages to crawl (required)
- `startUrl` - First URL to crawl (absolute URL)
- `forceSitewideChecks` - Enable sitewide checks when crawling single page
- `priorityUrls` - URLs to crawl bypassing queue (max 20, absolute URLs)
- `maxCrawlDepth` - Crawl depth level
- `crawlDelay` - Delay between hits in milliseconds (default: 2000)
- `storeRawHtml` - Store HTML of crawled pages (default: false)
- `enableContentParsing` - Parse content on crawled pages (default: false)
- `supportCookies` - Support cookies when crawling (default: false)
- `acceptLanguage` - Language header for accessing website
- `customRobotsTxt` - Custom robots.txt settings
- `robotsTxtMergeMode` - Merge mode: 'merge' or 'override' (default: 'merge')
- `customUserAgent` - Custom user agent
- `browserPreset` - Browser preset ('desktop', 'mobile', 'tablet')
- `browserScreenWidth` - Browser screen width (240-9999 pixels)
- `browserScreenHeight` - Browser screen height (240-9999 pixels)
- `browserScreenScaleFactor` - Browser screen scale factor (0.5-3)
- `respectSitemap` - Follow sitemap order when crawling (default: false)
- `customSitemap` - Custom sitemap URL
- `crawlSitemapOnly` - Crawl only pages in sitemap (default: false)
- `loadResources` - Load images, stylesheets, scripts (default: false)
- `enableWwwRedirectCheck` - Check www redirection (default: false)
- `enableJavascript` - Load JavaScript on pages (default: false)
- `enableXhr` - Enable XMLHttpRequest (default: false)
- `enableBrowserRendering` - Emulate browser for Core Web Vitals (default: false)
- `disableCookiePopup` - Disable cookie consent popup (default: false)
- `customJs` - Custom JavaScript (max 2000 chars, 700ms execution)
- `validateMicromarkup` - Enable microdata validation (default: false)
- `allowSubdomains` - Include subdomains (default: false)
- `allowedSubdomains` - Specific subdomains to crawl
- `disallowedSubdomains` - Subdomains to exclude
- `checkSpell` - Check spelling using Hunspell (default: false)
- `checkSpellLanguage` - Spell check language code
- `checkSpellExceptions` - Words to exclude from spell check (max 1000, 100 chars each)
- `calculateKeywordDensity` - Calculate keyword density (default: false)
- `checksThreshold` - Custom threshold values for checks
- `disableSitewideChecks` - Prevent certain sitewide checks
- `disablePageChecks` - Prevent certain page checks
- `switchPool` - Use additional proxy pools (default: false)
- `returnDespiteTimeout` - Return data despite timeout (default: false)
- `pingbackUrl` - Notification URL for task completion

**Basic Usage:**
```php
$result = $dfs->onPageTaskPost(
    target: 'example.com',
    maxCrawlPages: 100
);
$taskId = $result['response']['tasks'][0]['id'];
```

**Advanced Usage:**
```php
$result = $dfs->onPageTaskPost(
    target: 'example.com',
    maxCrawlPages: 500,
    startUrl: 'https://example.com/products',
    maxCrawlDepth: 3,
    enableContentParsing: true,
    validateMicromarkup: true,
    calculateKeywordDensity: true
);
```

### onPageSummary()

Get summary information for a completed onPage task.

The `OnPage API` has no specific `Task GET` endpoint. So there is no `onPageTaskGet` method.

Other endpoints like `Summary`, `Pages` etc. can be used with the task ID instead.

**Parameters:**
- `id` - Task ID from Task POST response (required)

**Usage:**
```php
$result = $dfs->onPageSummary($taskId);
```

### onPagePages()

Get detailed page analysis results for a completed task.

**Parameters:**
- `id` - Task ID from Task POST response (required)
- `limit` - Maximum number of pages to return (default: 100, max: 1000)
- `offset` - Offset in results array (default: 0)
- `filters` - Array of filtering parameters (max 8)
- `orderBy` - Results sorting rules (max 3)
- `searchAfterToken` - Token for subsequent requests

**Basic Usage:**
```php
$result = $dfs->onPagePages($taskId);
```

**Advanced Usage:**
```php
$result = $dfs->onPagePages(
    id: $taskId,
    limit: 50,
    offset: 0,
    filters: [
        ["resource_type", "=", "html"],
        'and',
        ["meta.scripts_count", ">", 40]
    ],
    orderBy: ['meta.content.plain_text_word_count,desc']
);
```

### onPageResources()

Get resource analysis (images, scripts, stylesheets) for crawled pages.

**Parameters:**
- `id` - Task ID from Task POST response (required)
- `url` - Specific URL to analyze resources for
- `limit` - Maximum number of resources to return (default: 100, max: 1000)
- `offset` - Offset in results array (default: 0)
- `filters` - Array of filtering parameters (max 8)
- `relevantPagesFilters` - Array of relevant pages filtering parameters
- `orderBy` - Results sorting rules (max 3)
- `searchAfterToken` - Token for subsequent requests

**Usage:**
```php
$result = $dfs->onPageResources(
    id: $taskId,
    url: 'https://example.com/page',
    limit: 100
);
```

### onPageWaterfall()

Get waterfall chart data for page loading performance.

**Parameters:**
- `id` - Task ID from Task POST response (required)
- `url` - Specific URL to get waterfall data for (required)

**Usage:**
```php
$result = $dfs->onPageWaterfall($taskId, 'https://example.com');
```

### onPageKeywordDensity()

Get keyword density analysis for crawled pages.

**Parameters:**
- `id` - Task ID from Task POST response (required)
- `keywordLength` - Length of keywords to analyze (required)
- `url` - Specific URL to analyze
- `limit` - Maximum number of keywords to return (default: 100, max: 1000)
- `filters` - Array of filtering parameters (max 8)
- `orderBy` - Results sorting rules (max 3)

**Usage:**
```php
$result = $dfs->onPageKeywordDensity(
    id: $taskId,
    keywordLength: 2,
    url: 'https://example.com'
);
```

### onPageRawHtml()

Get raw HTML content of crawled pages.

**Parameters:**
- `id` - Task ID from Task POST response (required)
- `url` - Specific URL to get HTML for

**Usage:**
```php
$result = $dfs->onPageRawHtml($taskId, 'https://example.com');
```

#### Saving Response Bodies to Files

Save all cached raw HTML response bodies to files:

```php
// Save all raw HTML responses to files
$stats = $dfs->saveAllResponseBodiesToFile(endpoint: 'on_page/raw_html');
print_r($stats);

// Save with custom options
$stats = $dfs->saveAllResponseBodiesToFile(
    batchSize: 10,                         // Process 10 at a time
    endpoint: 'on_page/raw_html',          // Only raw HTML endpoint
    savePath: 'storage/app/scraped',       // Custom directory
    overwriteExisting: true                // Overwrite existing files
);
```

Files are saved as `{id}-{url-slug}.html` in the specified directory.

### onPageContentParsing()

Get parsed content from crawled pages.

**Parameters:**
- `url` - URL to parse content from (required)
- `id` - Task ID from Task POST response (required)
- `markdownView` - Return content in markdown format

**Usage:**
```php
$result = $dfs->onPageContentParsing(
    url: 'https://example.com',
    id: $taskId,
    markdownView: true
);
```

## Hybrid Live and Task Methods

### onPageInstantPagesWithRawHtml()

Get immediate raw HTML results for a single URL. Internally calls `onPageInstantPages` with `storeRawHtml: true` and retrieves the task ID, then calls `onPageRawHtml` with the task ID to get the HTML content.

Has same parameters as `onPageInstantPages` with the exception of `storeRawHtml` which is forced to `true`.

**Parameters:**
- `url` - Target URL (required, must be absolute URL)
- `customUserAgent` - Custom user agent string
- `browserPreset` - Browser preset ('desktop', 'mobile', 'tablet')
- `browserScreenWidth` - Browser screen width (240-9999 pixels)
- `browserScreenHeight` - Browser screen height (240-9999 pixels)
- `browserScreenScaleFactor` - Browser screen scale factor (min: 0.5, max: 3)
- `acceptLanguage` - Language header for request
- `loadResources` - Load images, stylesheets, scripts (default: false)
- `enableJavascript` - Enable JavaScript execution (default: false)
- `enableBrowserRendering` - Enable browser rendering for Core Web Vitals (default: false)
- `disableCookiePopup` - Disable cookie consent popup (default: false)
- `returnDespiteTimeout` - Return data despite timeout (default: false)
- `enableXhr` - Enable XMLHttpRequest (default: false)
- `customJs` - Custom JavaScript code (max 2000 chars)
- `validateMicromarkup` - Enable microdata validation (default: false)
- `checkSpell` - Check spelling using Hunspell (default: false)
- `checksThreshold` - Custom threshold values for checks
- `switchPool` - Use additional proxy pools (default: false)
- `ipPoolForScan` - Proxy pool location ('us', 'de')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$result = $dfs->onPageInstantPagesWithRawHtml('https://example.com');
$response = $result['response'];
$json = $response->json();
```

**Advanced Usage:**
```php
$result = $dfs->onPageInstantPagesWithRawHtml(
    url: 'https://example.com',
    switchPool: true,
    ipPoolForScan: 'us'
);
```

## Typical Workflow for Task Post Methods

1. **Submit Task**: Use `onPageTaskPost()` to start crawling
2. **Check Status**: Use `onPageSummary()` to check completion
3. **Get Results**: Use `onPagePages()` to retrieve detailed analysis
4. **Additional Analysis**: Use specialized methods like `onPageKeywordDensity()` or `onPageResources()`

**Example Workflow:**
```php
$dfs = new DataForSeoApiClient();
$url = 'yahoo.com';

// Submit task
$result = $dfs->onPageTaskPost($url, 3, acceptLanguage: 'en');
$taskId = $result['response']['tasks'][0]['id'];

// Wait until crawl completed. Can take a long time.

// Check status
$result = $dfs->onPageSummary($taskId);

// Get detailed results
$result = $dfs->onPagePages($taskId);
$result = $dfs->onPageResources($taskId);
$result = $dfs->onPageKeywordDensity($taskId, 2);

// Use full URL for Waterfall, Raw HTML and Content Parsing endpoints
$result = $dfs->onPageWaterfall($taskId, 'https://www.' . $url);
$result = $dfs->onPageRawHtml($taskId, 'https://www.' . $url);
$result = $dfs->onPageContentParsing(url: 'https://www.' . $url, id: $taskId);
```