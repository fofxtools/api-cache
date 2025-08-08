# API Cache Library Blueprint

## What This Library Solves

Modern applications consume multiple third-party APIs (OpenAI, DataForSEO, YouTube, etc.) facing common challenges:
- **High API costs** from repeated identical requests
- **Rate limiting** across multiple application instances  
- **Complex error handling** across different API providers

**Solution**: A unified Laravel library that provides intelligent caching, distributed rate limiting, and consistent API client management.

## Core Architecture

### Five Key Components

#### 1. **BaseApiClient** (Template)
**Purpose**: Standard interface for all API integrations

**Key Features**:
- `sendCachedRequest()` - Main entry point with full caching lifecycle
- `getAuthHeaders()` / `getAuthParams()` - Flexible authentication strategies  
- `shouldCache()` - Custom caching logic per API
- Built-in error handling and request logging

#### 2. **ApiCacheManager** (Orchestrator)  
**Purpose**: Coordinates caching and rate limiting decisions

**Key Features**:
- `generateCacheKey()` - Consistent SHA1-based cache keys
- `getCachedResponse()` - Retrieve and reconstruct cached responses
- `allowRequest()` - Rate limit enforcement gateway

#### 3. **CacheRepository** (Data Layer)
**Purpose**: Database operations with compression support

**Key Features**:
- Per-client table isolation (`api_cache_openai_responses`)
- Optional compression/decompression of large responses
- TTL-based expiration with cleanup
- JSON pretty-printing for debugging

#### 4. **RateLimitService** (Distributed Control)
**Purpose**: Redis-based rate limiting across multiple app instances

**Key Features**:
- Configurable per-client limits and time windows
- Thread-safe distributed enforcement
- Graceful handling of unlimited rate limits

#### 5. **CompressionService** (Storage Optimization)
**Purpose**: Optional gzip compression for large API responses

**Key Features**:
- Per-client enable/disable configuration
- Transparent compression during storage
- Automatic decompression during retrieval

## How It Works

### Request Flow
1. **Client calls** `sendCachedRequest()`  
2. **Generate cache key** from endpoint + parameters
3. **Check cache** → Return immediately if found
4. **Check rate limit** → Throw exception if exceeded
5. **Make HTTP request** to external API
6. **Track usage** for rate limiting
7. **Store response** (if successful and cacheable)
8. **Return result** to client

### Configuration Per API
```env
# .env file
OPENAI_API_KEY=your-api-key-here
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_VERSION=v1
OPENAI_CACHE_TTL=null
OPENAI_COMPRESSION_ENABLED=false
OPENAI_RATE_LIMIT_MAX_ATTEMPTS=60
OPENAI_RATE_LIMIT_DECAY_SECONDS=60
```

## Supported APIs

### Current Integrations
- **OpenAI** - Chat completions, text generation
- **DataForSEO** - SERP results, keywords, backlinks, merchant data  
- **Pixabay** - Image/video search with download capabilities
- **YouTube** - Video search and metadata
- **ScraperAPI/ScrapingDog** - Web scraping services
- **Jina AI** - Reader, search, reranking
- **OpenRouter** - Multi-model AI access
- **OpenPageRank** - Domain authority metrics

### Adding New APIs
Extend `BaseApiClient` with:
1. Authentication method (`getAuthHeaders()` or `getAuthParams()`)
2. Endpoint methods with typed parameters  
3. Custom validation logic via `shouldCache()`

## Database Design

### Response Storage
**Table Pattern**: `api_cache_{client}_responses[_compressed]`

**Core Schema**:
- `key` - Unique cache identifier
- `endpoint` - API endpoint called
- `request_*` - Full request metadata  
- `response_*` - Response data and headers
- `expires_at` - TTL expiration timestamp
- `cost` - API usage cost tracking

### Advanced Processing  
**DataForSEO Processors**: Extract structured data from complex API responses into dedicated tables
- SERP results and People Also Ask items
- Keyword research metrics  
- Amazon product listings
- Backlink analysis data

## Key Design Decisions

### Caching Strategy
- **Deterministic keys** based on normalized parameters
- **Per-client TTL** configuration (null = infinite)
- **Compression** for large responses (optional per client)
- **Manual cleanup** of expired responses via `deleteExpired()` method

### Rate Limiting Strategy
- **Redis-backed** for multi-instance consistency
- **Per-client limits** respecting API quotas
- **Custom exceptions** with retry timing information
- **Fail-fast approach** before making expensive API calls

### Error Handling
- **Fail-fast approach** - Throws exceptions on compression/decompression failures
- **Structured logging** with contextual information across
- **Exception classification** - Connection, HTTP, rate limit errors
- **Error logging** - Optional database logging of API errors

## Requirements

### System Dependencies
- **PHP 8.3+** for modern language features
- **Laravel 11.38+** for framework integration  
- **Redis** for distributed rate limiting
- **Database** (MySQL/PostgreSQL) for response storage

### Performance Benefits
- **Potential cost savings** by avoiding repeated identical API calls
- **Improved response times** for cached requests
- **Rate limit protection** to help avoid API quota violations
- **Multi-instance coordination** via Redis-backed rate limiting

## Usage Example

```php
// Initialize client
$client = new OpenAIApiClient();

// Configure caching
$client->setUseCache(true);
$client->setTimeout(30);

// Make cached request
$response = $client->chatCompletion([
    'model' => 'gpt-4',
    'messages' => [['role' => 'user', 'content' => 'Hello']]
]);

// Response includes caching metadata
$isCached = $response['is_cached']; // true/false
$cost = $response['request']['cost']; // API cost if applicable
```

## Extension Points

### Custom API Clients
- Override authentication methods
- Implement endpoint-specific parameter validation
- Add custom cost calculation logic
- Define caching rules via `shouldCache()`

### Data Processing  
- **DataForSEO processors** available for extracting structured data:
  - SERP results and autocomplete suggestions
  - Keyword research and Google Ads data  
  - Amazon product listings and ASIN details
  - Backlinks analysis data
- Batch processing with configurable limits
- Duplicate detection and update strategies