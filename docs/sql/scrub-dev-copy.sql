-- Anonymise a production copy of the CLAS database for use on the dev server.
--
-- RUN THIS THROUGH ../../scrub-dev-copy.sh, which does the checks that plain
-- SQL cannot: that you are not on production, and that you meant it. Running
-- this file directly against the wrong database destroys customer data with
-- no warning and no undo.
--
-- What it keeps, deliberately:
--
--   * @commerx.com accounts, whole — name, email, profile. You need to be
--     able to log in as yourselves and recognise your own data. Note that
--     this includes their own licence numbers and phone numbers: 8 of the
--     30 carry a driver's licence number. Staff data, kept knowingly. Add
--     them to the scrub if that is not wanted.
--   * profile.user_lat / user_lng for everyone. That is the alert position
--     every geofence and proximity test depends on, and without it the copy
--     cannot exercise the feature it exists to test. It is still a home
--     location: treat the dev database as confidential even after this runs.
--   * Listing names and addresses. They are the business data under test,
--     and most are already public in the app.
--   * `gateway`, so the Moneris/Stripe split stays testable.
--
-- What it removes, and why each one matters:
--
--   * Every non-Commerx email address, so nothing this server sends can
--     reach a customer. 5,581 of them in the copy this was written against.
--     Addresses become user<id>@dev.invalid — `.invalid` is reserved by
--     RFC 2606 and can never resolve, so even a misconfigured mailer fails
--     rather than delivers.
--   * **1,456 driver's licence numbers** and 1,569 licence plates in
--     `profile`. This is the most sensitive data in the database and it is
--     not where anyone would look for it.
--   * 1,778 phone numbers and 1,777 home addresses.
--   * 5,610 registration IPs.
--   * Every Moneris vault token and Stripe id, from all five tables. These
--     are live payment credentials. v1's renewal scheduler only charges
--     `gateway = 'stripe'` rows, so it would not have used them — but a
--     dev box has no business holding the means to charge a real card.
--   * 3,717 device push tokens, which address real phones.
--   * 6,131 listing claim codes, each of which lets whoever holds it take
--     ownership of a listing. Regenerated rather than blanked, so the
--     claim flow still works.
--
-- Safe to run twice. Every statement is idempotent.

-- ---------------------------------------------------------------------------
-- The accounts to leave alone.
--
-- A real table rather than a temporary one: MySQL 5.7 cannot open a
-- temporary table twice in the same statement, and several statements below
-- need it in both a join and a subquery.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS _scrub_keep;
CREATE TABLE _scrub_keep (user_id INT PRIMARY KEY);
INSERT INTO _scrub_keep (user_id)
SELECT id FROM user WHERE email LIKE '%@commerx.com';

SELECT CONCAT('keeping ', COUNT(*), ' @commerx.com accounts') AS note FROM _scrub_keep;

-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------

-- `email` is UNIQUE, so the id keeps it so.
UPDATE user
SET email = CONCAT('user', id, '@dev.invalid'),
    unconfirmed_email = NULL,
    registration_ip = NULL,
    -- A production auth_key is a working production credential. Rotating it
    -- means a leaked dev copy cannot be used against the live API.
    auth_key = SUBSTRING(MD5(CONCAT(id, RAND(), UUID())), 1, 32)
WHERE id NOT IN (SELECT user_id FROM _scrub_keep);

-- `username` is left alone. Some are real names, but they are not
-- contactable, they are UNIQUE so rewriting them risks collisions, and
-- recognisable logins are most of what makes a dev copy usable.
--
-- `password_hash` is left alone too: nobody can log in as these accounts,
-- which is the safer default. To make one usable for testing, set a known
-- hash for that single id by hand.

-- ---------------------------------------------------------------------------
-- Profiles — the licence numbers live here
-- ---------------------------------------------------------------------------
UPDATE profile
SET first_name = 'Test',
    last_name = CONCAT('User', user_id),
    name = CONCAT('Test User', user_id),
    display_name = CONCAT('TestUser', user_id),
    public_email = NULL,
    gravatar_email = CONCAT('user', user_id, '@dev.invalid'),
    gravatar_id = NULL,
    phone = NULL,
    street = NULL,
    city = NULL,
    postal_code = NULL,
    location = NULL,
    website = NULL,
    bio = NULL,
    brief_bio = NULL,
    occupation = NULL,
    guide_number = NULL,
    vehicle_description = NULL,
    vehicle_year = NULL,
    vehicle_make = NULL,
    vehicle_model = NULL,
    vehilcle_color = NULL,
    license_plate = NULL,
    driver_license_number = NULL,
    other_features = NULL,
    question = NULL,
    pipedrive_person_id = NULL
WHERE user_id NOT IN (SELECT user_id FROM _scrub_keep);
-- user_lat / user_lng deliberately untouched. See the header.

-- ---------------------------------------------------------------------------
-- Listings
--
-- Not scoped to non-Commerx accounts: these are contact details for whoever
-- the listing names, who is often not the account holder at all.
-- ---------------------------------------------------------------------------
UPDATE land
SET contact_email = CASE
        WHEN contact_email IS NULL OR contact_email = '' THEN contact_email
        ELSE CONCAT('land', land_id, '@dev.invalid') END,
    contact_phone = NULL,
    supersede_emails_1 = NULL,
    supersede_emails_2 = NULL,
    -- A shipping address, as JSON. Replaced wholesale rather than edited,
    -- so the shape stays valid for the signage endpoints.
    signage_shipping_info = CASE
        WHEN signage_shipping_info IS NULL OR signage_shipping_info = ''
        THEN signage_shipping_info
        ELSE '{"street":"1 Test Road","city":"Lethbridge","province":"AB","country":"CA","postal_code":"T1J 3Y3","phone":"4035550100"}'
        END,
    -- Regenerated, not blanked: whoever holds a claim code can take over a
    -- listing, and the claim flow still has to be testable.
    claim_code = CASE
        WHEN claim_code IS NULL OR claim_code = '' THEN claim_code
        ELSE SUBSTRING(CONCAT(MD5(CONCAT(land_id, RAND())), MD5(RAND())), 1, 40)
        END;

UPDATE land_managers
SET user_email = CONCAT('manager', id, '@dev.invalid'),
    first_name = 'Test',
    last_name = CONCAT('Manager', id)
WHERE user_id IS NULL OR user_id NOT IN (SELECT user_id FROM _scrub_keep);

UPDATE mktplace_managers
SET user_email = CONCAT('mktmanager', id, '@dev.invalid'),
    first_name = 'Test',
    last_name = CONCAT('Manager', id)
WHERE user_id IS NULL OR user_id NOT IN (SELECT user_id FROM _scrub_keep);

-- ---------------------------------------------------------------------------
-- Orders and addresses
-- ---------------------------------------------------------------------------
-- `orders` and `marketplace_orders` key on order_id, not id.
UPDATE orders
SET email = CONCAT('order', order_id, '@dev.invalid'),
    phone = '4035550100',
    street = '1 Test Road',
    city = 'Lethbridge',
    postal_code = 'T1J 3Y3';

UPDATE marketplace_orders
SET email = CONCAT('order', order_id, '@dev.invalid'),
    phone = '4035550100',
    street = '1 Test Road',
    city = 'Lethbridge',
    postal_code = 'T1J 3Y3';

UPDATE mktplace_orders
SET billing_firstname = 'Test',
    billing_lastname = CONCAT('Buyer', id),
    billing_phone = '4035550100',
    billing_address = '1 Test Road',
    billing_city = 'Lethbridge',
    billing_postal_code = 'T1J 3Y3',
    shipping_firstname = 'Test',
    shipping_lastname = CONCAT('Buyer', id),
    shipping_phone = '4035550100',
    shipping_address = '1 Test Road',
    shipping_city = 'Lethbridge',
    shipping_postal_code = 'T1J 3Y3';

UPDATE mktplace_user_addresses
SET user_firstname = 'Test',
    user_lastname = CONCAT('User', id),
    user_phone = '4035550100',
    user_address = '1 Test Road',
    user_city = 'Lethbridge',
    user_postal_code = 'T1J 3Y3'
WHERE user_id NOT IN (SELECT user_id FROM _scrub_keep);

-- ---------------------------------------------------------------------------
-- Things with no business existing on a dev box at all
-- ---------------------------------------------------------------------------

-- Real phones. Push is on the `log` driver here, so nothing would be
-- delivered — but that is a config setting, and config settings get changed.
TRUNCATE TABLE device_tokens;

-- Health declarations.
TRUNCATE TABLE covid;

-- Moneris vault tokens, masked PANs and cardholder names. Emptied for every
-- account including Commerx: nobody needs the means to charge a real card
-- from a dev server.
TRUNCATE TABLE user_moneris_cards;

-- ---------------------------------------------------------------------------
-- Payment tokens on the subscriptions
--
-- `gateway` is left alone, so a Moneris row stays a Moneris row and the
-- coexistence behaviour is still testable. What goes is the ability to
-- actually charge anyone.
--
-- Worth knowing before you test: the renewal *reminder* does not need a
-- token. It selects Moneris rows on `auto_renew = 1` and a renewing plan
-- type, so the whole Moneris book is still reminder-testable after this.
-- The renewal *scheduler* needs `stripe_payment_method_id`, so it will find
-- nothing until you create a test subscription — which is the right way
-- round.
--
-- **This has to work on a raw production dump**, which is the whole point:
-- you scrub before the application touches the copy. Production has not had
-- our migrations yet, so `stripe_customer_id`, `stripe_payment_method_id`
-- and `cancellation_reason` do not exist there — a plain UPDATE naming them
-- fails with "unknown column" and leaves the scrub half-done, on the one
-- table where half-done means live payment tokens survive.
--
-- `data_key` is the Moneris token and predates everything, so it is
-- unconditional. The rest go through a prepared statement that is built
-- only if the column is there, which makes this script correct both before
-- and after `php artisan migrate`.
-- ---------------------------------------------------------------------------
UPDATE user_subscription SET data_key = NULL;
UPDATE wmu_subscription SET data_key = NULL;
UPDATE darkmode_subscription SET data_key = NULL;
UPDATE fishing_lands_subscription SET data_key = NULL;

-- user_subscription: the Stripe pair and the cancellation note.
SET @stmt := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_subscription'
        AND column_name = 'stripe_customer_id') > 0,
    CONCAT('UPDATE user_subscription SET stripe_customer_id = NULL, ',
           'stripe_payment_method_id = NULL, cancellation_reason = NULL'),
    'DO 0');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'wmu_subscription'
        AND column_name = 'stripe_customer_id') > 0,
    'UPDATE wmu_subscription SET stripe_customer_id = NULL, stripe_payment_method_id = NULL',
    'DO 0');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'darkmode_subscription'
        AND column_name = 'stripe_customer_id') > 0,
    'UPDATE darkmode_subscription SET stripe_customer_id = NULL, stripe_payment_method_id = NULL',
    'DO 0');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'fishing_lands_subscription'
        AND column_name = 'stripe_customer_id') > 0,
    'UPDATE fishing_lands_subscription SET stripe_customer_id = NULL, stripe_payment_method_id = NULL',
    'DO 0');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- Verification. Every count below must be zero except the Commerx one.
-- ---------------------------------------------------------------------------
SELECT 'commerx accounts kept' AS check_name,
       (SELECT COUNT(*) FROM _scrub_keep) AS n
UNION ALL SELECT 'non-commerx real emails remaining',
       (SELECT COUNT(*) FROM user
         WHERE email NOT LIKE '%@dev.invalid' AND email NOT LIKE '%@commerx.com')
UNION ALL SELECT 'driver licence numbers remaining',
       (SELECT COUNT(*) FROM profile p
         WHERE p.driver_license_number IS NOT NULL AND p.driver_license_number <> ''
           AND p.user_id NOT IN (SELECT user_id FROM _scrub_keep))
UNION ALL SELECT 'phone numbers remaining',
       (SELECT COUNT(*) FROM profile p
         WHERE p.phone IS NOT NULL AND p.phone <> ''
           AND p.user_id NOT IN (SELECT user_id FROM _scrub_keep))
UNION ALL SELECT 'moneris tokens remaining',
       (SELECT COUNT(*) FROM user_subscription WHERE data_key IS NOT NULL AND data_key <> '')
          + (SELECT COUNT(*) FROM wmu_subscription WHERE data_key IS NOT NULL AND data_key <> '')
          + (SELECT COUNT(*) FROM darkmode_subscription WHERE data_key IS NOT NULL AND data_key <> '')
          + (SELECT COUNT(*) FROM fishing_lands_subscription WHERE data_key IS NOT NULL AND data_key <> '')
          + (SELECT COUNT(*) FROM user_moneris_cards)
UNION ALL SELECT 'device push tokens remaining',
       (SELECT COUNT(*) FROM device_tokens)
UNION ALL SELECT 'listing contact emails remaining',
       (SELECT COUNT(*) FROM land
         WHERE contact_email IS NOT NULL AND contact_email <> ''
           AND contact_email NOT LIKE '%@dev.invalid');

DROP TABLE IF EXISTS _scrub_keep;
