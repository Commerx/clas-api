-- Phase 1 gateway columns: verification.
--
-- READ ONLY. Every statement is a SELECT.
--
-- The columns themselves are created by
-- database/migrations/2026_11_05_000000_add_gateway_columns_to_subscriptions.php,
-- which is the only source of that DDL — run it with:
--
--   php artisan migrate --database=clas_api
--
-- The flag keeps the migration history in this application's own schema while
-- the columns land in the shared CLAS one. Note that `migrate --pretend` is
-- not dry: it creates the tracking table for real before printing anything,
-- so on a database that has never been migrated it leaves a `migrations`
-- table behind.
--
-- Run what follows after the migration, and again after the first Stripe
-- subscription exists.


SELECT '=== 1. the columns exist, in the right places ===' AS section;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND COLUMN_NAME IN ('stripe_customer_id', 'stripe_payment_method_id', 'gateway')
 ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 14 rows expected: three on each of the four subscription tables, two on
-- user_moneris_cards. All nullable, all defaulting to NULL.
SELECT COUNT(*) AS columns_added_expected_14
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND COLUMN_NAME IN ('stripe_customer_id', 'stripe_payment_method_id', 'gateway');


SELECT '=== 2. nothing existing was disturbed ===' AS section;

-- Every row still has its Moneris token, and no row claims a gateway until
-- the ramp says so. Existing rows are left NULL deliberately: NULL is the
-- truth for a row created before the column existed, and it reads the same
-- as 'moneris' everywhere it matters.
SELECT 'user_subscription' AS tbl, COUNT(*) AS rows_total,
       SUM(data_key IS NOT NULL AND data_key <> '') AS still_have_data_key,
       SUM(gateway IS NOT NULL) AS claim_a_gateway
  FROM user_subscription
UNION ALL
SELECT 'wmu_subscription', COUNT(*),
       SUM(data_key IS NOT NULL AND data_key <> ''), SUM(gateway IS NOT NULL)
  FROM wmu_subscription
UNION ALL
SELECT 'darkmode_subscription', COUNT(*),
       SUM(data_key IS NOT NULL AND data_key <> ''), SUM(gateway IS NOT NULL)
  FROM darkmode_subscription
UNION ALL
SELECT 'fishing_lands_subscription', COUNT(*),
       SUM(data_key IS NOT NULL AND data_key <> ''), SUM(gateway IS NOT NULL)
  FROM fishing_lands_subscription;


SELECT '=== 3. the standing invariant ===' AS section;

-- Every subscription this API creates carries auto_renew = 0 and
-- gateway = 'stripe', which is what keeps the old Yii renewal cron off it:
-- that cron filters auto_renew = 1. A row here is a Stripe subscriber the
-- old cron can still pick up, whose Moneris fallback would charge any card
-- they have left on file or email them a payment decline.
--
-- This must return zero, always.
SELECT COUNT(*) AS stripe_rows_the_old_cron_could_pick_up
  FROM user_subscription
 WHERE gateway = 'stripe'
   AND auto_renew = 1;

-- The same check on the other three, for completeness. The cron only reads
-- user_subscription today, but that is a property of the cron, not of these
-- tables.
SELECT 'wmu_subscription' AS tbl, COUNT(*) AS stripe_rows_with_auto_renew
  FROM wmu_subscription WHERE gateway = 'stripe'
UNION ALL
SELECT 'darkmode_subscription', COUNT(*)
  FROM darkmode_subscription WHERE gateway = 'stripe'
UNION ALL
SELECT 'fishing_lands_subscription', COUNT(*)
  FROM fishing_lands_subscription WHERE gateway = 'stripe';


SELECT '=== 4. no framework tables in the shared schema ===' AS section;

-- Laravel's stock migrations are pinned to the clas_api connection, and
-- tests/Feature/MigrationSafetyTest.php holds that. This is the check from
-- the database side: all of these must be absent.
SELECT TABLE_NAME AS framework_table_that_should_not_be_here
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('users', 'password_reset_tokens', 'sessions', 'cache',
                      'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
                      'migrations');
