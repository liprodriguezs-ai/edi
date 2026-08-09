# Design: billing-plan-module-flags

> Phase: DESIGN. Artifact store: hybrid — this file + Engram `sdd/billing-plan-module-flags/design`.
> Inputs: proposal (constraints 1-10 are law), exploration #1742, and the live code of every seam (read, not guessed).
> Execution mode was `auto`: the 7 open decisions below are RESOLVED with rationale. Do NOT implement from the proposal — implement from this doc.

## Technical Approach

Option A as pinned: new central table `plan_module` owns `is_free`/`trial_days` per `(billing_package_id, billable_module_id)`; `plan_module_price` keeps its key/unique/FKs and only drops the two columns. Readers derive flags via batched lookups (no Eloquent relation exists between the two tables — 2-column match). The ONLY availability predicate that re-points to `plan_module` is `startModuleTrial()` guard (a); every interval-specific and contact-sales predicate stays on price rows. The 3 public read contracts stay byte-identical; only the price-matrix GET/PUT reshapes (v2).

## Architecture Decisions

### ADR-1 — Offered-by-plan semantics (proposal §5): narrow re-point

**Choice**: `plan_module` row existence answers ONLY the interval-independent question in `EntitlementService::startModuleTrial()` guard (a). Every other call site keeps price-row logic:

| Call site | Predicate after | Why |
|---|---|---|
| `startModuleTrial()` guard (a) | `plan_module` row exists → else 422 | The 2NF fact "module offered by plan"; trial reads flags from this row anyway |
| `quoteByPlan()` / `priceForModule()` "not available" | ACTIVE price row per (plan, module, **interval**) — unchanged | Interval-specific: a month-only module is genuinely unavailable for a year quote |
| `activePlans()` / `planByKey()` `whereHas('plan_prices')`, `isContactSales()`, `contactSalesPlan()` | unchanged (price rows) | Contact-sales = absence of an **ACTIVE** price row; `plan_module` has no `is_active`, so it cannot express this. Do NOT touch |
| `pricedModuleKeys()`, Stripe row selection | unchanged | Interval-specific row picking |

**Trial interval metadata** (the fact the proposal's "kill orderBy" left open — `grant()` stamps `tenant_entitlement.interval`, nullable): explicit month-preferred pluck over the pair's ACTIVE price rows, replacing the alphabetical accident:

```php
$intervals = PlanModulePrice::query()
    ->where('billing_package_id', $plan->id)
    ->where('billable_module_id', $module->id)
    ->where('is_active', true)
    ->pluck('interval');
$interval = $intervals->contains(PlanModulePrice::INTERVAL_MONTH)
    ? PlanModulePrice::INTERVAL_MONTH
    : $intervals->first(); // null when the pair has no active price row (drift-only state)
```

Identical observable value to today for every well-formed dataset (month wins; year-only pair → 'year'). The ONE divergence: pair with `plan_module` row but zero active price rows — today 422, after: trial allowed with `interval = null`. Defensible (a trial sells nothing; publication is a selling concern) and pinned by the integrity test (ADR-7).
**Alternatives rejected**: keep guard (a) on price rows (leaves the 2NF question answered by the wrong table; proposal default says re-point); re-point contact-sales checks too (breaks the deliberate "priceless = consultative" semantics).
**Parity test**: on fixture/seeded data assert `plan_module row exists ⇔ ≥1 active price row exists` for all pairs, and that trial accept/reject matches a price-row oracle (mirrors the `recompute()` vs `syncFromPlan` parity pattern).

### ADR-2 — Stripe sync join semantics (risk 4): PHP-side partition, exclude-and-surface

**Choice**: no SQL join. `syncableRows()` fetches candidate rows (active row/plan/module — the `is_free` where-clause deleted), batch-fetches `plan_module` keyed by `"{plan_id}|{module_id}"`, then partitions in PHP:

- pair HAS flags row, `is_free = false` → syncable;
- pair HAS flags row, `is_free = true` → excluded silently (the feature, as today);
- pair has NO flags row → **excluded + `Log::warning`** listing `plan×module×interval` pairs — never silently sync, never silently treat as paid.

**Alternatives rejected**: `whereExists` INNER-shaped subquery (silently swallows the missing-row case — exactly risk 4); LEFT-shaped "missing = not free" (silently syncs a module whose flags were never established). `whereHas` impossible: no Eloquent relation.
**Rationale**: table is dozens of rows; two queries + partition is readable, testable, and yields the surfaced list for free. Command output stays shape-stable; `Log::warning` is the surface (asserted in the dedicated test).

### ADR-3 — PUT v2 request: same class, `modules` rules, prohibited legacy keys

**Choice**: keep the name `PlanModulePriceMatrixRequest` (same endpoint/purpose; a rename inflates the diff for zero information). Delete `withValidator()` (37 lines) — the invalid state is now unexpressable. Array-style rules (module convention):

```php
public function rules(): array
{
    return [
        'modules' => ['required', 'array', 'min:1'],
        'modules.*.billable_module_id' => ['required', 'uuid', 'exists:billable_module,id'],
        'modules.*.is_free' => ['sometimes', 'boolean'],
        'modules.*.trial_days' => ['nullable', 'integer', 'min:0', 'max:730'],
        'modules.*.cells' => ['required', 'array:month,year'], // unknown interval keys → 422
        'modules.*.cells.month' => ['required', 'array'],
        'modules.*.cells.year' => ['required', 'array'],
        'modules.*.cells.*.unit_amount' => ['required', 'integer', 'min:0'],
        'modules.*.cells.*.currency' => ['sometimes', 'string', 'size:3'],
        // A stale v1 SPA bundle sending per-cell flags must fail LOUD (422), not have
        // its flags silently dropped and module-level is_free default to false.
        'modules.*.cells.*.is_free' => ['prohibited'],
        'modules.*.cells.*.trial_days' => ['prohibited'],
    ];
}
```

Both intervals REQUIRED per module (the UI already always sends both; keeps pair-completeness so the ADR-1 predicates stay extensionally equivalent). Other unknown keys: ignored (framework default — don't fight it; only the two dangerous legacy keys are prohibited).

`update()` — ONE transaction, flags row first, then both cells; Stripe ids/`is_active` untouched exactly as today:

```php
public function update(PlanModulePriceMatrixRequest $request, BillingPackage $billingPackage): JsonResponse
{
    $modules = $request->validated('modules');
    DB::transaction(function () use ($modules, $billingPackage): void {
        foreach ($modules as $entry) {
            PlanModule::updateOrCreate(
                ['billing_package_id' => $billingPackage->id, 'billable_module_id' => $entry['billable_module_id']],
                ['is_free' => (bool) ($entry['is_free'] ?? false), 'trial_days' => $entry['trial_days'] ?? null],
            );
            foreach (PlanModulePrice::INTERVALS as $interval) {
                $cell = $entry['cells'][$interval];
                PlanModulePrice::updateOrCreate(
                    ['billing_package_id' => $billingPackage->id, 'billable_module_id' => $entry['billable_module_id'], 'interval' => $interval],
                    ['unit_amount' => (int) $cell['unit_amount'], 'currency' => strtoupper($cell['currency'] ?? 'MXN')],
                );
            }
        }
    });
    return $this->show($billingPackage);
}
```

`show()` hoists flags to the module row (batch: `PlanModule::where('billing_package_id', …)->get()->keyBy('billable_module_id')`; missing row renders `is_free: false, trial_days: null`); cells drop the two fields, keep `id/unit_amount/currency/is_active/stripe_price_id`; `default_trial_days` stays.

### ADR-4 — PlanPricingService flag resolution: batched lookups, no relation, drift ⇒ not-free

**Choice**: private helper on `PlanPricingService` — `planModuleFlags(BillingPackage $plan): Collection` (`PlanModule::where('billing_package_id', $plan->id)->get()->keyBy('billable_module_id')`, ONE query per quote). Per site:

- `quoteByPlan()`: call helper once before the module loop; `$isFree = (bool) ($flags->get($module->id)?->is_free ?? false);` replaces `$price->is_free` at L158/L170.
- `buildPlanCatalog()`: one batch query for ALL plans keyed `"{plan_id}|{module_id}"`, passed into `mapPlanModules($planPrices, $flagsByPair)`; L492-493 denormalize the module flag into BOTH interval objects — JSON byte-identical.
- `priceForModule()`: single `PlanModule::…->first()` (callers pass one/few modules; no loop N+1).
- `EntitlementService` does its own direct query (one call site; no premature shared resolver).

**Missing-row (drift) read behavior**: `is_free = false` — a free grant must be an established fact, never an inferred one (consistent with ADR-2's refusal to publish). The integrity test pins this.
**Cache**: `PLAN_CATALOG_CACHE_KEY` unchanged; flags live inside the cached payload. Seeder already ends with `Cache::forget(...)` — its new `plan_module` writes happen earlier in the same `run()`, so busting is covered. The admin PUT has never busted this cache (≤300 s TTL staleness pre-exists and is orthogonal); out of scope, noted as follow-up.
**Alternative rejected**: Option-B style `belongsTo` via FK re-key — pinned out of scope (review budget).

### ADR-5 — Migration trio: names, backfill mechanics, down()

| # | File (`Modules/SubscriptionPlan/database/migrations/`) | up() | down() |
|---|---|---|---|
| 1 | `2026_07_22_000001_create_plan_module_table.php` | Proposal DDL verbatim (table comment, `BaseModel::columns($table,false)`, commented columns, 2 FKs cascade, unique pair) **plus `$table->index('billable_module_id')`** — the unique only covers the plan-side leftmost; `BillableModule::plan_modules()` queries the module side (FK-index rule) | `dropIfExists('plan_module')` |
| 2 | `2026_07_22_000002_backfill_plan_module_from_plan_module_price.php` | `DB::table('plan_module_price')` read ordered by `(billing_package_id, billable_module_id, interval asc)`; first row per pair wins (month before year; single-interval pairs: that row); insert via `DB::table('plan_module')` (`id` from DB default, timestamps `now()`, audit columns null). No-op on empty table | `DB::table('plan_module')->delete()` (all rows; dev-only, accepted) |
| 3 | `2026_07_22_000003_drop_flags_from_plan_module_price_table.php` | `Schema::table` → `dropColumn(['is_free','trial_days'])` | Re-add BOTH columns with the original create-migration comment strings verbatim (rule 13 applies to alters), `is_free` default false, `trial_days` nullable; no `->after()` (Postgres ignores it). Values restored only by re-seed — accepted, dev-only |

**Query builder, not Eloquent — deliberate deviation from the proposal's sketch** (decision 5 delegates this): this very change removes `is_free`/`trial_days` from `PlanModulePrice::$fillable`/casts, so a migration reading flags through the model would depend on model state the same working tree deletes. `DB::table` freezes the migration at schema-of-that-point, which is what migrations are for. Central tables — no tenancy scope in play; migrations are the sanctioned exception to the "no `DB::`" rule (it protects tenant data paths).
**No chunking**: bounded catalog table — 5 plans × 1 module × 2 intervals today, dozens at most by construction. A chunked iterator is noise, not safety. Cutover/rollback: exactly as pinned in the proposal (append-only, fresh DBs seed, `billing:sync-stripe` after `migrate:fresh`).

### ADR-6 — Frontend v2: types, simplifications, payload

`schema.ts` final shapes (flags MOVE between existing interfaces; `IPlanPrice` + `IBillingPackage.plan_prices` stay untouched as pinned — zero runtime consumers):

```ts
export interface IPlanPriceCell {            // loses is_free + trial_days
  id: string
  unit_amount: number      // minor units (centavos)
  currency: string
  is_active: boolean
  stripe_price_id: string | null
}
export interface IPlanPriceMatrixModule {    // gains the module-level flags
  billable_module_id: string
  key: string
  name: string
  is_base: boolean
  is_free: boolean
  trial_days: number | null
  cells: { month: IPlanPriceCell | null; year: IPlanPriceCell | null }
}
export interface IPlanPriceCellInput { unit_amount: number; currency?: string }  // reshaped: amounts only
export interface IPlanPriceMatrixModuleInput {
  billable_module_id: string
  is_free: boolean
  trial_days: number | null   // null = platform default (default_trial_days)
  cells: { month: IPlanPriceCellInput; year: IPlanPriceCellInput }
}
```

`PlanPriceMatrix.tsx` — verified by grep: the ONLY wire-shape touch points are `buildInitialState()` (L103-129) and `handleSave()` (L371-408); all render sites read editor state (`freeByModule`/`trialByModule`) and are untouched.
- `buildInitialState()`: `freeByModule[id] = mod.is_free`; `trialByModule[id] = mod.trial_days != null ? String(mod.trial_days) : ''` — the `.some()` OR-collapse and `month ?? year` fallback (and their "dato legado inválido" comments) are DELETED.
- `handleSave()`: build `IPlanPriceMatrixModuleInput[]` — one entry per module carrying the flags once + `cells: { month, year }` with amounts; the per-interval fan-out loop is DELETED.
- `BillingPackageResource.ts`: `update: async (planId, modules: IPlanPriceMatrixModuleInput[])` sending `{ modules }`.

**Zero-leakage confirmation**: `checkoutSubscription/schema.ts`, `tenant/billing/schema.ts`, `landing/Pricing.astro` consume the pinned per-interval nested `is_free` which the backend keeps emitting — zero edits; apply phase re-greps `is_free|trial_days` outside `modules/billingPackage/` to prove no leak.

### ADR-7 — Test plan (see Testing Strategy for the per-file table)

**Fixture invariant (the chokepoint rule)**: every helper that creates a `plan_module_price` row MUST upsert the pair's `plan_module` row. `BillingMatrixFixtures::makePlanModulePrice()` keeps its signature and splits `$overrides`: `is_free`/`trial_days` keys route to `PlanModule::updateOrCreate` for the pair; the rest to the price row — so the ~6 indirect suites and every local-helper test keep their one-line calling convention. `cleanBillingTables()` gains `DB::table('plan_module')->delete()` (after `plan_module_price`, before `billable_module`).
**Backfill migration test technique**: in-test `Schema::table` re-adds the two dropped columns, seeds the three pair shapes (month+year, month-only, year-only) with flags, empties `plan_module`, then `$migration = include <path>; $migration->up();` and asserts the copied flags; teardown drops the columns again. Self-contained in one class.
**Runs**: Billing suite as a SINGLE process on host php (`php artisan test` — shared `testing` DB rule; NOT sail).

## Data Flow

```
                       plan_module (flags: is_free, trial_days)          plan_module_price (amounts, stripe ids, is_active)
                          │ 1 row per (plan, module)                        │ 1 row per (plan, module, interval)
   writes:  PlanModulePriceController::update ── ONE tx ──┬─ upsert flags ──┘  upsert both cells
            PlanModuleMatrixSeeder ───────────────────────┘  (the ONLY two writers → drift funnel)
   reads:   PlanPricingService ── batched keyBy lookups ──→ denormalizes is_free into BOTH interval objects
                                                            (plan-catalog / plan-quote / tenant billing: byte-identical)
            BillingPackageStripeService::syncableRows ────→ partition: free→skip, missing→skip+warn
            EntitlementService::startModuleTrial ─────────→ guard (a) + trial_days from plan_module
```

## File Changes

| File | Action | Batch |
|---|---|---|
| `…/migrations/2026_07_22_00000{1,2,3}_*.php` | Create ×3 (ADR-5) | 1 |
| `…/Models/PlanModule.php` | Create — mirrors `PlanModulePrice` idiom: `CentralConnection`, `#[ObservedBy([GlobalObserver::class])]`, `$table='plan_module'`, fillable `[billing_package_id, billable_module_id, is_free, trial_days]`, casts `[is_free=>boolean, trial_days=>integer]`, `plan()`/`billable_module()` belongsTo | 1 |
| `…/Models/PlanModulePrice.php` | Modify — drop 2 fillable + 2 casts; docblock note | 1 |
| `…/Models/BillingPackage.php`, `BillableModule.php` | Modify — add `plan_modules(): HasMany` | 1 |
| `…/Http/Requests/PlanModulePriceMatrixRequest.php` | Modify — ADR-3 rules; DELETE `withValidator()` | 2 |
| `…/Http/Controllers/PlanModulePriceController.php` | Modify — `show()`/`update()` v2 (ADR-3); header docblock | 2 |
| `…/Services/PlanPricingService.php` | Modify — ADR-4 (helper + 4 read sites) | 2 |
| `…/Services/BillingPackageStripeService.php` | Modify — `syncableRows()` partition (ADR-2) | 2 |
| `…/Services/EntitlementService.php` | Modify — `startModuleTrial()` (ADR-1) | 2 |
| `…/seeders/PlanModuleMatrixSeeder.php` | Modify — new `upsertPlanModule()` per (plan,module) before the two `upsertPrice()` calls; `upsertPrice()` stops writing flags | 2 |
| 11 test files + `BillingMatrixFixtures.php` + 2 new test classes | Modify/Create per Testing Strategy; 2 sanctioned whole-test DELETIONS | 3 |
| `frontend/src/modules/billingPackage/{schema.ts, components/PlanPriceMatrix.tsx, services/BillingPackageResource.ts}` | Modify — ADR-6 | 4 |

**Implementation batches** (working tree only — NO commits/PRs; owner review budget 800 changed lines, estimate ~670): Batch 1 schema+models (~160) → Batch 2 write/read paths (~250) → Batch 3 backend tests (~200) → Batch 4 frontend (~60). Each batch leaves a coherent reviewable unit; suite green after Batch 3, frontend build green after Batch 4.

## Testing Strategy

| File | Change |
|---|---|
| `Concerns/BillingMatrixFixtures.php` | Chokepoint (ADR-7): flag-splitting upsert + `cleanBillingTables()` addition |
| `BillingPackageAdminCrudTest.php` | Reshape matrix CRUD to v2 `modules` payload; **DELETE** `test_price_matrix_rejects_mismatched_{is_free,trial_days}_across_intervals` (sanctioned — scenario unrepresentable); reshape the "consistent is_free" test into a module-level round-trip; add prohibited-legacy-keys 422 test |
| `BillingPackageStripeSyncTest.php` | L155 direct `where('is_free', false)` query → partition-based expectations; L179-180 overrides via fixture; **NEW** join-semantics test: price rows exist + `plan_module` missing → excluded + `Log::warning` asserted |
| `ModuleTrialAdminTest.php` | L158 `['trial_days'=>7]` override routes to `plan_module` via fixture; L69 behavioral assert unchanged; parity assertions (ADR-1) live here or in the integrity test |
| `SelfServeBillingPlanTest.php`, `OwnerSubscriptionLifecycleTest.php`, `PlanDowngradeGuardTest.php`, `UnitLimitServiceTest.php` | Local/private helpers adopt the fixture invariant (create the pair's `plan_module` row); drop raw `is_free` literals from price arrays |
| `PlanPricingTest.php` | L68/99/193 `->update(['is_free'=>true])` → write `PlanModule`; **L372 public JSON shape assertion passes UNMODIFIED** (constraint-1 proof) |
| `PlanModuleMatrixTest.php` | New seeder assertions: one `plan_module` row per (plan, base) with `is_free=false`/`trial_days=null`; price rows carry no flag columns |
| `MultiItemSubscriptionServiceTest.php` | L175 month-only override → fixture invariant |
| **NEW** `PlanModuleIntegrityTest.php` | Drift behavior pinned: active price rows + missing `plan_module` ⇒ quote/catalog `is_free=false`, sync excludes+warns, trial guard (a) 422s; plus the ADR-1 parity assertions on well-formed data |
| **NEW** `PlanModuleBackfillMigrationTest.php` | ADR-7 technique; three pair shapes + empty-table no-op |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. (Schema + service + HTTP-contract change only; `billing:sync-stripe` is an existing artisan command whose process boundary is untouched.)

## Migration / Rollout

Per proposal (pinned): 3 append-only migrations land WITH the code in one working tree; existing dev DBs run `php artisan migrate` (3 steps forward); fresh DBs migrate → seed → `php artisan billing:sync-stripe`. Rollback: discard working tree (nothing committed) + `migrate:rollback` ×3; flag values via re-seed. Never edit the original create migration.

## Risk Table (updated)

| # | Risk | Status after design |
|---|---|---|
| 1 | Public contract scope creep | RESOLVED by construction: flags sourced upstream of untouched serializers; `PlanPricingTest` L372 must pass unmodified |
| 2 | ~670 lines vs 400 default budget | Owner authorized 800; batches sized above; sdd-tasks MUST still emit the workload-guard forecast lines |
| 3 | `plan_module` ↔ price-row drift (no FK) | MITIGATED: two writers only (controller tx + seeder), fixture invariant, `PlanModuleIntegrityTest` pins all three read behaviors; Option B recorded as escalation |
| 4 | Stripe sync join semantics | RESOLVED: ADR-2 partition, exclude-and-surface, dedicated test |
| 5 | Offered-by-plan behavior change | RESOLVED: ADR-1 narrow re-point + parity test; residual divergence (trial allowed when all price rows inactive) documented + pinned by test |
| 6 | Parallel worktree `fix+billing-base-scope-menu` | UNCHANGED: do not touch; sdd-apply re-checks its status before editing main tree |
| 7 | Instinct to "fix" the 2 obsolete guard tests | UNCHANGED: deletion sanctioned (ADR-3 makes the scenario unexpressable) |
| 8 | NEW — stale v1 SPA bundle posts per-cell flags | MITIGATED: `modules` required + `prohibited` rules → loud 422, never a silent free→paid flip |
| 9 | NEW — backfill test touches live schema | CONTAINED: column re-add/drop confined to one self-contained test class with teardown |

## Open Questions

None — all seven delegated decisions are resolved above. One out-of-scope follow-up recorded (not a blocker): the admin PUT has never busted `PLAN_CATALOG_CACHE_KEY` (≤300 s staleness pre-exists; unchanged by this refactor).
