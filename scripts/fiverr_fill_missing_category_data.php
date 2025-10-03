<?php

declare(strict_types=1);

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\Utility\FiverrJsonImporter;

ini_set('memory_limit', -1);

$start = microtime(true);

$importer = new FiverrJsonImporter();

// Fill in missing category names and slugs for rows that have category IDs but missing display data
// (in fiverr_listings, fiverr_listings_gigs, and fiverr_listings_stats tables)
$stats = $importer->fillMissingCategoryData();
print_r($stats);

$end = microtime(true);
echo 'Time taken: ' . ($end - $start) . " seconds\n";
