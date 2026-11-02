# clas-api

The rebuilt CLAS API. Laravel 13 on PHP 8.5, reading the existing `clas`
database that the Yii application also reads and writes.

Served from its own subdomain; the old application continues to serve app
builds already in the field from the original domain. So this API is **not**
bug-compatible with it — see `/docs` for what changed and why.

## Rules that are enforced by tests

`tests/Feature/ResponseHygieneTest.php` fails the suite on any of these, so
they hold for endpoints nobody has written yet. Each has been verified to
fail when violated.

**Never select every column into a response.** No `select('*')` or
`select('table.*')` in a controller — list the columns. The old API's worst
exposure came from exactly this: `land.*` published `claim_code`, the secret
that lets whoever presents it take ownership of a listing, for 6,131 listings,
to any authenticated caller. An allowlist fails closed; a new column stays out
of the API until someone adds it deliberately.

**Every route sits behind `auth.token`,** except `security/login`, which
issues the token the rest require.

**Every route appears in `app/Http/Docs/OpenApi.php`.** The spec is the
contract the app developer works from, so an undocumented endpoint is one
nobody outside this repository knows about.

## Rules that are not enforced, and matter anyway

**Check object access, not just the token.** A valid token says who the caller
is, not what they may see. The old API answered `requests/detail` for any
request id to any caller, returning the requester's name, phone, address,
coordinates and driver licence number. Where access is refused, answer **404**
rather than 403, so the existence of a record is not confirmed to someone who
cannot see it.

**Access means owner, or a manager whose invitation was *accepted*.**
`land_managers` rows carry a status; `pending` means the invitation was sent
and never taken up, and grants nothing. Use `App\Support\V7\ListingAccess` —
one definition, because when this was three private copies two of them
forgot the status check. Reading uses `canManage`, writing uses `isOwner`,
which is what the old application enforced.

**Writes must stay semantically identical to the old application's.** Both
write to the same database, so a row created here has to be indistinguishable
from one it created. Reads are free to improve; rows are not.
`ListingWriteTest` asserts this for blocked days.

## Conventions

One envelope everywhere, `success, error, data`, with the HTTP status carrying
the outcome too: 401 unauthenticated, 403 not permitted, 404 not found, 422
validation with per-field `errors`. Use `App\Http\ApiResponse`.

Native types: ids are integers, flags are booleans. The old API returned `"56"`
from some endpoints and `56` from others.

## Local setup

PHP 8.5 lives beside Laragon's other versions; **do not switch Laragon's global
PHP**, as the old application needs 5.6 and will not load on anything newer.

```bash
D:/laragon/bin/php/php-8.5.12-Win32-vs17-x64/php.exe artisan serve
```

Tests read the live `clas` schema and need the fixture account and listing —
see `tests/Fixtures/v7/README.md`. They skip rather than fail without them.

```bash
php artisan test
php vendor/bin/pint
php tools/contract-report.php     # field-by-field diff against the old API
```

Local MySQL is 5.7; staging is 8.4. Anything relying on the older, laxer
`GROUP BY` handling will pass locally and fail there — `ONLY_FULL_GROUP_BY` is
on by default in 8.4 and in Laravel's strict mode.
