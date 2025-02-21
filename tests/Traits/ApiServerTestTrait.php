<?php

namespace FOfX\ApiCache\Tests\Traits;

trait ApiServerTestTrait
{
    public function checkServerStatus(string $url): void
    {
        $healthUrl = $url . '/health';
        $ch        = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            static::markTestSkipped('Demo API server not accessible: ' . $error);
        }

        $data = json_decode($response, true);
        if (!isset($data['status']) || $data['status'] !== 'OK') {
            static::markTestSkipped('Demo API server health check failed');
        }
    }

    public function getWslAwareBaseUrl(string $baseUrl): string
    {
        if (PHP_OS_FAMILY === 'Linux' && getenv('WSL_DISTRO_NAME')) {
            $nameserver = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print $2}'"));

            return str_replace('localhost', $nameserver, $baseUrl);
        }

        return $baseUrl;
    }
}
