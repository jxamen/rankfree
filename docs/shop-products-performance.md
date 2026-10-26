# Collected product list index (2026-10-26)

The old request grouped keyword_shop_ranks by product_key, joined shop_products, and counted twice (pagination and the total badge). Production measurements supplied with the incident were 195.9 seconds for count and over 300 seconds for ordering, exceeding the 15-second FPM limit.

shop_product_summaries is an additive, rebuildable index. It contains one row per product and collected_month, plus month 0 for cross-month COUNT(DISTINCT keyword). Price, title, mall, ad, and talk availability are copied so filtering and ordering need no unbounded join. All five sorts have month-prefixed indexes; mall/ad/talk have recent-order indexes. Product details and rank keywords are fetched for at most 100 visible products. Rank-keyword reads retain collected_month predicates for partition pruning.

The page uses simplePaginate(100): no total count, previous/next navigation, an ascending No column and a displayed range in the existing total badge. Visible product columns and filter meanings remain unchanged. Title search retains literal substring semantics; for MariaDB it scans the clustered month range rather than doing random lookups through a sort index. Search still requires a scan and must be remeasured if the dataset grows substantially.

ShopSerpStore updates summaries after collection, replacement, month deletion and partition deletion, including products removed from a keyword. A daily 04:40 reconciliation repairs direct writes or interrupted collectors. Refresh locks product rows in sorted order and replaces each bounded batch transactionally. Concurrent readers see the previous or new committed batch. An interrupted rebuild is safe to rerun.

## Deployment

Use PRs to the existing default/production branch `master` (this repository has no `main`). Phase 1 adds only the table, summary service and backfill command. After git pull --ff-only, run the single new migration and shop-products:refresh-summaries. Phase 2 switches the controller and installs collector hooks after backfill completes. Never execute deploy.sh or overwrite existing production changes. Refresh route/config/view caches after phase 2.

Run `php83 artisan shop-products:refresh-summaries` for reconciliation. It does not drop or alter source tables. Roll back application code through a revert PR and ff-only pull; leave the additive table in place. Reverting the optimized read path restores the original slow behavior, so prefer a forward fix.

## References

- https://laravel.com/framework/docs/13.x/pagination#simple-pagination : simplePaginate avoids total-count queries.
- https://mariadb.com/docs/server/server-usage/partitioning-tables/partition-pruning-and-selection : collected_month predicates and EXPLAIN PARTITIONS verify pruning.

Production timings, exact EXPLAIN output, unchanged-file hashes and test comparisons are in the incident result on the Windows project PC: jobs/rankfree-shop-products-speed-1026.result.md.
