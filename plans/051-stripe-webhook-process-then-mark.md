# Plan 051: Fix Stripe webhook idempotency so a failed handler doesn't strand a paid tenant

> **Executor instructions**: Follow step by step; run every verification and
> confirm the expected result before moving on. On a STOP condition, stop and
> report. Update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- app/Listeners/StripeWebhookListener.php`
> Compare the excerpt below against the live code; on mismatch treat as STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED
- **Depends on**: none
- **Category**: bug (correctness) + tests
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

The webhook listener marks a Stripe event id as "processed" in the cache BEFORE it
runs the handler, and the handler body is wrapped in a `catch (Throwable)` that only
logs. So if `handleCheckoutCompleted` flips the tenant to ACTIVO and then fails to
grant entitlements (e.g. a transient Stripe `retrieveSubscriptionItems` error or a DB
deadlock on the upsert), the tenant is left **paid + ACTIVO + zero entitlements** →
`SubscriptionStateService` treats zero active entitlements as blocked → every
non-allowlisted API call 403s. Worse, because the event id was already cached for 4
days, Stripe's automatic retry of that same event hits the early-return and is
dropped — disabling the natural self-heal. The dedup design itself is good; the
ordering (mark-first) is the bug.

## Current state

`backend/app/Listeners/StripeWebhookListener.php`:

- Mark-first + swallow-catch (lines 58–90):
  ```php
  public function handle(WebhookReceived $event): void
  {
      $payload = $event->payload;
      $type = $payload['type'] ?? null;
      $eventId = $payload['id'] ?? null;

      if ($eventId && ! Cache::add('stripe:evt:'.$eventId, true, now()->addDays(4))) {
          return; // already processed
      }

      try {
          match ($type) {
              'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
              // ... other cases ...
              default => null,
          };
      } catch (Throwable $e) {
          // Never bubble up — Stripe must still receive a 200 from the controller.
          Log::error('StripeWebhookListener failed', [
              'event_type' => $type,
              'error' => $e->getMessage(),
          ]);
      }
  }
  ```
- `handleCheckoutCompleted` (lines 102–164): flips `tenant.status = ACTIVO` in its own
  transaction (≈132–135), then grants entitlements from subscription items (≈143–153),
  recomputing access only `if ($granted > 0)`.
- Grants are **idempotent upserts** keyed by tenant+module (confirmed by a1 and a7:
  `EntitlementService` uses upsert semantics), so reprocessing the same event is safe.
- The happy-path dedup + a monotonic `expires_at` guard on late invoices (≈288–296)
  are correct and MUST be preserved.

Convention: keep the "never bubble up (return 200 to Stripe)" behavior — Stripe needs a
2xx or it will retry, and retry is exactly what we want to STAY possible on failure.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l app/Listeners/StripeWebhookListener.php` | `No syntax errors detected` |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=StripeWebhook` | all pass incl. new tests |

> Host php, shared `testing` DB, ONE process at a time.

## Scope

**In scope**:
- `backend/app/Listeners/StripeWebhookListener.php` (the `handle` method only)
- `backend/tests/Feature/Billing/StripeWebhookEntitlementTest.php` (extend with the two new cases)

**Out of scope**:
- The individual `handleX` handlers' internal logic — do NOT change what they do, only
  WHEN the event is marked processed.
- The monotonic `expires_at` guard (≈288–296) — leave intact.
- `SubscriptionStateService` / `EnsureSubscriptionActive` — the block-on-zero-entitlements
  behavior is correct; the fix is to not strand the tenant in the first place.

## Steps

### Step 1: Only mark the event processed after the handler succeeds

Reorder so the dedup marker is written after the `match` completes without throwing,
and on failure the marker is NOT left set (so Stripe's retry reprocesses). Keep the
atomic pre-check to collapse concurrent deliveries of the SAME event, but release it on
failure:

```php
public function handle(WebhookReceived $event): void
{
    $payload = $event->payload;
    $type = $payload['type'] ?? null;
    $eventId = $payload['id'] ?? null;

    // Atomic claim so two concurrent deliveries of the same event don't both run.
    if ($eventId && ! Cache::add('stripe:evt:'.$eventId, true, now()->addDays(4))) {
        return; // already processed (or in-flight)
    }

    try {
        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
            // ... unchanged cases ...
            default => null,
        };
    } catch (Throwable $e) {
        // Release the claim so Stripe's retry (up to ~3 days) reprocesses this event.
        // Grants are idempotent upserts, so reprocessing is safe.
        if ($eventId) {
            Cache::forget('stripe:evt:'.$eventId);
        }

        Log::error('StripeWebhookListener failed', [
            'event_type' => $type,
            'error' => $e->getMessage(),
        ]);
    }
}
```

(Keeping `Cache::add` as the claim + `Cache::forget` on failure is preferable to
"process then add" because it still dedupes concurrent in-flight deliveries. If the
team prefers the simpler "add only after success", that also closes the bug — but note
it loses the concurrent-delivery guard, so prefer the claim+forget shape above.)

**Verify**: `cd backend && php -l app/Listeners/StripeWebhookListener.php` → `No syntax errors detected`.

### Step 2: Regression tests (also closes plan a7 TEST-03 gap)

Extend `tests/Feature/Billing/StripeWebhookEntitlementTest.php`:
1. **Failed handler → event reprocessable**: fire `checkout.session.completed`; force
   the grant step to throw once (e.g. mock/stub the item resolver to throw, or point at
   an unmappable price); assert the tenant is NOT left with zero entitlements after a
   SECOND delivery of the same event id succeeds (i.e. the retry heals). Assert
   `Cache::has('stripe:evt:'.$id)` is false after the failing delivery.
2. **Duplicate delivery → single effect** (protects the existing dedup): fire the same
   successful event id twice; assert entitlements/grants applied exactly once.
3. **Out-of-order invoice → `expires_at` monotonic** (protects the existing guard): an
   older-period invoice arriving after a newer one does not regress `expires_at`.

**Verify**: `cd backend && php artisan test --filter=StripeWebhook` → all pass. Confirm test #1 FAILS against the old mark-first code.

### Step 3: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] `php -l` clean
- [ ] Event id is released (`Cache::forget`) on handler failure; happy-path dedup preserved
- [ ] `php artisan test --filter=StripeWebhook` passes with 3 new/extended cases
- [ ] Test #1 fails against the pre-fix code (proves it guards the strand)
- [ ] `vendor/bin/pint --dirty` exit 0
- [ ] Only the listener + its test modified (`git -C backend status`)
- [ ] `plans/README.md` row updated

## STOP conditions

- The `handle` method differs from the excerpt (drift).
- Removing/altering the marker breaks the monotonic `expires_at` guard test — if so,
  you touched the wrong thing; revert and report.
- You discover the grants are NOT actually idempotent (a non-upsert write path) — STOP;
  the reorder is unsafe until grants are idempotent, report this instead of shipping.

## Maintenance notes

- Any new event handler added to the `match` is automatically covered by the
  claim+forget semantics — no per-handler idempotency plumbing needed, but each handler
  MUST remain safe to reprocess (idempotent writes).
- Reviewer: verify `Cache::forget` runs on the SAME key used by `Cache::add`, and that a
  2xx is still returned to Stripe on failure (the controller must not start 500-ing).
- Follow-up (not in this plan): consider persisting processed event ids in a table
  rather than cache if cache eviction under memory pressure becomes a concern.
