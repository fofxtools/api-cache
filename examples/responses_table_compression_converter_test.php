<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\DB;

try {
    echo "=== ResponsesTableCompressionConverter Test (750 rows) ===\n";

    // Step 1: Setup test environment
    echo "\n1. Setting up test environment...\n";
    $clientName = 'test-comp'; // Shorter name to avoid MySQL index name length issues

    // Create both uncompressed and compressed tables (drop existing for clean test)
    createClientTables($clientName, true, false);

    // Create converter instance
    $converter = new ResponsesTableCompressionConverter($clientName);

    echo "   - Tables created (fresh for clean test)\n";
    echo "   - Converter instance created\n";

    // Step 2: Generate and insert 750 test rows
    echo "\n2. Generating and inserting 750 test records...\n";

    $uncompressedTable = app(CacheRepository::class)->getTableName($clientName, false);

    $templates = [
        ['compact' => true, 'size' => 'small', 'type' => 'simple'],
        ['compact' => false, 'size' => 'small', 'type' => 'simple'],
        ['compact' => true, 'size' => 'medium', 'type' => 'complex'],
        ['compact' => false, 'size' => 'large', 'type' => 'complex'],
    ];

    $testData = [];
    for ($i = 1; $i <= 750; $i++) {
        $template   = $templates[($i - 1) % 4]; // Cycle through templates
        $testData[] = generateTestRow($i, $template, $clientName);
    }

    DB::table($uncompressedTable)->insert($testData);
    echo '   - Inserted ' . count($testData) . " test records (mix of compact/pretty-printed, small/medium/large)\n";

    // Step 3: Test row counts
    echo "\n3. Testing row counts...\n";
    $uncompressedCount = $converter->getUncompressedRowCount();
    $compressedCount   = $converter->getCompressedRowCount();
    echo "   - Uncompressed rows: {$uncompressedCount}\n";
    echo "   - Compressed rows: {$compressedCount}\n";

    // Step 4: Test convertAll() with 750 rows
    echo "\n4. Testing convertAll() with 750 rows...\n";
    $startTime = microtime(true);
    $allStats  = $converter->convertAll();
    $endTime   = microtime(true);
    $duration  = round($endTime - $startTime, 2);

    echo "   - convertAll() completed in {$duration} seconds\n";
    echo "   - Conversion stats:\n";
    echo '     * Total: ' . number_format($allStats['total_count']) . "\n";
    echo '     * Processed: ' . number_format($allStats['processed_count']) . "\n";
    echo '     * Skipped: ' . number_format($allStats['skipped_count']) . "\n";
    echo '     * Errors: ' . number_format($allStats['error_count']) . "\n";
    echo '   - Processing rate: ' . round($allStats['total_count'] / $duration, 1) . " rows/second\n";

    // Step 5: Test validateAll() with 750 rows
    echo "\n5. Testing validateAll() with 750 rows...\n";
    $validationStartTime = microtime(true);
    $validationStats     = $converter->validateAll();
    $validationEndTime   = microtime(true);
    $validationDuration  = round($validationEndTime - $validationStartTime, 2);

    echo "   - validateAll() completed in {$validationDuration} seconds\n";
    echo "   - Validation stats:\n";
    echo '     * Validated: ' . number_format($validationStats['validated_count']) . "\n";
    echo '     * Mismatches: ' . number_format($validationStats['mismatch_count']) . "\n";
    echo '     * Errors: ' . number_format($validationStats['error_count']) . "\n";
    echo '   - Validation rate: ' . round($validationStats['validated_count'] / $validationDuration, 1) . " rows/second\n";

    // Step 6: Final row counts
    echo "\n6. Final verification...\n";
    $finalUncompressed = $converter->getUncompressedRowCount();
    $finalCompressed   = $converter->getCompressedRowCount();
    echo "   - Final uncompressed rows: {$finalUncompressed}\n";
    echo "   - Final compressed rows: {$finalCompressed}\n";

    if ($finalUncompressed === $finalCompressed && $finalCompressed === 750) {
        echo "   ✅ Perfect: All 750 rows successfully converted and validated!\n";
    } else {
        echo "   ❌ Mismatch in row counts!\n";
    }

    echo "\n=== Test completed successfully! ===\n";
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

/**
 * Generate a test row based on template
 */
function generateTestRow($index, $template, $clientName)
{
    // Base row structure
    $row = [
        'key'                    => "test-key-{$index}",
        'client'                 => $clientName,
        'version'                => '1.0',
        'endpoint'               => "test-endpoint-{$index}",
        'base_url'               => 'https://api.example.com',
        'full_url'               => "https://api.example.com/test-endpoint-{$index}",
        'method'                 => ($index % 2 === 0) ? 'POST' : 'GET',
        'attributes'             => null,
        'credits'                => ($index % 5) + 1,
        'cost'                   => round(($index % 10) * 0.01, 2),
        'request_params_summary' => "test params {$index}",
        'response_status_code'   => 200,
        'response_time'          => round(rand(100, 2000) / 1000, 2),
        'expires_at'             => now()->addHours(rand(1, 24)),
        'created_at'             => now(),
        'updated_at'             => now(),
        'processed_at'           => null,
        'processed_status'       => null,
    ];

    // Generate content based on template
    $requestData  = generateRequestData($index, $template);
    $responseData = generateResponseData($index, $template);

    // Apply formatting (compact vs pretty-printed)
    $jsonFlags = $template['compact'] ? 0 : JSON_PRETTY_PRINT;

    $row['request_headers'] = json_encode([
        'Content-Type'  => 'application/json',
        'Authorization' => "Bearer token-{$index}",
        'X-Request-ID'  => "req-{$index}",
    ], $jsonFlags);

    $row['request_body'] = json_encode($requestData, $jsonFlags);

    $row['response_headers'] = json_encode([
        'Content-Type'  => 'application/json',
        'X-Rate-Limit'  => (string)(100 - ($index % 50)),
        'X-Response-ID' => "resp-{$index}",
    ], $jsonFlags);

    $row['response_body'] = json_encode($responseData, $jsonFlags);
    $row['response_size'] = strlen($row['response_body']);

    return $row;
}

/**
 * Generate request data based on template
 */
function generateRequestData($index, $template)
{
    $baseRequest = [
        'query' => "test query {$index}",
        'limit' => ($index % 50) + 10,
    ];

    if ($template['size'] === 'medium' || $template['size'] === 'large') {
        $baseRequest['filters'] = [
            'category' => 'category-' . ($index % 10),
            'active'   => ($index % 2 === 0),
            'tags'     => array_map(fn ($i) => "tag-{$i}", range(1, ($index % 5) + 1)),
        ];
    }

    if ($template['size'] === 'large') {
        $baseRequest['metadata'] = [
            'source'    => "source-{$index}",
            'timestamp' => now()->toISOString(),
            'options'   => array_fill_keys(
                array_map(fn ($i) => "option-{$i}", range(1, ($index % 10) + 5)),
                true
            ),
        ];
    }

    return $baseRequest;
}

/**
 * Generate response data based on template
 */
function generateResponseData($index, $template)
{
    $itemCount = match($template['size']) {
        'small'  => ($index % 3) + 1,
        'medium' => ($index % 10) + 5,
        'large'  => ($index % 25) + 15,
        default  => ($index % 3) + 1,
    };

    $items = [];
    for ($i = 1; $i <= $itemCount; $i++) {
        $item = [
            'id'    => ($index * 100) + $i,
            'name'  => "Item {$index}-{$i}",
            'value' => round(rand(1, 1000) / 10, 1),
        ];

        if ($template['type'] === 'complex') {
            $item['metadata'] = [
                'created' => now()->subDays(rand(1, 30))->toISOString(),
                'score'   => rand(1, 100),
                'tags'    => array_map(fn ($t) => "tag-{$t}", range(1, ($i % 3) + 1)),
            ];
        }

        $items[] = $item;
    }

    return [
        'status' => 'success',
        'data'   => $items,
        'meta'   => [
            'total'           => $itemCount,
            'page'            => 1,
            'request_id'      => "req-{$index}",
            'processing_time' => round(rand(10, 500) / 1000, 3),
        ],
    ];
}
