# Pixabay API Client

The PixabayApiClient provides access to Pixabay's image and video search services.

## Configuration

Set your API key in your `.env` file:

```env
PIXABAY_API_KEY=your_api_key_here
```

## Setup

```php
require_once __DIR__ . '/examples/bootstrap.php';

use FOfX\ApiCache\PixabayApiClient;
use function FOfX\ApiCache\createClientTables;
use function FOfX\ApiCache\create_pixabay_images_table;

$schema = app('db')->connection()->getSchemaBuilder();

// Create the responses tables and pixabay_images table if not present
$clientName = 'pixabay';
createClientTables($clientName, false);
create_pixabay_images_table($schema, 'pixabay_images', false, false);

$client = new PixabayApiClient();
```

## Caching & Rate Limiting

All requests are automatically cached and rate-limited. The client handles these concerns transparently.

To disable caching for specific requests:

```php
$client->setUseCache(false);
$result = $client->searchImages('yellow flowers');
$client->setUseCache(true); // Re-enable
```

## Methods

### Image Search

Search for images on Pixabay:

```php
$result = $client->searchImages('yellow flowers');
$response = $result['response'];
$json = $response->json();
```

#### Image Search Options

```php
$result = $client->searchImages(
    query: 'yellow flowers',
    lang: 'en',                     // Language code (default: 'en')
    id: null,                       // Get specific image by ID
    imageType: 'photo',             // 'all', 'photo', 'illustration', 'vector' (default: 'all')
    orientation: 'horizontal',      // 'all', 'horizontal', 'vertical' (default: 'all')
    category: 'nature',             // Category filter (optional)
    minWidth: 800,                  // Minimum width in pixels (default: 0)
    minHeight: 600,                 // Minimum height in pixels (default: 0)
    colors: 'yellow',               // Color filter (optional)
    editorsChoice: true,            // Only Editor's Choice images (default: false)
    safeSearch: true,               // Safe for all ages (default: false)
    order: 'latest',                // 'popular' or 'latest' (default: 'popular')
    page: 1,                        // Page number (default: 1)
    perPage: 20,                    // Results per page, 3-200 (default: 20)
    callback: null,                 // JSONP callback (optional)
    pretty: false                   // Pretty print JSON (default: false)
);
```

### Video Search

Search for videos on Pixabay:

```php
$result = $client->searchVideos('sunset');
```

#### Video Search Options

```php
$result = $client->searchVideos(
    query: 'sunset',
    lang: 'en',                     // Language code (default: 'en')
    id: null,                       // Get specific video by ID
    videoType: 'film',              // 'all', 'film', 'animation' (default: 'all')
    category: 'nature',             // Category filter (optional)
    minWidth: 1920,                 // Minimum width in pixels (default: 0)
    minHeight: 1080,                // Minimum height in pixels (default: 0)
    editorsChoice: true,            // Only Editor's Choice videos (default: false)
    safeSearch: true,               // Safe for all ages (default: false)
    order: 'latest',                // 'popular' or 'latest' (default: 'popular')
    page: 1,                        // Page number (default: 1)
    perPage: 20,                    // Results per page, 3-200 (default: 20)
    callback: null,                 // JSONP callback (optional)
    pretty: false                   // Pretty print JSON (default: false)
);
```

## Helper Methods

### Process Responses

Process cached responses and extract image data into database:

```php
$stats = $client->processResponses(10); // Process up to 10 cached response rows
echo "Processed: {$stats['processed']}, Duplicates: {$stats['duplicates']}";
```

### Reset Processing Status

Reset the processed status to allow reprocessing of cached responses:

```php
$client->resetProcessed('api'); // Reset image search responses
```

### Clear Processed Data

Clear all processed image data from the database:

```php
$count = $client->clearProcessedTable(); // Returns number of records cleared
echo "Cleared {$count} image records";
```

### Download Images

Download images by ID and store them in the database:

```php
// Download specific image, all sizes
$count = $client->downloadImage(6999568, 'all');

// Download next undownloaded image, preview only
$count = $client->downloadImage(null, 'preview');
```

Available image types: `preview`, `webformat`, `largeImage`, `all`

### Save Images to Files

Save downloaded images to the filesystem:

```php
// Save specific image to default location
$count = $client->saveImageToFile(6999568);

// Save next image to custom path
$count = $client->saveImageToFile(null, 'all', storage_path('app/public/images'));
```

## Categories

Available categories for filtering: `backgrounds`, `fashion`, `nature`, `science`, `education`, `feelings`, `health`, `people`, `religion`, `places`, `animals`, `industry`, `computer`, `food`, `sports`, `transportation`, `travel`, `buildings`, `business`, `music`

## Colors

Available color filters: `grayscale`, `transparent`, `red`, `orange`, `yellow`, `green`, `turquoise`, `blue`, `lilac`, `pink`, `white`, `gray`, `black`, `brown`