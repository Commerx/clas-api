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
| 3 | User, UserSettings, Security | 15 | onboarding | excludes user/subscribe |
| 4 | Notifications remainder | 8 | — | needs mail |
| 5 | HuntersTags, Chat, Wmu, FishingLands | 17 | — | self-contained |
| 6 | ManageListings, non-payment | 18 | — | largest controller |
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

### 2. Finish the Requests controller — submit DONE

```
Port requests/upcoming with the nearby-listings geofence query — 4h
Port requests/today-bookings — 4h
Contract tests and OpenAPI entries for the three — 3h
```

### 3. User, settings and security

```
Port user/register and the email confirmation flow — 6h
Port user/login-related remainder: forgot-password, logout — 4h
Port security/version-check, replacing the broken string version comparison — 3h
Port user profile read and update endpoints — 6h
Port user/delete-account and its data handling — 4h
Port the remaining User controller endpoints — 8h
Port UserSettings endpoints — 3h
Contract tests and OpenAPI entries for the user and security group — 4h
```

### 4. Notifications

```
Port notifications/submit and the subscriber fan-out — 6h
Port notifications/hunting-alert — 5h
Port notifications/community-awareness — 4h
Port notifications/read, get-notification, delete-notification — 5h
Port notifications/report-notification and subscriber-notification — 5h
Contract tests and OpenAPI entries for notifications — 3h
```

### 5. Self-contained features

```
Port the HuntersTags controller, 7 endpoints — 10h
Port the Chat controller, 5 endpoints — 7h
Port the Wmu controller, 3 endpoints, with the province allowlist — 4h
Port the FishingLands controller, 2 endpoints — 3h
Contract tests and OpenAPI entries for these four — 4h
```

### 6. ManageListings

```
Port manage-listings read endpoints: all, detail, listing data — 8h
Port listing create and update, including the multi-step draft flow — 14h
Port listing publish, unpublish and status changes — 6h
Port the photo and KML upload endpoints — 8h
Port manage-listings availability and blocked-day management — 8h
Port the land manager invite and acceptance endpoints — 6h
Port the remaining ManageListings endpoints — 10h
Contract tests and OpenAPI entries for ManageListings — 6h
```

### 7. Payments — after the Stripe decision

```
Decide Stripe versus Moneris for the rebuilt API, and record it — 2h
Build the payment abstraction and Stripe integration — 16h
Port user/subscribe onto the new payment path — 10h
Port the WMU, dark mode and fishing lands subscription endpoints — 12h
Port the signage payment endpoints in ManageListings — 8h
Reconcile subscription state between the old and new applications — 6h
Payment tests against Stripe test keys — 6h
```

### Cross-cutting, to schedule alongside

```
Rotate the Google timezone API key that was committed in the old repository — 1h
Produce the field-by-field change report for the app developer — 2h
Set up the staging subdomain, TLS and DNS for the new API — 4h
Deploy pipeline from the repository to the staging server — 4h
Load-check the geofence query and add the bounding-box prefilter — 6h
Decide and document the old API sunset date and the forced-update trigger — 2h
```

## Totals

| Group | Hours |
|---|---|
| 1. Mail infrastructure | 12 |
| 2. Finish Requests | 11 |
| 3. User, settings, security | 38 |
| 4. Notifications | 28 |
| 5. Self-contained features | 28 |
| 6. ManageListings | 66 |
| 7. Payments | 60 |
| Cross-cutting | 19 |
| **Total** | **262** |

Payments is 60 of that, and is the part that can be deferred rather than
removed — those ten endpoints can keep running on the old API after the rest
has moved, since both read the same database.

Excluding payments and cross-cutting: **193 hours** to finish every endpoint
the new app needs apart from paying for things.

## What is already done

Login, token middleware, two notifications endpoints, all thirteen Listings
endpoints, and thirteen of sixteen Requests endpoints — 29 in total, with
interactive documentation at `/docs`, 63 tests, and three enforced rules that
hold for endpoints nobody has written yet (see `CLAUDE.md`).
