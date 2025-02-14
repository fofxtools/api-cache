<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Dotenv\Dotenv;

class TestCase extends Orchestra
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // Load .env.testing before parent setup
        if (file_exists(dirname(__DIR__) . '/.env.testing')) {
            (Dotenv::createImmutable(dirname(__DIR__), '.env.testing'))->safeLoad();
        }

        parent::setUp();
    }

    protected function defineDatabaseMigrations(): void
    {
        // This will run our package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return ['FOfX\ApiCache\ApiCacheServiceProvider'];
    }
}
