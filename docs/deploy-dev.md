# Deploying to api-dev.canadianlandaccess.com

An ordered walkthrough: TLS, then the production database copy, then the
code. Run top to bottom. Commands are on the **server over SSH** unless the
heading says otherwise.

## What is already true

Checked against the live host on 2026-11-16:

| | |
|---|---|
| DNS | `api-dev.canadianlandaccess.com` → `3.145.179.249` |
| Host | Ubuntu, nginx 1.28.3 |
| Ports | 22 open, 80 open, **443 closed** |
| PHP | 8.5.4 — above the 8.4.1 floor the dependencies impose |
| Laravel | 13.39.0, same as local |
| App | This repository is already deployed and serving: `/up`, `/health`, `/docs` all answer 200 |

So nothing has to be installed. The deployed build is just old — its `/docs`
publishes 29 paths under `/api/v7/...` and calls itself version 7.0.0, which
is this repo from before the move to `/v1/`. Current is **102 endpoints
across 101 documented paths**.

Its `/health` reports `"db":"clas_api"`, which is the problem addressed in
step 7: the default connection is pointed at the wrong schema for this build.

---

# Part 0 — on your machine, before anything

## 0. Push the scrub tooling

`deploy.sh` deploys **what is on GitHub**. Three files are still only local:

```
scrub-dev-copy.sh
docs/sql/scrub-dev-copy.sql
docs/deploy-dev.md          (this file)
```

Commit and push them. Without the first two there is nothing to sanitise the
dump with, and `deploy.sh` prunes `docs/` on the server, so copy the SQL up
separately or run the scrub from a second checkout (step 10 clones one
anyway).

```bash
git status -sb     # expect: ## main...origin/main with no ahead/behind
```

---

# Part 1 — HTTPS

Do this first. It is independent of the database work, it is quick, and
everything afterwards can then be verified over `https`.

## 1. Open port 443

443 is closed at the host, so nothing nginx does will help until that
changes. On an EC2 instance that is the **security group**, not the server:
add an inbound rule for TCP 443 from `0.0.0.0/0` (and `::/0` — the hostname
has an AAAA record).

Confirm from your own machine:

```bash
nc -vz api-dev.canadianlandaccess.com 443
```

Until that succeeds, skip to Part 2 and come back.

## 2. Get a real certificate — not a self-signed one

**Use Let's Encrypt, not `openssl req -x509`.** A self-signed certificate
will actively break three things you need working:

- **The mobile app will refuse it.** Android and iOS both reject untrusted
  certificates by default, and there is no dialog to click through in a
  native HTTP client.
- **Stripe will not send webhooks to it.** Stripe verifies the chain and
  simply drops the endpoint.
- **`curl`, CI and your own smoke tests** all fail, so you lose the ability
  to tell a broken deploy from a broken certificate.

Self-signed is for a throwaway box nobody else talks to. This box has an app
and a payment processor talking to it.

```bash
sudo apt update
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api-dev.canadianlandaccess.com
```

Certbot edits the existing nginx server block, installs the certificate and
sets up a systemd timer for renewal. Say yes when it offers to redirect HTTP
to HTTPS — the app should not be reachable over plaintext once there is an
alternative.

Two things in its favour here: the HTTP-01 challenge uses port 80, which is
already open, and this subdomain resolves **straight to the instance** rather
than through Cloudflare like the apex domain, so there is no proxy to work
around.

Check it:

```bash
sudo certbot renew --dry-run
curl -sI https://api-dev.canadianlandaccess.com/health | head -1
```

Let's Encrypt rate-limits to 5 identical certificates per week, so if
something fails, read the error rather than re-running the command in a loop.

---

# Part 2 — the production database copy

The API is almost entirely reads against the shared CLAS schema, so a
realistic copy is the point of the exercise. There is also no safe way to put
one on a dev box as it comes.

> **The hazard, plainly.** `clas:send-renewal-reminders` covers the *whole*
> Moneris book by design. Pointed at an unscrubbed copy with a working
> mailer, its first run emails thousands of real customers about a renewal
> that is not going to happen. Nothing in Part 2 may be skipped or reordered.

## 3. Copy the dump up

From your machine. The dump is gzipped SQL, about 8.4 MB:

```bash
scp clas-production-YYYY-MM-DD.sql.gz ubuntu@api-dev.canadianlandaccess.com:~/
```

## 4. Look inside it before importing

Two minutes here saves an afternoon. A dump can carry statements that target
a database other than the one you point it at, or drop one.

```bash
zcat clas-production-*.sql.gz | head -40
zcat clas-production-*.sql.gz | grep -ciE '^(CREATE|DROP) DATABASE|^USE '
```

- **`CREATE DATABASE` / `USE` / `DROP DATABASE`** — if any are present the
  dump chooses its own target and `mysql clas < dump` will not put it where
  you think. Strip them, or import and then verify very carefully.
- **`DEFINER=`** clauses on views, triggers or procedures name a user that
  may not exist on this server, and the import fails on them. Count them:
  `zcat ... | grep -c DEFINER=`. If there are any, pipe through
  `sed 's/DEFINER=[^ ]* //g'` on import.

Check it is complete while you are there — 8.4 MB compressed is plausible but
small, and a dump that quietly excluded tables looks exactly like a good one:

```bash
zcat clas-production-*.sql.gz | grep -c '^CREATE TABLE'
```

**Expect 81.** Anything less means tables were excluded and the copy will
fail in ways that look like application bugs.

## 5. Create the two databases

This application uses two, and the distinction matters throughout:

- **`clas`** — the shared schema the Yii application also reads and writes.
- **`clas_api`** — ours alone: migration history, `stripe_events`,
  `subscription_reminders`.

```bash
mysql -u root -p -e "
CREATE DATABASE IF NOT EXISTS clas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS clas_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SHOW DATABASES LIKE 'clas%';"
```

## 6. Import

```bash
zcat clas-production-*.sql.gz | mysql -u root -p clas
```

Then confirm what landed:

```bash
mysql -u root -p -e "
SELECT COUNT(*) AS tables_in_clas FROM information_schema.tables WHERE table_schema='clas';
SELECT COUNT(*) AS accounts FROM clas.user;
SELECT COUNT(*) AS listings FROM clas.land;"
```

81 tables. If the account count is in the thousands, the copy is real.

## 7. Sanitise it — before the application touches it

```bash
cd /var/www/api        # or wherever the checkout is
./scrub-dev-copy.sh clas
```

The script refuses to run on a host whose name does not look like a dev box,
refuses to work across the network, and makes you type the database name
rather than pressing `y`. It prints what it is about to destroy and waits.

**On AWS the hostname is usually something like `ip-10-0-0-5`, which the
guard will refuse.** That is the guard working. Re-run with `DEV_HOST_OK=1`
once you are sure where you are.

It is safe to run twice, and it works both before and after migrations — the
columns our migrations add are handled through a conditional, because a raw
production dump does not have them and a plain `UPDATE` naming them would
fail halfway, on the one table where halfway means live payment tokens
survive.

What it does:

| | |
|---|---|
| Keeps | the 30 `@commerx.com` accounts whole, so you can log in as yourselves |
| Keeps | `profile.user_lat` / `user_lng` for everyone — the alert positions every geofence test needs |
| Keeps | listing names and addresses, and the `gateway` column, so the Moneris/Stripe split stays testable |
| Removes | every other email address, rewritten to `user<id>@dev.invalid` — a TLD reserved by RFC 2606 that can never resolve |
| Removes | **1,456 driver's licence numbers** and 1,569 licence plates from `profile` |
| Removes | 1,778 phone numbers, 1,777 home addresses, 5,610 registration IPs |
| Removes | every Moneris vault token and Stripe id from all five tables, and the whole `user_moneris_cards` table |
| Removes | 3,717 device push tokens |
| Rotates | 5,611 `auth_key`s — production session tokens that work against the live API |
| Rotates | 6,131 listing claim codes, each of which lets its holder take over a listing |

Counts are from the local copy, which is stale; production will be larger.
Every verification line it prints at the end must be zero except the Commerx
one.

Two things stay true afterwards, both deliberate: Commerx staff keep their
own licence and phone data, and alert positions are home locations. **Treat
the dev database as confidential even after scrubbing.**

---

# Part 3 — the application

## 8. Fix `.env`

`.env` is gitignored and belongs to the server; `deploy.sh` never touches it.
The live `/health` reports `"db":"clas_api"`, so the default connection is
currently pointed at the wrong schema for this build — every listing endpoint
would fail, because `clas_api` has no `land` table.

```ini
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://api-dev.canadianlandaccess.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=clas
DB_USERNAME=...
DB_PASSWORD=...

CLAS_API_DB_DATABASE=clas_api

STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=            # filled in at step 13
STRIPE_ALLOWED_KEY_PREFIXES=sk_test_

# Leave on `log` until the scrub is verified and you have seen a dry run.
MAIL_MAILER=log

PUSH_DRIVER=log
GOOGLE_TIMEZONE_KEY=...
LEGACY_WEB_URL=https://canadianlandaccess.com
ADMIN_EMAIL=...
```

Three of these are easy to get wrong:

- **`APP_DEBUG=false`** even on dev. With it on, an exception page prints the
  configuration, including the Stripe secret.
- **`APP_URL`** is what the `/docs` console calls and what appears as the
  server in `openapi.json`. It is currently the bare IP.
- **`STRIPE_ALLOWED_KEY_PREFIXES=sk_test_`** is what stops a live key being
  used here by accident. Do not widen it on this host.

## 9. Deploy

```bash
ssh ubuntu@api-dev.canadianlandaccess.com 'cd /var/www/api && ./deploy.sh'
```

It fetches `origin/main`, hard-resets to it, installs without dev
dependencies, prunes `docs/` `tests/` `CLAUDE.md`, runs migrations, reloads
PHP-FPM, then checks `/up` and `/health` — exiting non-zero with a log tail
if either fails, so a broken deploy stops rather than going quiet.

Four migrations will run. Two alter the shared schema, two create tables in
`clas_api`:

| Migration | Schema | What |
|---|---|---|
| `add_gateway_columns_to_subscriptions` | `clas` | `gateway`, `stripe_customer_id`, `stripe_payment_method_id` on five tables |
| `create_stripe_events_table` | `clas_api` | webhook idempotency |
| `create_subscription_reminders_table` | `clas_api` | one row per reminder sent |
| `add_cancellation_to_subscriptions` | `clas` | `cancelled_at`, `cancellation_reason` |

All four are additive and nullable; the Yii application selects with
`SELECT *` and ignores columns it does not know.

The script runs `migrate --database=clas_api`, which is **not optional**.
Without the flag, migrations run on the default connection — now the shared
schema — and would create the history table there plus Laravel's default
`users`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` and
`failed_jobs`. Eight tables inside a schema the Yii application depends on,
including a `users` table beside its existing `user`.

Check that did not happen:

```bash
mysql -u root -p -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='clas';"
```

It was 81 before the deploy. It should still be 81 after.

## 10. Run the test suite against the real schema

This is the step that has not happened anywhere yet. The suite has only ever
run against the local copy, which is stale.

`deploy.sh` prunes `tests/` and installs `--no-dev`, so use a second
checkout:

```bash
git clone https://github.com/Commerx/clas-api.git ~/clas-api-test
cd ~/clas-api-test
cp /var/www/api/.env .env
composer install
php artisan test
```

Watch the **skip count**. Without the fixture account and listing
(`tests/Fixtures/v1/README.md`) most tests skip rather than fail, which looks
like a pass and proves nothing.

The suite also asserts the host's timezone database is current. If that test
fails, `sudo apt install --only-upgrade tzdata` and reload PHP-FPM — the old
Yii app reports sunrise an hour early across five provinces for exactly this
reason, and this app would inherit it.

## 11. Smoke-test over HTTPS

```bash
curl -s https://api-dev.canadianlandaccess.com/health
```

Expect `ok: true` and a **listings count**. The current health check queries
`land`, so a non-zero count proves the shared schema is really connected —
the old build reported only a database name, which a server pointed at the
wrong schema answers just as happily.

```bash
curl -s https://api-dev.canadianlandaccess.com/docs/openapi.json \
  | python3 -c "import json,sys; print(len(json.load(sys.stdin)['paths']), 'paths')"
```

Expect **101**, not 29. If it still says 29, the deploy did not land.

Then a real call, and a login to prove the copy is connected:

```bash
curl -s "https://api-dev.canadianlandaccess.com/v1/security/version-check"
curl -s -X POST https://api-dev.canadianlandaccess.com/v1/security/login -d email=... -d password=...
```

`/docs` is a working console — the quickest way to exercise the rest by hand
once you have a token.

## 12. The scheduler

Nothing renews, reminds or expires without it.

**Dry-run both commands first, against the real data**, and read the output
before you add the cron entry. The reminder covers Moneris subscribers too,
so on a production copy it will list real subscriptions — that count is the
thing worth seeing before anything is able to send.

```bash
cd /var/www/api
php artisan clas:send-renewal-reminders --dry-run
php artisan clas:renew-subscriptions --dry-run
```

Neither sends or charges anything. Only once those look right:

```bash
* * * * * cd /var/www/api && php artisan schedule:run >> /dev/null 2>&1
```

And only then consider switching `MAIL_MAILER` off `log`.

## 13. The Stripe webhook

Needs TLS, so it comes last. Add a **test-mode** endpoint in Stripe pointing
at:

```
https://api-dev.canadianlandaccess.com/v1/stripe/webhook
```

Put its signing secret in `STRIPE_WEBHOOK_SECRET`. Until that is set the
endpoint returns 503 by design — Stripe retries a 503, so events arriving
before the secret is configured are redelivered rather than lost.

---

## Known gaps on this host

Not blockers for dev, but they are why this is not production:

- **Push notifications do nothing.** No FCM service account, so the driver
  stays `log`. Every intended push is written to the log, which verifies
  fan-out but delivers nothing.
- **Listing photo uploads** need a path decision.
  `clas.uploads.photos_path` points at the old application's document root
  on a Windows machine; here it needs a real directory and a URL serving it.
- **Shipping rates are derived, not quoted.** One real FedEx quote would
  confirm the four-zone card.
- **The committed secrets are still live.** The live Stripe key and the AWS
  pair are in git history. Rotating them is unrelated to this deploy and
  does not wait for it either.
- **`/docs` is public.** Fine for a dev box; worth deciding before the same
  configuration reaches production.
