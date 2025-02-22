<?php

namespace FOfX\ApiCache\Tests\Traits;

use Illuminate\Support\Facades\Log;

trait ApiCacheTestTrait
{
    private array $mockedFunctions = [];

    public function checkServerStatus(string $url): void
    {
        $healthUrl = $url . '/health';
        $ch        = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        Log::info('API server status check', [
            'url'      => $healthUrl,
            'response' => $response,
            'error'    => $error,
        ]);

        if ($response === false) {
            static::markTestSkipped('API server not accessible: ' . $error);
        }

        $data = json_decode($response, true);
        if (!isset($data['status']) || $data['status'] !== 'OK') {
            static::markTestSkipped('API server health check failed');
        }
    }
}
