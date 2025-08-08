# Database Migrations

This library takes an unconventional approach to database migrations in order to follow the DRY principle and simplify maintenance across multiple clients, while maintaining consistency between tables.

Instead of duplicating table creation logic in each migration file, we use shared helper functions defined in `src/functions.php`:

- `create_responses_table()`: Creates tables for storing API responses
- `create_errors_table()`: Creates table for storing error logs
- Other tables for saving processed data from API responses

Example migration:
```php
public function up(): void
{
    $schema = Schema::connection($this->getConnection());
    create_responses_table($schema, 'api_cache_demo_responses', false);
}
```