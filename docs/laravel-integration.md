# Laravel Integration Guide

This guide shows how to integrate the API Cache library into a full Laravel project.

## Installation

### 1. Create Laravel Project
```bash
composer create-project laravel/laravel your-project-name
cd your-project-name
```

### 2. Add API Cache Library
```bash
# If using from local development
composer config repositories.local-api-cache path ../api-cache
composer require fofx/api-cache:@dev
composer install

# If using from packagist (when published)
composer require fofx/api-cache
```

## Configuration

### 1. Environment Variables
Add API credentials to your `.env` file:

```env
# OpenAI API
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_COMPRESSION_ENABLED=false

# DataForSEO API
DATAFORSEO_API_KEY=your_dataforseo_api_key
DATAFORSEO_COMPRESSION_ENABLED=false
DATAFORSEO_WEBHOOK_BASE_URL=http://your-domain.com
DATAFORSEO_LOGIN=your_dataforseo_login
DATAFORSEO_PASSWORD=your_dataforseo_password

# Redis (for rate limiting)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CLIENT=phpredis
REDIS_PREFIX=api_cache_
```

### 2. Publish Configuration
```bash
php artisan vendor:publish --provider="FOfX\ApiCache\ApiCacheServiceProvider"
```

This publishes:
- `config/api-cache.php` - API client configurations
- Database migrations for all API cache tables

### 3. Run Migrations
```bash
php artisan migrate
```

## Sample Usage

### Standalone Scripts

Create test scripts in a `scripts/` folder:

**scripts/demo.php:**
```php
<?php

use Illuminate\Contracts\Console\Kernel;

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use FOfX\ApiCache\DemoApiClient;

echo "=== Demo API Client Test ===\n\n";

$client = new DemoApiClient();

echo "Testing predictions...\n";
$result = $client->predictions('test query', 5);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n\n";

echo "Testing reports...\n";
$result = $client->reports('test report', 3);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n";
```

**scripts/dataforseo-serp-google-organic.php:**
```php
<?php

use Illuminate\Contracts\Console\Kernel;

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use FOfX\ApiCache\DataForSeoApiClient;

echo "=== DataForSEO SERP Google Organic Test ===\n\n";

$client = new DataForSeoApiClient();

echo "Testing SERP Google Organic Live Advanced...\n";
$result = $client->serpGoogleOrganicLiveAdvanced(
    keyword: 'laravel php framework',
    locationName: 'United States',
    languageName: 'English',
    peopleAlsoAskClickDepth: 1,
    loadAsyncAiOverview: true
);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n\n";

echo "Testing SERP Google Autocomplete Live Advanced...\n";
$result = $client->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'laravel framework',
    locationName: 'United States',
    languageName: 'English'
);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n\n";

echo "Testing SERP Google Autocomplete YouTube Live Advanced...\n";
$result = $client->serpGoogleAutocompleteLiveAdvanced(
    keyword: 'laravel tutorial',
    locationName: 'United States',
    languageName: 'English',
    client: 'youtube'
);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n";
```

**scripts/dataforseo-labs-google.php:**
```php
<?php

use Illuminate\Contracts\Console\Kernel;

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use FOfX\ApiCache\DataForSeoApiClient;

echo "=== DataForSEO Labs Google Test ===\n\n";

$client = new DataForSeoApiClient();

echo "Testing Labs Google Keyword Overview Live...\n";
$keywords = ['digital marketing', 'content marketing', 'email marketing'];
$result = $client->labsGoogleKeywordOverviewLive(
    keywords: $keywords,
    locationName: 'United States',
    languageName: 'English',
    includeSerpInfo: true
);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n\n";

echo "Testing Labs Google Related Keywords Live...\n";
$result = $client->labsGoogleRelatedKeywordsLive(
    keyword: 'laravel framework',
    locationName: 'United States',
    languageName: 'English',
    depth: 3,
    includeSeedKeyword: true,
    includeSerpInfo: true
);
$response = $result['response'];
$json = $response->json();

echo "Is cached: " . ($result['is_cached'] ? 'Yes' : 'No') . "\n";
echo "Response:\n";
echo json_encode($json, JSON_PRETTY_PRINT) . "\n";
```

### In Routes

Create route files for different APIs:

**routes/demo.php:**
```php
<?php

use Illuminate\Support\Facades\Route;
use FOfX\ApiCache\DemoApiClient;

Route::get('/demo-predictions', function () {
    try {
        $client = new DemoApiClient();
        $result = $client->predictions('test query', 5);
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});

Route::get('/demo-reports', function () {
    try {
        $client = new DemoApiClient();
        $result = $client->reports('test report', 3);
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});
```

**routes/dataforseo-serp-google-organic.php:**
```php
<?php

use Illuminate\Support\Facades\Route;
use FOfX\ApiCache\DataForSeoApiClient;

Route::get('/dataforseo-serp-google-organic-live', function () {
    try {
        $client = new DataForSeoApiClient();
        $result = $client->serpGoogleOrganicLiveAdvanced(
            keyword: 'laravel php framework',
            locationName: 'United States',
            languageName: 'English',
            peopleAlsoAskClickDepth: 1,
            loadAsyncAiOverview: true
        );
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});

Route::get('/dataforseo-serp-google-autocomplete-live', function () {
    try {
        $client = new DataForSeoApiClient();
        $result = $client->serpGoogleAutocompleteLiveAdvanced(
            keyword: 'laravel framework',
            locationName: 'United States',
            languageName: 'English'
        );
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});

Route::get('/dataforseo-serp-google-autocomplete-youtube-live', function () {
    try {
        $client = new DataForSeoApiClient();
        $result = $client->serpGoogleAutocompleteLiveAdvanced(
            keyword: 'laravel tutorial',
            locationName: 'United States',
            languageName: 'English',
            client: 'youtube'
        );
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});
```

**routes/dataforseo-labs-google.php:**
```php
<?php

use Illuminate\Support\Facades\Route;
use FOfX\ApiCache\DataForSeoApiClient;

Route::get('/dataforseo-labs-google-keyword-overview-live', function () {
    try {
        $keywords = ['digital marketing', 'content marketing', 'email marketing'];
        $client = new DataForSeoApiClient();
        $result = $client->labsGoogleKeywordOverviewLive(
            keywords: $keywords,
            locationName: 'United States',
            languageName: 'English',
            includeSerpInfo: true
        );
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});

Route::get('/dataforseo-labs-google-related-keywords-live', function () {
    try {
        $client = new DataForSeoApiClient();
        $result = $client->labsGoogleRelatedKeywordsLive(
            keyword: 'laravel framework',
            locationName: 'United States',
            languageName: 'English',
            depth: 3,
            includeSeedKeyword: true,
            includeSerpInfo: true
        );
        $response = $result['response'];
        $json = $response->json();
        
        return response()->json($json, 200, [], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500, [], JSON_PRETTY_PRINT);
    }
});
```

#### Register Routes

In `bootstrap/app.php`, add your route files:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/demo.php'));
            
            Route::middleware('web')
                ->group(base_path('routes/dataforseo-serp-google-organic.php'));
                
            Route::middleware('web')
                ->group(base_path('routes/dataforseo-labs-google.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```