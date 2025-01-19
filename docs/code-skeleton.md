# Code Skeleton Structure

## Project Structure Overview

```
├── config/
│   └── api-cache.php
├── database/
│   └── migrations/
│       └── create_demo_cache_tables.php
├── src/
│   ├── ApiCacheHandler.php
│   ├── BaseApiClient.php
│   ├── CacheRepository.php
│   ├── CompressionService.php
│   ├── RateLimitService.php
│   ├── OpenAIApiClient.php
│   └── DemoApiClient.php
└── tests/
    ├── Unit/
    │   ├── ApiCacheHandlerTest.php
    │   ├── BaseApiClientTest.php
    │   ├── CacheRepositoryTest.php
    │   ├── CompressionServiceTest.php
    │   └── RateLimitServiceTest.php
    └── Integration/
        ├── ApiClientIntegrationTest.php
        ├── CacheStorageIntegrationTest.php
        └── RateLimitIntegrationTest.php
```

## Core Components

### Base API Client

```php
namespace FOfX\ApiCache;

abstract class BaseApiClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected ?string $version;
    protected HttpClient $client;
    
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?string $version = null
    );
    
    abstract public function getClientName(): string;
    abstract public function buildUrl(string $endpoint): string;
    public function getRateLimitKey(): string;
    public function sendRequest(string $endpoint, array $params = [], string $method = 'GET'): array;
}
```

### Child API Clients

These child classes extend the BaseApiClient class and provide specific implementations for different APIs.

In array_merge(), required parameters are passed after $additionalParams. This allows them to override $additionalParams
in case of a conflict.

```php
// DemoApiClient.php
namespace FOfX\ApiCache;

class DemoApiClient extends BaseApiClient
{
    public function getClientName(): string 
    {
        return 'demo';
    }
    
    public function buildUrl(string $endpoint): string 
    {
        return "{$this->baseUrl}/{$endpoint}";
    }
    
    // Additional methods specific to demo API
    public function prediction(
        string $query,
        int $maxResults = 10,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'query' => $query,
            'max_results' => $maxResults,
        ]);

        return $this->sendRequest('predictions', $params, 'GET');
    }

    public function report(
        string $reportType,
        string $dataSource,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ]);

        return $this->sendRequest('reports', $params, 'POST');
    }
}

// OpenAIApiClient.php
namespace FOfX\ApiCache;

class OpenAIApiClient extends BaseApiClient
{
    public function getClientName(): string 
    {
        return 'openai';
    }
    
    public function buildUrl(string $endpoint): string 
    {
        return "{$this->baseUrl}/{$endpoint}";
    }

    public function completions(
        string $prompt,
        string $model = 'gpt-3.5-turbo-instruct',
        int $maxTokens = 16,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'prompt'       => $prompt,
            'model'        => $model,
            'max_tokens'   => $maxTokens,
            'n'            => $n,
            'temperature'  => $temperature,
            'top_p'        => $topP,
        ]);

        return $this->sendRequest('completions', $params, 'POST');
    }

    public function chatCompletions(
        array|string $messages,
        string $model = 'gpt-4o-mini',
        ?int $maxCompletionTokens = null,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
        // If messages is a string, assume it is a prompt and wrap it in an array
        if (is_string($messages)) {
            $messages = ['role' => 'user', 'content' => $messages];
        }

        $params = array_merge($additionalParams, [
            'messages'     => $messages,
            'model'        => $model,
            'n'            => $n,
            'temperature'  => $temperature,
            'top_p'        => $topP,
        ]);

        if ($maxCompletionTokens !== null) {
            $params['max_completion_tokens'] = $maxCompletionTokens;
        }

        return $this->sendRequest('chat/completions', $params, 'POST');
    }
}
```

### Services

```php
// ApiCacheHandler.php
namespace FOfX\ApiCache;

class ApiCacheHandler
{
    protected CacheRepository $repository;
    protected RateLimitService $rateLimiter;
    
    public function __construct(
        CacheRepository $repository,
        RateLimitService $rateLimiter
    ) {
        $this->repository = $repository;
        $this->rateLimiter = $rateLimiter;
    }
    
    public function processRequest(
        BaseApiClient $client,
        string $endpoint,
        array $params = []
    ): array {
        // Algorithm:
        // - Generate cache key from client name, endpoint, params, version
        // - Look for cached response
        // - If valid cached response exists, return it
        // - If no cache or expired:
        //    - Check rate limits before proceeding
        //    - Make API request
        //    - Store response in cache
        //    - Return response
        
        $cacheKey = $this->generateCacheKey(
            $client->getClientName(),
            $endpoint,
            $params,
            $client->version
        );
        
        // Rest of implementation will go here...
    }
    
    protected function getCachedResponse(string $cacheKey): ?array 
    {
        // Algorithm:
        // - Get response from repository
        // - Check if response has expired
        // - Return response if valid, null if expired
    }
    
    protected function cacheResponse(
        string $cacheKey, 
        array $response, 
        ?int $ttl = null
    ): void {
        // Algorithm:
        // - Calculate expiry time from ttl
        // - Prepare response metadata (based on Laravel HTTP client's response):
        //   - response_status_code (from HTTP response status)
        //   - response_headers (from HTTP response headers)
        //   - response_body (from HTTP response body)
        //   - response_size (calculated from response body)
        //   - response_time (total execution time in seconds)
        //   - expires_at
        //   Example:
        //   $metadata = [
        //       'response_status_code' => $response->status(),
        //       'response_headers' => json_encode($response->headers()),
        //       'response_body' => $response->body(),
        //       'response_size' => strlen($response->body()),
        //       'response_time' => $endTime - $startTime,
        //       'expires_at' => $expiryTime
        //   ];
        //   Note: Response headers are JSON encoded before storage
        //         Response body is stored as raw string
        // - Store in repository with compression if enabled
    }
    
    protected function generateCacheKey(
        string $client,
        string $endpoint,
        array $params,
        ?string $version = null
    ): string {
        // Algorithm:
        // - Normalize params (sort, filter)
        // - Combine client, endpoint, params, version
        // - Generate hash that uniquely identifies this request
    }
}

// RateLimitService.php
namespace FOfX\ApiCache;

class RateLimitService
{
    protected RateLimiter $limiter;
    
    public function __construct(RateLimiter $limiter);
    public function allowRequest(string $key, int $maxAttempts): bool;
    public function incrementAttempts(string $key): void;
    public function remaining(string $key): int;
}

// CompressionService.php
namespace FOfX\ApiCache;

class CompressionService
{
    protected bool $enabled = false;
    
    public function __construct(bool $enabled = false);
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function isEnabled(): bool;
}
```

### Repository

```php
// CacheRepository.php
namespace FOfX\ApiCache;

class CacheRepository
{
    protected Connection $db;
    protected CompressionService $compression;
    
    public function __construct(Connection $db, CompressionService $compression);
    public function store(string $client, string $key, array $data, ?int $ttl = null): void;
    public function get(string $client, string $key): ?array;
    public function exists(string $client, string $key): bool;
    public function cleanup(): void;
}
```

### Database Migrations

```php
// create_demo_cache_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard response table
        Schema::create('api_cache_demo_responses', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url');
            $table->string('full_url');
            $table->string('method');
            $table->mediumText('request_headers');
            $table->mediumText('request_body');
            $table->integer('response_status_code');
            $table->mediumText('response_headers');
            $table->mediumText('response_body');
            $table->integer('response_size');
            $table->double('response_time');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Compressed response table
        Schema::create('api_cache_demo_responses_compressed', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url');
            $table->string('full_url');
            $table->string('method');
            $table->mediumBlob('request_headers_compressed');
            $table->mediumBlob('request_body_compressed');
            $table->integer('response_status_code');
            $table->mediumBlob('response_headers_compressed');
            $table->mediumBlob('response_body_compressed');
            $table->integer('response_size_compressed');
            $table->double('response_time');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_responses');
        Schema::dropIfExists('api_cache_demo_responses_compressed');
    }
};
```

### Configuration

```php
// api-cache.php
return [
    'apis' => [
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'cache_ttl' => env('OPENAI_CACHE_TTL', null),
            'rate_limit' => [
                'requests_per_minute' => 60,
            ],
            'compression' => [
                'enabled' => false,
            ],
        ],
        'demo' => [
            'base_url' => env('DEMO_API_BASE_URL', 'http://localhost:8000/demo-api/v1'),
            'api_key' => null,
            'cache_ttl' => env('DEMO_API_CACHE_TTL', null),
            'rate_limit' => [
                'requests_per_minute' => 1000,
            ],
            'compression' => [
                'enabled' => false,
            ],
        ],
    ],
];
```