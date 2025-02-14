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

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Run package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Ensure SQLite database exists and is migrated
        $this->artisan('migrate', ['--database' => 'sqlite']);
    }
}
