<?php

/**
 * Crunz Task for Fiverr Gigs Zyte Processor
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run every minute, processing the set number of URLs at a time
$task = $schedule->run('php scripts/fiverr_gigs_zyte_processor.php')
    ->description('Download Fiverr gig URLs from fiverr_listings_gigs using Zyte API')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/fiverr_gigs_zyte_processor.log');

return $schedule;
