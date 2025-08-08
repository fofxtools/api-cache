# API Cache Library

A Laravel-based PHP library for caching API responses from various services (OpenAI, DataForSEO, Pixabay, YouTube, and more). Provides intelligent caching, rate limiting, compression, and response management through a unified interface.

## Requirements

- **PHP 8.3+**
- **Laravel 11.38+**
- **Redis** (for distributed rate limiting)

## Development Setup

1. Clone the repository
2. Install dependencies:
```bash
composer install
```
3. Copy `.env.example` to `.env`
4. For testing the demo API:
```bash
php -S 0.0.0.0:8000 -t public
```

## Documentation

Please see the [docs](docs) folder for:
- [Laravel Integration Guide](docs/laravel-integration.md) - How to use this library in a full Laravel project
- [Usage](docs/usage-intro.md) - Basic setup guide
- [Database Migrations](docs/database-migrations.md) - Explanation of unconventional approach to database migrations
- [Rate Limiting](docs/rate-limiting.md) - Rate limiting with Redis
- [Cloudflare Tunnel](docs/cloudflare-tunnel.md) - Usage of Cloudflare Tunnel for local development

### Diagrams
- [Workflow Diagram](docs/diagrams/workflow-diagram.mmd)
- [Sequence Diagram](docs/diagrams/sequence-diagram.mmd)

## Features

- API response caching
- Rate limiting with Redis
- Compression support
- Multiple API client support

## Caching Control

The `sendCachedRequest()` method respects the caching settings of the API client. You can control caching behavior using:

```php
// Create a new client instance
$client = new ScraperApiClient();

// Disable caching for a specific request
$client->setUseCache(false);

// Check current caching status
$isCachingEnabled = $client->getUseCache();
echo 'Is caching enabled: ' . ($isCachingEnabled ? 'true' : 'false') . PHP_EOL;

$response = $client->scrape('https://httpbin.org/headers');
echo format_api_response($response, true);

// Re-enable caching
$client->setUseCache(true);
```

## Rate Limiting

Redis-based distributed rate limiting is implemented to ensure consitent rate limiting across multiple application instances.

See [Rate Limiting Documentation](docs/rate-limiting.md) for details.

## Database Migrations

This library takes an unconventional approach to database migrations in order to follow the DRY principle and simplify maintenance across multiple clients, while maintaining consistency between tables.

For more details, see [Database Migrations Documentation](docs/database-migrations.md).

## Laravel Integration

To use this library in a full Laravel project, see the [Laravel Integration Guide](docs/laravel-integration.md) for step-by-step setup instructions including installation, configuration, and usage examples.

## Usage

### Getting Started
- [Usage Introduction](docs/usage-intro.md) - Basic setup and usage guide

### API Clients
- [Jina AI](docs/usage-jina.md)
- [OpenAI](docs/usage-openai.md)
- [OpenPageRank](docs/usage-openpagerank.md)
- [OpenRouter](docs/usage-openrouter.md)
- [Pixabay](docs/usage-pixabay.md)
- [ScraperAPI](docs/usage-scraperapi.md)
- [ScrapingDog](docs/usage-scrapingdog.md)
- [YouTube](docs/usage-youtube.md)

### DataForSEO API
- [DataForSEO Setup](docs/usage-dataforseo-setup.md)
- [DataForSEO Webhooks](docs/usage-dataforseo-webhooks.md)
- [SERP Google Organic](docs/usage-dataforseo-serpGoogleOrganic.md)
- [SERP Google Autocomplete](docs/usage-dataforseo-serpGoogleAutocomplete.md)
- [Keywords Data Google Ads](docs/usage-dataforseo-keywordsDataGoogleAds.md)
- [Labs](docs/usage-dataforseo-labs.md)
- [Labs Google](docs/usage-dataforseo-labsGoogle.md)
- [Merchant Amazon Products](docs/usage-dataforseo-merchantAmazonProducts.md)
- [Merchant Amazon ASIN](docs/usage-dataforseo-merchantAmazonAsin.md)
- [On Page](docs/usage-dataforseo-onPage.md)
- [Backlinks](docs/usage-dataforseo-backlinks.md)
- [Backlinks Bulk](docs/usage-dataforseo-backlinksBulk.md)

### Utilities
- [Responses Table Converters](docs/usage-responses-table-converters.md) - Can be used to convert between compressed and uncompressed response tables

## License

MIT