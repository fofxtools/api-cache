<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\CompressionService;
use Orchestra\Testbench\TestCase;

class CompressionServiceTest extends TestCase
{
    public function test_isEnabled_returns_false_by_default(): void
    {
        $service = new CompressionService();
        $this->assertFalse($service->isEnabled());
    }

    public function test_compress_returns_original_when_disabled(): void
    {
        $service = new CompressionService(false);
        $data    = 'test data';

        $this->assertSame($data, $service->compress($data));
    }

    public function test_compress_modifies_data_when_enabled(): void
    {
        $service = new CompressionService(true);
        $data    = 'test data';

        $compressed = $service->compress($data);
        $this->assertNotSame($data, $compressed);
    }

    public function test_compress_includes_context_in_logs(): void
    {
        $service = new CompressionService(true);
        $data    = 'test data';
        $context = 'test-context';

        // Capture logs
        $logs = [];
        $this->app->make('log')->listen(function ($event) use (&$logs) {
            $logs[] = $event;
        });

        // Compress with context
        $service->compress($data, $context);

        // Assert log contains context
        $this->assertTrue(collect($logs)->contains(function ($event) use ($context) {
            return $event->message === 'Data compressed successfully'
                && isset($event->context['context'])
                && $event->context['context'] === $context;
        }));
    }

    public function test_compress_works_without_context(): void
    {
        $service = new CompressionService(true);
        $data    = 'test data';

        // Capture logs
        $logs = [];
        $this->app->make('log')->listen(function ($event) use (&$logs) {
            $logs[] = $event;
        });

        // Compress without context
        $service->compress($data);

        // Assert log contains empty context
        $this->assertTrue(collect($logs)->contains(function ($event) {
            return $event->message === 'Data compressed successfully'
                && isset($event->context['context'])
                && $event->context['context'] === '';
        }));
    }

    /**
     * Test that empty data is handled correctly
     */
    public function test_compress_handles_empty_data(): void
    {
        $service = new CompressionService(true);

        $emptyString = '';
        $result      = $service->compress($emptyString, 'test-context');

        $this->assertSame($emptyString, $result);
    }

    /**
     * Test that empty data is handled correctly when compression is disabled
     */
    public function test_compress_handles_empty_data_when_disabled(): void
    {
        $service = new CompressionService(false);

        $emptyString = '';
        $result      = $service->compress($emptyString, 'test-context');

        $this->assertSame($emptyString, $result);
    }

    public function test_decompress_returns_original_when_disabled(): void
    {
        $service = new CompressionService(false);
        $data    = 'test data';

        $this->assertSame($data, $service->decompress($data));
    }

    public function test_decompress_restores_compressed_data(): void
    {
        $service = new CompressionService(true);
        $data    = 'test data';

        $compressed   = $service->compress($data);
        $decompressed = $service->decompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    public function test_decompress_throws_on_invalid_data(): void
    {
        $service = new CompressionService(true);

        $this->expectException(\RuntimeException::class);

        // With compression enabled, trying to decompress a raw string should throw an exception
        $service->decompress('invalid data');
    }
}
