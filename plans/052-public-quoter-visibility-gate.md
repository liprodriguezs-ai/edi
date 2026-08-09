# Plan 052: Gate the public plan-quoter to public, active plans only

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/SubscriptionPlan/app/Services/PlanPricingService.php Modules/SubscriptionPlan/app/Http/Controllers/BillingPlanQuoteController.php Modules/SubscriptionPlan/routes/api.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

`POST /api/billing/plan-quote` is public (no `auth:sanctum`, only `throttle:60,1`) — it
feeds the landing pricing page. It resolves the plan purely by key with **no
`is_public` and no `is_active` filter**, while the public catalog endpoint deliberately
shows only `is_public = true` plans and the preferential-swap flow treats
`is_public = false` as super-admin-only hidden/negotiated variants. So anyone who knows
or guesses a hidden/preferential plan key can POST it to the public quoter and get its
full per-module price breakdown + total — an unauthenticated disclosure of confidential
negotiated pricing. It also lets callers quote `is_active = false` (retired) plans.

## Current state

- `backend/Modules/SubscriptionPlan/routes/api.php` (lines 20–23) — public group:
  ```php
  Route::middleware('throttle:60,1')->group(function () {
      Route::get('billing/plan-catalog', [BillingPlanQuoteController::class, 'catalog']);
      Route::post('billing/plan-quote', [BillingPlanQuoteController::class, 'quote']);
  });
  ```
- `backend/Modules/SubscriptionPlan/app/Http/Controllers/BillingPlanQuoteController.php`
  (lines 28–43) — `quote()` passes the client's raw `plan_key` straight through:
  ```php
  $quote = $planPricingService->quoteByPlan(
      $validated['plan_key'],
      $validated['modules'],
      $validated['interval'],
  );
  ```
- `backend/Modules/SubscriptionPlan/app/Services/PlanPricingService.php` (lines 82–88) —
  the ungated lookup, whose own docblock admits it:
  ```php
  // The plan is resolved purely by key (public or private): is_active is
  // NOT gated here (see class docblock).
  $plan = BillingPackage::query()->where('key', $planKey)->first();

  if ($plan === null) {
      throw new InvalidArgumentException("Plan inválido: {$planKey}.");
  }
  ```
- Contrast — the public catalog DOES gate: `PlanPricingService::planCatalog()` /
  `buildPlanCatalog` filters `where('is_public', true)` (grep `is_public` in that file
  to find the exact line, ≈300–301). `PreferentialSwapController::resolveContext` treats
  `is_public === false` as the super-admin-only hidden variant.
- `quoteByPlan(...)` is ALSO called by AUTHENTICATED, super-admin-gated paths (checkout,
  preferential swap) that legitimately quote hidden plans — so the gate must apply ONLY
  to the public path, not inside `quoteByPlan` unconditionally.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/SubscriptionPlan/app/Http/Controllers/BillingPlanQuoteController.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=BillingPlanQuote` (new) + `--filter=PreferentialSwap` (regression) | all pass |

> Host php, shared `testing` DB, ONE process at a time.

## Scope

**In scope**:
- `BillingPlanQuoteController.php` (the public `quote` action) — reject non-public /
  inactive plans, OR
- `PlanPricingService.php` — add a `bool $publicOnly = false` parameter to `quoteByPlan`
  and pass `true` from the public controller only.
- A new test under `backend/Modules/SubscriptionPlan/tests/Feature/` (or `tests/Feature/Billing/`).

**Out of scope**:
- The authenticated checkout / preferential-swap callers of `quoteByPlan` — they MUST
  keep quoting hidden/inactive plans. Do not gate them.
- `planCatalog()` — already correct.
- The route file — public-ness of the quote route is by design (landing needs it); the
  fix is the visibility filter, not adding auth.

## Steps

### Step 1: Add a public-only gate to the quote path

Preferred shape — a flag on the service so the authority stays in one place:

```php
// PlanPricingService::quoteByPlan(..., bool $publicOnly = false)
$planQuery = BillingPackage::query()->where('key', $planKey);

if ($publicOnly) {
    $planQuery->where('is_public', true)->where('is_active', true);
}

$plan = $planQuery->first();

if ($plan === null) {
    // Same message as an unknown key — do NOT reveal that a hidden plan exists.
    throw new InvalidArgumentException("Plan inválido: {$planKey}.");
}
```

Then in `BillingPlanQuoteController::quote`, pass `publicOnly: true`. Leave every other
caller of `quoteByPlan` unchanged (defaults to `false`).

**Verify**: `php -l` clean on both edited files.

### Step 2: Tests

New feature test:
1. Seed a public+active plan P and a hidden (`is_public=false`) plan H.
2. `POST /api/billing/plan-quote` with P's key + valid modules → 200 with a quote.
3. `POST /api/billing/plan-quote` with H's key → 422 with the SAME "Plan inválido"
   message (does not leak that H exists, does not return pricing).
4. `POST` with an `is_active=false` public plan → 422.

Regression: `--filter=PreferentialSwap` still green (confirms authenticated hidden-plan
quoting still works).

**Verify**: `php artisan test --filter=BillingPlanQuote` passes; the H-key case fails against the pre-fix code.

### Step 3: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Public quote rejects `is_public=false` and `is_active=false` plans with the generic 422
- [ ] Authenticated checkout/swap paths unchanged (regression suite green)
- [ ] New test fails against pre-fix code
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] Only the service + controller + new test modified
- [ ] `plans/README.md` row updated

## STOP conditions

- The lookup no longer matches the excerpt (drift).
- `quoteByPlan` has callers you cannot enumerate (grep `quoteByPlan(` across
  `backend/`) — enumerate them all and confirm only the public controller passes
  `publicOnly: true` before finishing.
- The catalog uses a different visibility column name than `is_public`/`is_active` on
  `BillingPackage` (check the model/migration) — use the real column names.

## Maintenance notes

- Any future public pricing endpoint must pass `publicOnly: true`.
- Reviewer: confirm the 422 message is identical for "unknown key" and "hidden key" (no
  existence oracle).
- The landing already only ever sends public keys, so no landing change is needed.
