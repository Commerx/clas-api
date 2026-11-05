-- Production subscription audit.
--
-- READ ONLY. Every statement is a SELECT; nothing here writes, locks or
-- changes a row. Safe to run against the live database.
--
-- Run it on production and keep the output. The migration plan in
-- docs/moneris-to-stripe-migration.md needs these numbers, and the local
-- copy cannot supply them: its subscription tables are stale, and figures
-- taken from it were wrong about the most important thing — whether there
-- are live subscriptions at all.
--
--   mysql -u <user> -p clas < production-subscription-audit.sql > audit.txt
--
-- Nothing in the output is cardholder data: only counts, masked last four
-- digits and expiry months. Still worth treating the file as confidential,
-- since it says who holds a card on file.

SELECT '=== 1. live subscriptions, by product ===' AS section;

SELECT 'user_subscription'          AS product,
       COUNT(*)                     AS rows_total,
       SUM(status = 'Active')       AS flagged_active,
       SUM(expiration_date >= CURDATE()) AS unexpired,
       SUM(status = 'Active' AND expiration_date >= CURDATE()) AS live,
       SUM(status = 'Active' AND expiration_date >= CURDATE()
           AND auto_renew = 1)      AS live_auto_renewing,
       SUM(status = 'Active' AND expiration_date >= CURDATE()
           AND data_key IS NOT NULL AND data_key <> '') AS live_with_card
  FROM user_subscription
UNION ALL
SELECT 'wmu_subscription', COUNT(*), SUM(status = 'Active'),
       SUM(expiration_date >= CURDATE()),
       SUM(status = 'Active' AND expiration_date >= CURDATE()),
       NULL,
       SUM(status = 'Active' AND expiration_date >= CURDATE()
           AND data_key IS NOT NULL AND data_key <> '')
  FROM wmu_subscription
UNION ALL
SELECT 'darkmode_subscription', COUNT(*), SUM(status = 'Active'),
       SUM(expiration_date >= CURDATE()),
       SUM(status = 'Active' AND expiration_date >= CURDATE()),
       NULL,
       SUM(status = 'Active' AND expiration_date >= CURDATE()
           AND data_key IS NOT NULL AND data_key <> '')
  FROM darkmode_subscription
UNION ALL
SELECT 'fishing_lands_subscription', COUNT(*), SUM(status = 'Active'),
       SUM(expiration_date >= CURDATE()),
       SUM(status = 'Active' AND expiration_date >= CURDATE()),
       NULL,
       SUM(status = 'Active' AND expiration_date >= CURDATE()
           AND data_key IS NOT NULL AND data_key <> '')
  FROM fishing_lands_subscription;


SELECT '=== 2. cards that must survive the migration ===' AS section;

-- One card can back several subscriptions, so the number of cards to import
-- is the distinct data_key count, not the subscription count. This is the
-- figure Stripe and Moneris both want.
SELECT COUNT(DISTINCT data_key) AS distinct_data_keys_live
  FROM (
    SELECT data_key FROM user_subscription
     WHERE status = 'Active' AND expiration_date >= CURDATE()
       AND data_key IS NOT NULL AND data_key <> ''
    UNION ALL
    SELECT data_key FROM wmu_subscription
     WHERE status = 'Active' AND expiration_date >= CURDATE()
       AND data_key IS NOT NULL AND data_key <> ''
    UNION ALL
    SELECT data_key FROM darkmode_subscription
     WHERE status = 'Active' AND expiration_date >= CURDATE()
       AND data_key IS NOT NULL AND data_key <> ''
    UNION ALL
    SELECT data_key FROM fishing_lands_subscription
     WHERE status = 'Active' AND expiration_date >= CURDATE()
       AND data_key IS NOT NULL AND data_key <> ''
  ) AS live_cards;

SELECT COUNT(*) AS rows_in_user_moneris_cards,
       COUNT(DISTINCT data_key) AS distinct_data_keys,
       COUNT(DISTINCT user_id)  AS distinct_users,
       SUM(masked_pan IS NOT NULL AND masked_pan <> '') AS with_masked_pan,
       SUM(expiry IS NOT NULL AND expiry <> '')         AS with_expiry
  FROM user_moneris_cards;

-- Every data_key anywhere, live or not. The export request to Moneris should
-- cover all of them: a lapsed subscriber who comes back expects their card to
-- still be there, and a second export later costs another round trip.
SELECT COUNT(DISTINCT data_key) AS distinct_data_keys_all_tables
  FROM (
    SELECT data_key FROM user_subscription          WHERE data_key IS NOT NULL AND data_key <> ''
    UNION ALL SELECT data_key FROM wmu_subscription           WHERE data_key IS NOT NULL AND data_key <> ''
    UNION ALL SELECT data_key FROM darkmode_subscription      WHERE data_key IS NOT NULL AND data_key <> ''
    UNION ALL SELECT data_key FROM fishing_lands_subscription WHERE data_key IS NOT NULL AND data_key <> ''
    UNION ALL SELECT data_key FROM user_moneris_cards         WHERE data_key IS NOT NULL AND data_key <> ''
  ) AS all_cards;


SELECT '=== 3. is the customer id usable as the export key? ===' AS section;

-- The mapping file Stripe returns is keyed by whatever identifiers Moneris
-- puts in the export. CLAS uses 'CLAS-USER-<uid>' as cust_id, so if the
-- export carries cust_id and data_key the join back to these rows is exact.
-- Anything that does not follow that shape has to be mapped by hand.
SELECT SUM(customer_id REGEXP '^CLAS-USER-[0-9]+$') AS well_formed,
       SUM(customer_id IS NULL OR customer_id = '') AS missing,
       SUM(customer_id NOT REGEXP '^CLAS-USER-[0-9]+$'
           AND customer_id IS NOT NULL AND customer_id <> '') AS other_shape,
       COUNT(*) AS total
  FROM user_subscription;

-- And whether the uid inside it agrees with the row's own user_id.
SELECT COUNT(*) AS rows_where_customer_id_disagrees_with_user_id
  FROM user_subscription
 WHERE customer_id REGEXP '^CLAS-USER-[0-9]+$'
   AND CAST(SUBSTRING(customer_id, 11) AS UNSIGNED) <> user_id;


SELECT '=== 4. the renewal window: what the cron would charge next ===' AS section;

-- The old cron charges user_subscription rows of type 3, 4 or 5 that are
-- Active, auto-renewing, already past expiry, and whose account is not
-- blocked. These are the rows a migration must not disturb.
SELECT subscription_type,
       COUNT(*) AS due_now,
       SUM(data_key IS NOT NULL AND data_key <> '') AS with_card
  FROM user_subscription us
  JOIN user u ON u.id = us.user_id
 WHERE us.subscription_type IN (3, 4, 5)
   AND us.status = 'Active'
   AND us.auto_renew = 1
   AND us.expiration_date <= CURDATE()
   AND u.blocked_at IS NULL
 GROUP BY subscription_type;

-- Renewals falling due over the next 90 days, by week. The quietest week is
-- where the cutover goes.
SELECT YEARWEEK(expiration_date, 3) AS iso_week,
       MIN(expiration_date) AS week_starting,
       COUNT(*) AS renewals_due
  FROM user_subscription
 WHERE status = 'Active'
   AND auto_renew = 1
   AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
 GROUP BY iso_week
 ORDER BY iso_week;


SELECT '=== 5. what the gateway has been doing lately ===' AS section;

SELECT 'subscription_transactions' AS tbl,
       DATE_FORMAT(created, '%Y-%m') AS month,
       COUNT(*) AS attempts,
       SUM(txn_status LIKE 'APPROVED%') AS approved,
       ROUND(SUM(CASE WHEN txn_status LIKE 'APPROVED%' THEN amount ELSE 0 END), 2) AS approved_amount
  FROM subscription_transactions
 WHERE created >= DATE_SUB(CURDATE(), INTERVAL 18 MONTH)
 GROUP BY month
 ORDER BY month;

SELECT txn_status, COUNT(*) AS n
  FROM subscription_transactions
 WHERE created >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
 GROUP BY txn_status
 ORDER BY n DESC
 LIMIT 20;


SELECT '=== 6. card expiry profile of the live book ===' AS section;

-- A card that expires before the migration lands is one to re-collect rather
-- than import. Expiry is stored as MM/YYYY text in user_moneris_cards.
SELECT SUBSTRING(expiry, 4) AS expiry_year,
       COUNT(*) AS cards
  FROM user_moneris_cards
 WHERE expiry REGEXP '^[0-9]{2}/[0-9]{4}$'
 GROUP BY expiry_year
 ORDER BY expiry_year;

SELECT SUM(STR_TO_DATE(CONCAT('01/', expiry), '%d/%m/%Y') < CURDATE()) AS already_expired,
       SUM(STR_TO_DATE(CONCAT('01/', expiry), '%d/%m/%Y')
             BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)) AS expiring_within_6_months,
       COUNT(*) AS cards_with_parseable_expiry
  FROM user_moneris_cards
 WHERE expiry REGEXP '^[0-9]{2}/[0-9]{4}$';


SELECT '=== 7. the odd bulk stamp, if it is in production too ===' AS section;

-- On the local copy 2,668 rows carry last_processed = 2025-11-10 with no
-- transactions on that date. If production shows the same, something ran a
-- bulk update and it is worth knowing what before trusting these dates.
SELECT 'user_subscription' AS tbl, last_processed, COUNT(*) AS n
  FROM user_subscription
 WHERE last_processed IS NOT NULL
 GROUP BY last_processed
 ORDER BY n DESC
 LIMIT 5;
