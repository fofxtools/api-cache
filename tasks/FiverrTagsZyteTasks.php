<?php

/**
 * Crunz Task for Fiverr Tags Zyte Processor
 */

declare(strict_types=1);

use Crunz\Schedule;

$schedule = new Schedule();

// Run every minute, processing the set number of URLs at a time
$task = $schedule->run('php scripts/fiverr_tags_zyte_processor.php')
    ->description('Download Fiverr tag URLs using Zyte API and process with FiverrJsonImporter')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Comment to enable
    ->appendOutputTo(__DIR__ . '/../storage/logs/fiverr_tags_zyte_processor.log');

return $schedule;
