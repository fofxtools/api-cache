<?php

/**
 * Crunz Task for Amazon Products Process Responses
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run at set interval
$task = $schedule->run('php scripts/amazon_products_process_responses.php')
    ->description('Process DataForSEO Merchant Amazon Products responses and extract data into dataforseo_merchant_amazon_products_listings and dataforseo_merchant_amazon_products_items.')
    ->everyFifteenMinutes()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/amazon_products_process_responses.log');

return $schedule;
