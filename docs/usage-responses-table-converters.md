# Responses Table Converters

The responses table converter classes provide migration between uncompressed and compressed response tables. They handle batch processing, validation, and error handling.

## Classes Overview

- **ResponsesTableCompressionConverter**: Converts uncompressed responses to compressed format
- **ResponsesTableDecompressionConverter**: Converts compressed responses back to uncompressed format

## Basic Usage

### Compression Converter

Convert uncompressed responses to save storage space:

```php
use FOfX\ApiCache\ResponsesTableCompressionConverter;

// Initialize for a specific API client
$converter = new ResponsesTableCompressionConverter('openai');

// Convert all rows
$stats = $converter->convertAll();

// Results: ['total_count' => 1000, 'processed_count' => 950, 'skipped_count' => 50, 'error_count' => 0]
```

### Decompression Converter

Convert compressed responses back to uncompressed format:

```php
use FOfX\ApiCache\ResponsesTableDecompressionConverter;

// Initialize for a specific API client
$converter = new ResponsesTableDecompressionConverter('openai');

// Convert all rows
$stats = $converter->convertAll();

// Results: ['total_count' => 950, 'processed_count' => 950, 'skipped_count' => 0, 'error_count' => 0]
```

## Batch Processing

Process specific batches for control over large datasets:

```php
$converter = new ResponsesTableCompressionConverter('openai');

// Process first 100 rows
$stats = $converter->convertBatch(100, 0);

// Process next 100 rows
$stats = $converter->convertBatch(100, 100);
```

## Configuration Options

### Batch Size

Control processing batch size for memory management:

```php
$converter = new ResponsesTableCompressionConverter('openai');
$converter->setBatchSize(50); // Process 50 rows at a time

$stats = $converter->convertBatch(); // Now processes 50 rows rather than default 100
```

### Overwrite Mode

Allow overwriting existing records:

```php
$converter = new ResponsesTableCompressionConverter('openai');
$converter->setOverwrite(true); // Overwrite existing compressed records

$stats = $converter->convertAll();
```

### Processing State

Preserve processing status during conversion:

```php
$converter = new ResponsesTableCompressionConverter('openai');
$converter->setCopyProcessingState(true); // Keep processed_at and processed_status

$stats = $converter->convertAll();
```

## Validation

Verify conversion accuracy:

```php
$converter = new ResponsesTableCompressionConverter('openai');

// Convert data
$converter->convertAll();

// Validate all converted data
$validation = $converter->validateAll();
// Results: ['validated_count' => 500, 'mismatch_count' => 0, 'error_count' => 0]

// Validate specific batch
$validation = $converter->validateBatch(50, 0);
```

## Row Counts

Check table sizes before and after conversion:

```php
$converter = new ResponsesTableCompressionConverter('openai');

echo "Uncompressed rows: " . $converter->getUncompressedRowCount();
echo "Compressed rows: " . $converter->getCompressedRowCount();
```

## Error Handling

The converters handle errors gracefully and provide detailed statistics:

```php
$converter = new ResponsesTableCompressionConverter('openai');
$stats = $converter->convertAll();

if ($stats['error_count'] > 0) {
    echo "Conversion completed with {$stats['error_count']} errors";
    echo "Successfully processed: {$stats['processed_count']} rows";
    echo "Skipped existing: {$stats['skipped_count']} rows";
}
```

## Complete Example

```php
use FOfX\ApiCache\ResponsesTableCompressionConverter;
use FOfX\ApiCache\ResponsesTableDecompressionConverter;

// Compress OpenAI responses
$clientName = 'openai';

$compressor = new ResponsesTableCompressionConverter($clientName);
$compressor->setBatchSize(10);
$compressor->setOverwrite(true);
$compressor->setCopyProcessingState(true);

echo "Starting compression...\n";
$stats = $compressor->convertAll();
print_r($stats);

// Validate compression
$validationStats = $compressor->validateAll();
print_r($validationStats);

// Later: decompress if needed
$decompressor = new ResponsesTableDecompressionConverter($clientName);
$decompressor->setBatchSize(10);
$decompressor->setOverwrite(true);
$decompressor->setCopyProcessingState(true);

echo "\nStarting decompression...\n";
$stats = $decompressor->convertAll();
print_r($stats);

$validationStats = $decompressor->validateAll();
print_r($validationStats);
```

## Best Practices

- **Backup data** before running large conversions
- **Use validation** to ensure data integrity after conversion
- **Set appropriate batch sizes** based on available memory
- **Monitor error counts** and investigate any failures