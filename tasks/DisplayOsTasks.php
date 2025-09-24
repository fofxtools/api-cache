<?php

declare(strict_types=1);

use Crunz\Schedule;

// Crunz task: run scripts/display_os.php every minute
$schedule = new Schedule();

$schedule
    ->run('php scripts/display_os.php')
    ->description('Display OS and append to storage/logs/os_test.log')
    ->everyMinute()
    ->preventOverlapping()
    ->skip(fn () => true) // Uncomment to skip
    ->appendOutputTo(__DIR__ . '/../storage/logs/crunz_display_os.log');

return $schedule;
