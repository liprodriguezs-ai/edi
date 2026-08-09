# Proposal: billing-plan-module-flags

> Phase: PROPOSE. Artifact store: hybrid — this file + Engram `sdd/billing-plan-module-flags/proposal`.
> Ground truth: exploration `sdd/billing-plan-module-flags/explore` (Engram #1742). Owner approved the intent;
> orchestrator pinned decisions 1–10 are baked in as constraints and are NOT relitigated here.

## Intent

Move `is_free` and `trial_days` off `plan_module_price` (keyed per `(plan, module, interval)`) into a new
central table `plan_module` (keyed per `(plan, module)`), so the invalid state — the same plan×module pair
carrying different flag values on its month vs year rows — becomes **structurally unrepresentable**.

Why now (business/technical rationale):

1. **2NF violation.** `is_free`/`trial_days` are properties of `(billing_package_id, billable_module_id)` — a
   strict subset of the row key `(billing_package_id, billable_module_id, interval)`. The same fact is stored
   twice (month + year rows) and can disagree.
2. **Dead year-row data.** The only `trial_days` consumer, `EntitlementService::startModuleTrial()`, resolves the
   row via `orderBy('interval')->first()` — "month wins" only because `'month' < 'year'` alphabetically. Year-row
   flag values are never read: dead data that can silently drift.
3. **Endpoint-only guard.** Today's protection is `PlanModulePriceMatrixRequest::withValidator()` (an interim
   cross-interval consistency check shipped earlier today). It guards exactly ONE endpoint; the seeder, a future
   bulk import, or any other writer can still create mismatched rows. This change supersedes and removes it.
4. Dev-only DB, no production data — the cheapest moment to normalize.

## Scope

### In scope — backend (Modules/SubscriptionPlan unless noted)

| File | Change |
|---|---|
| `database/migrations/` ×3 NEW | create `plan_module`, backfill copy, drop columns from `plan_module_price` |
| `app/Models/PlanModule.php` NEW | central model mirroring `PlanModulePrice` |
| `app/Models/PlanModulePrice.php` | drop `is_free`/`trial_days` from `$fillable` + `$casts` |
| `app/Models/BillingPackage.php`, `BillableModule.php` | add `plan_modules()` hasMany relations |
| `app/Http/Controllers/PlanModulePriceController.php` | price-matrix GET/PUT contract v2 (show/update) |
| `app/Http/Requests/PlanModulePriceMatrixRequest.php` | new rules; **delete `withValidator()` guard (37 lines)** |
| `app/Services/PlanPricingService.php` | 4 read sites derive flags from `plan_module` (resolver/eager-load) |
| `app/Services/BillingPackageStripeService.php` | `syncableRows()` free-module exclusion via `plan_module` lookup |
| `app/Services/EntitlementService.php` | `startModuleTrial()` trial resolution from `plan_module` row |
| `database/seeders/PlanModuleMatrixSeeder.php` | dual upsert (`plan_module` + prices); stops writing dropped columns |

### In scope — backend tests (11 files, per exploration blast radius)

`tests/Feature/Billing/Concerns/BillingMatrixFixtures.php` (chokepoint), `BillingPackageAdminCrudTest.php`
(2 whole tests DELETED — sanctioned), `BillingPackageStripeSyncTest.php` (rewrite + new join-semantics test),
`ModuleTrialAdminTest.php`, `SelfServeBillingPlanTest.php`, `OwnerSubscriptionLifecycleTest.php`,
`PlanDowngradeGuardTest.php`, `PlanPricingTest.php`, `PlanModuleMatrixTest.php`,
`MultiItemSubscriptionServiceTest.php`, `Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php`.
Plus one NEW integrity test (drift risk) and migration/backfill coverage.

### In scope — frontend (`frontend/src/modules/billingPackage/`)

| File | Change |
|---|---|
| `schema.ts` | `IPlanPriceCell` loses flags; `IPlanPriceMatrixModule` gains `is_free`/`trial_days`; `IPlanPriceCellInput` → v2 PUT payload types |
| `components/PlanPriceMatrix.tsx` | delete `buildInitialState()` collapse hack + `handleSave()` fan-out (UI state is ALREADY module-level: `freeByModule`/`trialByModule`) — net simplification |
| `services/BillingPackageResource.ts` | `PlanPriceMatrixResource.update()` sends the v2 payload |

### Out of scope (explicit non-goals)

- **Onboarding/base trial**: `AuthController`, `TenantProvisioningService`, and the `config('billing.trial_days')`
  platform fallback semantics — a separate trial concept; ZERO changes.
- **Public read contracts**: `GET /api/billing/plan-catalog`, `POST /api/billing/plan-quote`,
  `GET /api/tenant/{id}/billing` stay BYTE-IDENTICAL (`is_free` may still appear nested per interval, derived).
- **`landing/`**: zero changes. `Pricing.astro`'s plan-level `is_free` check is pre-existing dead code — leave as is.
- **Frontend `checkoutSubscription` + `tenant/billing` schemas**: zero changes. `IPlanPrice`/`IBillingPackage.plan_prices`
  type: zero runtime consumers — leave untouched.
- **Option B** (full 3NF: re-key `plan_module_price` on `plan_module_id` FK): rejected for review budget; recorded
  as the future path if drift ever bites (it would give DB-enforced integrity via cascade).
- **Parallel worktree** `backend/.claude/worktrees/fix+billing-base-scope-menu` and unrelated uncommitted WIP in
  either submodule: DO NOT TOUCH.

## Capabilities

> Contract with sdd-spec. No main `openspec/specs/` tree exists in this repo; capabilities live per-change
> under `changes/{name}/specs/{capability}/spec.md`.

### New Capabilities
- `billing-plan-modules`: plan×module offering flags (`is_free`, `trial_days`) as a single-row fact; price-matrix
  admin contract v2; derived per-interval read models for the pinned public contracts; Stripe-sync free-module
  exclusion semantics; trial-days resolution.

### Modified Capabilities
- None (no existing main-tree specs; public billing contracts are pinned unchanged).

## Approach (Option A — pinned)

### 1. New central table `plan_module` (DDL sketch, house idiom)

```php
Schema::create('plan_module', function (Blueprint $table) {
    $table->comment('Billing v2: interval-independent link of a billable_module offered within a plan (billing_package); owns the per-(plan,module) flags formerly duplicated per interval on plan_module_price');
    BaseModel::columns($table, false); // CENTRAL — no BelongsToTenant

    $table->uuid('billing_package_id')->comment('FK to billing_package (the plan)');
    $table->uuid('billable_module_id')->comment('FK to billable_module (the sellable feature)');
    $table->boolean('is_free')->default(false)->comment('Whether this module is included at no cost in the plan (price pending / free)');
    $table->unsignedSmallInteger('trial_days')->nullable()->comment("Per-(plan,module) trial override; fallback config('billing.trial_days')");

    $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
    $table->foreign('billable_module_id')->references('id')->on('billable_module')->cascadeOnDelete();
    $table->unique(['billing_package_id', 'billable_module_id']);
});
```

`plan_module_price` keeps its columns, FKs, and unique `(plan, module, interval)` UNCHANGED — it only drops
`is_free` + `trial_days`. New model `PlanModule` mirrors `PlanModulePrice` (rich-pivot pattern, like
`TenantEntitlement`); relations `BillingPackage::plan_modules()` / `BillableModule::plan_modules()` (hasMany,
plural per naming convention), plus `plan()` / `billable_module()` belongsTo on `PlanModule`.

### 2. Read-model derivation (pinned contracts stay byte-identical)

`PlanPricingService` loads the plan's `plan_module` rows once per request (keyed by `billable_module_id`) and
denormalizes `is_free` into BOTH interval objects when building `plan-catalog` / `plan-quote` /
`tenant/{id}/billing` responses — same JSON keys, same nesting, single source of truth. Zero-amount logic
(`quoteByPlan`, `priceForModule`, `mapPlanModules`) branches on the `plan_module` flag instead of the row column.

### 3. Price-matrix contract v2 (the ONLY shape change)

GET hoists flags to the module row; PUT mirrors GET (per-module input, cells carry amounts only):

```jsonc
// GET response module row                      // PUT request body
{ "billable_module_id": "...", "key": "...",    { "modules": [ {
  "name": "...", "is_base": false,                  "billable_module_id": "...",
  "is_free": false, "trial_days": 7,                "is_free": false, "trial_days": 7,
  "cells": {                                        "cells": {
    "month": { "unit_amount": ..., "currency": "MXN",   "month": { "unit_amount": ..., "currency": "MXN" },
               "stripe_price_id": "...", /* read-only */ "year": { ... }
               "is_active": true },                 } } ] }
    "year":  { ... } } }
```

The React editor (`PlanPriceMatrix.tsx`) already models module-level state — the wire contract catches up to it.
The mismatched-flags state becomes impossible to EXPRESS in the request, so `withValidator()` and its 2 tests
(`test_price_matrix_rejects_mismatched_{is_free,trial_days}_across_intervals`) are deleted, not adapted —
sanctioned deletion, documented here.

### 4. Trial + Stripe consumers

- `EntitlementService::startModuleTrial()` reads `trial_days` from the `plan_module` row
  (`?? config('billing.trial_days')` fallback stays) — kills the fragile `orderBy('interval')->first()` lookup.
- `BillingPackageStripeService::syncableRows()` keeps excluding free modules via a lookup/join against
  `plan_module`. INNER vs LEFT semantics must be an explicit, tested decision: what happens to price rows whose
  `plan_module` row is missing (exclude-and-flag recommended — fail loud, never silently sync a free module).

### 5. Open design question → sdd-design (framed, default proposed — do not implement here)

**Should "module offered by plan" re-point from price-row existence to `plan_module`-row existence?**
Affected: `PlanPricingService` availability checks, `startModuleTrial()` guard (a). Proposed default: YES for
interval-independent questions ("is module in plan", trial gate) — that is exactly the 2NF argument again, and
trial resolution reads `plan_module` anyway; KEEP price-row existence for interval-specific paths (quote cells,
catalog amounts, Stripe row selection), with flags joined in. Backfill makes both predicates extensionally
equivalent at cutover; require a parity test (mirroring the existing `recompute()` vs `syncFromPlan` pattern)
plus the drift integrity test below. sdd-design decides and records the rationale.

## Migration sequence (append-only, NO database reset)

1. `create_plan_module_table` — DDL above; `down()` drops the table.
2. `backfill_plan_module_from_plan_module_price` — data copy via Eloquent (central tables, no tenancy): for each
   distinct `(billing_package_id, billable_module_id)`, take `orderBy('interval')->first()` (month wins
   alphabetically — the same deterministic trick the code relies on today) and insert its `is_free`/`trial_days`
   into `plan_module`. **Must survive pairs that only have a month row OR only a year row** (single-row pairs:
   that row wins). No-op on an empty DB (fresh-install path). `down()` deletes the backfilled rows.
3. `drop_flags_from_plan_module_price_table` — `dropColumn(['is_free','trial_days'])`; `down()` re-adds both
   columns WITH their `->comment()` (rule 13 applies to alters); flag values after a rollback are restored by
   re-seeding — accepted, dev-only.

Cutover expectations: code + all 3 migrations land together in the working tree; existing dev DBs run the 3
forward in one `php artisan migrate`; fresh DBs migrate then seed (seeder now writes `plan_module` and no longer
writes the dropped columns — this is the second backfill source per pinned decision 9). After any
`migrate:fresh`, run `php artisan billing:sync-stripe` (project convention). Never edit the original create
migration.

## Risks and mitigations (carried from exploration, ranked)

| # | Risk | Mitigation |
|---|---|---|
| 1 | Scope creep onto public contract shapes | Pinned constraint: 3 read endpoints byte-identical; existing shape assertion (`PlanPricingTest` L372) must pass unmodified |
| 2 | ~650 est. lines > default 400 review budget | Owner authorized 800; sdd-tasks MUST still emit the workload-guard forecast and plan chained slices (backend / frontend) |
| 3 | Lookup-not-FK drift between `plan_module` and price rows (Option A tradeoff) | Funnel writes through Controller + Seeder only; NEW integrity test: active price rows with missing `plan_module` row has defined behavior; Option B recorded as escalation path |
| 4 | Stripe sync join semantics (INNER silently excludes / LEFT silently includes) | Explicit decision in design + dedicated test "price rows exist, plan_module row missing" |
| 5 | "Offered by plan" semantics change behavior | Framed as design question §5 with parity-test requirement |
| 6 | Parallel worktree `fix+billing-base-scope-menu` holds copies of every touched file | DO NOT TOUCH (pinned); sdd-apply re-checks its status before editing the main tree |
| 7 | Implementer instinct to "fix" the 2 obsolete guard tests | Deletion is sanctioned and documented (Intent §3, Approach §3) — delete, don't patch |

## Test strategy summary

- **Chokepoint first**: `BillingMatrixFixtures::makePlanModulePrice()` learns to upsert the matching
  `plan_module` row — one edit makes the ~6 indirect suites (quote visibility, Stripe parity, tenant billing
  read, cross-tenant isolation, entitlement consistency, EntitlementService unit) pass with a green re-run only.
- **Contract v2**: reshape `BillingPackageAdminCrudTest` matrix CRUD; delete the 2 unrepresentable-scenario tests.
- **Stripe**: rewrite `BillingPackageStripeSyncTest` L155 direct query; add the join-semantics test (risk 4).
- **Integrity**: new drift test (risk 3); migration/backfill coverage for month+year, month-only, year-only pairs.
- **Local fixtures**: update the 4 files with private helpers (`SelfServeBillingPlanTest`,
  `OwnerSubscriptionLifecycleTest`, `PlanDowngradeGuardTest`, `UnitLimitServiceTest`); redirect
  `PlanPricingTest` direct `->update(['is_free'=>true])` writes to `plan_module`; new seeder assertions in
  `PlanModuleMatrixTest`; `ModuleTrialAdminTest` L158 override site.
- Run the Billing suite as a SINGLE process on host php (shared `testing` DB — project rule).

## Delivery and rollback

- **NO commits, NO PRs** — all edits stay in the working tree for owner review. Review budget: 800 changed lines
  (owner-authorized; estimate ~650).
- Rollback (code): discard working-tree edits (nothing committed). Rollback (DB): `migrate:rollback` ×3 steps
  restores the schema; flag values via `db:seed`; or `migrate:fresh --seed` + `billing:sync-stripe` (dev-only).

## Success criteria

- [ ] `plan_module_price` has no `is_free`/`trial_days` columns; `plan_module` enforces unique `(plan, module)`.
- [ ] The 3 public read contracts are byte-identical (existing shape assertions pass unmodified).
- [ ] Price-matrix GET/PUT v2 round-trips module-level flags; `withValidator()` guard fully removed.
- [ ] `startModuleTrial()` resolves trial from `plan_module`; no `orderBy('interval')` trial dependency remains.
- [ ] Stripe sync excludes free modules with tested missing-row semantics.
- [ ] Seeder + backfill produce `plan_module` rows for all existing dev data (incl. single-interval pairs).
- [ ] Full Billing test suite green (single-process run).

## Proposal question round (auto mode — assumptions recorded for owner review)

Execution mode `auto` blocked a live question round; these assumptions ship unless the owner corrects them:

1. **Free modules keep their zero-amount price rows** — `plan_module` adds flags; we did NOT reinterpret
   "free ⇒ no price rows". Seeder keeps emitting price rows for free modules as today.
2. **Decision §5 default** (offered-by-plan = `plan_module` row for interval-independent checks) is a
   recommendation — owner/design review may override before apply.
3. **PUT v2 makes `is_free`/`trial_days` per-module fields** (no partial per-cell writes) — matches the UI's
   existing module-level state model.
4. **Rollback data-loss tolerance** on migration 3 `down()` (flags restored only by re-seed) accepted as dev-only.
