<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

ini_set('memory_limit', -1);

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Process gigs into stats
// (first process listings into stats via processListingsStatsAll)
// (gigs must be downloaded first, see scripts/fiverr_gigs_zyte_processor.php)
// Note: To re-process all, call $importer->resetListingsGigsStatsProcessed() first.
echo "\n== Process fiverr_listings_stats from gigs ==\n";
$importer->resetListingsGigsStatsProcessed();
$stats = $importer->processGigsStatsAll(forceCacheRebuild: true);
print_r($stats);

// For 1644 rows this script took about 612 seconds
// After inserting tags, for 2338 rows this script took about 77414 seconds
$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";
