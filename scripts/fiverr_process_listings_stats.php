<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Process listings into stats
// (first process listings into gigs via processListingsGigsAll)
// Note: To re-process all, call $importer->resetListingsStatsProcessed() first.
$importer->resetListingsStatsProcessed();
echo "\n== Process fiverr_listings_stats from listings JSON ==\n";
$stats = $importer->processListingsStatsAll();
print_r($stats);

// For 2340 listings rows this script took about 392 seconds
$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";
