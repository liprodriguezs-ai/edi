# Plan 013: Move synchronous FCM push fan-out onto the queue

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- app/Notifications/Channels/FirebasePushChannel.php app/Notifications/Concerns/NotifiesResidents.php Modules/Notification/app/Jobs`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

`FirebasePushChannel::send()` loops over recipient device tokens and makes **one
synchronous HTTP call to Firebase per token**, inline in the Octane worker. It's
triggered after batch activation (`ChargeGenerationService`) and announcement
publication (`AnnouncementService`) — events that can target hundreds of units.
Activating a 300-unit batch can therefore fire up to 300 blocking HTTP
round-trips to Google before the admin's request returns, risking request
timeouts and tying up a worker. The push delivery should run on the queue, not in
the request. This plan moves it there.

## Current state

- `backend/app/Notifications/Channels/FirebasePushChannel.php` (~lines 38–43):
  ```php
  foreach ($recipients as $token) {
      $messaging->send($message);   // synchronous HTTP per token
  }
  ```
- `backend/app/Notifications/Concerns/NotifiesResidents.php` (~lines 131–146):
  `notifyUsers(...)` already correctly defers via `DB::afterCommit(...)`, but the
  channel execution itself is synchronous in-process.
- A job already exists: `backend/Modules/Notification/app/Jobs/` contains a
  `SendPushNotificationJob` (read it — it may already encapsulate the FCM send and
  just need to be dispatched here).
- The kreait/firebase-php SDK supports batched sends (`$messaging->sendAll([...])`
  / `sendMulticast(...)`) — fewer round-trips than per-token.
- Queue config: the app uses Redis queues (per `.project-context/stack.md`).
  `QUEUE_CONNECTION` drives sync vs async; tests typically run `sync`.

## Commands you will need

| Purpose         | Command (from `backend/`)                          | Expected     |
|-----------------|----------------------------------------------------|--------------|
| Lint            | `php -l <file>`                                     | no errors    |
| Notification tests | `php artisan test tests/Feature/Notifications`  | all pass     |
| Format          | `vendor/bin/pint --dirty`                          | exit 0       |

## Scope

**In scope**:
- `backend/app/Notifications/Channels/FirebasePushChannel.php`
- Possibly `backend/Modules/Notification/app/Jobs/SendPushNotificationJob.php`
  (if it needs to accept the payload shape the channel produces)
- `backend/tests/Feature/Notifications/` — add/extend a test asserting the push
  is queued, not sent inline (see Test plan)

**Out of scope**:
- The notification *content*/templating, the in-app/email channels, and
  `NotifiesResidents`'s `DB::afterCommit` logic (already correct).
- Switching the default queue driver or any infra config.

## Git workflow

- Branch: `advisor/013-queue-fcm-push`
- Conventional commit: `perf(notifications): dispatch FCM push fan-out to the queue`
- No push/PR unless instructed. `backend/` is a submodule.

## Steps

### Step 1: Inspect the existing job

Read `Modules/Notification/app/Jobs/SendPushNotificationJob.php`. Determine
whether it already performs the FCM send (`implements ShouldQueue`, a `handle()`
that calls `$messaging->send`/`sendAll`). If it does, you'll dispatch it. If it's
a stub or differently-shaped, you'll adapt it to accept `(tokens[], title, body,
data[])`.

### Step 2: Make the channel dispatch instead of send inline

In `FirebasePushChannel::send()`, replace the synchronous per-token loop with a
single dispatch of the queued job carrying all tokens + the message payload:
```php
SendPushNotificationJob::dispatch($recipients, $title, $body, $data);
```
Inside the job's `handle()`, prefer the SDK's batch API
(`$messaging->sendMulticast(...)` or `sendAll([...])`) over a per-token loop, so
even the queued work is one (or few) round-trips. Preserve the existing
per-token error handling (invalid/expired tokens) — if the current loop catches
and prunes dead tokens, keep that behavior inside the job.

### Step 3: Verify

- `php -l` on every changed file → no errors.
- `php artisan test tests/Feature/Notifications` → all pass.
- `vendor/bin/pint --dirty` → exit 0.

## Test plan

Extend `tests/Feature/Notifications/` (model on the existing notification wiring
test). Use Laravel's queue fake:
- `Queue::fake()` (or `Bus::fake()`), trigger an action that fans out a push
  (e.g. publish an announcement targeting multiple units), and assert
  `Queue::assertPushed(SendPushNotificationJob::class)` — proving the send is
  queued, not executed inline.
- If practical, assert the job carries all recipient tokens in one dispatch
  (one job for the fan-out, not one per token).

**Verify**: `php artisan test --filter <NewOrUpdatedTest>` → passes.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `FirebasePushChannel::send()` no longer makes a synchronous HTTP call per token; it dispatches `SendPushNotificationJob`.
- [ ] The job sends via the SDK batch API (or at least runs off-request) and preserves dead-token handling.
- [ ] A test asserts the push is queued (`Queue::assertPushed`) for a multi-unit fan-out.
- [ ] `php artisan test tests/Feature/Notifications` exits 0.
- [ ] `vendor/bin/pint --test` clean on in-scope files.
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match (drift).
- `SendPushNotificationJob` already dispatches and the channel is already
  non-blocking (drift — the finding is fixed). Report and stop.
- Moving to the queue would break a test that asserts synchronous delivery
  semantics — report it; the test may need to switch to `Queue::fake()` assertions,
  but confirm that's the intent.
- The FCM SDK's batch API isn't available in the installed `kreait/firebase-php`
  version — fall back to keeping the per-token loop INSIDE the queued job (still
  off-request) and note the SDK limitation.

## Maintenance notes

- Ensure a queue worker is running in production (Redis) — if the deploy doesn't
  run `queue:work`/Horizon, queued pushes won't deliver; flag this to ops in the PR.
- A reviewer should confirm dead-token pruning didn't get lost in the move, and
  that the fan-out produces one job (not N).
