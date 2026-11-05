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

**Phase 1 — new customers on Stripe (46h).** Needs a Stripe account and its
test keys; nothing here needs live keys.

```
Stripe PHP SDK, config, keys and test-mode wiring — 3h
Payment service interface with one implementation, Stripe — 8h
The gateway columns on the five subscription tables, plus rollback — 2h
user/subscribe on Stripe, taking a payment method id, never a card — 8h
The WMU, dark mode and fishing lands subscription endpoints — 8h
Renewal scheduler in v1 for gateway = 'stripe' rows — 6h
Webhooks for the asynchronous outcomes — 5h
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

**Phase 2 — move the existing book (38h).**

```
Run the production subscription audit and size the live book — 1h
Request the card export from Moneris, early: it is the longest pole — 2h
Migrate the card file: schema, mapping importer, gateway switch, ramp — 27h
Port the signage payment endpoints in ManageListings — 8h
```

### Cross-cutting, to schedule alongside

```
Rotate the four secrets committed in the old repository: the Google timezone
key, the Moneris store id and API token, the FCM server key and the PEASI
token — 2h
Clear the card data out of the production logs and stop $_POST being logged —
see docs/payments-decision.md — 2h
Produce the field-by-field change report for the app developer — 2h
Set up the staging subdomain, TLS and DNS for the new API — 4h
Deploy pipeline from the repository to the staging server — 4h
Load-check the geofence query and add the bounding-box prefilter — 6h
Decide and document the old API sunset date and the forced-update trigger — 2h
Supply an FCM service account so push can be delivered at all — 3h
Decide what to do about profile positions — see docs/profile-positions.md — 4h
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
| 7. Payments | 84 |
| Cross-cutting | 37 |
| **Total** | **121** |

Payments is 60 of that, and is the part that can be deferred rather than
removed — those ten endpoints can keep running on the old API after the rest
has moved, since both read the same database.

Excluding payments and cross-cutting: **0 hours** — every endpoint the new
app needs apart from paying for things is built. What is left is the
payment group and the cross-cutting work.

## What is already done

**87 endpoints.** Token middleware, all thirteen Listings endpoints, all
sixteen Requests endpoints, all ten Notifications endpoints, the fourteen in
the user, settings and security group, the fifteen in hunters tags, chat and
reference data, and the eighteen non-payment ManageListings endpoints.

Interactive documentation at `/docs` covers every one of them, with 161 tests
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
