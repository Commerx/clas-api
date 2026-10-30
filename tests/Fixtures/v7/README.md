# v7 contract fixtures

The rebuilt endpoints must match the Yii v7 API field for field and type for
type, because two shipped mobile apps call them and cannot be patched from the
server. A renamed field, or a number serialised as a string instead of an
integer, breaks an installed app — and the only remedy is an emergency release
that users may not take for weeks.

So v7 itself is the specification. `tools/contract-cases.php` lists the request
matrix, `tools/contract-diff.php` calls both implementations and diffs them, and
`tests/Feature/Api/V7/ContractParityTest.php` runs the same comparison under
PHPUnit.

## Running the comparison

```bash
# both implementations side by side
php tools/contract-diff.php                      # defaults: clas.test vs 127.0.0.1:8000
php tools/contract-diff.php http://clas.test http://127.0.0.1:8000

# as part of the suite
php artisan test --filter=ContractParityTest
```

The test **skips** rather than fails when the legacy app is unreachable, so CI
without the old stack still runs green. Set `CONTRACT_OLD_BASE` to point at it.

## What is compared

Every value is replaced by its JSON type before comparing, so `0` and `"0"` are
treated as different. Top-level key order is asserted too. `token` and
`deactivation_date` are masked, since they vary per account and per run.

Quirks in v7 that the rebuild reproduces deliberately:

- `data.id` is an **integer** but `data.notifications_unread_count` is a
  **string** — the old code passed a PDO `COUNT` straight through.
- `data.listings_count` is present **only** when `land_owner` is true, and
  absent otherwise rather than `0`.
- `deactivation_date` is boolean `false` for confirmed users, a date string
  otherwise.
- `property_access_subscription` and friends are boolean `false` or an object.
- `wmu_subscriptions` (plural) appears alongside `wmu_alberta_subscription`
  for subscribed users.
- Any HTTP verb is accepted; non-POST returns `{"error":"Invalid Request!"}`
  with status 200, not a 405.

## The fixture account

The cases authenticate as a local-only account. **Never create it on
production or on any host holding real data** — it exists so the comparison has
a known password to log in with.

```sql
-- local/dev databases only
SET @pw  = '<bcrypt hash of ContractTest123!, cost 10>';
SET @ak  = '<32 hex chars>';
SET @now = UNIX_TIMESTAMP();

INSERT INTO user (username, email, password_hash, auth_key,
                  confirmed_at, created_at, updated_at, flags, role)
VALUES ('contract_fixture', 'contract-fixture@example.invalid', @pw, @ak,
        @now, @now, @now, 0, 'USER')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                        auth_key      = VALUES(auth_key),
                        confirmed_at  = VALUES(confirmed_at);

INSERT INTO profile (user_id, name)
SELECT id, 'Contract Fixture' FROM user WHERE username = 'contract_fixture'
ON DUPLICATE KEY UPDATE name = VALUES(name);
```

Generate the hash with `password_hash('ContractTest123!', PASSWORD_BCRYPT, ['cost' => 10])`.

## Password compatibility

Existing hashes are bcrypt `$2y$10$`, written by Yii's security component.
Verified interoperable in both directions between PHP 5.6 and PHP 8.5, so
Laravel authenticates the existing accounts unchanged. **No password reset is
required as part of the migration.**
