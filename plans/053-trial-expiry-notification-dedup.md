# Plan 053: Stop the trial-expiry cron from re-notifying expired trials daily (and for now-paid modules)

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/SubscriptionPlan/app/Console/Commands/ExpireManualEntitlementsCommand.php Modules/SubscriptionPlan/app/Services/BillingNotificationService.php Modules/SubscriptionPlan/app/Models/TenantEntitlement.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (correctness)
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

The daily `billing:expire-manual-entitlements` cron re-queries ALL `SOURCE_TRIAL`
entitlements with `expires_at < now` and calls `notifyTrialExpired` for each, every
run. There is no "already notified" marker and no check that the module is currently
inactive, and expired trial rows are never deleted (kept by design). So once a trial
lapses, the cluster's admins get the same "tu prueba de {módulo} venció" in-app + push
notification **every single day, forever** — one per historically-expired module.
Worse, it fires even for a module the cluster has SINCE PAID for (the expired trial row
still matches `source=trial AND expires_at<now`), telling a paying admin their access
lapsed, which is simply false.

## Current state

`backend/Modules/SubscriptionPlan/app/Console/Commands/ExpireManualEntitlementsCommand.php`:

- Candidate tenants (lines 77–82): any tenant with an expired non-stripe entitlement.
- The notify loop (lines 115–134) inside `handle`, per candidate tenant:
  ```php
  $expiredTrials = TenantEntitlement::query()
      ->where('tenant_id', $tenant->id)
      ->where('source', TenantEntitlement::SOURCE_TRIAL)
      ->whereNotNull('expires_at')
      ->where('expires_at', '<', $now)
      ->with('billable_module')
      ->get();

  foreach ($expiredTrials as $expiredTrial) {
      $billableModule = $expiredTrial->billable_module;
      if ($billableModule === null) { continue; }

      $tenant->run(function () use ($billingNotificationService, $billableModule): void {
          $billingNotificationService->notifyTrialExpired($billableModule);
      });
  }
  ```
  The code comment at ≈108–110 explicitly says it fires "regardless of whether the
  cluster is blocked or kept active by another source."
- `EntitlementService` keeps expired trial rows (never deletes them), so the matched
  set only grows over time.
- `BillingNotificationService::notifyTrialExpired` (≈35–54) has no dedup guard.

Convention: catalog/enum values live in DB, money in minor units — neither applies
here. Adding a timestamp column follows the repo's migration rules (every own column
needs `->comment()`; see CLAUDE.md strict rule 9).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Migrate (test DB) | `cd backend && php artisan migrate --env=testing` | runs the new migration |
| Syntax | `cd backend && php -l Modules/SubscriptionPlan/app/Console/Commands/ExpireManualEntitlementsCommand.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=ExpireManualEntitlements` | all pass incl. new dedup cases |

> Host php, shared `testing` DB, ONE process at a time.

## Scope

**In scope**:
- A new migration adding `trial_expiry_notified_at` (nullable timestamp, `->comment(...)`)
  to the `tenant_entitlement` table.
- `TenantEntitlement.php` — add the column to `$fillable`/casts as the model requires.
- `ExpireManualEntitlementsCommand.php` — the notify loop only.
- `backend/Modules/SubscriptionPlan/tests/` (or `tests/Feature/Billing/`) — extend the
  existing `ExpireManualEntitlementsCommandTest`.

**Out of scope**:
- `recompute()` and the blocking decision (lines ≈97–144 outside the notify loop) — the
  access/blocking logic is correct; only the NOTIFICATION is over-firing.
- The retention of expired trial rows (keeping them is intentional).
- `notifyTrialExpired`'s message/channel content.

## Steps

### Step 1: Add a `trial_expiry_notified_at` marker column

New migration (mirror an existing `tenant_entitlement` alter for style; comment the
column):
```php
$table->timestamp('trial_expiry_notified_at')->nullable()
    ->comment('When the trial-expiry notification was sent for this entitlement; NULL = not yet notified. Prevents daily re-notification.');
```
Add it to `TenantEntitlement`'s `$fillable` and `$casts` (`'datetime'`).

**Verify**: `php artisan migrate --env=testing` applies cleanly; `php artisan test --filter=ExpireManualEntitlements` still runs (may be red until Step 2).

### Step 2: Notify once, and skip when the module is currently active

Replace the notify loop so it (a) only selects trials not yet notified, and (b) skips
any module that currently has an ACTIVE entitlement of any source (i.e. it was paid /
re-granted since the trial lapsed):

```php
$expiredTrials = TenantEntitlement::query()
    ->where('tenant_id', $tenant->id)
    ->where('source', TenantEntitlement::SOURCE_TRIAL)
    ->whereNotNull('expires_at')
    ->where('expires_at', '<', $now)
    ->whereNull('trial_expiry_notified_at')   // notify once
    ->with('billable_module')
    ->get();

foreach ($expiredTrials as $expiredTrial) {
    $billableModule = $expiredTrial->billable_module;
    if ($billableModule === null) { continue; }

    // Skip if this module is currently kept active by ANY entitlement (e.g. now paid).
    $moduleStillActive = TenantEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('billable_module_id', $expiredTrial->billable_module_id)
        ->active()   // the existing active scope: null-or-future expiry
        ->exists();

    if (! $moduleStillActive) {
        $tenant->run(function () use ($billingNotificationService, $billableModule): void {
            $billingNotificationService->notifyTrialExpired($billableModule);
        });
    }

    // Mark notified either way, so a lapsed-then-paid module never re-fires later.
    $expiredTrial->forceFill(['trial_expiry_notified_at' => $now])->save();
}
```

(Confirm the exact scope name — grep `function scopeActive` / `->active()` in
`TenantEntitlement.php` — and the `billable_module_id` column name before writing.)

**Verify**: `php -l` clean.

### Step 3: Tests

Extend `ExpireManualEntitlementsCommandTest`:
1. **Notify once**: expired trial → run cron twice → `notifyTrialExpired` fired exactly
   once (assert via a fake/spy on the notification, or a sent-notifications count).
2. **Skip if now active**: expired trial for module M + an active paid entitlement for M
   → cron does NOT notify for M.
3. **Existing behavior intact**: a freshly-lapsed trial with no other coverage → notified
   once and blocking/recompute still behaves as the existing tests assert.

**Verify**: `php artisan test --filter=ExpireManualEntitlements` → all pass; case #1 fails against the pre-fix code (which would fire twice).

### Step 4: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Migration adds a commented `trial_expiry_notified_at`; model updated
- [ ] Cron notifies each expired trial at most once and skips now-active modules
- [ ] `php artisan test --filter=ExpireManualEntitlements` green with 3 cases
- [ ] Case #1 fails against pre-fix code
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] Only the migration, model, command, and test modified
- [ ] `plans/README.md` row updated

## STOP conditions

- The notify loop differs from the excerpt (drift).
- There is no `active()` scope / the expiry semantics differ from "null-or-future" —
  read the model and adapt; if the "currently active" concept is ambiguous, report
  rather than guess.
- `notifyTrialExpired` turns out to already have an internal dedup you missed (grep it)
  — if so, the column may be redundant; report and prefer the smaller fix.

## Maintenance notes

- New notification types on the cron should follow the same "mark on send" pattern.
- Reviewer: confirm `trial_expiry_notified_at` is set even when notification is skipped
  (so a lapsed-then-paid trial never re-fires if it later lapses again from a new grant).
- If trials become re-triggerable per period, the marker may need resetting on a new
  trial grant — note for whoever builds trial renewals.
