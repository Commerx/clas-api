# Payments: Stripe or Moneris

The recommendation is **Stripe, with the card entered client-side so it never
reaches a CLAS server.**

> **Correction, 2026-11-05.** The first version of this document claimed there
> was nothing to migrate — no live subscriptions, no cards attached to
> anything. That was taken from the local `clas` copy, whose subscription
> tables are stale, and it was wrong. Production has a substantial book of
> active subscriptions with cards in the Moneris Vault, and keeping them is a
> requirement, not an afterthought. The recommendation survives the
> correction, but it now rests on PCI scope and the integration rather than on
> a free migration. The plan for moving the card file is
> `docs/moneris-to-stripe-migration.md`, and
> `docs/sql/production-subscription-audit.sql` gets the real numbers.
>
> Figures below that came from the local copy are marked. Treat an unmarked
> structural claim — which tables hold what, what the code does — as sound,
> and any volume figure as unverified.

There is one finding in here that needs attention regardless of which gateway
is chosen: a full card number and CVV are sitting in a plaintext log file.
It is the last section.

## The migration is real work, and it is solvable

Leaving a gateway is expensive because the stored cards cannot simply come
with you: a Moneris Vault `data_key` is meaningless to Stripe, and Stripe
cannot import it as a token.

What Stripe *can* do is take the card data itself. It runs a documented,
PCI-gated migration in which Moneris sends the cards directly to Stripe over
an SFTP server Stripe provisions, encrypted with Stripe's published PGP key,
and Stripe returns a file mapping every Moneris identifier to the Stripe
object that replaced it. No subscriber re-enters a card. Stripe's own
guidance is to build the integration first, start new customers on Stripe, and
move the existing book afterwards.

So the question is not whether the subscriptions survive — they do — but what
the move costs and how it is sequenced without double-charging anybody.
`docs/moneris-to-stripe-migration.md` is that plan: the process, the mapping,
the schema, four ways to avoid two schedulers charging the same row, the
cutover order, and what to ask Moneris and Stripe. It adds **27 hours** to the
payment group, or 36 if the old renewal cron may not be touched.

Two things from it belong here, because they bear on the choice:

- **The export request to Moneris is the longest pole.** Stripe's side is
  about 10 working days once it has valid data; Stripe says a previous
  processor can take days to months. Nothing about the gateway decision should
  wait on it, and the request should go in early.
- **If Moneris will not export, the fallback is not re-collecting every
  card.** It is running both gateways: Stripe for new subscriptions, Moneris
  charging the existing book until it churns. That works — the renewal cron is
  a CLI command, so it can outlive the old API — but with annual subscriptions
  it means two gateways for at least a year.

## What the gateway has actually done

Approved transactions, all four products, all time — **from the local copy,
so treat as a lower bound and re-run section 5 of the audit against
production**:

| Year | Charges | Approved |
|---|---|---|
| 2021 | 5 | $24.95 |
| 2022 | 1,978 | $37,989.23 |
| 2023 | 921 | $9,871.79 |
| 2024 | 556 | $5,554.44 |
| **Total** | **3,460** | **$53,440.41** |

Both of those patterns are worth checking against production rather than
believing from here. On the local copy the trend is steeply down and
`fishing_lands_subscription` shows 765 rows with no approved payment at all —
but this is the same copy that showed no live subscriptions, so the absence of
recent transactions is exactly what a stale table looks like. The audit's
section 5 gives the real monthly picture for the last 18 months.

What the figures are good for is the decline-rate baseline: 11% on the main
table historically. That is the number the Stripe ramp has to be measured
against, and a baseline from production is worth having before the first
cohort moves.

## Cost, at the volume that exists

Published rates, from third-party summaries rather than either company's own
page, so treat them as indicative. **Your own Moneris statement is the
authority for the Moneris side, and the negotiated rate may be better than
the published one.**

| | Moneris (card not present) | Stripe (domestic card) |
|---|---|---|
| Per transaction | 2.85% + $0.30 | 2.9% + $0.30 |
| Monthly account fee | ~$20–45 reported, plan-dependent | none on standard pricing |
| Contract | 3-year initial term reported, with an exit fee | none |
| Recurring billing | built in the application, as today | 0.7% extra if Stripe Billing is used; avoidable |

The per-transaction rates are within 0.05% of each other, so on processing
fees alone the two are interchangeable. The differences that matter are the
monthly account fee, which Stripe does not charge on standard pricing, and
the contract term. At a few thousand dollars a year the account fee dominates;
at ten times that, an interchange-plus quote from Moneris could beat Stripe's
flat rate, which is why the audit's revenue figures should come from
production before this is treated as settled.

If Stripe is used, keep the renewal schedule in CLAS and charge saved cards
with ordinary off-session payments rather than adopting Stripe Billing. That
is how the application works today, it avoids the 0.7%, and it keeps the
subscription tables as the record both applications already share.

## The reason this is not a close call

The old API accepts the raw card:

```php
$txnRequest['pan']     = $postParams["cc_number"];
$txnRequest['expdate'] = $postParams["cc_expiry"];
// and the CVV, via mpgCvdInfo
```

`cc_number`, `cc_expiry` and `cc_cvv` are ordinary POST parameters. The card
number crosses the CLAS server, which puts the whole application in **PCI DSS
SAQ D** scope — the heaviest self-assessment there is, covering the servers,
the network, logging, access control and annual review.

With Stripe the card is entered into Stripe's own SDK on the device, which
returns a payment method id. The number never reaches a CLAS server, and the
application drops to **SAQ A**. That is a different order of compliance
burden, and it is worth more than any rate difference at this volume.

Moneris can do the same thing — it has hosted and tokenised entry options —
so this is an argument for client-side tokenisation rather than for Stripe
specifically. But it does mean the old server-side card flow should not be
ported as it stands to either gateway, and rebuilding it anyway removes the
main reason to stay.

Supporting the recommendation, in rough order of weight:

- **Nothing to migrate**, as above.
- **PCI scope**, as above.
- **The SDK.** Stripe's PHP library is first-party, versioned and documented.
  The Moneris integration here is a vendored `mpgClasses.php` copied into
  `components/moneris/`, written in a PHP 4 constructor style, with the store
  id and API token committed to `config/params.php` — one of the four secrets
  already on the rotation list.
- **Test mode.** Stripe's test keys and test cards need no agreement with
  anybody, so the payment work can be built and tested before any commercial
  decision is finalised.
- **No contract term.** Reported Moneris agreements run three years with a
  per-location exit fee. Given the revenue trend, a three-year commitment is
  a poor fit.

Against it, stated fairly:

- Moneris is already integrated and has a working history in this
  application. Staying costs no integration work beyond the port.
- Moneris is Canadian-domiciled, settles in CAD natively, and supports Interac
  Online, which Stripe does not in the same way. Nothing in CLAS uses Interac
  today, but it is a real difference if that ever matters.
- A negotiated Moneris rate may beat the published one, and an
  interchange-plus quote at higher volume could beat Stripe's flat rate.
  That matters at ten times this volume, not at this one.

## Before any of that: there is card data in a log file

`app.log.1` on the production server contains one full 16-digit card number
and its 3-digit CVV, in plaintext, dated **2025-11-06**. The file path in the
surrounding entries is `/var/www/clas/canadianlandaccess.com/html/`, so this
is the live server's log, not a local artefact. Neither value is reproduced
here or anywhere else in these notes.

The mechanism is not a one-off mistake. `config/web.php` configures the log
target without setting `logVars`, so Yii's default applies and the handler
writes the whole `$_POST` body for every logged error or warning. In this case
a Swiftmailer SMTP exception during a subscription request was enough. Any
error on that endpoint does the same thing.

Storing a CVV after authorisation is prohibited outright by PCI DSS, not
merely discouraged. And the CVV should not have been collected in the first
place: `moneris_cvv_check` is `false` in the production parameters, so the CVV
was taken from the user, excluded from the request to Moneris, and then
written to disk when something went wrong.

What this needs, in order:

1. **The log files.** `app.log` (9.9 MB) and `app.log.1` (10.5 MB) on the
   production server, and any backups or log shipping that have copied them.
   They are untracked by git, so nothing is in the repository history — worth
   confirming on the server. Do not grep them for card numbers and leave the
   output lying around.
2. **Set `logVars` to an empty array**, or exclude `$_POST`, in the old
   application's log configuration. This is one line in `config/web.php`. It
   is a change to the old code, which the standing decision says not to make —
   but it is also the difference between this happening again and not, for as
   long as that endpoint is live. Worth an exception; your call.
3. **Stop collecting the CVV** on the old endpoint, since it is discarded
   anyway. Same standing decision applies.
4. **Whether this is a reportable incident.** One cardholder's full number and
   CVV, in a server log, for an unknown period. That is a question for
   whoever handles the merchant agreement, not one I can answer.

The new API cannot reproduce this: Laravel does not log request bodies, and
under the recommendation above the card never arrives at all.

## What the port looks like under this recommendation

Unchanged from the build order's 60 hours, with the shape settled:

- A payment service with one implementation, Stripe, behind an interface
  narrow enough that the gateway is not spread through the controllers.
- `user/subscribe`, the three product subscription endpoints, and
  `user/apply-promocode` take a Stripe payment method id, never a card.
- The signage endpoints in ManageListings — promocode, add-signages,
  update-shipping-cost, update-payment — on the same service.
- Renewals stay a scheduled job in CLAS charging saved cards off-session,
  reading and writing the same subscription tables the old application does.
- Webhook handling for the asynchronous outcomes, which the old integration
  had no equivalent of: it read the synchronous response and nothing else.

Plus the migration work in `docs/moneris-to-stripe-migration.md`. Two things
to settle before any of it starts:

- **Which subscription products are still sold.** Four exist. If any are
  finished, the group gets smaller — but decide it from the audit output, not
  from the local copy, which understates all four.
- **Keep the Moneris account open until the ramp is verified.** The
  `data_key` values are the rollback: as long as they are intact and the
  account is live, any cohort can go back to Moneris. Closing the account is
  what makes that irreversible, so it is the last step, not an early one.

## One more thing, and it may be a local artefact

On the local copy, 2,668 subscription rows carry `last_processed =
2025-11-10` and 1,581 carry `expiration_date = 2025-11-10`, across all four
tables, with **zero payment transactions on that date**. Something stamped
those columns without charging anything.

Given that the same copy is stale about everything else in these tables, the
most likely explanation is now that this *is* the staleness — whatever was
done to the copy when it was taken. Section 7 of the audit says whether
production has the same stamp. If it does, it is a bulk update with no record
of what ran it, the same shape as the profile-position overwrite in
`docs/profile-positions.md`, and worth understanding before anybody reads
those dates as evidence of a charge. If it does not, it is an artefact of the
copy and can be ignored.
