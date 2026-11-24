# Push notifications: what broke, and what it takes to fix

**Push has not been delivered to anyone since July 2024.** Not degraded —
delivering nothing, to every user, on every notification type, for about
sixteen months. Nothing in the application noticed, because nothing reads the
response.

Found 2026-11-23 while moving a hardcoded key into config.

## The evidence

Every live call in the Yii application posts to FCM's legacy HTTP endpoint,
which Google retired in July 2024. It no longer exists:

```
POST https://fcm.googleapis.com/fcm/send   →   HTTP 404 Not Found
```

That is measured against the live endpoint, not inferred from the
announcement. The response is a Google HTML error page, and every call site
does this:

```php
$result = curl_exec($ch);
curl_close($ch);
// $result is never read
```

So a 404 is indistinguishable from success, from inside the application.

The migration was started and abandoned. In **all ten** places the modern
endpoint sits on the line directly above, commented out:

```php
// curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/clas-63124/messages:send');
curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
```

| Endpoint | Live calls | Commented out |
|---|---|---|
| `fcm/send` (legacy, retired) | **11** | 0 |
| `v1/projects/clas-63124/messages:send` | 0 | **10** |

## Who has been affected

**5,471 device registrations across 5,288 distinct users**, out of 7,442
accounts — counted from the production dump. Most of the user base has a
device on file and has been receiving nothing.

What those users have silently missed:

| Notification | Where | Who it was for |
|---|---|---|
| Request accepted | `v7/RequestsController::actionAccept` → `sendNotificationToRequester` | the requester |
| Request declined | `v7/RequestsController::actionDecline` → same | the requester |
| Request auto-accepted on an open property | `v7/RequestsController::actionSubmit` | the requester |
| Hunting alert | `v7/NotificationsController::actionHuntingAlert` | nearby subscribers |
| Community awareness alert | `v7/NotificationsController::actionCommunityAwareness` | nearby subscribers |
| Subscriber notification | `v7/NotificationsController::actionSubscriberNotification` | subscribers |
| Notification submitted | `v7/NotificationsController::actionSubmit` | nearby subscribers |

Worth stating plainly because it is easy to misread: **the landowner never
received a push for anything.** No call site targets the owner. Owners are
notified of bookings, check-ins and check-outs by **email**, and those are
working. That is why the system looks alive.

## Two separate pieces of work

### 1. v1 — credentials only, no code. ~3h

`App\Services\PushSender` already speaks the modern API: it builds a
service-account JWT, exchanges it at `oauth2.googleapis.com/token` for an
access token (cached 50 minutes), and posts to
`v1/projects/{project}/messages:send`. It is written, and it is wired into
`NotificationBroadcast`.

It is inert only because it has no credentials, and it says so rather than
failing quietly — with `PUSH_DRIVER=log` every intended push is written to
the log with its recipient, so fan-out and targeting are already testable.

To turn it on:

1. In the Firebase console for project **`clas-63124`**, create a service
   account with the Firebase Cloud Messaging API enabled and download its
   JSON key.
2. Put the file on the server **outside the webroot** — `/var/www/fcm/` not
   `/var/www/api/public/`.
3. Set three values:
   ```ini
   PUSH_DRIVER=fcm
   FCM_PROJECT_ID=clas-63124
   FCM_CREDENTIALS=/var/www/fcm/clas-fcm-service-account.json
   ```
4. Confirm against a real device before trusting it. The `log` driver proves
   the fan-out; only a real handset proves delivery.

Nothing here exists today: there is no service-account JSON anywhere in
either project, and the local `.env` has `FCM_PROJECT_ID=` and
`FCM_CREDENTIALS=` both empty. This is a Firebase console task, not a
copy-the-key-across task.

### 2. v7 — a real migration, only if push must work before cutover. ~8h

The old application cannot be fixed by changing a URL. The legacy API
authenticates with a static server key in a header; the modern one needs an
OAuth2 access token from a service account, refreshed hourly, and a different
request body (`message.token`, `message.notification` rather than `to` and
`notification`).

That means, in PHP 5.6, without Composer's Google libraries: JWT signing,
token exchange, token caching, and a rewritten payload — then applied across
**seven distinct functions in eleven places**, five of which are copies of
the same helper in v3, v4, v5, v6 and v7.

**Whether to do this at all is the actual decision.** Against it: the old
application is frozen, it is being retired, push has been dead for sixteen
months already, and nobody has complained loudly enough for it to be noticed.
For it: if the cutover slips, that is sixteen months becoming longer, and the
new app build is what will carry v1's working push.

The cheaper middle path, if push matters sooner than the cutover: point the
**new** app build at v1 for notifications only, the same way payments can
stay on v7. Both applications share `user.auth_key` and the `device_tokens`
table, so v1 can deliver to the same devices without the old application
changing at all.

## The credentials, which need dealing with either way

Moving the key to config was the original request. The survey turned up more
than one key:

- **12 hardcoded occurrences** across `commands/ServicesController.php` (×3),
  `components/GlobalComponent.php`, `controllers/NotificationsController.php`,
  `controllers/admin/NotificationsController.php`,
  `controllers/PropertiesController.php`, and the Notifications controllers
  for **v3, v4, v5, v6 and v7**
- **two distinct server keys**, not one
- **one expired Firebase ID token** (a JWT, not a server key) at
  `commands/ServicesController.php:135`, carrying a staff email address and
  Firebase user id. It expired in August 2021.

All of it is in git history, so removing it from the working tree achieves
nothing on its own.

The saving grace: **legacy server keys are now worthless.** The endpoint that
accepted them is gone, so there is nothing to revoke and nothing an attacker
can do with them. They should be deleted rather than rotated — and the
Firebase project should be checked for any legacy key still marked active, in
case something else uses one.

There is also no good destination in that application today.
`config/params.php` and every environment file under `config/environments/`
are **tracked**, so moving a secret there leaves it in git exactly as it is
now. `.gitignore` lists `/config/mailer.php`, but that rule was added after
the file was committed and therefore does nothing — which is why the SendGrid
key is still readable in history. A genuine fix reads from the server
environment with no fallback.

## Recommended order

1. **Delete the dead keys from the Yii source** — they protect nothing and
   their presence implies the opposite. Cheap, and it removes 12 findings
   from any future audit.
2. **Credential v1's `PushSender`** — 3 hours, no code, and it is what the
   new app build will use.
3. **Decide on v7** only after the cutover date is fixed. If the date is
   close, do nothing; if it slips, reconsider — or point the new app at v1
   for notifications early.
