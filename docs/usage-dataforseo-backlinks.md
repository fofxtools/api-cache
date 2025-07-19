# DataForSEO Backlinks Methods

## Overview

The DataForSEO Backlinks API provides comprehensive backlink analysis for domains, subdomains, and specific webpages. All methods are **Live** methods that return immediate results.

**Note**: These methods require a DataForSEO Backlinks subscription. For testing purposes, use the sandbox environment.

## Live Methods

### backlinksSummaryLive()

Get a comprehensive summary of backlink data for a domain or webpage.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `includeSubdomains` - Include subdomains in analysis (default: true)
- `includeIndirectLinks` - Include indirect links (default: true) 
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `backlinksFilters` - Array of backlink filtering parameters
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Basic Usage:**
```php
$dfs = new DataForSeoApiClient();

// A domain or a subdomain should be specified without https:// and www.
$result = $dfs->backlinksSummaryLive('example.com');

// A page should be specified with absolute URL (including http:// or https://)
$result = $dfs->backlinksSummaryLive('https://example.com/page');

$response = $result['response'];
$data = $response->body();
```

### backlinksHistoryLive()

Get historical backlink data for a domain over time.

**Parameters:**
- `target` - Domain (required, without https:// and www.)
- `dateFrom` - Start date in yyyy-mm-dd format (minimum: 2019-01-01)
- `dateTo` - End date in yyyy-mm-dd format (default: today)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get last 6 months of backlink history
$result = $dfs->backlinksHistoryLive(
    target: 'example.com',
    dateFrom: '2024-01-01',
    dateTo: '2024-06-30'
);
```

### backlinksBacklinksLive()

Get detailed backlink data with advanced filtering and sorting options.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `mode` - Results grouping: 'as_is', 'one_per_domain', 'one_per_anchor' (default: 'as_is')
- `customMode` - Custom grouping with field and value
- `filters` - Array of filtering parameters (max 8 filters)
- `orderBy` - Array of sorting rules (max 3 rules)
- `offset` - Results offset (default: 0, max: 20,000)
- `searchAfterToken` - Token for pagination
- `limit` - Max returned backlinks (default: 100, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `includeSubdomains` - Include subdomains (default: true)
- `includeIndirectLinks` - Include indirect links (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get top 50 backlinks grouped by domain
$result = $dfs->backlinksBacklinksLive(
    target: 'example.com',
    mode: 'one_per_domain',
    limit: 50
);
```

### backlinksAnchorsLive()

Get anchor text analysis for backlinks pointing to your target.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `limit` - Max returned anchors (default: 100, max: 1000)
- `offset` - Results offset (default: 0)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `filters` - Array of filtering parameters
- `orderBy` - Array of sorting rules
- `backlinksFilters` - Backlink filtering parameters
- `includeSubdomains` - Include subdomains (default: true)
- `includeIndirectLinks` - Include indirect links (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get top anchor texts
$result = $dfs->backlinksAnchorsLive(
    target: 'example.com',
    limit: 100
);
```

### backlinksDomainPagesLive()

Get pages within a domain that have backlinks.

**Parameters:**
- `target` - Domain or subdomain (required, without https:// and www.)
- `limit` - Max returned pages (default: 100, max: 1000)
- `offset` - Results offset (default: 0)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `filters` - Array of filtering parameters
- `orderBy` - Array of sorting rules
- `backlinksFilters` - Backlink filtering parameters
- `includeSubdomains` - Include subdomains (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get pages with most backlinks
$result = $dfs->backlinksDomainPagesLive(
    target: 'example.com',
    limit: 50
);
```

### backlinksDomainPagesSummaryLive()

Get summary data for pages within a domain or specific webpage.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `limit` - Max returned anchors (default: 100, max: 1000)
- `offset` - Results offset (default: 0)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `filters` - Array of filtering parameters
- `orderBy` - Array of sorting rules
- `backlinksFilters` - Backlink filtering parameters
- `includeSubdomains` - Include subdomains (default: true)
- `includeIndirectLinks` - Include indirect links (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get page summary
$result = $dfs->backlinksDomainPagesSummaryLive('https://example.com/blog');
```

### backlinksReferringDomainsLive()

Get domains that link to your target.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `limit` - Max returned domains (default: 100, max: 1000)
- `offset` - Results offset (default: 0)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `filters` - Array of filtering parameters
- `orderBy` - Array of sorting rules
- `backlinksFilters` - Backlink filtering parameters
- `includeSubdomains` - Include subdomains (default: true)
- `includeIndirectLinks` - Include indirect links (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get top referring domains
$result = $dfs->backlinksReferringDomainsLive(
    target: 'example.com',
    limit: 200
);
```

### backlinksReferringNetworksLive()

Get IP networks or subnets that host domains linking to your target.

**Parameters:**
- `target` - Domain/subdomain or webpage URL (required)
- `networkAddressType` - Network type: 'ip', 'subnet' (default: 'ip')
- `limit` - Max returned networks (default: 100, max: 1000)
- `offset` - Results offset (default: 0)
- `internalListLimit` - Max internal list elements (default: 10, max: 1000)
- `backlinksStatusType` - Backlink status: 'all', 'live', 'lost' (default: 'live')
- `filters` - Array of filtering parameters
- `orderBy` - Array of sorting rules
- `backlinksFilters` - Backlink filtering parameters
- `includeSubdomains` - Include subdomains (default: true)
- `includeIndirectLinks` - Include indirect links (default: true)
- `excludeInternalBacklinks` - Exclude internal backlinks (default: true)
- `rankScale` - Rank scale: 'one_hundred', 'one_thousand' (default: 'one_thousand')

**Usage:**
```php
// Get referring networks by subnet
$result = $dfs->backlinksReferringNetworksLive(
    target: 'example.com',
    networkAddressType: 'subnet'
);
```

## Sandbox Testing

For testing without a paid subscription:

```php
$dfs = new DataForSeoApiClient();
$dfs->setBaseUrl('https://sandbox.dataforseo.com/v3');

$result = $dfs->backlinksSummaryLive('example.com');
```