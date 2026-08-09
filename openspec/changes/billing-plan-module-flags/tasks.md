# Tasks: billing-plan-module-flags

> Word budget: this artifact exceeds the skill's default 530-word guideline, same accepted
> tradeoff already logged on this change's spec.md (~870w vs 650w budget) — a 30+ file
> brownfield refactor under an 800-line owner-authorized review budget needs per-file,
> per-line concreteness. Verified against live code (Read + codegraph), not guessed; line
> numbers are current as of this writing and may drift a few lines during implementation.

## Requirement Legend (spec `billing-plan-modules`)
R1 plan_module source of truth · R2 backfill preserves flags · R3 price-matrix v2 shape ·
R4 public contracts byte-identical · R5 trial resolves from plan_module · R6 Stripe sync
excludes free/missing · R7 offered-by-plan predicate · R8 seeder writes plan_module ·
R9 orphaned rows are a defined/tested state

## Parallel vs Sequential
Phase 4 (frontend) has zero backend dependency — run in parallel with Phases 1-3. Phase 1:
1.4-1.7 parallel after 1.1. Phase 2: 2.1-2.3 sequential (same files); 2.4-2.7 sequential
(same file, PlanPricingService.php); 2.8/2.9/2.10 parallel with 2.4-2.7, all need only 1.4.
Phase 3: 3.1 is a hard chokepoint — do first; 3.2-3.5, 3.8, 3.10-3.11 then parallel; 3.6/3.7
run last (re-grep needs every other file settled). Phase 5 is strictly sequential.

## Phase 1: Schema + Models (Batch 1, ~160 lines)

- [x] 1.1 Create `Modules/SubscriptionPlan/database/migrations/2026_07_22_000001_create_plan_module_table.php` — DDL per proposal (table comment first in closure, `BaseModel::columns($table,false)`, `billing_package_id`/`billable_module_id` uuid+comment, `is_free` boolean default false+comment, `trial_days` unsignedSmallInteger nullable+comment, 2 `->foreign()->cascadeOnDelete()`, `unique(['billing_package_id','billable_module_id'])`, **plus** `$table->index('billable_module_id')` per ADR-5). `down()`: `dropIfExists`. Done: file exists, `php artisan migrate:status` lists it pending. (R1)
- [x] 1.2 Create `..._000002_backfill_plan_module_from_plan_module_price.php` — `DB::table()` (query builder, not Eloquent — the flags leave `PlanModulePrice::$fillable` this same tree). Read `plan_module_price` ordered `(billing_package_id, billable_module_id, interval asc)`; first row per pair wins (month<year; single-interval pairs survive); insert into `plan_module`. No-op on empty table, no chunking. `down()`: delete all `plan_module` rows. Done: file mirrors ADR-5 pseudocode exactly. (R2, dep 1.1)
- [x] 1.3 Create `..._000003_drop_flags_from_plan_module_price_table.php` — `dropColumn(['is_free','trial_days'])`. `down()`: re-add both WITH the exact `->comment()` text from `2026_07_01_000003_create_plan_module_price_table.php` (rule 13), no `->after()`. Done: down() comment strings match source verbatim. (R1, dep 1.2)
- [x] 1.4 Create `Modules/SubscriptionPlan/app/Models/PlanModule.php` — mirror `PlanModulePrice.php`: `CentralConnection`, `#[ObservedBy([GlobalObserver::class])]`, `$table='plan_module'`, `$fillable=[billing_package_id,billable_module_id,is_free,trial_days]`, `casts()` is_free:boolean/trial_days:integer, `plan()`/`billable_module()` belongsTo. Done: Pint clean. (R1, dep 1.1)
- [x] 1.5 Modify `Modules/SubscriptionPlan/app/Models/PlanModulePrice.php` — remove `is_free`/`trial_days` from `$fillable` (L38,40) and `casts()` (L47,49). Done: `grep -n "is_free\|trial_days" PlanModulePrice.php` → 0 matches. (R1, dep 1.3)
- [x] 1.6 Modify `Modules/SubscriptionPlan/app/Models/BillingPackage.php` — add `plan_modules(): HasMany` (`hasMany(PlanModule::class)`) beside existing `plan_prices()`. Done: relation resolves in tinker. (R1, dep 1.4)
- [x] 1.7 Modify `Modules/SubscriptionPlan/app/Models/BillableModule.php` — same `plan_modules(): HasMany` addition. Done: relation resolves. (R1, dep 1.4)

## Phase 2: Write/Read Paths (Batch 2, ~250 lines)

- [x] 2.1 Modify `Modules/SubscriptionPlan/app/Http/Requests/PlanModulePriceMatrixRequest.php` — replace `rules()` with ADR-3's `modules` shape (`cells.*.is_free`/`cells.*.trial_days` = `['prohibited']`; `cells` = `['required','array:month,year']`; both `cells.month`/`cells.year` required). DELETE `withValidator()` (current L35-71, 37 lines) and the now-unused `Validator`, `Rule`, `PlanModulePrice` imports (interval is no longer a flat validated field). Done: `withValidator()` absent, no unused-import warnings. (R3)
- [x] 2.2 Modify `PlanModulePriceController.php::show()` — batch `PlanModule::where('billing_package_id',$billingPackage->id)->get()->keyBy('billable_module_id')`; hoist `is_free`/`trial_days` onto each module row (false/null when missing); drop them from `$cells[$interval]` (keep id/unit_amount/currency/is_active/stripe_price_id); keep `default_trial_days`. Done: GET response module rows carry flags, cells don't. (R3, dep 1.4)
- [x] 2.3 Modify `PlanModulePriceController.php::update()` — one `DB::transaction`: loop `$request->validated('modules')`; `PlanModule::updateOrCreate([plan,module],[is_free,trial_days])` then per `PlanModulePrice::INTERVALS` `PlanModulePrice::updateOrCreate([plan,module,interval],[unit_amount,currency])`; Stripe ids/is_active untouched; return `$this->show(...)`. Done: PUT round-trips a v2 payload, Stripe ids preserved. (R3, dep 2.1, 2.2)
- [x] 2.4 Modify `Modules/SubscriptionPlan/app/Services/PlanPricingService.php` — add private `planModuleFlags(BillingPackage $plan): Collection` (one query, `keyBy('billable_module_id')`). Done: compiles. (R4, R5, dep 1.4)
- [x] 2.5 Same file, `quoteByPlan()` — call `planModuleFlags($plan)` once before the module loop; replace L158 (`$price->is_free ? 0 : ...`) and L170 (`'is_free'=>$price->is_free`) with `$flags->get($module->id)?->is_free ?? false`. Done: `test_quote_base_only_equals_45000_for_basico` still asserts `is_free=false`. (R4, dep 2.4)
- [x] 2.6 Same file, `priceForModule()` — single `PlanModule::where(...)->first()` lookup after `activePrice()`; replace L206 `$price->is_free` with the lookup (`?? false`). Done: green once fixture (3.6) updates. (R4, dep 2.4)
- [x] 2.7 Same file, `buildPlanCatalog()`+`mapPlanModules()` — in `buildPlanCatalog()` (~L393-398) add one batch query for ALL plans keyed `"{plan_id}|{module_id}"`; pass into `mapPlanModules($planPrices, $flagsByPair)` (~L415); update `mapPlanModules()` signature (~L471) and replace L492-493 `$price->is_free` reads with the pair lookup (default false). Done: catalog JSON keys/nesting unchanged; `test_plan_catalog_lists_the_five_priced_plans_plus_the_contact_sales_tier` green. (R4, dep 2.4)
- [x] 2.8 Modify `Modules/SubscriptionPlan/app/Services/BillingPackageStripeService.php::syncableRows()` — remove `->where('is_free', false)` (current L150); batch-fetch `PlanModule` keyed `"{plan_id}|{module_id}"` for candidates; partition in PHP: flags+not-free→keep, flags+free→drop silently, missing flags→drop+`Log::warning` (plan/module/interval). Done: still returns `Collection<PlanModulePrice>`; missing-row logs (asserted by 3.3). (R6, dep 1.4)
- [x] 2.9 Modify `Modules/SubscriptionPlan/app/Services/EntitlementService.php::startModuleTrial()` — replace guard-(a) price lookup (current L191-201) with `PlanModule::query()->where(billing_package_id,billable_module_id)->first()` existence check (same 422 message on null); replace the `orderBy('interval')` trial pluck with ADR-1's month-preferred pluck over ACTIVE `PlanModulePrice` rows (nullable `$interval`); L240 `$price->trial_days ?? config(...)` → `$planModule->trial_days ?? config(...)`; L248 `grant()`'s `'interval'=>$price->interval` → `'interval'=>$interval`. Done: `test_start_trial_rejects_a_module_not_offered_by_the_plan` still 422s. (R5, R7, dep 1.4)
- [x] 2.10 Modify `Modules/SubscriptionPlan/database/seeders/PlanModuleMatrixSeeder.php` — add private `upsertPlanModule(BillingPackage $package, BillableModule $module, bool $isFree=false, ?int $trialDays=null): void` (`PlanModule::updateOrCreate`, near `upsertPrice()`); call once per plan inside the `run()` foreach (current L131-137) BEFORE the two `upsertPrice()` calls; remove the dead `$isFree` param and `is_free`/`trial_days` array entries from `upsertPrice()` (current L208,221,223). Done: `Cache::forget(...)` at end of `run()` still covers the new writes (same transaction/method). (R8, dep 1.4)

## Phase 3: Backend Tests (Batch 3, ~200 lines, 13 files)

- [x] 3.1 **Chokepoint — do first.** Modify `tests/Feature/Billing/Concerns/BillingMatrixFixtures.php::makePlanModulePrice()` (current L132-143) — split `$overrides`: `is_free`/`trial_days` keys route to `PlanModule::updateOrCreate([plan,module], [...])`; the rest stays on the `PlanModulePrice::create()` call. Add `DB::table('plan_module')->delete();` to `cleanBillingTables()` (current L39-49) right after the `plan_module_price` delete (L45). Done: `php artisan test --compact tests/Feature/Billing/ModuleTrialAdminTest.php` green with zero edits to that file (its L158 `trial_days` override routes through here). (R9, dep 1.4, 2.10)
- [x] 3.2 Modify `tests/Feature/Billing/BillingPackageAdminCrudTest.php` — reshape `test_price_matrix_returns_cells_and_all_active_modules` + `test_price_matrix_bulk_upsert_creates_and_updates_without_touching_stripe` PUT bodies to v2 `{modules:[...]}`; **DELETE** `test_price_matrix_rejects_mismatched_is_free_across_intervals` (L224-242) and `test_price_matrix_rejects_mismatched_trial_days_across_intervals` (L270-289) — sanctioned, scenario now unrepresentable; reshape `test_price_matrix_accepts_consistent_is_free_across_intervals` (L244-268) into a module-level round-trip; add a NEW test: a `cells.month.is_free` legacy-shape payload → HTTP 422. Done: file has 2 fewer + 1 new test method, suite green. (R3, dep 2.3, 3.1)
- [x] 3.3 Modify `tests/Feature/Billing/BillingPackageStripeSyncTest.php` — rewrite L155 (`PlanModulePrice::where('is_free',false)->get()`, breaks once the column drops) to derive the syncable set via a `PlanModule` lookup mirroring 2.8's partition; L179-180 `['is_free'=>true]` overrides need no edit (auto-routed by 3.1). Add NEW test: price row with NO matching `plan_module` row → `syncToStripe()` excludes it AND `Log::warning` fires (`Log::spy()`/`shouldReceive`). Done: file green incl. new test. (R6, dep 2.8, 3.1)
- [x] 3.4 Verify `tests/Feature/Billing/ModuleTrialAdminTest.php` — no direct edit expected (L158 override + L69 assertion covered by 3.1/2.9). Done: run standalone, green. (R5, dep 3.1, 2.9)
- [x] 3.5 Modify local helpers: `SelfServeBillingPlanTest.php::makePrice()` (L325-339, keep its `$trialDays` param), `OwnerSubscriptionLifecycleTest.php::seedYearPrices()` (L737-762) + `::seedMatrix()` (L802-856), `PlanDowngradeGuardTest.php::makePrice()` (L273-284), and the 3 inline `PlanModulePrice::create()` sites in `Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php` (L278-286, L377-385, L414-422) — drop the dead `is_free` array key from each `PlanModulePrice::create()` call, add a companion `PlanModule::updateOrCreate([plan,module],[is_free,trial_days])` per pair. Done: `grep -n "is_free" <4 files>` shows zero hits inside a `PlanModulePrice::create()` array. (R9, dep 1.4)
- [x] 3.6 **Carry-forward (orchestrator-mandated).** Scrub `is_free`/`trial_days` write-literals in `tests/Feature/Billing/PlanPricingTest.php`: L99 create-array literal (`test_quote_requires_base_module_when_modules_are_explicit`), L193 `->update(['is_free'=>true])` write (`test_price_for_module_returns_zero_for_free_row` — replace with a `PlanModule::updateOrCreate` write for that pair), L294 and L340 create-array literals (`test_plan_catalog_exposes_is_public_on_every_plan`, `test_plan_catalog_exposes_empty_array_for_module_with_null_features`). Leave L68 (`assertFalse($quote['lines'][0]['is_free'])`) and **L372 UNTOUCHED** (`assertJsonStructure` — constraint-1 proof, must pass unmodified). Done: `grep -n "is_free\|trial_days" PlanPricingTest.php` shows only read-site occurrences; file green. (R4, dep 2.5, 2.6, 2.7)
- [x] 3.7 **Carry-forward (orchestrator-mandated).** Backend-wide re-grep: `grep -rn "is_free\|trial_days" backend/Modules/SubscriptionPlan backend/tests/Feature/Billing` — confirm every remaining hit is a `PlanModule`/`plan_module` read-write, a pinned-unchanged public-JSON-shape assertion, or `config('billing.trial_days')`; zero hits are a `plan_module_price`/`PlanModulePrice` column write. Mirrors design ADR-6's frontend leak-check (4.4). Done: output reviewed line-by-line, documented clean. (R1, R8, dep 3.2, 3.3, 3.5, 3.6, 2.10)
- [x] 3.8 Modify `tests/Feature/Billing/PlanModuleMatrixTest.php` — add test(s): one `plan_module` row per (plan, `base`) with `is_free=false`/`trial_days=null`; `plan_module_price` no longer has `is_free`/`trial_days` columns (`Schema::getColumnListing`). Done: file green incl. new assertions. (R8, dep 2.10, 1.3)
- [x] 3.9 Verify `tests/Feature/Billing/MultiItemSubscriptionServiceTest.php` — no direct edit expected (L175 already uses `$this->makePlanModulePrice(...)`, covered by 3.1). Done: run standalone, green. (R9, dep 3.1)
- [x] 3.10 **NEW** `tests/Feature/Billing/PlanModuleIntegrityTest.php` (mirror sibling structure: `Tests\Feature\Billing`, `RefreshDatabase`, `BillingMatrixFixtures`) — drift behavior: active price row + missing `plan_module` ⇒ `quoteByPlan`/`planCatalog` report `is_free=false`, `syncableRows()` excludes+warns, `startModuleTrial()` 422s; plus ADR-1 parity on well-formed fixtures (`plan_module` row exists ⇔ ≥1 active price row, for every seeded pair; trial accept/reject matches a price-row oracle). Done: new file green. (R7, R9, dep 2.5, 2.6, 2.7, 2.9, 3.1)
- [x] 3.11 **NEW** `tests/Feature/Billing/PlanModuleBackfillMigrationTest.php` — self-contained: in-test `Schema::table('plan_module_price', ...)` re-adds `is_free`/`trial_days`; seed 3 pair shapes (month+year differing flags, month-only, year-only); empty `plan_module`; `include` the 000002 migration file + `up()`; assert copied flags (month wins on conflict); teardown drops the columns again. Done: new file green, does not leak schema state into other Billing tests. (R2, dep 1.2)

## Phase 4: Frontend (Batch 4, ~60 lines) — parallel with Phases 1-3

- [x] 4.1 Modify `frontend/src/modules/billingPackage/schema.ts` — `IPlanPriceCell` (L45-53): remove `is_free`/`trial_days`. `IPlanPriceMatrixModule` (L57-66): add `is_free: boolean`, `trial_days: number | null`. `IPlanPriceCellInput` (L84-92): reshape to `{unit_amount: number; currency?: string}`. Add **new** `IPlanPriceMatrixModuleInput {billable_module_id; is_free; trial_days; cells:{month,year}}`. Leave `IPlanPrice`/`IBillingPackage.plan_prices` untouched (pinned, zero consumers). Done: `pnpm exec tsc -b` (from `frontend/`) shows errors only in 4.2/4.3's files. (R3)
- [x] 4.2 Modify `frontend/src/modules/billingPackage/components/PlanPriceMatrix.tsx` — `buildInitialState()` (L103-129): `freeByModule[id]=mod.is_free`, `trialByModule[id]=mod.trial_days!=null?String(...):''`; **delete** the `.some()` OR-collapse (L109-113) + `month??year` fallback (L115-119) and their comments. `handleSave()` (L371-408): build one `IPlanPriceMatrixModuleInput` per module (flags once + `cells:{month,year}` amounts only); **delete** the per-interval fan-out loop (L381-394); call `PlanPriceMatrixResource.update(plan.id, modulesPayload)`. Done: grep confirms only these 2 functions reference the wire shape; render sites untouched. (R3, dep 4.1)
- [x] 4.3 Modify `frontend/src/modules/billingPackage/services/BillingPackageResource.ts` — `PlanPriceMatrixResource.update()` (L29-35): param type `IPlanPriceMatrixModuleInput[]`, body `{ modules }` (was `{ cells }`). Done: matches controller's v2 `modules` key (task 2.3). (R3, dep 4.1, 4.2)
- [x] 4.4 Zero-leakage grep (ADR-6): `grep -rn "is_free\|trial_days" frontend/src/modules/checkoutSubscription frontend/src/modules/tenant/billing landing/` — confirm 0 hits (those consumers keep reading the pinned per-interval `is_free`, no edits needed). Done: output documented. (R4)

## Phase 5: Verification & Handoff (sequential)

- [x] 5.1 `cd backend && php artisan migrate` (host php, not sail) — applies the 3 new migrations on the dev DB. Done: `php artisan migrate:status` shows all 3 `Ran`. (dep: Phase 1)
- [x] 5.2 Confirm no other `php artisan test` process is running against the shared `testing` DB (concurrent `migrate:fresh` corrupts it non-deterministically), then `cd backend && php artisan test --compact --filter=Billing` SINGLE-PROCESS (covers `tests/Feature/Billing/*` + `Modules/SubscriptionPlan/tests/Unit/*`). Done: 0 failures. (dep: Phases 1-3)
- [x] 5.3 `cd backend && vendor/bin/pint --dirty --format agent` on touched files (3 migrations, PlanModule.php, PlanModulePrice/BillingPackage/BillableModule models, request, controller, 3 services, seeder, 13 test files). Done: 0 style violations after auto-fix, run AFTER 5.2 so fixes don't mask a real failure.
- [x] 5.4 `cd frontend && pnpm exec tsc -b && pnpm lint` (from `frontend/`, NOT repo root) on `schema.ts`, `PlanPriceMatrix.tsx`, `BillingPackageResource.ts`. Done: both exit 0. (dep: Phase 4)
- [x] 5.5 Hand the working tree to the owner for review — NO commit, NO PR, NO branch. Summarize files touched, suite/Pint/tsc/eslint/migration results. Owner reviews via `git status`/`git diff`. Done: summary delivered, working tree left exactly as produced. (dep: 5.1-5.4)

## Review Workload Forecast

| Batch | Files | Est. changed lines |
|---|---|---|
| Phase 1 — schema+models | 7 | ~160 |
| Phase 2 — write/read paths | 7 | ~250 |
| Phase 3 — backend tests | 13 | ~200 |
| Phase 4 — frontend | 3 | ~60 |
| **Total** | **30** | **~670 (of 800 authorized)** |

### Batch Checkpoints (informal — single working-tree delivery, not chained PRs)

| Phase | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|
| 1 | `php artisan migrate:status` | N/A — schema/model only, no runtime path yet | `git checkout` the 3 migrations + `PlanModule.php` + the 2 relation additions |
| 2 | `php artisan test --compact --filter=PlanPricingTest` (fails until 3.6) | `php artisan tinker` — `app(PlanPricingService::class)->planCatalog()` | `git checkout` the request/controller/3 services/seeder |
| 3 | `php artisan test --compact --filter=Billing` | Same (this batch turns the suite green) | `git checkout tests/` (13 files) |
| 4 | `pnpm exec tsc -b` | Manual: open the price-matrix modal in `/billing_package`, edit + save one plan | `git checkout frontend/src/modules/billingPackage/` |

Decision needed before apply: No
Chained PRs recommended: No (delivery=working-tree only, owner decision)
Chain strategy: size-exception (owner pre-authorized the 800-line ceiling; single working-tree handoff, no PR chain)
400-line budget risk: Medium (670 lines exceeds the generic 400-line default but is within the owner's explicit 800-line ceiling for this change; the 4 batches above are natural review checkpoints within that one delivery)
