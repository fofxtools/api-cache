<?php

/**
 * Crunz Task for Amazon Browse Nodes DFS API Search
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run every minute, processing the set number of items at a time
$task = $schedule->run('php scripts/amazon_browse_nodes_search.php')
    ->description('Search Amazon browse nodes using DataForSEO API and update table amazon_browse_nodes.')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/amazon_browse_nodes_search.log');

return $schedule;
