<?php

declare(strict_types=1);

/**
 * Basic OS Test Script
 *
 * Outputs the OS and appends timestamp + OS to log file
 */

// Get current timestamp
$timestamp = date('Y-m-d H:i:s');

// Prepare log entry
$logEntry = "[{$timestamp}] " . PHP_OS_FAMILY . PHP_EOL;
echo $logEntry;

// Ensure storage/logs directory exists
$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Append to log file
$logFile = $logDir . '/display_os.log';
file_put_contents($logFile, $logEntry, FILE_APPEND);
