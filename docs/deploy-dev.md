# Deploying to api-dev.canadianlandaccess.com

A runbook for the dev server. Everything here is run **on the server over
SSH** unless it says otherwise.

## What is already true

Checked from here on 2026-11-10, against the live host:

| | |
|---|---|
| DNS | `api-dev.canadianlandaccess.com` → `3.145.179.249` (also AAAA) |
| Host | Ubuntu, nginx 1.28.3 |
| Ports | 22 open, 80 open, **443 closed** |
| PHP | **8.5.4** — above the 8.4.1 floor the dependencies impose |
| Laravel | 13.39.0, same as local |
| App | **This repository is already deployed and serving.** `/up`, `/health` and `/docs` all answer 200 |

So the hard part is done. The server exists, nginx is configured, PHP is the
right version and a previous deploy of this repo works. What follows is
bringing it up to date, which is mostly environment rather than installation.

**But the deployed build is old.** Its `/docs` publishes 29 paths under
`/api/v7/...` and calls itself version 7.0.0 — that is this repo from before
the API moved to `/v1/` on its own host. The current build has **102
endpoints across 101 documented paths**. None of `user/subscribe`,
`user/payment-method`, `manage-listings/add-signages`,
`user/cancel-subscription` or `stripe/webhook` exist on it.

Its OpenAPI document also names `http://3.145.179.249` as the server, which
is `APP_URL` on that box. The `/docs` console calls whatever that says, so
it needs to become the real hostname — see `.env` below.

## Prerequisites

### 1. The work has to be pushed, and two files are not committed yet

`deploy.sh` does `git fetch origin main` then `git reset --hard origin/main`.
It deploys **what is on GitHub**, not what is on your machine, so anything
not pushed does not exist as far as the server is concerned.

As of 2026-11-10:

- `3fd43f7` "payment and subscription related API updates" holds the
  reminder, cancellation and signage work — 27 files, committed but **1
  commit ahead of `origin/main`**, so it has not been pushed.
- **`deploy.sh` and `.env.example` are still uncommitted**, and the
  `deploy.sh` change is the migration fix described in step 5. Deploying
  without it is the one action in this runbook that can damage the shared
  production schema.

So: commit those two, push, then deploy. Confirm with

```bash
git status -sb     # expect: ## main...origin/main  (no ahead/behind)
```

### 2. The database configuration on the server is wrong for this build

This is the one that will break the deploy, and it is worth understanding
before touching anything.

The live `/health` reports `"db":"clas_api"`. That means the server's default
connection points at `clas_api`. That was right when the app used one
database; it is wrong now. The current code expects **two**:

- `mysql` (the default) → the **shared CLAS schema**, which the Yii
  application also uses. Every listing, user and subscription lives here.
- `clas_api` → this application's own schema: migration history,
  `stripe_events`, `subscription_reminders`.

Left as it is, every endpoint that touches a listing will fail, because
`clas_api` has no `land` table.

Check what the server actually has:

```bash
mysql -u root -p -e "SHOW DATABASES LIKE 'clas%';"
```

If `clas` is missing, import a copy before going further — the API is almost
entirely reads against that schema, and there is no point testing without it.
If `clas_api` is missing, create it:

```bash
mysql -u root -p -e "CREATE DATABASE clas_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 3. `.env` on the server needs editing

`.env` is gitignored and belongs to the server; `deploy.sh` never touches it.
Edit it before deploying. `.env.example` documents the full set; these are
the ones that matter here.

```ini
APP_ENV=staging
APP_DEBUG=false
# Also what the /docs console calls, and what appears as the server in
# openapi.json. It is currently the bare IP on this host.
APP_URL=https://api-dev.canadianlandaccess.com

# The shared CLAS schema — NOT clas_api.
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=clas
DB_USERNAME=...
DB_PASSWORD=...

# Our own schema. Inherits host/port/credentials from the DB_* values above.
CLAS_API_DB_DATABASE=clas_api

# Stripe. Test keys only on this host: the prefix allowlist is what stops a
# live key being used by accident, so do not widen it here.
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_ALLOWED_KEY_PREFIXES=sk_test_

# Real SMTP, or the renewal reminder and cancellation mails go nowhere.
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...

# Push stays on the log driver until there is an FCM service account.
PUSH_DRIVER=log

GOOGLE_TIMEZONE_KEY=...
LEGACY_WEB_URL=https://canadianlandaccess.com
ADMIN_EMAIL=...
```

`APP_DEBUG=false` matters even on a dev box: with it on, an exception page
prints the configuration, and that includes the Stripe secret.

### 4. Check the server's timezone database

The old Yii application reports sunrise an hour early across five Canadian
provinces because its PHP ships tzdata 2016.10. This app inherits the same
bug on a stale host.

```bash
php -r 'echo timezone_version_get(), PHP_EOL;'
```

Anything below `2026.` needs `sudo apt update && sudo apt install --only-upgrade tzdata`
and a PHP-FPM reload. The test suite asserts this, so step 7 below catches it
too.

## Deploying

### 5. Run the deploy script

```bash
ssh ubuntu@api-dev.canadianlandaccess.com 'cd /var/www/api && ./deploy.sh'
```

Adjust the path if the checkout is elsewhere. The script fetches
`origin/main`, hard-resets to it, installs without dev dependencies, prunes
`docs/`, `tests/` and `CLAUDE.md`, runs migrations, reloads PHP-FPM and then
checks `/up` and `/health`. It exits non-zero and prints the log tail if
either fails, so a broken deploy stops rather than going quiet.

**The migration step was fixed on 2026-11-10** and that fix has to be in the
commit you deploy. It now runs `migrate --database=clas_api`. Without the
flag, migrations run on the default connection — which is now the shared CLAS
schema — and would create the migration history table there, along with
Laravel's default `users`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches` and `failed_jobs` tables. Eight tables inside a production
schema the Yii application depends on, including a `users` table beside its
existing `user`.

Four migrations will run. Two alter the shared schema and two create tables
in `clas_api`:

| Migration | Schema | What |
|---|---|---|
| `add_gateway_columns_to_subscriptions` | `clas` | `gateway`, `stripe_customer_id`, `stripe_payment_method_id` on the five subscription tables |
| `create_stripe_events_table` | `clas_api` | webhook idempotency |
| `create_subscription_reminders_table` | `clas_api` | one row per reminder sent |
| `add_cancellation_to_subscriptions` | `clas` | `cancelled_at`, `cancellation_reason` |

All four are additive and nullable. The Yii application selects these tables
with `SELECT *` and ignores columns it does not know, so it is unaffected.

Confirm the shared schema did not gain tables:

```bash
mysql -u root -p -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='clas';"
```

It was 81 before. It should still be 81.

### 6. TLS

Port 443 is closed, so the dev host is HTTP-only today. The app sends
password-reset and confirmation links and takes Stripe payment method ids;
neither belongs on plain HTTP even in dev.

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api-dev.canadianlandaccess.com
```

Certbot edits the nginx server block and sets up renewal. Check the security
group allows 443 inbound first — that is the likelier reason the port is
closed, and certbot's HTTP-01 challenge needs 80 open, which it is.

The main domain sits behind Cloudflare; this subdomain resolves straight to
the instance, so there is no proxy to configure.

## Testing it

### 7. Run the suite on the server, against the real schema

This is the step that has not happened yet, and the reason for deploying to
dev at all. The suite has only ever run against a local copy that is known to
be stale — the signage tables hold 15 rows and four shipments here, and the
subscription book is far smaller than production.

`deploy.sh` prunes `tests/` and installs `--no-dev`, so run this **in a
separate checkout**, not the deployed one:

```bash
git clone https://github.com/Commerx/clas-api.git ~/clas-api-test
cd ~/clas-api-test
cp /var/www/api/.env .env
composer install
php artisan test
```

It needs the fixture account and listing (`tests/Fixtures/v1/README.md`
describes them) or most tests skip rather than fail — check the summary for a
large skip count, which means the fixtures are missing and the run proved
nothing.

### 8. Smoke-test the dev URL

```bash
curl -s https://api-dev.canadianlandaccess.com/health
```

Expect `ok: true`, the environment, and a **listings count** — the current
health check queries `land`, so a non-zero count proves the shared schema is
connected. The old build reported only a database name, which a server
pointed at the wrong schema would answer just as happily.

```bash
curl -s https://api-dev.canadianlandaccess.com/docs/openapi.json \
  | python3 -c "import json,sys; print(len(json.load(sys.stdin)['paths']), 'paths')"
```

Expect **101**, not 29. If it still says 29 the deploy did not land.

Then a few real calls. Public first:

```bash
curl -s "https://api-dev.canadianlandaccess.com/v1/security/version-check"
```

And one authenticated, to prove the shared database is really connected:

```bash
curl -s -X POST https://api-dev.canadianlandaccess.com/v1/security/login \
  -d email=... -d password=...
```

`/docs` is a working API console — the fastest way to exercise the rest by
hand once you have a token.

### 9. The scheduler

Nothing renews, reminds or expires without it. One cron entry:

```bash
* * * * * cd /var/www/api && php artisan schedule:run >> /dev/null 2>&1
```

Both commands are safe to run by hand first, and both take `--dry-run`, which
changes nothing and sends nothing:

```bash
php artisan clas:send-renewal-reminders --dry-run
php artisan clas:renew-subscriptions --dry-run
```

**Do the dry runs before adding the cron entry**, against the real data. The
reminder covers Moneris subscribers as well as Stripe ones, so on a
production copy it will list real people — that is the number worth seeing
before anything can send to them.

### 10. The Stripe webhook

Point a Stripe **test-mode** endpoint at:

```
https://api-dev.canadianlandaccess.com/v1/stripe/webhook
```

and put its signing secret in `STRIPE_WEBHOOK_SECRET`. Until that is set the
endpoint returns 503 by design — Stripe retries a 503, so events that arrive
before the secret is configured are redelivered rather than lost. Needs TLS
first; Stripe will not send to plain HTTP.

## Known gaps on this host

Not blockers for dev, but they are why this is not production:

- **Push notifications do nothing.** No FCM service account, so the driver
  stays `log`. Every intended push is written to the log, which is enough to
  verify fan-out but delivers nothing to a device.
- **Listing photo uploads** need a path decision. `clas.uploads.photos_path`
  currently points at the old application's document root on a Windows
  machine; on this host it needs a real directory and a URL that serves it.
- **Shipping rates are derived, not quoted.** One real FedEx quote would
  confirm the four-zone card.
- **The committed secrets are still live.** The live Stripe key and the AWS
  pair are in git history and rotating them is unrelated to this deploy, but
  it does not wait for it either.
- **`/docs` is public.** Fine for a dev box; worth a decision before the same
  configuration reaches production.
