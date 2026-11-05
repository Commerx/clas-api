# Moving the card file from Moneris to Stripe

**Nobody has to re-enter a card.** Stripe runs a documented, PCI-gated
migration in which Moneris sends the card data straight to Stripe and Stripe
hands back a file mapping every Moneris identifier to the Stripe one that
replaced it. The subscriptions keep running; what changes is which gateway
charges them.

This document is the plan for moving the existing book, which is phase 2.
Phase 1 — Stripe for new customers while Moneris keeps the existing ones — is
the section immediately below, and it has a trap in it that phase 1 has to
handle rather than phase 2.

It assumes the live book is substantial, which is the correction to my earlier
note: the figures in the first version of
`docs/payments-decision.md` came from the local copy, whose subscription
tables are stale, and they were wrong in the direction that mattered most.
Nothing here depends on them. `docs/sql/production-subscription-audit.sql`
gets the real numbers — read only, seven sections, verified to run clean — and
three decisions below need its output before they can be made.

## Phase 1: Stripe for new customers, Moneris for the existing book

Decided 2026-11-05. This is also Stripe's own step 1, and it is the right
order: it ships without waiting on the Moneris export negotiation, and it
warms the new merchant id on customer-initiated payments, which is the
traffic Stripe says to send first.

But it is not simply "create new subscriptions on Stripe". Phase 1 needs the
coexistence guard that I had put in phase 2, because of what the old renewal
cron does to a subscription with no Moneris card.

### The trap, and it is not hypothetical

A Stripe-created row in `user_subscription` is still a row in
`user_subscription`. The old cron selects type 3, 4 or 5, Active,
`auto_renew = 1`, `expiration_date <= CURDATE()`, account not blocked — which
a new Stripe subscription matches. Then, in `processAnnualSubscriptions`:

1. `data_key` is empty, so the first Moneris charge is skipped.
2. **A fallback looks for any card the user has in `user_moneris_cards`** and,
   if it finds one, charges *that* through Moneris and writes its `data_key`
   onto the subscription row.
3. If there is no card, it sets `'Card Information not present'`, **sends the
   customer a payment-declined email**, and stamps `last_processed`.

So without a guard, every new Stripe subscriber either gets charged a second
time through Moneris and silently reattached to an old card, or receives a
"your payment was declined" email from the old application on their renewal
date. The second is worse, because it is invisible to us and visible to them.
There are 22 transactions carrying that exact message in the local copy's
2024 history, so the branch is live code, not a dead path.

### The guard: `auto_renew = 0` on Stripe-created rows

**Decided 2026-11-05.** The alternative was one extra `andWhere` in the old
cron, which would have been cleaner semantically; the old application stays
sealed instead.

The cron filters `auto_renew = 1`, and `auto_renew` is **never read anywhere
else** in the old application — not in a view, not in an entitlement check,
not in the API. Grepping it returns writes only, plus that one filter. There
is already a precedent for using it this way: the old API sets
`auto_renew = 0` when a promocode brings a subscription to zero, precisely so
the cron leaves it alone.

So a Stripe subscription is written with `auto_renew = 0`:

- the old cron ignores it completely — no Moneris charge, no declined email,
  no reattachment to a stale card;
- `status` and `expiration_date` are untouched, so every entitlement check in
  the old application and in app builds already in the field still sees an
  active subscription — `User::getSubscription()` reads those two columns and
  nothing else, across roughly 11 call sites;
- v1's own scheduler renews it, reading `gateway = 'stripe'`;
- nothing in the frozen code changes.

Two costs, both accepted knowingly:

**The flag's meaning drifts.** It now means "not renewed by the old cron"
rather than "not auto-renewing". Anyone building a UI on `auto_renew` in the
old application would read it wrongly. The `gateway` column carries the truth
for anything new.

**The guard depends on a property of code we do not own.** It holds only
while the old cron's query keeps `->andWhere(['us.auto_renew' => 1])` in
`processAnnualSubscriptions`. That is the whole of the dependency, and it is
worth stating as an invariant:

> Every subscription row this API creates carries `auto_renew = 0` and
> `gateway = 'stripe'`. The old Yii renewal cron must keep filtering
> `auto_renew = 1`. If that filter is ever removed or changed, this guard is
> void and every Stripe subscriber becomes exposed to the Moneris fallback
> path described above.

Deliberately not enforced by a test that reads the Yii source: this
application does not depend on the old one, and a test pointing at a path in
that repository would break when it is retired. So it is a documented
invariant to re-check if anyone touches the cron, plus a behavioural test on
our own side — phase 1 asserts that every row v1 creates has `auto_renew = 0`,
which is a property of our code and testable without the old application.

**What makes it safe to rely on** is that it fails in the harmless direction.
If the guard works, the cron does less. The failure mode is not a wrong
charge; it is the cron reverting to what it does today, which is the behaviour
these rows would have had anyway without the move. That is why this is a
reasonable trade and the separate-table option was not.

### What phase 1 includes

```
Stripe PHP SDK, config, keys and test-mode wiring — 3h
Payment service interface with one implementation, Stripe — 8h
The gateway columns migration, pinned to the shared connection — 2h
user/subscribe on Stripe, taking a payment method id, never a card — 8h
The three product subscription endpoints on the same path — 8h
Renewal scheduler in v1 for gateway = 'stripe' rows — 6h
Webhooks for the asynchronous outcomes — 5h
Tests against Stripe test keys, plus the auto_renew = 0 invariant — 6h
```

**46 hours.** The signage payment endpoints in ManageListings (8h) and the
card-file migration (27h) stay in phase 2.

### What phase 1 does not achieve

Worth being explicit, so nobody expects more of it than it gives:

- **It does not end the PCI exposure.** App builds already in the field keep
  calling the old API's `user/subscribe`, which takes `cc_number`,
  `cc_expiry` and `cc_cvv` as POST parameters. That path closes when the
  forced app update lands and the old API is retired, not when phase 1 ships.
  The log-dump problem in `docs/payments-decision.md` stays live until then
  too.
- **It does not reduce the existing book's risk.** Those subscriptions keep
  renewing through Moneris exactly as they do now.
- **It creates two creation paths** for the duration: the new app build
  creates Stripe subscriptions through v1, old builds create Moneris
  subscriptions through v7. Both write `user_subscription`, and only the
  `gateway` column distinguishes them. Every report or query about
  subscriptions needs to know that for the length of the overlap.

### What it needs from you

1. **A Stripe account, and its test keys.** Everything in phase 1 can be
   built and tested in test mode; nothing needs live keys until you are ready
   to take real money. If you want the work to start before the commercial
   side is settled, test keys are enough.
2. **Which products go on Stripe first.** All four — property access, WMU,
   dark mode, fishing lands — or just property access, with the rest
   following. Property access is 1,166 of the local copy's rows at type 5, so
   it is where the volume is.
3. **Whether amounts stay in CLAS.** I would keep them in
   `config/clas.php:prices` as they are now and charge with PaymentIntents,
   rather than mirroring them into Stripe products and prices. One source of
   truth for what things cost, no reconciliation between two systems, and it
   avoids Stripe Billing's 0.7%. The subscription tables stay the record both
   applications read.

## What Stripe's process actually is

From Stripe's own documentation, not a summary of it:

| Step | Who | What |
|---|---|---|
| 1 | You | Build the Stripe integration and start taking **new** customers on it. Existing ones keep going through Moneris. |
| 2 | You | Ask Moneris for a data export, and submit Stripe's migrations intake form. |
| 3 | Stripe + Moneris | Stripe stands up a dedicated SFTP server per migration and gives Moneris the credentials, encrypted with Moneris's PGP key. Moneris encrypts the export with **Stripe's** published PGP key (`9C78B7620C1E99AD`, RSA 4096) and uploads it. |
| 4 | Stripe + you | Stripe validates the file and sends a summary. You approve it — expect to be asked to confirm the last four digits for two customer ids. Stripe then creates the `Customer` and payment method objects. |
| 5 | You | Parse the mapping file, update the database, move charging over, watch the decline rate. |

Three details to plan around:

- **The card never passes through us.** Processor to processor, encrypted,
  over SFTP. CLAS is not in that path at any point.
- **CVCs are not part of it**, by PCI rule. They are not needed to charge a
  saved card, and CLAS does not need them for renewals.
- **Timing is Moneris's to set.** Stripe's own side is ~10 working days after
  it has valid data. Stripe says the previous processor can take days to
  months, and recommends asking as early as possible. So the export request
  goes in before the integration work is finished, not after.

Stripe asks for `pm_` PaymentMethod objects rather than legacy `card_` objects
if you specify it in the request. Specify it.

## The mapping is the whole game

Stripe returns a file like this, keyed by **the identifiers Moneris put in the
export**:

```csv
old_id,source_old_id,created_customer,source_new_id,card_fingerprint,card_last4,card_exp_month,card_exp_year,card_brand
old_cus_100,old_src_100,cus_abc123def456,pm_2222222222,x9yW1WE4nLvl6zjg,4242,09,2024,Visa
```

So the migration works if, and only if, the export carries identifiers that
join back to our rows. Ours are:

- `customer_id`, which CLAS sets to `CLAS-USER-<uid>` — the Moneris `cust_id`.
- `data_key`, the Moneris Vault token, one per stored card.

**This is the first thing to confirm with Moneris**: that the export's
customer and card identifiers are the `cust_id` and `data_key` we hold. If it
is keyed on anything else — an internal Moneris row id, say — the join has to
be reconstructed from masked PAN and expiry, which is slower and not
guaranteed unique. Ask before the export is produced, not after.

Where the card data lives on our side, measured on the local copy — the shape
is reliable even though the counts are not:

| Table | Holds |
|---|---|
| `user_subscription.data_key` | the card backing a property-access subscription |
| `wmu_subscription.data_key` | same, per WMU province |
| `darkmode_subscription.data_key` | same |
| `fishing_lands_subscription.data_key` | same |
| `user_moneris_cards` | the card itself: `data_key`, `masked_pan`, `expiry`, `cc_name`, plus a `card_info` JSON blob repeating them |

One card commonly backs several subscriptions, so **the number of cards to
import is the distinct `data_key` count, not the subscription count**. On the
local copy that is 2,155 distinct keys across those five tables. Give Moneris
and Stripe the distinct-card figure; section 2 of the audit produces it.

`user_moneris_cards` is more useful than it looks: it holds `masked_pan` and
`expiry` for every row. Stripe's mapping file returns `card_last4`,
`card_exp_month` and `card_exp_year`. So the mapping can be **verified**
rather than trusted — every mapped card's last four and expiry must agree
with what we already hold, and any that disagrees is quarantined rather than
charged.

### Rows that will not map cleanly

The audit's section 3 counts these. On the local copy:

- 5 rows with no `customer_id` at all.
- 1 row where the uid embedded in `customer_id` disagrees with the row's own
  `user_id`.

Small numbers, but each one is a real subscriber whose card silently fails to
map, so they need listing and handling by hand rather than averaging away.

## What the database needs

Additive and nullable, so the old application is unaffected — it selects these
tables with `SELECT *`, ignores columns it does not know, and never writes
them:

`database/migrations/2026_11_05_000000_add_gateway_columns_to_subscriptions.php`
adds, on each of `user_subscription`, `wmu_subscription`,
`darkmode_subscription` and `fishing_lands_subscription`:

```
stripe_customer_id        VARCHAR(64) NULL
stripe_payment_method_id  VARCHAR(64) NULL
gateway                   ENUM('moneris','stripe') NULL   -- indexed
```

and the first two on `user_moneris_cards`. Run it with
`php artisan migrate --database=clas_api`, which keeps the migration history
in this application's own schema while the columns land in the shared one;
`docs/sql/phase1-gateway-verification.sql` checks the result.

Three things about this shape:

- **`data_key` is never overwritten.** It stays exactly as it is. That is what
  makes rollback possible: if the Stripe path misbehaves, the Moneris token is
  still there and still valid.
- **`gateway` is the switch**, per subscription. NULL or `moneris` means the
  old path owns it; `stripe` means the new one does. That is what makes a
  gradual ramp possible instead of a single flip.
- It is a change to a **shared** schema, so it wants your sign-off even though
  it is additive. It is also the only schema change in the plan.

## The part that can double-charge

Two schedulers will exist: the old Yii cron
(`commands/PaymentsController.php`, charging `user_subscription` types 3, 4
and 5 that are Active, auto-renewing and past expiry) and the new Laravel job.
Both read the same rows. If both run against the same subscription, the
subscriber is charged twice.

Four ways to avoid it, with what each costs:

**A — Flip everything at once.** Import completes, map every row, set
`gateway = 'stripe'`, disable the old cron, new job takes the whole book.
Simplest, one scheduler, nothing to coordinate. The cost is that Stripe's own
guidance says not to: a new Stripe account has a new merchant id, issuers do
not recognise it, and declines rise temporarily. Stripe recommends ramping and
sending customer-initiated payments before recurring ones. The CLAS book is
*entirely* recurring, which is the hardest traffic to start a MID with. Fine
for a small book, bad for a large one.

**B — One line in the old cron.** Add `->andWhere(['or', ['us.gateway' =>
null], ['<>', 'us.gateway', 'stripe']])` to `processAnnualSubscriptions`.
The two schedulers then partition the book cleanly and you can move it across
in cohorts at whatever pace the decline rate justifies. It is one line, in
code the standing decision says not to touch — the same kind of exception as
the `logVars` fix. It is the cheapest correct answer.

**C — Port the Moneris charge path into v1 temporarily.** One scheduler, in
the new application, charging through whichever gateway a row names, with the
Moneris path deleted once the ramp finishes. Keeps the old code frozen and
keeps control in one place. Costs roughly 10 hours and means writing Moneris
code we have already decided to retire.

**D — Don't import at all.** Stripe takes new subscriptions; Moneris keeps
charging the existing book until each one lapses or the subscriber re-enters a
card. No migration, no mapping, no risk. The costs are that two gateways run
indefinitely, the old cron has to stay alive — note it is a CLI command, not
an API endpoint, so it can outlive the old API — and, with annual
subscriptions, "indefinitely" means at least a year. This is also the fallback
if Moneris will not export.

**Recommendation: B if you will allow the one line, otherwise C.** A only if
the audit shows the live auto-renewing book is small — under a couple of
hundred, where a decline bump is a handful of emails rather than a support
queue. Which it is, section 1 and section 4 of the audit will say.

There is a fifth option that needs no code change at all: the old cron filters
`auto_renew = 1`, so setting `auto_renew = 0` on a migrated row makes it skip
that row. I would not do it. The subscription *is* auto-renewing, just
elsewhere, and that column is read by the old web UI and the expiry reminder
mails, so the lie would surface to subscribers. Mentioned because it is the
only no-change option, not because it is a good one.

## Why not pull the cards out ourselves?

The obvious instinct, and worth answering properly rather than waving away.

**It is technically possible.** Moneris Vault has two lookups:
`res_lookup_masked`, which returns the first four and last four digits, and
`res_lookup_full`, which returns the unmasked card number. Both are in the
`mpgClasses.php` bundled in the old repository — the application currently
calls only the masked one, in the renewal cron and in user settings. So the
capability is sitting there.

Three reasons not to use it.

**1. It creates the exposure the move is meant to end.** Every PAN retrieved
that way lands in PHP variables on a CLAS server. A few thousand of them, in a
loop, on a schedule. That is cardholder data flowing through the application
again — which is the definition of PCI DSS SAQ D scope, and the reason to
leave the old card flow behind in the first place. This is not a theoretical
risk here: the production log already holds one full PAN and CVV, written
there by Yii's error handler dumping `$_POST` when an unrelated SMTP call
threw (see `docs/payments-decision.md`). Any exception inside a bulk PAN loop
does the same thing, except now the loop variable holds somebody's card
number on every iteration.

**2. Stripe will not take the data from us.** Their migration documentation is
explicit: "Never send sensitive credit card details or customer information
directly to Stripe. If you have this data, let us know in your migration
request form so we can help you securely transfer your data." So even having
extracted the cards, the route back in is the same migrations team, the same
PGP key and the same SFTP server — with the extra step of us having handled
the cards in between. The processor-to-processor transfer is not a hoop
Stripe makes you jump through; it is the thing that keeps the data out of our
hands.

**3. It is also Moneris's decision.** Whether `res_lookup_full` is enabled for
this merchant, and under what attestation, is a question for them and for the
acquirer, not something to discover by calling it. It goes on the question
list.

### "...and store it in Stripe at the next renewal"

This is the part that does not work as stated, for a specific reason: **at
renewal time the server holds a token, not a card.** The cron reads
`data_key`, sends it to Moneris, and gets back a response code. The card
number never comes back. So a renewal charge gives us nothing to put in
Stripe — there is no card data in that transaction to copy.

It only becomes possible by combining it with `res_lookup_full` above — look
the PAN up at each renewal, create the Stripe payment method, charge there
instead. That spreads the new-MID decline risk naturally, which is a genuine
advantage. But it keeps PANs moving through our servers for a **whole renewal
cycle**, which on annual subscriptions is a year, so the PCI scope the project
is trying to close stays open for that year — and the handling happens
unattended, in a cron, which is the worst place for it.

### The lazy option that does work

Ask the subscriber to re-enter the card, through Stripe's own SDK, at their
next renewal or next sign-in. The card never touches a CLAS server, there is
no export to negotiate, and no mapping to verify. Stripe's own migration
overview recommends building this flow regardless, because it is what you need
for an expired or declined card anyway.

The cost is subscribers. Every renewal becomes a decision the user has to
make, and an auto-renewing subscriber who does not open the app simply lapses
— silently, having previously been paying. On a book this size that is the
expensive option, and it is the reason the processor-to-processor transfer
exists.

A reasonable hybrid, and probably the right shape: transfer the book, and use
re-collection for the remainder — the rows that fail to map, the cards that
have already expired, and anything Stripe declines after the move.
`res_lookup_masked` and `res_get_expiring` are both available for working out
which cards those are before the migration rather than after.

## Cutover sequence

1. **Audit.** Run `docs/sql/production-subscription-audit.sql` on production
   and keep the output. It fixes the book size, the distinct-card count, the
   rows that will not map, and the quietest renewal week.
2. **Ask Moneris.** Export request, with the questions below. Do this first;
   it is the longest pole.
3. **Build.** The Stripe integration, taking new subscriptions only. Nothing
   touches the existing book. This is the bulk of the 58 hours in the build
   order.
4. **Schema.** Add the three columns.
5. **Submit the Stripe intake form**, specifying `pm_` objects and giving the
   distinct-card count.
6. **Freeze card changes.** Stripe is explicit that card updates made at the
   old processor during the transfer window are lost. So between Moneris
   producing the export and Stripe finishing the import, no card may be added
   or changed through the old application. The old API's `user/subscribe` is
   the path that does it; blocking it for the window is a deployment
   decision, not a code change. Any subscriber who needs a new card in that
   window goes onto Stripe directly and is marked `gateway = 'stripe'` from
   the start.
7. **Import, then verify before charging anything.** Load the mapping file,
   write `stripe_customer_id` and `stripe_payment_method_id`, and check every
   row: last four and expiry must match `user_moneris_cards`. Mismatches and
   unmapped rows get a report, not a charge.
8. **Ramp.** Move cohorts to `gateway = 'stripe'`, smallest first, watching
   the decline rate against the Moneris baseline the audit's section 5
   establishes. Stripe's Card Account Updater and network tokens are worth
   turning on before this starts; they exist for exactly this.
9. **Stop the old cron** once nothing is left on `moneris`, and only then.
10. **Keep the Moneris account open** until the ramp is finished and verified.
    Closing it is what makes the `data_key` values unrecoverable, and they are
    the rollback.

## Verification, and what rollback means

The migration is reversible right up to the point the Moneris account closes,
because `data_key` is never overwritten. Rollback is setting `gateway` back to
`moneris` for the affected rows and re-enabling the old cron.

What to check, in order of how much it would hurt to get wrong:

- **No row has both gateways live.** A `gateway` value and the old cron's
  filter should never both select the same subscription. Worth a query that
  asserts it, run before and after each cohort.
- **Every mapped card's last four and expiry agree** with
  `user_moneris_cards`. Anything that disagrees is a mis-join, and charging it
  means charging the wrong person's card.
- **Charge counts reconcile.** Renewals attempted on Stripe plus renewals
  attempted on Moneris should equal what the old cron alone would have
  attempted. A gap means a cohort fell between the two schedulers; an excess
  means a double charge.
- **Decline rate per cohort**, against the Moneris baseline. A new MID costs
  something; the question is how much, and the answer decides the pace.
- **No subscription silently lapses.** A row whose card failed to map and
  whose expiry passes while it waits is a cancelled subscription nobody
  decided to cancel.

## Questions for Moneris

1. Will you export the stored card data to Stripe, a PCI DSS Level 1
   processor, encrypted with their public PGP key and delivered to an SFTP
   server they provide? Stripe's AOC is available from our Stripe dashboard if
   you need it on file.
2. **What identifiers will the export be keyed on?** We need the `cust_id`
   (`CLAS-USER-<uid>`) and the per-card `data_key` to appear in it, because
   those are what our records hold.
3. Is `res_lookup_full` enabled for our merchant account, and under what
   conditions? We do not intend to use it — see above — but the answer bears
   on what the export can contain and on our own PCI position.
4. Is there a fee, and a form?
5. What turnaround, from request to upload? Stripe's side is about 10 working
   days after that.
6. Does our agreement oblige you to release the data on request, and when
   does the current term end? Stripe will join the thread if the request
   stalls.
7. Will the export include cards whose subscriptions have lapsed, or only
   active ones? We would rather have all of them: a returning subscriber
   expects their card to still be on file, and a second export is another
   round trip.

## Questions for Stripe

1. Confirm `pm_` PaymentMethod objects rather than legacy `card_` objects.
2. We will supply a CSV mapping `CLAS-USER-<uid>` to Stripe customer ids if we
   create the customers ahead of the import; otherwise create them. Which is
   preferable at our volume?
3. What does the validation summary look like, and what is the approval SLA on
   our side, so the freeze window can be planned?
4. Card Account Updater and network tokens on migrated cards — on from the
   start, or after the ramp?

## What this does to the estimate

The build-order figure of 58 hours covered building the payment path. The
migration is additional:

```
Schema: the three columns, on five tables, with a rollback script — 2h
Mapping-file importer, with the last-four and expiry verification — 8h
Gateway switch and cohort tooling, including the double-charge assertion — 6h
Reconciliation and monitoring queries for the ramp — 4h
Option B's one-line change to the old cron, or Option C's Moneris path — 1h or 10h
Coordination with Moneris and Stripe, and the approval cycle — 6h
```

So **27 hours** on top of the 58, or 36 if the old cron may not be touched and
the Moneris path has to be ported instead. The elapsed time is governed by
Moneris's export turnaround, not by any of this.

## What I got wrong, recorded deliberately

The first version of `docs/payments-decision.md` said there was nothing to
migrate: no live subscriptions, no cards attached to anything, and therefore
no switching cost. That came from the local database and it was wrong. The
recommendation to go to Stripe happens to survive the correction, but it now
rests on PCI scope and the integration, not on a free migration — and the
plan above is work that the earlier note said did not exist. The audit script
is there so the next version of these numbers comes from production.
