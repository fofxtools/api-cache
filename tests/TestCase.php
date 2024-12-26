<?php

namespace FOfX\ApiCache\Tests;

use FOfX\ApiCache\ApiCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Support\Facades\DB;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->artisan('migrate:fresh');
        } catch (\Exception $e) {
            // Only show debug info if migration fails
            $this->printDebug('Tables before error:', $this->getTables());

            throw $e;
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            ApiCacheServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../src/Database/migrations');
    }

    private function getTables(): array
    {
        return DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    }

    private function printDebug(string $message, array $data): void
    {
        fwrite(STDERR, "\n\n" . str_repeat('=', 50) . "\n");
        fwrite(STDERR, $message . "\n");
        fwrite(STDERR, print_r($data, true));
        fwrite(STDERR, "\n" . str_repeat('=', 50) . "\n\n");
    }
}
