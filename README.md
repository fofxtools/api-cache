# API Cache Library

**🚧 Under Construction 🚧**

A Laravel-based PHP library for caching API responses. Currently in early development.

## Documentation

Please see the [docs](docs) folder for:
- [Technical Specification](docs/technical-specification.md)
- [Usage](docs/usage.md)
- [Code Skeleton](docs/code-skeleton.md)

### Diagrams
- [Class Diagram](docs/diagrams/class-diagram.mmd)
- [Sequence Diagram](docs/diagrams/sequence-diagram.mmd)
- [Workflow Diagram](docs/diagrams/workflow-diagram.mmd)

## Development Setup

1. Clone the repository
2. Install dependencies:
```bash
composer install
```
3. Copy `.env.example` to `.env`
4. For testing the demo API:
```bash
php -S 0.0.0.0:8000 -t public
```

## Database Migrations

This library takes an unconventional approach to database migrations in order to follow the DRY principle and simplify maintenance across multiple clients, while maintaining consistency between tables.

Instead of duplicating table creation logic in each migration file, we use shared helper functions defined in `src/functions.php`:

- `create_responses_table()`: Creates tables for storing API responses
- `create_pixabay_images_table()`: Creates tables for storing Pixabay image data

Example migration:
```php
public function up(): void
{
    $schema = Schema::connection($this->getConnection());
    create_responses_table($schema, 'api_cache_demo_responses', false);
}
```

## License

MIT 