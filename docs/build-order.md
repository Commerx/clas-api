# API rebuild — recommended build order

29 of 100 endpoints are done. 71 remain, about 7,400 lines of the original
10,107. This is the order I'd build them in, and why.

## The reasoning

**Mail first, because 15 endpoints need it.** Every write endpoint ported
without it accrues the same gap — a booking changes hands and nobody is told.
It is small and isolated, and doing it once beats retrofitting it fifteen
times.

**Then finish Requests, because that completes the loop.** Browse → request →
decide → check in → check out is the product. `submit` is the missing link:
without it nothing can be created, so the app developer cannot integrate
against anything end to end. This is the first milestone worth handing over.

**Then the user and account endpoints,** because a new build has to be able to
register, sign in, recover a password and update a profile before anyone can
use it at all.

**Then the self-contained features,** which carry no payment or mail coupling
and can be done steadily without blocking anything.

**ManageListings last of the non-payment work.** It is the largest controller
at 22 endpoints, it is owner-side rather than visitor-side, and nothing else
depends on it.

**Payments last, and only after the Stripe decision.** Ten endpoints touch
Moneris. Rebuilding that integration and then replacing it with Stripe is the
same work twice. Either go straight to Stripe here, or leave these on the old
API until Stripe lands — but do not port Moneris.

**Do not port MarketplaceOrders.** The marketplace is retired and its web
routes already return 410. Its two API endpoints should simply not exist in
the new API; the app developer removes those calls. That is 197 lines not
written.

## Order at a glance

| # | Group | Endpoints | Blocks | Notes |
|---|---|---|---|---|
| 1 | Mail infrastructure | — | 15 endpoints | do first |
| 2 | Requests: submit, upcoming, today-bookings | 3 | core loop | first handover milestone |
| 3 | User, UserSettings, Security | 14 | onboarding | **done** |
| 4 | Notifications remainder | 8 | — | **done** |
| 5 | HuntersTags, Chat, Wmu, FishingLands | 15 | — | **done** |
| 6 | ManageListings, non-payment | 18 | — | **done** |
| 7 | Payments | 10 | Stripe decision | do not port Moneris |
| — | MarketplaceOrders | 2 | — | **skip: retired** |

## Tasks

One line each, with an estimate. Estimates assume the patterns already in
place — envelope, auth, access checks, row casting, contract tests, OpenAPI
entry — so each endpoint is translation plus tests, not design.

### 1. Mail infrastructure — DONE

```
Port request-response, request-cancel and checkin_checkout mail templates to Laravel mailables — 6h
Move the hardcoded check-in/check-out BCC addresses into config — 1h
Wire RequestNotifier to send, and assert delivery with Mail::fake in tests — 3h
Configure SMTP for staging and verify a real send end to end — 2h
```

### 2. Finish the Requests controller — DONE

```
```

### 3. User, settings and security — DONE

All fourteen endpoints: signup and the confirmation flow, forgot-password,
logout, version-check, user/get, the three profile writes, the password
change, deactivate, report-issue, and the two UserSettings endpoints.

`user/subscribe` and `user/apply-promocode` were in the old UserController but
both run card payments through Moneris, so they are counted under payments
rather than here.

What changed beyond translation, and why each one is a change rather than a
port:

- **The three profile writes had no access control at all.** Each assigned
  `$user = User::findOne(['auth_key' => ..., 'id' => $uid])` and never read
  the result, and the controller carried no access filter — so the profile
  written was whichever one `uid` named, with no valid token required. They
  now act on the account the token identifies, `uid` cannot steer a write,
  and two tests plus a source guard hold that.
- **version-check compared versions with `>`.** iOS ships 2.54, so no client
  on 2.6 through 2.9 was ever told an update existed. Now `version_compare`.
- **forgot-password and send-confirmation-code reported whether an address
  had an account.** Both answer uniformly now, and the three open endpoints
  that send mail are rate limited.
- **The password change left old tokens working.** It now rotates `auth_key`
  and returns the new token.
- **report-issue sent mail as the user and interpolated their text
  unescaped.** Now sent from the application with the user in `Reply-To`,
  through a Blade template.
- **user/get returned every profile column**, `pipedrive_person_id` included.
  Now an allowlist of 50 fields.
- **save-notification-settings defaulted every absent switch to Yes**, so
  changing one turned the other three back on. Each is independent now.

### 4. Notifications — DONE

All eight: submit, community-awareness, subscriber-notification,
hunting-alert, get-notification, read, delete-notification and
report-notification.

The fan-out was written out four times in the old controller — once in submit
and once in each dedicated endpoint — and the four had drifted. It is now one
service, `App\Services\NotificationBroadcast`, which is where the differences
between those four are recorded.

What changed beyond translation:

- **Anyone could post to any listing's subscribers.** The old endpoint checked
  only that the listing existed, and recipients read these as coming from the
  property they chose to follow; one sub-category is "Product/Service
  Advertisement". Now owner or accepted manager, refused as 404.
- **`all_notifications` did nothing.** The condition was
  `all_notifications == 'Yes' || <specific> == 'Yes'`, so turning it off had
  no effect unless every specific switch was off too. It is a master switch
  now.
- **Nobody without a settings row was ever pushed.** The old code required the
  row to exist; 11 accounts of 5,600 have one, so push reached almost nobody
  even where it worked.
- **`read` was a GET that wrote.** Now a POST.
- **Push has been dead since July 2024.** The old code posts to FCM's legacy
  endpoint with a server key hardcoded in the controller, and Google retired
  that API; nothing read the response, so it failed silently. See the note
  below — this needs a decision, not just a port.
- **PEASI was reached from `submit` only,** not from the dedicated
  hunting-alert endpoint, and posted to a hardcoded `…-dev.peasi.ca` URL with
  a token in the source. Now configuration, off by default.
- **report-notification left `is_reported` unset** although the column exists,
  so a user could report the same message repeatedly with no sign of it. Mail
  also went out as the reporting user, failing SPF and DKIM.
- **get-notification selected every column.** Now an allowlist.

### Two things this group surfaced that are not code

**Push notifications need an FCM decision.** The transport is written against
FCM's v1 API and is configuration-gated; until a service account is supplied
the driver stays `log`, which records every intended push without sending it.
The fan-out — who receives what, and whose settings suppress a push — is
complete and tested either way. Supplying the credentials is the remaining
step, and it is worth telling the client that push has not worked since July
2024 regardless of this rebuild.

**Proximity alerts are all or nothing, and that is a data problem.** 5,610 of
5,611 profiles hold one coordinate: the old web app's default map centre,
which a single statement wrote over the whole table some time after December
2023 — ten accounts that genuinely sat near Lahore in that month hold it too
now. Of the active listings with coordinates, 88 lie within 25 km of that
point, so an alert on any of them reaches **every account**; the other 16,451
reach nobody, as do the 302 with no coordinates. The new API treats a position
within fifty metres of a known map default as no position and refuses to store
one, which makes it safe. What to do about the rows already there, the old API,
position capture and fan-out size are decisions, not ports.
`docs/profile-positions.md` has the measurements and the options.

### 5. Self-contained features — DONE

**15 endpoints, not 17.** Two of the HuntersTags controller's seven actions
were never endpoints: `hunters-tags/list` and `hunters-tags/categories` are
copies of the two Notifications actions of the same name, pasted in and left
behind. `list` carries a double comma in its SELECT, and both reference
classes the file never imports. Both answer with a PHP fatal error on the
running old API:

```
hunters-tags/list        500  Class '...\v7\NotificationsUsersMapping' not found
hunters-tags/categories  500  Class '...\v7\NotificationCategories' not found
```

So no app build can be calling them, and the working versions —
`notifications/list` and `notifications/categories` — were ported in group 4.

What changed beyond translation:

- **A tag was filed against the wrong province.** `add-tag` resolved each
  management unit with `WMU::findOne(['wmu_number' => $wmu])`, by number
  alone. `wmu_number` is not unique: 1,599 rows carry 1,145 distinct numbers,
  and "3" exists in six provinces. A Saskatchewan tag for unit 3 could be
  filed against Manitoba's unit 3, so the hunter is alerted about the wrong
  province and missed for their own. 3 zone rows are in that state today.
  Resolution is now by province and number together, in
  `App\Support\V1\Wmu`, which the hunting alert endpoint already used.
- **A client could write to the unit reference table.** When a number did not
  resolve, `add-tag` inserted a new `wmus` row holding nothing but that
  number. 58 rows have no province as a result, with values like "00539" and
  "69A-2". An unknown unit is now a validation failure.
- **Expiry swept every account.** `get-all-tags` called
  `HuntersMatchTags::updateExpiredStatus()`, an `updateAll` across the whole
  table, so one hunter opening that screen rewrote every other hunter's rows
  and whether a tag counted as expired depended on who had read theirs most
  recently. It now expires the caller's own rows only — and alert matching no
  longer depends on the sweep at all: a tag past its season is excluded by
  date as well as by status. All 72 tags in the table are currently Expired.
- **A tag could be written with any status, any date, any species.** Status is
  an enum of three values and was unvalidated; dates were passed through as
  text; the species id was checked only by the model, whose failure the
  controller reported as "something went wrong". All checked now, and the tag
  and its units are written in one transaction — `update-tag` deleted and
  reinserted the units with no transaction, so a failure part way through
  left a tag covering some of the units asked for and none of the rest.
- **Chat had no limits.** `history` is LONGTEXT and nothing capped what a
  client could store; it is a megabyte now. Access control was already right
  in that controller — every lookup was scoped to the account — so the rest is
  the envelope and the status codes.
- **The games list repeated itself.** `wmu/games` built it by walking the
  game details and appending each one's game, so a unit with two details for
  the same game listed it twice. Distinct now.
- **Season tables stayed behind the allowlist**, which is the only thing
  standing between a client and a table name, and is now expressed once
  rather than twice. Nine provinces have a season table; NL, NT, NU and QC
  do not.
- **Reference names are fit to display.** 13 of the 174 species names hold a
  carriage return and newline, so "White-tailed\r\nDeer" reached the app
  wherever a species was named. Whitespace is collapsed; matching is by
  `species_id` everywhere, so nothing else changes.
- **The fishing pair returned two shapes under one key** — `regulations` was a
  list from one endpoint and a single object from the other. The list is
  `items` and the single row is `item`, as everywhere else in this API.

One question for the app developer: `wmu/game-season-details` requires
`wmu_id` and does not use it — the old endpoint required it and filtered on
`game_detail_id` alone. It stays that way deliberately, because `wmu/games`
takes the unit *number* in a parameter called `id` while this one calls its
parameter `wmu_id`, and which identifier the app actually sends here cannot be
settled from the data. Filtering on a guess would silently return nothing.

### 6. ManageListings — DONE

All 18 non-payment endpoints. The old controller has 22 actions; promocode,
add-signages, update-shipping-cost and update-payment move money or discounts
and stay with group 7.

Three things carried most of the work:

- **`detail` had no access check at all.** It took a land id, selected
  `land.*`, and answered for any listing owned by anyone, to any caller with a
  valid token. That published `claim_code` — the secret that lets whoever
  presents it take ownership of a listing, set on 6,131 rows — along with the
  payment identifiers and the supersede email addresses. Every endpoint here
  now goes through `App\Support\V1\ListingAccess`, and a listing the caller
  does not manage answers 404.
- **A manager could appoint and remove managers.** Access throughout the old
  controller was `owner OR any land_managers row`, with no check on the
  invitation's status, so somebody invited and never accepted could add
  further managers to a property that was not theirs and remove the ones who
  were. add-manager and delete-manager are owner-only here; the rest stay
  owner-or-manager, but only an accepted manager counts.
- **Every write answered with the whole row**, so each of them leaked what
  `detail` leaked. `App\Support\V1\ManagedListingRow` is the allowlist and
  every response here is built from it.

### The photo upload

This is the most serious thing found so far, and it is worth stating on its
own. The old endpoint did:

```php
$image = UploadedFile::getInstanceByName('image');
$imageName = Yii::$app->security->generateRandomString() . '.' . $image->getExtension();
$image->saveAs($uploadPath . '/' . $imageName);
```

`getExtension()` reads the *client's* filename. `$uploadPath` is under the web
document root. Nothing looked at the file's contents first, and the thumbnail
step that would have failed on a non-image runs afterwards, so its failure
does not remove what was already written.

Verified against the running old API on 2026-11-04: a text file posted as
`probe.txt` was written to `web/uploads/photos/19133/<random>.txt` and served
at that URL with HTTP 200. The probe file and its row were removed afterwards.
The `.htaccess` in `web/` has no restriction on `uploads/`, and the rewrite
rules serve any file that exists directly. On a host where PHP is handled
across the document root — the normal configuration, and this one — that is
**remote code execution for any caller who owns or manages a listing**, and
in the old API a never-accepted manager invitation was enough.

There was also no null check on the upload, so a request with no `image` part
reached `$image->getExtension()` on null.

In v1, `App\Services\ListingPhotos` requires a real JPEG, PNG, GIF or WebP
within a size limit, takes the extension from what the bytes are rather than
from the name, and writes nothing until all of that holds.

**Decided on 2026-11-04: left as it is.** Blocking it would have needed only
a `<FilesMatch>` deny for `uploads/` in `web/.htaccess` — server
configuration rather than a code change — and that was put to Suresh with the
severity stated. The answer was to leave it. So the exposure stands for the
whole overlap window and ends when the old API is retired, which makes the
sunset date the thing that bounds it. Fixed in v1 either way. Don't raise it
again unless that date slips materially.

### Smaller things in this group

- **Stored XSS through the feature list.** `features_list` and
  `allowed_activities_text` hold rendered HTML, built by interpolating the
  owner's own free text — the "other" feature box and the extra-rules box —
  with no escaping. `views/admin/listings/view.php` does
  `echo $model->features_list`, and two mail templates do the same, so that
  text ran in an administrator's browser and in the property notification
  emails. v1 escapes it on write, which also fixes those pages, since what
  they render is what this writes.
- **`availability` divided by `people_allowed`** without checking it, and a
  listing created through `add` starts at 0. A limit of zero now reports
  every requested day as full.
- **`publish` answered the same error for two different refusals** — not paid
  for, and already published — so an owner could not tell them apart.
- **`delete-photos` refused when the file was missing from disk**, so a row
  left behind by a failed upload could not be cleared through the API at all.
  It also left a listing with no title photo when the title photo was the one
  removed.
- **`update-location` split the coordinate string and assigned the halves**
  without checking them, so a malformed value wrote nonsense into `land_lat`
  and `land_lng`.
- **The supersede addresses were never validated**, though every request for
  the listing is copied to them.
- **`add-manager` sends mail to any address the caller names** and had no
  rate limit; it is throttled per IP here.

### Two things this group leaves open

**Where photos live.** The app is given bare file names and builds its own
URLs, so a photo uploaded through this API has to land where the application
serving those URLs can find it. Today that is the old Yii app's document root,
which this API writes into directly because both run on the same host. Before
the new API moves to its own subdomain this needs settling: a shared volume,
an object store with a new base URL the app is told about, or serving photos
from this application. The path is `clas.uploads.photos_path`, so moving it is
one environment change.

**Manager invitations to people with no account.** The invitation is answered
in the web application, which requires the invited person to be signed in as
the account the invitation names. That account is resolved when the
invitation is sent, so an invitation to an address with no account yet can
never be answered, even after they sign up. 4 of the 7 pending invitations are
in that state. Closing it needs an acceptance endpoint in this API that
resolves the account at acceptance time instead — about 4h, and it needs the
app to handle the link.

### 7. Payments — Stripe, in two phases

Recommended: **Stripe**, with the card tokenised on the device so it never
reaches a CLAS server. `docs/payments-decision.md` has the measurements and
the argument. The short version: every subscription in the database has
expired and nothing has been charged since 2024-09-24, so there are no cards
to migrate and the usual reason to stay with a gateway does not apply; and
the old endpoint takes the raw card number, which puts the application in PCI
SAQ D scope where client-side tokenisation would put it in SAQ A.

**The card file migrates; nobody re-enters a card.** Stripe runs a documented
PCI-gated migration in which Moneris sends the cards directly to Stripe and
Stripe returns a file mapping each Moneris identifier to its replacement.
`docs/moneris-to-stripe-migration.md` is the plan: the process, the mapping,
the schema, how to avoid two schedulers charging the same subscription, and
what to ask each company. It adds 27 hours, or 36 if the old renewal cron may
not be touched.

Before any of it: run `docs/sql/production-subscription-audit.sql` on
production. It is read only. The local copy's subscription tables are stale,
and an earlier version of these notes drew the wrong conclusion from them.

It runs in two phases, decided 2026-11-05: Stripe takes new customers first,
Moneris keeps the existing book until it is moved.

**Phase 1 — new customers on Stripe — DONE.** Running on the
marketplace *test* keys, copied once into `.env`. A live key is refused by
`StripeGateway::isConfigured()` unless `STRIPE_ALLOWED_KEY_PREFIXES` names it,
so the endpoints report payments unavailable rather than charging real cards
if the wrong key is pasted in. Verified end to end against Stripe test mode:
a card attached, charged $9.99 CAD, and the same idempotency key returned the
original charge instead of a second one.

```
Stripe PHP SDK, config, keys and test-mode wiring — DONE
Payment service interface with one implementation, Stripe — DONE
The gateway columns on the five subscription tables, plus rollback — 2h
user/subscribe and user/apply-promocode on Stripe — DONE
The WMU, dark mode and fishing lands subscription endpoints — DONE
Renewal scheduler in v1 for gateway = 'stripe' rows — DONE
Webhooks for the asynchronous outcomes — DONE
Tests against Stripe test keys, including the coexistence assertion — 6h
```

Phase 1 carries one trap worth reading before it starts: a Stripe-created row
in `user_subscription` is still a row the old renewal cron selects, and its
fallback charges any Moneris card the user has left on file — or emails the
customer a payment decline. **Decided 2026-11-05:** those rows are written
with `auto_renew = 0`, which the cron already filters on and nothing else in
the old application reads, so the old code stays sealed. That makes one
standing invariant — every row this API creates carries `auto_renew = 0`, and
the cron must keep filtering `auto_renew = 1`. The migration document has
the reasoning and the failure mode.

Two defects in the promo code logic were fixed on the way, both of which the
live data shows were exercised:

- **`promo_codes.type` was ignored.** Any code in that table gave 100% off,
  so all 300 `annual50` codes were granting a free year rather than half off.
  The type is now honoured, from `config/clas.php:promocodes`. This is a
  behaviour change for the 298 unused `annual50` codes already in
  circulation — they become 50% off, which is what the column says they are.
- **`used` was never read.** It was incremented on redemption and never
  checked, so every code was unlimited. One code of type `onetime` has been
  redeemed **28 times**, another 7, two more twice. Single-use types are now
  refused once spent.

Also worth recording, because it corrects an earlier note here: the WMU and
fishing-lands tables hold hundreds of rows and almost no approved payments
because **both subscribe paths have always bundled a free year of each**
alongside the main subscription, at 0.00 with `txn_number` 'FREE ADD-ON'.
Those products did not fail to sell; they are add-ons.

The renewal scheduler is `clas:renew-subscriptions`, daily, scoped to
`gateway = 'stripe'`. Two flaws in the old cron were fixed rather than
carried over:

- **A missed run lost the renewal.** `canProcessPaymentToday()` returned true
  on exactly two dates — the expiry, and seven days later — and false on
  every other day. A cron that did not run on one of those two dates never
  charged that subscription at all, and the expiry sweep then expired it a
  week later with nothing distinguishing "declined" from "never attempted".
  An attempt is now made on any day inside the grace window where one has
  not already been made, up to the same cap of two.
- **Attempts are counted from the transaction rows**, not the calendar, so
  running the command twice in a day cannot produce a third attempt.

**Renewal reminders — DONE, 2026-11-10.** `clas:send-renewal-reminders`,
daily at 08:15, seven days ahead. Added after customers wrote in saying no
email warns them a renewal is coming: the Yii application has that reminder,
in `sendExpiryReminder()`, and it has been commented out since **14 May 2024**
(commit `7370d58e`, "remove sending expiry reminder emails"). For two years
the first a subscriber heard of a renewal was the receipt for a charge
already taken.

**This one covers the whole book, not only this API's rows** — the single
place that deliberately crosses the gateway line. Charging a Moneris row from
here would double-bill somebody, which is what `auto_renew = 0` prevents;
*reminding* one moves no money and writes nothing to the shared database, and
nearly every live subscription is still on Moneris, so scoping it to Stripe
would have fixed the complaint for almost nobody. **It is safe only while the
Yii reminder stays switched off** — turning that back on would have both
applications mailing the same people. `clas.subscriptions.reminder.gateways`
carries the note, and dropping `moneris` from it is the switch if that ever
changes.

Only subscriptions that really will be charged are reminded: a Stripe row
with no payment method, a Moneris row with `auto_renew = 0`, and a type 1 or
2 row the old cron does not select are all going to expire rather than renew,
and "renews on the 17th" would be false for every one of them. Nothing warns
those subscribers that access is about to lapse — that is a different email
saying the opposite thing, and it is not built.

Both of the old implementation's failure modes are avoided, and each is
covered by a test that fails without the guard:

- **A missed run lost the reminder.** It matched `expiration_date` against a
  single date, so a day the cron did not run was a cohort that heard nothing.
  The window here is a range, and a missed run costs a day.
- **Running twice mailed twice.** Nothing recorded what had been sent. A row
  in `clas_api.subscription_reminders` is now claimed before the mail goes
  out, under a unique key on the subscription *and* the renewal date — per
  renewal, not per subscriber, or the second year's reminder would never
  arrive. A failed send releases its claim so the next day retries.

Also worth recording: **there is no way to cancel a subscription or turn off
auto-renewal in either application.** `auto_renew = 0` is only ever set on
the free-promocode path. So a subscriber's only way to stop renewing is to
let the card fail. If a cancel path is ever built it needs its own column,
because `auto_renew` now means "not renewed by the old cron" for Stripe rows.

That gap is now visible to customers rather than merely true: the reminder
tells people a charge is coming and has to tell them what to do if they don't
want it, and the only honest answer is to email support. A self-serve cancel
is worth its own task — it needs a column of its own, an endpoint, and a line
in the app.

One bug the renewal tests caught before it could ship: the payment intent had
`automatic_payment_methods` with redirects disabled, which Stripe refuses to
combine with `error_on_requires_action`. On-session purchases worked
throughout; only unattended renewals failed — which is a year after a
subscription is sold, when nobody would connect it to this change. Intents
now name `payment_method_types: ['card']` explicitly, and an on-session
purchase records `setup_future_usage: 'off_session'` so the renewal is not
the first off-session use of that card.

The webhook endpoint at `POST /v1/stripe/webhook` closes a gap the old
integration had completely: it read the synchronous response to its own
request and nothing else, so **a refund issued from the processor's dashboard
left the subscription marked Active and paid** and nobody found out. Refunds
and disputes are the events that matter here, because both happen outside the
application.

Three properties, each with a test proved to fail without it:

- **It fails closed.** With no `STRIPE_WEBHOOK_SECRET` configured, nothing is
  processed — 503, which Stripe retries, so events arriving before the secret
  is set are redelivered rather than lost. The signature is the only
  authentication an unauthenticated endpoint has, and "we have not set the
  secret yet" is exactly when it would otherwise be an open door.
- **It is idempotent.** Every event id is recorded in `stripe_events` and a
  redelivery is acknowledged without being acted on twice.
- **It verifies the raw body.** Re-encoding parsed JSON changes the bytes —
  PHP escapes the slashes in values like `/v1/charges`, Stripe does not — so
  the signature would never verify.

A full refund revokes the subscription and its add-ons. A partial refund is
recorded and left alone: it could be goodwill, a correction or a proration,
and guessing would be worse than not guessing. A dispute is logged as
critical and revokes nothing — it can be won, the response deadline is short,
and the record of use is often what wins it.

`stripe_events` lives on the `clas_api` connection, not the shared CLAS
schema: it is entirely this application's bookkeeping, and the connection
split added for the migrations is what makes that possible without putting
another table into a database two applications read.

The three add-on products — WMU, dark mode, fishing lands — were three
controllers of 355, 346 and 346 lines, differing in a table name, a price key
and the wording of one error message. Everything else was copied between
them, which is how a fix in one stayed broken in the other two. They are one
code path over `config/clas.php:subscriptions.products` now, with one test
body across all three.

Two behaviours deliberately not carried over:

- **The WMU endpoint rewrote the caller's profile.** It took a phone number,
  split it on dashes, wrote it to the profile, and **refused the entire
  purchase when it did not split into three parts** — so the subscription
  could not be bought unless the stored phone happened to be formatted
  `xxx-xxx-xxxx`. Updating a profile is `user/update-profile`'s job.
- **`wmu_subscription.wmu_province` is still not set**, as the old endpoint
  also never set it: 817 rows are NULL, 2 empty, and the 35 with a province
  came from the web application. The price list has per-province keys the API
  never read, always charging the annual rate. Rather than invent a meaning,
  it stays as it was — worth a decision if per-province pricing is ever
  wanted.

**Phase 1 is complete.** 93 endpoints, 217 tests. New customers are charged
through Stripe end to end: buy, renew, refund, dispute. The existing book is
untouched and still on Moneris, which is phase 2.

**Phase 2 — provisionally deferred, 2026-11-06.** The existing book stays on
Moneris for now and existing renewals keep running there. Suresh is
confirming this with Mo, so treat it as the working assumption rather than a
settled one. The card export, the mapping importer and the ramp come
off the active plan — 30 hours — and
`docs/moneris-to-stripe-migration.md` holds the plan for when it happens,
along with what the deferral costs.

The short version of that cost: **retiring the API is not retiring the
application.** While anyone is billed through Moneris, the Yii renewal cron
has to keep running — it is a console command, so that part is fine — but so
does the old **web** card-update page, because
`controllers/user/SettingsController.php` is the only place a Moneris
customer can change their card, and it takes a raw card number. That keeps
the `$_POST` log-dump exposure live *and in use*, which is the one accepted
risk that gets worse rather than merely longer. The other two — the
profile-write hole and the photo upload — are API endpoints and still end
when the API is retired.

**Migration on card change — built.** `POST /v1/user/payment-method` moves a
subscriber to Stripe when they change their card in the app, which needs no
export from Moneris and no mapping file. It is a drain rather than a cutover:
it reduces the Moneris book over time instead of ending it on a date, and
anyone who never changes their card stays there. It is also what eventually
removes the reason the old web card page has to stay in service.

The move is silent by decision: nothing changes from the customer's side, and
the Stripe account's statement descriptor is already CANADIAN LAND ACCESS, so
a migrated charge is recognisable on a bank statement. That was the one thing
that could have made silence a bad idea, since an unrecognised charge is how
a migration turns into chargebacks.

**The signage payment endpoints — DONE, 2026-11-10.** add-signages,
promocode, update-shipping-cost and update-payment, on Stripe. The
arithmetic the four of them share is in `App\Services\SignageOrder` rather
than repeated in each, because four endpoints disagreeing about the order
total is how a listing gets charged for something it did not buy.

Five defects, all of them exercised by the live app:

- **The caller set part of the amount they were charged.**
  `update-shipping-cost` took a string of the form
  `Fedex@@@Fedex Ground@@@24.50`, split it on `@@@` and used the third part
  as the shipping charge. That figure went into `land.total_amount`, which is
  exactly what `update-payment` passed to Moneris. Posting `@@@0.00` shipped
  for nothing; a negative third part reduced the whole order. It also read
  index `[2]` without checking the string had three parts, so anything
  without separators was a PHP notice and a silent zero. The caller now names
  a *method* and `App\Services\SignageShipping` prices it.
- **Quantities were truthy, not validated.** `if ($option && $qty && ...)`
  passes a negative quantity, which was then added to the stored one —
  reducing the order and taking `signage_amount` with it.
- **An order could be paid twice.** Nothing looked at `payment_status`;
  calling `update-payment` again charged the card a second time and
  overwrote the first receipt, so there was not even a record of it.
- **Promo codes ignored their own type**, the same defect as the
  subscription path: any `onetime` row gave 100% off whatever the column
  said.
- **And were never marked used.** The signage path stored the code against
  the listing and never incremented `used`, so a single-use code worked on
  every listing for ever. `FREELISTING` is kept as a signage-only code,
  because that is what it was — it never applied to subscriptions.

Two things this port does **not** carry over, both flagged rather than
quietly dropped:

- **Live FedEx rates.** The old application quoted them through FedEx's Rate
  Service v28 SOAP API — their retired Web Services interface, with
  credentials among the secrets waiting to be rotated.
  `clas.signage.shipping.rates` is empty by default and **nothing invents a
  price**: a deployment with no rate card offers collection only. Supply the
  real card, or put a live quote behind the same service, before signs can
  be posted. **1h** to enter a rate card; more to reinstate a live quote.
- **The order PDF.** `_NotifyAdmin` rendered the listing to a PDF with a
  Code 39 barcode and mailed it to the admin address. The admin notice is
  ported and carries the listing, the lines, the address and what was paid —
  what fulfilment reads off it — but not the attachment. Worth checking
  whether anyone downstream depends on it. **2h** if it has to come back.

**Cancelling a subscription — DONE, 2026-11-10.** `user/cancel-subscription`,
`user/resume-subscription` and `user/subscription`. Neither application had
this: no endpoint, no screen, no setting, so a subscriber's only way to stop
renewing was to let their card fail. The renewal reminder forced it — a mail
saying "we will charge your card on the 17th" has to be able to answer "then
don't".

Cancelling does not revoke access. The subscription runs to the date already
paid for and the ordinary expiry sweep ends it, so this is a change to what
happens next, not a refund.

It needed a column of its own: `auto_renew` already means "not renewed by the
old cron" for a Stripe row, so a cancelled Stripe subscription would be
indistinguishable from an ordinary one. `cancelled_at` is the intent for both
gateways, and what actually stops the money differs — a Moneris row also gets
`auto_renew = 0`, because that is the only signal the Yii cron reads.

**Resuming is deliberately not the mirror image**, and this is the sharp
edge: `auto_renew` is restored only for a Moneris row. Restoring it on a
Stripe row would hand that row to an application that still holds the
customer's Moneris card, and a year later they would be charged twice, once
by each processor, with nothing in either application connecting the two. A
test fails if that guard is removed.

Deferred, not cancelled:

```
(deferred) Run the production subscription audit and size the live book — 1h
(deferred) Request the card export from Moneris — 2h
(deferred) Migrate the card file: mapping importer, gateway switch, ramp — 27h
```

### Cross-cutting, to schedule alongside

One of these is time-sensitive in a way the hours do not show. **Which Stripe
account CLAS subscriptions go live on is undecided**, and the card-change
migration accumulates customers in whichever account is used — so the cost of
changing that choice grows with every subscriber who moves. Nothing is
blocked today: test mode works, and the live-key decision is separate. What
matters is that the production cutover does not quietly inherit the account
whose test keys happen to be in `.env`.

For the record, that account is `acct_1J08AcLcHWnrcjPs`, descriptor
`CANADIAN LAND ACCESS`, Canadian, CAD — a CLAS account rather than a
marketplace-branded one, despite being reached through what were described as
the marketplace keys. It is also the account a bulk card export would have to
target if the drain is ever replaced by a dated cutover.

```
Rotate the committed secrets. A sweep of the tracked config files on
2026-11-06 found **17 distinct secret names across 61 occurrences**, not the
four previously recorded here. The Stripe live secret key and the AWS key
pair are the two that matter most: a live `sk_live_` key is full API access to
the Stripe account, and the AWS pair is identical across dev, staging and
production. Also: six Slack webhook URLs, the Moneris store id and API token
for both the main and marketplace accounts, FedEx key and password, three
database passwords, the mailer password, the reCAPTCHA pair, and a Yii
`cookieValidationKey` of only 7 characters. All of it is in git history, so
rotation is the only remedy — removing it from the working tree achieves
nothing — 8h
Clear the card data out of the production logs and stop $_POST being logged —
see docs/payments-decision.md — 2h
Produce the field-by-field change report for the app developer — 2h
Set up the staging subdomain, TLS and DNS for the new API — 4h
Deploy pipeline from the repository to the staging server — 4h
Make sure the deployment host's timezone database is current — the live Yii
app runs PHP 5.6 with tzdata 2016.10 and reports sunrise an hour early across
five Canadian provinces — 1h
Load-check the geofence query and add the bounding-box prefilter — 6h
Decide and document the old API sunset date and the forced-update trigger — 2h
Supply an FCM service account so push can be delivered at all — 3h
Decide which Stripe account CLAS subscriptions go live on — undecided as of
2026-11-06 — 1h
Decide what to do about profile positions — see docs/profile-positions.md — 4h
Confirm the signage shipping rate card against one real FedEx quote. A
four-zone card is now in config/clas.php, derived from the four 2023
shipments in the database and uplifted for three years of rate increases —
derived, not quoted. It over-charges cities and under-charges rural
addresses, by design. It also assumes every sign is a small sign — the old
quote sent 2 lb / 10x10x3 for every line including the $399.99 Large Sign,
so both the historical figures and these rates understate that one, which
was accepted on 2026-11-10 because large signs are rare — 1h
Decide whether the signage order PDF has to come back; the admin notice is
ported, the barcode attachment is not — 2h
Decide where listing photos live once the API moves to its own subdomain — 4h
Add a manager-acceptance endpoint so invitations to new accounts work — 4h
```

## Totals

| Group | Hours |
|---|---|
| 1. Mail infrastructure | 0 |
| 2. Finish Requests | 0 |
| 3. User, settings, security | 0 |
| 4. Notifications | 0 |
| 5. Self-contained features | 0 |
| 6. ManageListings | 0 |
| 7. Payments | 0 |
| Cross-cutting | 48 |
| **Total** | **48** |

**Every endpoint is built.** Payments is 0: phase 1 is complete, the signage
endpoints are ported and phase 2 is deferred. The deferred migration work is
30 hours and stays listed in group 7 so it is not lost — it is excluded from
the total because it is not on the plan.

What remains is entirely cross-cutting, and most of it is not code: secrets
to rotate, a staging host to stand up, a deploy pipeline, and several
decisions nobody has made yet.

## What is already done

**102 endpoints.** Token middleware, all thirteen Listings endpoints, all
sixteen Requests endpoints, all ten Notifications endpoints, the fourteen in
the user, settings and security group, the fifteen in hunters tags, chat and
reference data, all twenty-two ManageListings endpoints including the four
that take payment, and the payment group: subscribe, apply-promocode, the
three product subscriptions, the Stripe webhook, the card-on-file pair that
migrates a subscriber off Moneris when they change their card, and the three
that cancel, resume and report a subscription.

Interactive documentation at `/docs` covers every one of them, with 291 tests
and seven enforced rules that hold for endpoints nobody has written yet (see
`CLAUDE.md`):

- no controller may `select('*')`
- every route carries `auth.token` unless named in an explicit public list
- nothing may stay in that public list once it is guarded
- every open endpoint that sends mail is rate limited
- no controller may read an account id from the request
- only `App\Support\V1\Position` may store a caller's position
- every route appears in the OpenAPI document

The API is served from `/v1/`, on its own host, with no `/api` segment.
