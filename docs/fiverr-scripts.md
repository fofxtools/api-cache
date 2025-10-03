# Fiverr Scripts

Scripts for collecting Fiverr marketplace data via Zyte API.

## Overview

The Fiverr data collection pipeline consists of several scripts that work together to:
- Download category and tag listing pages via Zyte API
- Extract and import listings data into the database
- Process individual gig pages
- Generate statistics from the collected data

## Workflow

**Download listings pages:**
- [scripts/fiverr_categories_zyte_processor.php](../scripts/fiverr_categories_zyte_processor.php) - Download category pages (Crunz: [tasks/FiverrCategoriesZyteTasks.php](../tasks/FiverrCategoriesZyteTasks.php))
- [scripts/fiverr_tags_zyte_processor.php](../scripts/fiverr_tags_zyte_processor.php) - Download tag pages (Crunz: [tasks/FiverrTagsZyteTasks.php](../tasks/FiverrTagsZyteTasks.php))

**Process listings data:**
- [scripts/fiverr_process_listings.php](../scripts/fiverr_process_listings.php) - Extract gigs from listings (run once)
- [scripts/fiverr_process_listings_stats.php](../scripts/fiverr_process_listings_stats.php) - Extract listing stats (run once)

**Download and process gigs:**
- [scripts/fiverr_gigs_zyte_processor.php](../scripts/fiverr_gigs_zyte_processor.php) - Download gig pages (Crunz: [tasks/FiverrGigsZyteTasks.php](../tasks/FiverrGigsZyteTasks.php))
- [scripts/fiverr_process_gigs_stats.php](../scripts/fiverr_process_gigs_stats.php) - Extract gig stats (run once)

**Optional:**
- [scripts/fiverr_fill_missing_category_data.php](../scripts/fiverr_fill_missing_category_data.php) - Fill in missing category data. Category information for source_format=category pages can be used to fill categories for source_format=tag rows. (run once)
- [scripts/fiverr_backfill_listing_urls.php](../scripts/fiverr_backfill_listing_urls.php) - Backfill URL column if needed (run once)

## Automation

Set up [Crunz](https://github.com/crunzphp/crunz) to run tasks automatically. Enable Crunz tasks by removing `->skip(fn () => true)` from respective task files.

Logs: `storage/logs/fiverr_*_processor.log`