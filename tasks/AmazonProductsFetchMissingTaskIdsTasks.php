<?php

/**
 * Crunz Task for Amazon Products Fetch Missing Task IDs
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run every minute, processing the set number of items at a time
$task = $schedule->run('php scripts/amazon_products_fetch_missing_task_ids.php')
    ->description('Fetch missing task IDs for DataForSEO Merchant Amazon Products')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/amazon_products_fetch_missing_task_ids.log');

return $schedule;
