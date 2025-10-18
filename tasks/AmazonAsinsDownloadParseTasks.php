<?php

/**
 * Crunz Task for Amazon ASIN Download and Parse
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run every minute, processing the set number of URLs at a time
$task = $schedule->run('php scripts/amazon_asins_download_parse.php')
    ->description('Download ASINs from dataforseo_merchant_amazon_products_items using Zyte API. And insert into amazon_products using Utility\AmazonProductPageParser.')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/amazon_asins_download_parse.log');

return $schedule;
