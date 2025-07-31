# DataForSEO API Webhooks

## Overview

DataForSEO API provides two methods for retrieving data:

- **Live Methods** - Get immediate results but with higher costs
- **Standard Methods** - Submit tasks for processing and receive delayed results via webhooks or polling (cheaper)

This documentation covers the standard webhook endpoints available in the DataForSeoApiClient. These methods submit tasks to DataForSEO's queue and return results asynchronously, making them cost-effective for bulk operations.

**How it works:**
- Your app calls a standard method with `usePingback: true` and/or `usePostback: true`. These options are non-exclusive - you can enable either one independently, or both simultaneously.
- DataForSEO queues the task and returns a task ID
- When complete, DataForSEO sends results to your webhook URL

### Pingback vs Postback

| Mode | Method | Payload | Best for |
|------|--------|---------|----------|
| **Pingback** | `GET` | Task ID only | Gives the task ID, must fetch task |
| **Postback** | `POST` | Full results | Complete JSON result |

## Webhook Setup

The webhook handling is implemented in `public/pingback.php` and `public/postback.php`. Configure the `.env` URLs to point to these files:

```env
DATAFORSEO_POSTBACK_URL=https://your-domain.com/postback.php
DATAFORSEO_PINGBACK_URL=https://your-domain.com/pingback.php
```

Then create your client normally:

```php
$dfs = new DataForSeoApiClient();
```

For local development, you can start a Cloudflare tunnel and PHP local server. On Windows they should be started in the same environment (e.g. both in Windows or both in WSL), and the script should also be run in that environment:

```bash
# Terminal 1: Start tunnel
cloudflared tunnel --url http://localhost:8000

# Terminal 2: Start PHP server
php -S 0.0.0.0:8000 -t public
```

## Method Examples

### SERP Google Organic

**Standard Regular**
```php
$result = $dfs->serpGoogleOrganicStandardRegular(
    keyword: 'desktop computers',
    usePostback: true,
    postTaskIfNotCached: true
);
$response = $result['response'];
$json = $response->json();
```

**Standard Advanced**
```php
$result = $dfs->serpGoogleOrganicStandardAdvanced(
    keyword: 'laptop computers',
    peopleAlsoAskClickDepth: 4,
    loadAsyncAiOverview: true,
    expandAiOverview: true,
    usePostback: true,
    postTaskIfNotCached: true
);
```

### SERP Google Autocomplete

**Standard Advanced**
```php
$result = $dfs->serpGoogleAutocompleteStandardAdvanced(
    keyword: 'notebook computers',
    usePostback: true,
    postTaskIfNotCached: true
);
```

### Keywords Data Google Ads

**Search Volume Standard**
```php
$result = $dfs->keywordsDataGoogleAdsSearchVolumeStandard(
    keywords: ['laptop', 'computer', 'notebook'],
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Keywords For Site Standard**
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForSiteStandard(
    target: 'example.com',
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Keywords For Keywords Standard**
```php
$result = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsStandard(
    keywords: ['digital marketing', 'seo'],
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Ad Traffic By Keywords Standard**
```php
$result = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsStandard(
    keywords: ['buy laptop', 'laptop deals'],
    bid: 2.50,
    match: 'exact',
    usePostback: true,
    postTaskIfNotCached: true
);
```

### Merchant Amazon

**Products Standard Advanced**
```php
$result = $dfs->merchantAmazonProductsStandardAdvanced(
    keyword: 'wireless headphones',
    usePostback: true,
    postTaskIfNotCached: true
);
```

**ASIN Standard Advanced**
```php
$result = $dfs->merchantAmazonAsinStandardAdvanced(
    asin: 'B09B8V1LZ3',
    usePostback: true,
    postTaskIfNotCached: true
);
```

**Sellers Standard Advanced**
```php
$result = $dfs->merchantAmazonSellersStandardAdvanced(
    asin: 'B09B8V1LZ3',
    usePostback: true,
    postTaskIfNotCached: true
);
```

## Common Parameters

- `usePingback: true` - Enable webhook notifications
- `postTaskIfNotCached: true` - Submit task if not found in cache
- `locationCode: 2840` - USA (default for most methods)
- `languageCode: 'en'` - English (default)

## Response Handling

For `postTaskIfNotCached: true`, the method returns:
- Cached data if available
- Task creation response for posting a new task

For `postTaskIfNotCached: false`, the method returns:
- Cached data if available
- `null` if not cached