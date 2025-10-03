<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Process listings into gigs
// Note: To re-process all, call $importer->resetListingsProcessed() first.
echo "\n== Process fiverr_listings_gigs from listings JSON ==\n";
$importer->resetListingsProcessed();
$stats = $importer->processListingsGigsAll();
print_r($stats);

// For 2340 listings rows this script took about 1481 seconds
$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";
