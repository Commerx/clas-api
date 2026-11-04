# Profile positions

`profile.user_lat` / `user_lng` is the only user location either application
holds. It decides who receives a community-awareness notification: everyone
within 25 km of the listing the alert is about.

Measured on the local `clas` copy on 2026-11-04. The copy's newest real
account is from 2024-09-24, so **confirm these figures against production
before acting on them.**

## What the column holds

| | |
|---|---|
| profiles at `49.90261265,-114.12588656` | **5,610** of 5,611 |
| distinct positions in the whole table | **2** |
| profiles at zero, the column's default | 0 |

The one exception is user 1716, `RileyRanch`, at `51.0447,-114.0719`
(downtown Calgary, four decimal places).

That first pair is the old web application's default map centre, hardcoded in
ten of its views. It is also, exactly, the coordinate of the Waldron listing
(land 6539) — the default was set to a flagship property.

## It was one statement, not the clients

The ten accounts that received the only proximity alerts this feature has
ever delivered — `clasdemo`, `talhabukhari_OLD`, `bukhari`, `talhacommerx`,
`adamsmith`, `ismael`, `abdullah`, `haris`, `talhaumair`, `devdev` — were
within 25 km of `31.61,74.43` in December 2023. Every one of them now holds
the default centre.

So real positions existed and were overwritten. A client sending the default
cannot explain it: that would leave the accounts that did send real
coordinates alone. Between 2023-12-12 and 2024-09-24 something ran the
equivalent of `UPDATE profile SET user_lat = 49.90261265, user_lng =
-114.12588656`, over every row.

The column has no `updated_at`, so the date cannot be narrowed further from
the data.

## What it does to notifications

Under the 25 km rule, across the 16,541 active public listings that have
coordinates:

| recipients per alert | listings |
|---|---|
| **5,610** — every account | **88** |
| 1 | 2 |
| 0 | 16,451 |

Plus 302 active public listings with no coordinates at all (332 across all
16,980 land rows), which reach nobody.

It is all or nothing, and the 88 are the properties nearest the default
centre: Waldron, Flying E Ranche, Riley Ranch West, Outlook Ranch and
neighbours. One owner posting a community notice on any of them notifies the
entire user base — and once push has credentials, pushes to every device.

This is also why it has never been noticed. Every category-1 alert in the
history of the system, 30 of them, was posted on one listing: land 11243,
"TR Land", at `31.61,74.43` — Lahore, not Canada, owned by `clasdemo`. It is
still `active='Y'` and `visibility_status='public'`. The feature has only
ever been exercised by the development team on a test property, before the
overwrite.

## What the new API does about it

`App\Support\V1\Position` is the single place that decides what counts as a
position. It treats a pair within about fifty metres of a known map default
as no position at all, which means:

- a profile holding the default centre is not near anything, so those 88
  listings reach the people genuinely near them and nobody else;
- a client reporting the default centre has it dropped rather than stored, so
  the condition stops growing through this API whatever is done about the rows
  already there.

The tolerance is a distance rather than an equality because a client that
rounds the pair on the way out still means the same non-position. The cost is
that a real reading taken while standing on the Waldron listing is discarded;
the listing's own coordinate is not filtered this way, so an alert about
Waldron is still a real alert.

Three tests hold this, each verified to fail when the guard is removed:
`test_a_profile_on_the_map_default_is_not_near_anything`,
`test_a_client_sending_the_map_default_does_not_move_the_profile`, and the
hygiene rule `test_only_the_position_class_writes_a_profile_position`.

## The decisions that are yours

**1. The existing rows.** The guard makes them harmless to the new API, but
they are still wrong, and the old API — which stays on the domain until the
forced update — has no such guard. Restoring them to "unknown" is one
statement. It is not reversible, so it wants a backup of the two columns
first:

```sql
CREATE TABLE profile_position_backup_20261104 AS
  SELECT user_id, user_lat, user_lng FROM profile;

UPDATE profile
   SET user_lat = 0, user_lng = 0
 WHERE ABS(user_lat - 49.90261265) <= 0.0005
   AND ABS(user_lng + 114.12588656) <= 0.0005;
```

Expect 5,610 rows on the local copy. Zero is what this schema uses for
"unset", and both applications already treat it as missing.

**2. Whether the old API should get the same guard — decided, 2026-11-04: no.**
It would have been five lines at five call sites. The old application stays
untouched before the cutover, so for the weeks between go-live and the forced
app update it keeps writing the default centre onto profiles and keeps fanning
community alerts out to everyone near that point. Clearing the rows (decision
1) does not stop that; the old API would refill them.

**3. Capture.** Nothing fixes the feature until real positions arrive. The
API stores whatever a client sends alongside a listings or requests call;
there is no consent step, no accuracy, and no recorded time, so a position is
also never stale. Worth deciding what the app should send, how often, and
whether a user can turn it off — and whether 25 km is the right radius once
positions are real.

**4. Fan-out size.** Even with correct positions, a community alert has no
ceiling and no confirmation. 25 km around a city is a large number of people
receiving a push from a stranger. A cap, a rate limit per sender, or a
review step for anything above a threshold is a product decision; the new API
returns the recipient count on every broadcast, so it is measurable either
way.
