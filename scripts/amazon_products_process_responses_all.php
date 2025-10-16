<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/../examples/bootstrap.php';

$start = microtime(true);

// Initialize processor
$processor = new DataForSeoMerchantAmazonProductsProcessor();

// Process all available responses
echo "=== Processing all available responses ===\n\n";
$stats = $processor->processResponsesAll();
print_r($stats);

$end = microtime(true);
echo 'Time taken: ' . round($end - $start, 2) . " seconds\n";
