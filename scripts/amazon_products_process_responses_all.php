<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

$start = microtime(true);

// Initialize processor
$processor = new DataForSeoMerchantAmazonProductsProcessor();

// Process available responses
echo "=== Processing available responses ===\n\n";
//$stats = $processor->processResponsesAll();
$stats = $processor->processResponses();
print_r($stats);

$end = microtime(true);
echo 'Time taken: ' . round($end - $start, 2) . " seconds\n";
