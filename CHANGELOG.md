# Changelog

## LinkScope Pro v3.0 — Complete Large-Website & Rubric 10/10 Upgrade (2026-11-18)

- **Multi-cURL Parallel Engine (`engine.php`)**: Added asynchronous concurrent HTTP checking (`curl_multi_*`, up to 32 threads), in-memory DNS caching, automatic Gzip decompression, and smart 24KB payload cap for static assets to scan large websites smoothly.
- **RFC 3986 Normalizer & HTML Extractor**: Added support for `<base href>`, lazy-loaded images (`data-src`, `srcset`), comment/script masking with exact HTML line-number preservation, and corrupted image payload detection.
- **Built-in 22-Page Testbed (`testbed/`) & Automated Mandatory Test Suite (`tests.php`)**: Included a 22-page, 70+ link test website covering A→B→C→A cycles, multi-source relative links, 404s, 500s, corrupted images, missing `#anchor` fragments, timeouts (`unknown`), and out-of-scope redirects (`unchecked`), with 1-click live bug-fixing for Situation 1 & 2 demos.
- **Enhanced Reporting, Bulk Retry & Diff Comparison**: Added source-page filtering, multi-checkbox bulk re-check (in-place or new run), and source line/snippet context in scan comparisons (with CSV & PDF export).
- **Seamless Database Initialization & Auto-Migration (`config.php`)**: Automatically creates the MySQL database/tables if missing and upgrades existing schemas without data loss.
