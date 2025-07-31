# DataForSEO Backlinks Bulk Methods

## Overview

The DataForSEO Backlinks Bulk API provides efficient batch methods for retrieving backlink data across multiple targets. All bulk methods are **Live** endpoints that return immediate results. You can analyze up to 1,000 targets per request.

## Live Methods

### backlinksBulkRanksLive()

Get domain authority ranks for multiple targets in a single request.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)
- `rankScale` - Scale for rank calculation: 'one_hundred' or 'one_thousand' (default: 'one_thousand')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

$targets = ['example.com', 'google.com', 'facebook.com'];
$result = $dfs->backlinksBulkRanksLive($targets);
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkBacklinksLive()

Get total backlinks count for multiple targets in a single request.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)

**Basic Usage:**
```php
$targets = ['example.com', 'google.com', 'facebook.com'];
$result = $dfs->backlinksBulkBacklinksLive($targets);
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkSpamScoreLive()

Get spam scores for multiple targets in a single request.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)

**Basic Usage:**
```php
$targets = ['example.com', 'suspicious-site.com', 'trusted-site.org'];
$result = $dfs->backlinksBulkSpamScoreLive($targets);
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkReferringDomainsLive()

Get referring domains count for multiple targets in a single request.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)

**Basic Usage:**
```php
$targets = ['example.com', 'competitor1.com', 'competitor2.com'];
$result = $dfs->backlinksBulkReferringDomainsLive($targets);
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkNewLostBacklinksLive()

Get new and lost backlinks data for multiple targets within a specified date range.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)
- `dateFrom` - Starting date in yyyy-mm-dd format (default: today minus one month, minimum value equals today’s date -(minus) one year)

**Basic Usage:**
```php
$targets = ['example.com', 'competitor1.com'];
$result = $dfs->backlinksBulkNewLostBacklinksLive($targets, '2025-06-01');
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkNewLostReferringDomainsLive()

Get new and lost referring domains data for multiple targets within a specified date range.

**Parameters:**
- `targets` - Array of domains, subdomains or webpages (required, max 1000)
- `dateFrom` - Starting date in yyyy-mm-dd format (default: today minus one month, minimum value equals today’s date -(minus) one year)

**Basic Usage:**
```php
$targets = ['example.com', 'competitor1.com'];
$result = $dfs->backlinksBulkNewLostReferringDomainsLive($targets, '2025-06-01');
$response = $result['response'];
$json = $response->json();
```

### backlinksBulkPagesSummaryLive()

Get comprehensive backlink summary data for multiple pages, domains, or subdomains.

**Parameters:**
- `targets` - Array of pages, domains or subdomains (required, max 1000, max 100 different domains)
- `includeSubdomains` - Include subdomains of target (default: true)
- `rankScale` - Scale for rank calculation: 'one_hundred' or 'one_thousand' (default: 'one_thousand')

**Basic Usage:**
```php
$targets = ['example.com', 'http://example.com/page1', 'http://example.com/page2'];
$result = $dfs->backlinksBulkPagesSummaryLive($targets, true, 'one_thousand');
$response = $result['response'];
$json = $response->json();
```

## Response Item Processor

The `DataForSeoBacklinksBulkProcessor` class processes API responses and extracts bulk data into the database.

### Usage

```php
$processor = new DataForSeoBacklinksBulkProcessor();

// Optionally reset processed columns and clear tables
//$processor->resetProcessed();
//$processor->clearProcessedTables();

// Process responses and extract bulk items
$stats = $processor->processResponses(limit: 100);
```

### Features

- Extracts bulk backlink data into `dataforseo_backlinks_bulk_items` table
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
- `bulk_items` - Bulk items found
- `items_inserted` - New items inserted
- `items_updated` - Items updated
- `items_skipped` - Items skipped
- `total_items` - Total items processed
- `errors` - Processing errors

### Data Structure

#### Bulk Items (`dataforseo_backlinks_bulk_items`)

Each bulk item contains:
- `target` - Target domain, subdomain, or page
- `rank` - Domain authority rank
- `main_domain_rank` - Main domain rank
- `backlinks` - Total backlinks count
- `new_backlinks` - New backlinks count
- `lost_backlinks` - Lost backlinks count
- `broken_backlinks` - Broken backlinks count
- `broken_pages` - Broken pages count
- `spam_score` - Spam score
- `backlinks_spam_score` - Backlinks spam score
- `referring_domains` - Referring domains count
- `referring_domains_nofollow` - Nofollow referring domains
- `referring_main_domains` - Referring main domains
- `referring_main_domains_nofollow` - Nofollow referring main domains
- `new_referring_domains` - New referring domains
- `lost_referring_domains` - Lost referring domains
- `new_referring_main_domains` - New referring main domains
- `lost_referring_main_domains` - Lost referring main domains
- `first_seen` - First seen date
- `lost_date` - Lost date
- `referring_ips` - Referring IPs count
- `referring_subnets` - Referring subnets count
- `referring_pages` - Referring pages count
- `referring_pages_nofollow` - Nofollow referring pages

For testing the processor, see `examples/dfs_backlinks_bulk_test.php`.