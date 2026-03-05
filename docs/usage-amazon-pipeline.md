# Amazon Data Pipeline

Fetches Amazon keyword search results via DataForSEO, downloads product pages via Zyte, and aggregates everything into keyword-level stats.

## Tables

| Table | Description |
|-------|-------------|
| `amazon_browse_nodes` | Seed keywords to search |
| `dataforseo_merchant_amazon_products_listings` | One row per keyword search result |
| `dataforseo_merchant_amazon_products_items` | Individual product items from each search |
| `amazon_products` | Parsed product pages (via Zyte) |
| `amazon_keywords_stats` | Final aggregated stats per keyword |

---

## Pipeline Steps

### Step 1 — Search browse nodes (Crunz, ongoing)

**Task:** `tasks/AmazonBrowseNodesSearchTasks.php`

**Script:** `scripts/amazon_browse_nodes_search.php`

Reads keywords from `amazon_browse_nodes` where `processed_at IS NULL`, calls the DataForSEO `merchantAmazonProductsStandardAdvanced` endpoint, and marks each row as processed. Runs every minute.

### Step 2 — Process API responses (Crunz, ongoing)

**Task:** `tasks/AmazonProductsProcessResponsesTasks.php`

**Script:** `scripts/amazon_products_process_responses.php`

Extracts raw DataForSEO API responses into `dataforseo_merchant_amazon_products_listings` and `dataforseo_merchant_amazon_products_items`. Runs every 15 minutes.

For a one-time bulk catchup (e.g. after a backlog has built up), run directly:

```bash
php scripts/amazon_products_process_responses_all.php
```

### Step 3 — Download and parse ASINs (Crunz, ongoing)

**Task:** `tasks/AmazonAsinsDownloadParseTasks.php`

**Script:** `scripts/amazon_asins_download_parse.php`

Takes the top-ranked ASINs from `dataforseo_merchant_amazon_products_items` (where `processed_at IS NULL`), downloads their product pages via Zyte, parses them with `AmazonProductPageParser`, and inserts into `amazon_products`. Runs every minute (rate-limited by Zyte).

### Step 4 — Compute keyword stats (CLI, one-time)

**Script:** `utility/examples/amazon_keywords_stats_test.php`

(**Note**: The `utility` directory is not included in the repository. You will need to install it separately.)

Aggregates data from the listings, items, and products tables into `amazon_keywords_stats`. Safe to re-run — uses `insertOrIgnore` so already-computed keywords are skipped. Also sets `processed_at` on the corresponding listings row.

```bash
cd /path/to/utility
php examples/amazon_keywords_stats_test.php
```

---

## Enabling the Crunz Tasks

All three Crunz tasks are currently disabled. To enable one, open the task file and comment out the `->skip()` line:

```php
// ->skip(fn () => true) // Comment to enable
```

---

## Verification Queries

```sql
-- Step 1: keywords searched
SELECT COUNT(*), SUM(processed_at IS NOT NULL) FROM amazon_browse_nodes;

-- Step 2: responses extracted
SELECT COUNT(*) FROM dataforseo_merchant_amazon_products_listings;
SELECT COUNT(*) FROM dataforseo_merchant_amazon_products_items;

-- Step 3: product pages downloaded and parsed
SELECT COUNT(*) FROM amazon_products;

-- Step 4: stats computed
SELECT COUNT(*) FROM amazon_keywords_stats;
SELECT COUNT(*) FROM dataforseo_merchant_amazon_products_listings WHERE processed_at IS NOT NULL;
```
