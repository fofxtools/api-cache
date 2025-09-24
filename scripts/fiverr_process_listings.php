<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

$start = microtime(true);

// Process listings stats
$importer = new FiverrJsonImporter();

// Process listings into gigs
// Note: To re-process all, call $importer->resetListingsProcessed() first.
echo "\n== Process fiverr_listings_gigs from listings JSON ==\n";
$stats = $importer->processListingsGigsAll();
print_r($stats);

// Process listings into stats
// Note: To re-process all, call $importer->resetListingsStatsProcessed() first.
echo "\n== Process fiverr_listings_stats from listings JSON ==\n";
$stats = $importer->processListingsStatsAll();
print_r($stats);

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";
