# Testing this API

The new API is served from its own host and consumed by a new app build; the
old Yii application keeps serving builds already in the field from the original
domain. So this API is **not** bug-compatible with the old one, and it is not
tested against it.

## What asserts the contract

`tests/Feature/Api/V7/ApiContractTest.php`. It checks the envelope and its key
order, the status codes, that ids are integers and flags are booleans, that
geometry fields are always lists, and that a token obtained from `login` works
as a bearer token.

```bash
php artisan test --filter=ApiContractTest
```

It reads the live `clas` schema, because the whole API does — `phpunit.xml`
points the suite at in-memory sqlite, which has none of the legacy tables, so
the test overrides the connection in `setUp`. Nothing in it writes.

## What documents the differences

`tools/contract-report.php` calls both implementations with the same input and
reports every difference field by field. It is a handover document for the app
developer, not a test: **it always exits 0, because a difference is the point.**

```bash
php tools/contract-report.php                                  # local defaults
php tools/contract-report.php https://old.example.com https://api.example.com
```

The request matrix lives in `tools/contract-cases.php`. Credentials are
resolved from the database at runtime, so no real token is committed.

Current output: 25 of 25 responses differ. Among them:

- the envelope is `success, error, data` everywhere, where the old API varied
- auth failures are 401, not 200 with one of three different messages
- `notifications_unread_count` is an integer, not `"0"`
- `features` returns the listing's features; the old API returned nothing
- `points` returns every boundary point, not just the last one
- validation failures are 422 and name the field in `errors`

## Interactive documentation

`/docs` renders the OpenAPI document with a **Test Request** panel against the
live API. Served from the app itself so those calls are same-origin; a page
hosted elsewhere could show the spec but not call it. Open outside production;
on production it needs `DOCS_ENABLED=true`.

The spec itself is at `/docs/openapi.json`, written by hand in
`app/Http/Docs/OpenApi.php`. **An endpoint added without an entry there is an
endpoint nobody outside this repository knows about.**

## The fixture account

Tests authenticate as a local-only account. **Never create it on production or
on any host holding real data.**

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
The tests skip rather than fail when the account or the database is absent.

## Password compatibility

Stored hashes are bcrypt `$2y$10$`, written by Yii. Verified interoperable in
both directions between PHP 5.6 and PHP 8.5, so existing accounts authenticate
against this API unchanged. **No password reset is required.**

## Shared database

This API and the old one read and write the same `clas` database. Reads are
free to improve; **writes must stay semantically identical**, so that a record
created through either implementation is indistinguishable from the other.
