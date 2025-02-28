<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use FOfX\Helper;

// Test URLs
$urls = [
    'http://localhost/api',
    'http://localhost:8000/api',
    'http://localhost:8001/api/v1',
    'http://api.example.com/v1',
];

echo "Testing WSL URL conversion:\n";
echo "-------------------------\n";
echo 'OS : ' . PHP_OS_FAMILY . "\n";
echo 'WSL: ' . (getenv('WSL_DISTRO_NAME') ?: 'No') . "\n\n";

foreach ($urls as $url) {
    $wslUrl = Helper\wsl_url($url);
    echo "Original : {$url}\n";
    echo "WSL-aware: {$wslUrl}\n\n";
}
