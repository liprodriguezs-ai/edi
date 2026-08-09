# Tasks: Trial Usage Quotas

Standard Mode (no strict TDD). Tests are written per-task alongside the code they cover, not
upfront. All new backend identifiers in English; all new UI copy in Mexican Spanish (tú-form).
Every migration column needs `->comment()`; comment the table INSIDE the `Schema::create`
closure (chaining `->comment()` onto `Schema::create(...)` throws — it returns `void`). Use
`php artisan module:make-migration` / `module:make-model` / `module:make-service` /
`module:make-request` (qch/core stubs) for new module files; there is no `module:make-exception`
— create `QuotaExceededException.php` by hand, mirroring `UnitLimitExceededException.php`
verbatim (namespace/imports/`ShouldntReport`/`render()` shape). Run `vendor/bin/pint --dirty
--format agent` after any PHP edit, before moving to the next phase.

Legend: **[P]** = parallel-safe with its siblings in the same phase. **Depends:** lists task IDs
that must be `completed` first. REQ-IDs map 1:1 to `specs/*/spec.md`.

---

## Phase 1 — Schema (2 new tables)

### [x] T1 [P] — Migration: `plan_quota` (central catalog)
**File:** `backend/Modules/SubscriptionPlan/database/migrations/*_create_plan_quota_table.php` (new)
**Satisfies:** REQ-QC-01, REQ-QC-04
**Depends:** none

- `BaseModel::columns($table, false)` (central, no `tenant_id`). Table comment inside the
  closure: `$table->comment('Central catalog of usage caps per plan (or global) and
  subscription scope, -1 = unlimited.');`.
- Columns (all `->comment()`'d): `billing_package_id` nullable FK to `billing_package`
  (`->nullable()->comment('FK to billing_package; NULL = applies to all plans (global row).')`),
  `scope` string (`'trial'|'active'`, comment noting the enum), `resource_key` string
  (`'unit'|'amenity_reservation'|'visitor'`, comment noting the enum), `limit_value` integer
  (comment: `'-1 = unlimited; otherwise the max lifetime/live count allowed.'`).
- Two partial unique indexes via `DB::statement` (D4, schema builder cannot express partial
  indexes — plain `unique()` allows duplicate global rows because Postgres treats NULLs as
  distinct):
  ```sql
  CREATE UNIQUE INDEX plan_quota_global_unique ON plan_quota (scope, resource_key)
    WHERE billing_package_id IS NULL AND deleted_at IS NULL;
  CREATE UNIQUE INDEX plan_quota_package_unique ON plan_quota (billing_package_id, scope, resource_key)
    WHERE billing_package_id IS NOT NULL AND deleted_at IS NULL;
  ```
- `down()`: `DB::statement('DROP INDEX IF EXISTS ...')` for both indexes (in reverse order),
  then `Schema::dropIfExists('plan_quota')` — mirrors `2026_06_09_000001_create_receipt_sequence_table.php`.

### [x] T2 [P] — Migration: `tenant_quota_usage` (per-tenant counter)
**File:** `backend/Modules/SubscriptionPlan/database/migrations/*_create_tenant_quota_usage_table.php` (new)
**Satisfies:** REQ-CT-01
**Depends:** none

- `BaseModel::columns($table, true)` (tenant-scoped — adds `tenant_id` NOT NULL + FK + index).
  Table comment inside the closure: `'Per-tenant lifetime consumption counter for accumulating
  quota resources (amenity_reservation, visitor). unit is NOT tracked here — see
  UnitLimitService::activeUnitCount().'`.
- Columns: `resource_key` string (comment), `used_count` bigInteger `->default(0)` (comment:
  `'Lifetime count, never decremented on delete (locked product decision).'`).
- One partial unique index (mirrors `receipt_sequence_tenant_year_unique`):
  ```sql
  CREATE UNIQUE INDEX tenant_quota_usage_tenant_resource_unique ON tenant_quota_usage (tenant_id, resource_key)
    WHERE deleted_at IS NULL;
  ```
- `down()`: drop index, then `Schema::dropIfExists('tenant_quota_usage')`.

---

## Phase 2 — Models

### [x] T3 [P] — `PlanQuota` model
**File:** `backend/Modules/SubscriptionPlan/app/Models/PlanQuota.php` (new)
**Satisfies:** REQ-QC-01, REQ-QC-03
**Depends:** T1

- Extends `BaseModel`. **NO** `BelongsToTenant` (central). `#[ObservedBy([GlobalObserver::class])]`
  (mirrors `ReceiptSequence.php` shape, minus the tenant trait).
- Class constants (no PHP enums, laravel-backend-architecture rule 14): `SCOPE_TRIAL = 'trial'`,
  `SCOPE_ACTIVE = 'active'`, `SCOPES = [self::SCOPE_TRIAL, self::SCOPE_ACTIVE]`;
  `RESOURCE_UNIT = 'unit'`, `RESOURCE_AMENITY_RESERVATION = 'amenity_reservation'`,
  `RESOURCE_VISITOR = 'visitor'`, `RESOURCES = [self::RESOURCE_UNIT, self::RESOURCE_AMENITY_RESERVATION, self::RESOURCE_VISITOR]`;
  `UNLIMITED = -1` (used ONLY by the seeder and `QuotaService::resolveQuota`, per D6).
- `$fillable = ['billing_package_id', 'scope', 'resource_key', 'limit_value']`; casts:
  `'limit_value' => 'integer'`.
- `belongsTo(BillingPackage::class)` relation (snake_case method name = the relation key).

### [x] T4 [P] — `TenantQuotaUsage` model
**File:** `backend/Modules/SubscriptionPlan/app/Models/TenantQuotaUsage.php` (new)
**Satisfies:** REQ-CT-01
**Depends:** T2

- Extends `BaseModel`, `use BelongsToTenant`, `#[ObservedBy([GlobalObserver::class])]` — copy
  `ReceiptSequence.php` verbatim, swapping columns.
- `$fillable = ['resource_key', 'used_count']`; casts: `'used_count' => 'integer'`.

---

## Phase 3 — DTO + Exception

### [x] T5 [P] — `QuotaCheck` DTO
**File:** `backend/Modules/SubscriptionPlan/app/Services/QuotaCheck.php` (new)
**Satisfies:** contract in design.md §Interfaces/Contracts
**Depends:** none

- `final class QuotaCheck` (readonly constructor-promoted properties, PHPDoc array-shape
  style per project convention): `bool $allowed`, `string $resource`, `?string $scope`
  (null when fail-open), `?int $limit` (null = unlimited — **never** `-1`, per D6), `int $used`,
  `int $attempted`, `?string $planName`.

### [x] T6 [P] — `QuotaExceededException`
**File:** `backend/Modules/SubscriptionPlan/app/Exceptions/QuotaExceededException.php` (new)
**Satisfies:** REQ-QE-03
**Depends:** T5

- `final class QuotaExceededException extends Exception implements ShouldntReport` — copy the
  shape of `UnitLimitExceededException.php` exactly: private `CODE` constant (a NEW stable
  string, e.g. `'QUOTA_EXCEEDED'` — must match `BUSINESS_CODES.QUOTA_EXCEEDED` byte-for-byte,
  see T21), private `?array $payload`, a named constructor `forQuota(QuotaCheck $check): self`
  building `{message, code, resource, limit, used, scope, plan_name}` (design.md:114), a
  private `buildMessage(QuotaCheck $check): string` producing a tú-form Mexican Spanish message
  naming the resource and the limit (REQ-QE-03), and `render(): JsonResponse` returning
  `response()->json($this->payload, 422)`.
- `ShouldntReport` is MANDATORY (REQ-QE-03: "a quota block is an expected business outcome and
  MUST NOT be reported as an application error/alert" — same reasoning as
  `UnitLimitExceededException`'s docblock).

---

## Phase 4 — Trial/active scope (independent track, no dependency on Phase 1–3)

### [x] T7 — `SubscriptionStateService::quotaScopeFor()`
**File:** `backend/app/Services/SubscriptionStateService.php` (modify)
**Satisfies:** REQ-QE-02
**Depends:** none — can start immediately, in parallel with Phase 1–3

- Extract the EXACT trial-precedence branch already inlined in `entitledNarrative()`
  (`SubscriptionStateService.php:143-191`) into one shared private predicate, e.g.
  `private function isTrialScope(SubscriptionGate $gate, Tenant $tenant): bool`: Cashier
  `stripe_status === STATUS_TRIALING` via `ownerSubscription($tenant)` → trial; else all
  active entitlements have `source === TenantEntitlement::SOURCE_TRIAL` → trial; else false.
  `entitledNarrative()` calls the new predicate instead of re-inlining the logic — zero
  behavior change there (verify with the existing `SubscriptionStateService` test suite).
- Add the new public method: `public function quotaScopeFor(Tenant $tenant): string` returning
  `PlanQuota::SCOPE_TRIAL` or `PlanQuota::SCOPE_ACTIVE`, resolving the gate the same way
  `entitledNarrative()`'s caller does (reuse the existing gate-resolution helper — do NOT
  duplicate `gateFor()`/`EnsureSubscriptionActive` logic, per design D1: "`gateFor()`/
  `EnsureSubscriptionActive` untouched").
- Do NOT call `narrativeFor()` (full payload) — this is a write-path hot method, per D1 cost
  control.
- Write/update a unit test exercising both branches (Stripe trialing, local trial-source
  entitlements, and paid) — the existing `SubscriptionStateService` test file is the home for
  this; do not create a new test file.

---

## Phase 5 — QuotaService (the guard)

### [x] T8 — `QuotaService`
**File:** `backend/Modules/SubscriptionPlan/app/Services/QuotaService.php` (new)
**Satisfies:** REQ-QC-02, REQ-QC-03, REQ-CT-02, REQ-CT-04, REQ-CT-05, REQ-QE-01, REQ-QE-02, REQ-QE-04, REQ-12
**Depends:** T3, T4, T5, T6, T7

The single largest task — implement in this order inside one file:

1. **`resolveQuota(string $resource, string $scope): ?int`** (D6, the SINGLE translation
   point for `-1`/absent → `null`-unlimited): resolve the tenant's active `billing_package_id`
   using the SAME entitlement-query shape as `UnitLimitService::resolveBand()`
   (`TenantEntitlement::query()->where('tenant_id', tenant('id'))->active()->with('billing_package')`),
   then pick the most-specific `PlanQuota` row — package-specific row beats the
   `billing_package_id IS NULL` global row (REQ-QC-02) — and map `limit_value === PlanQuota::UNLIMITED`
   OR row-absent → `null`.
2. **`checkConsumption(string $resource, int $delta = 1): QuotaCheck`**: fail-open
   (`allowed: true, scope: null`, no numbers resolved) when `! tenancy()->initialized`
   (REQ-QE-04 scenario "no tenancy allows"). Otherwise: call `resolveQuota()` for BOTH `trial`
   and `active`... no — per D1 cost control, resolve `plan_quota` rows FIRST; only when
   NEITHER scope's row is finite for this resource, allow WITHOUT resolving scope (the
   shipped-OFF fast path, ~1 query). Otherwise call `SubscriptionStateService::quotaScopeFor()`,
   resolve THAT scope's quota, read `TenantQuotaUsage` (or 0 if no row), and return
   `allowed = ($used + $delta) <= $limit` (or `allowed = true` when `$limit` is `null`,
   REQ-QC-03).
3. **`consume(string $resource, int $delta = 1): void`** (D3 — the concurrency-safe write,
   verbatim shape of `PaymentService::nextReceiptSequence()`/`createReceiptSequenceRow()`):
   - `throw new \RuntimeException(...)` when `DB::transactionLevel() === 0` — makes the
     "must run inside the caller's transaction" invariant (REQ-CT-02) self-enforcing.
   - `TenantQuotaUsage::where('resource_key', $resource)->lockForUpdate()->first()`; on miss,
     create `used_count = 0` inside a nested `DB::transaction()` (Postgres SAVEPOINT) catching
     `UniqueConstraintViolationException` (a concurrent winner aborts only the savepoint, per
     REQ-CT-05), then re-read with `lockForUpdate()->firstOrFail()`.
   - Increment `used_count += $delta` and `save()`.
4. **`assertAndConsume(string $resource, int $delta = 1): void`**: `checkConsumption()`, throw
   `QuotaExceededException::forQuota($check)` if not allowed, else `consume($resource, $delta)`.
5. **`assertCanRegisterUnits(int $delta = 1): void`** (D5 — band + trial-quota composition,
   REQ-12): delegate `UnitLimitService::checkIncrease($delta)` first and throw
   `UnitLimitExceededException::forIncrease($check)` UNCHANGED when the band rejects (same
   code/payload as today — `UnitLimitService.php` stays untouched). Only when the band allows,
   AND `resolveQuota('unit', scope)` is finite, compare
   `UnitLimitService::activeUnitCount() + $delta` (LIVE rows — `unit` has no counter row) against
   that quota and throw `QuotaExceededException::forQuota(...)` if it would exceed. Sequential
   checks implement `min(band, quota)`; band-first preserves today's UX (band error first when
   both would reject).
6. **`summary(?int $liveUnitCount = null): array`** (D8): 1 query for all `plan_quota` rows +
   1 `whereIn('resource_key', ...)` for the tenant's `tenant_quota_usage` rows, scope via
   `quotaScopeFor()`. Return `[{resource, scope, limit, used, remaining}]` for all 3 v1
   resources, `used` for `unit` = `$liveUnitCount` (passed in, not re-queried — REQ-QV-01),
   `limit: null` for unlimited (not a fake large number).
- Fail-open criterion (design.md:117) applies uniformly: no tenancy, or unresolvable/unlimited
  quota → allow, no numbers resolved. **Never harden this.**

### [x] T9 [P] — Register `QuotaService` scoped
**File:** `backend/Modules/SubscriptionPlan/app/Providers/SubscriptionPlanServiceProvider.php` (modify)
**Satisfies:** design.md §File Changes (QuotaService row)
**Depends:** T8

- In `register()`, add `$this->app->scoped(QuotaService::class);` — one instance per request,
  same precedent as `CollectionsServiceProvider::register()`'s
  `$this->app->scoped(DelinquencyRestrictionService::class);` (`CollectionsServiceProvider.php:56`).

### [x] T10 [P] — Unit test: `QuotaService` resolution + fail-open + D5 composition
**File:** `backend/Modules/SubscriptionPlan/tests/Unit/QuotaServiceTest.php` (new)
**Satisfies:** REQ-QC-02, REQ-QC-03, REQ-QE-04, REQ-12 (sibling location to `UnitLimitServiceTest.php`)
**Depends:** T8

- Seed `plan_quota` rows PER TEST (never rely on the seeded `-1`, per REQ-QE-05). Cover: most-
  specific-row-wins (plan row over global row, and vice versa for a tenant on a different
  plan); `-1` never blocks at any usage level; no resolvable row → fail-open, no numbers
  resolved; no tenancy initialized → fail-open; D5 composition — band stricter than quota
  still governs (band error), quota stricter than band blocks first (quota error), unlimited
  quota leaves band-only behavior byte-identical.

---

## Phase 6 — Seeder

### [x] T11 — `PlanQuotaSeeder`
**File:** `backend/Modules/SubscriptionPlan/database/seeders/PlanQuotaSeeder.php` (new)
**Satisfies:** REQ-QC-05
**Depends:** T3

- Three `PlanQuota::firstOrCreate(['billing_package_id' => null, 'scope' => PlanQuota::SCOPE_TRIAL,
  'resource_key' => $key], ['limit_value' => PlanQuota::UNLIMITED])` calls, one per
  `PlanQuota::RESOURCES` entry. `firstOrCreate` NEVER updates an existing row (D7) — this is
  the entire idempotency guarantee; do not add any update/upsert path.

### [x] T12 — Wire `PlanQuotaSeeder` into `DatabaseSeeder`
**File:** `backend/database/seeders/DatabaseSeeder.php` (modify)
**Satisfies:** REQ-QC-05
**Depends:** T11

- Add `use Modules\SubscriptionPlan\Database\Seeders\PlanQuotaSeeder;` and
  `$this->call(PlanQuotaSeeder::class);` immediately after `$this->call(PlanModuleMatrixSeeder::class);`
  (`DatabaseSeeder.php:34`) — matches the message's required call order.

### [x] T13 [P] — Feature test: `plan-quota-catalog` capability
**File:** `backend/tests/Feature/Billing/TrialQuotaCatalogTest.php` (new)
**Satisfies:** REQ-QC-01, REQ-QC-02, REQ-QC-03, REQ-QC-04, REQ-QC-05
**Depends:** T3, T11, T12

- Cover all 5 scenarios verbatim from `specs/plan-quota-catalog/spec.md`: a trial-scope global
  row resolves; a plan-specific row overrides the global row for tenants on that plan (and the
  global row still applies to tenants on other plans); `-1` never blocks at 100,000 consumed;
  inserting a second global row for the same `(scope, resource_key)` raises a DB uniqueness
  violation (assert the DB-level rejection, e.g. `\Illuminate\Database\QueryException`, NOT an
  application-level check — REQ-QC-04 is explicit that this must be enforced by the database);
  re-running the seeder after an operator edited a seeded row to `limit_value=5` leaves it at
  `5` with no duplicate row (call `$this->seed(PlanQuotaSeeder::class)` twice, or the Artisan
  `db:seed --class=...` command).

---

## Phase 7 — Wire the three hooks (parallel across modules; each depends only on Phase 5)

### [x] T14 [P] — `UnitObserver`: swap to `assertCanRegisterUnits`
**File:** `backend/Modules/Housing/app/Observers/UnitObserver.php` (modify, `:33` and `:65`)
**Satisfies:** REQ-12
**Depends:** T8

- `creating()` (`:33`): `app(UnitLimitService::class)->assertCanIncrease(1);` →
  `app(QuotaService::class)->assertCanRegisterUnits(1);`.
- `restoring()` (`:65`, inside the existing `if (! $unit->trashed()) { return; }` guard): same
  swap.
- Update the `use` import (`UnitLimitService` → `QuotaService`, or add both if still referenced
  elsewhere in the file — it is not).
- Run `Modules/Housing/tests/Feature/UnitLimitEnforcementTest.php` (untouched file) after this
  change — with the seeded `-1` quota it MUST stay green byte-for-byte (REQ-QE-05).

### [x] T15 [P] — `ReservationService::create`: consume after the delinquency guard
**File:** `backend/Modules/AmenityReservations/app/Services/ReservationService.php` (modify)
**Satisfies:** REQ-CT-01, REQ-CT-02, REQ-QE-01
**Depends:** T8

- Inside the existing `DB::transaction()` closure (`:100`), AFTER `assertNotRestricted()`
  (`:115-118`) and BEFORE `AmenityReservation::create()` (`:135`), call
  `app(QuotaService::class)->assertAndConsume('amenity_reservation');` — the same transaction
  the reservation insert runs in, satisfying REQ-CT-02 for free (a later rollback, e.g. the
  `UniqueConstraintViolationException` → `abort(409, ...)` path at `:151-155`, unwinds the
  increment automatically).
- Constructor-inject `QuotaService` alongside the existing `DelinquencyRestrictionService`
  injection (`:88-92`) rather than resolving via `app()`, matching this service's existing
  DI style.

### [x] T16 [P] — `AccessService::generateQr`: wrap in transaction, consume before `Visitor::create`
**File:** `backend/Modules/Access/app/Services/AccessService.php` (modify, `:145-177`)
**Satisfies:** REQ-CT-01, REQ-CT-02, REQ-CT-04, REQ-QE-01
**Depends:** T8

- `generateQr()` currently has NO transaction (verified `:145-177`). Wrap the existing body in
  `DB::transaction(function () use ($data): Visitor { ... });`. This is safe per the design's
  verified precondition: `Visitor` carries only `GlobalObserver` (no creation listeners),
  `generateUniqueToken()` is a bounded 10-iteration `exists()` loop, no queue/mail/HTTP inside.
- Insert `app(QuotaService::class)->assertAndConsume('visitor');` immediately after the
  `assertNotRestricted()` call (`:150-153`) and before `Visitor::create()` (`:161`) — i.e.
  inside the new transaction, before the insert.
- Custom routes own their own transactions (Orion auto-wraps only its CRUD, per laravel-backend-architecture) —
  this is the ONE place in this change that introduces a transaction rather than reusing one.
- `requestEntry()` (`:381`, NOT `generateQr`) is explicitly OUT of scope — do not touch it
  (REQ-CT-04 scenario "guard-initiated visitor consumes nothing").

### [x] T17 [P] — Feature test: `quota-consumption-tracking` capability (REQ-CT-02 gets its OWN dedicated test)
**File:** `backend/tests/Feature/Billing/TrialQuotaConsumptionTest.php` (new)
**Satisfies:** REQ-CT-01, REQ-CT-02, REQ-CT-03, REQ-CT-04, REQ-CT-05
**Depends:** T14, T15, T16

- Seed a test-local quota (e.g. `limit_value=2`) for `amenity_reservation` — never rely on the
  seeded `-1`.
- `REQ-CT-01`: creating a reservation increments the counter by exactly 1.
- **`REQ-CT-02` — dedicated, isolated test method(s), the highest-risk correctness property in
  this change.** Force the enclosing transaction to roll back AFTER the quota check has run but
  BEFORE (or without) the resource actually persisting — e.g. trigger the existing
  `UniqueConstraintViolationException` → `abort(409)` path in `ReservationService::create`
  (`:151-155`, a same-day duplicate `by_day` booking) with a quota that would still have room,
  and assert: (a) no reservation exists, (b) `tenant_quota_usage.used_count` is UNCHANGED. This
  test must be written so it FAILS if the increment ever escapes the caller's transaction — do
  not fold it into a broader multi-assertion test.
- `REQ-CT-03`: deleting/soft-deleting a reservation or a visitor does NOT decrement the counter
  (assert `used_count` unchanged after delete, and that a subsequent creation at the limit is
  still blocked).
- `REQ-CT-04`: three physical entries/exits against one visit still show `used_count === 1`;
  a guard-initiated `requestEntry()`-created visitor record consumes nothing even at the limit.
- `REQ-CT-05`: since no true multi-process concurrency test exists anywhere in this codebase
  (verified — `CollectionsReceiptSequenceTest.php`, the closest precedent, tests sequencing
  correctness, not true races), test the SAME way: (a) the miss/create/savepoint path — a row
  is created on first use and a duplicate-insert race is handled by the `UniqueConstraintViolationException`
  catch + re-read (mirror `PaymentService::createReceiptSequenceRow()`'s coverage shape); (b)
  sequential check-then-increment correctness at the boundary (2 consumed of quota 3 → third
  succeeds, fourth is rejected, final `used_count === 3`, never `4`).

---

## Phase 8 — Enforcement safety property + regression floor test

### [x] T18 — Feature test: `quota-enforcement` capability (REQ-QE-05 safety property gets its OWN test)
**File:** `backend/tests/Feature/Billing/TrialQuotaEnforcementTest.php` (new)
**Satisfies:** REQ-QE-01, REQ-QE-02, REQ-QE-03, REQ-QE-04, REQ-QE-05
**Depends:** T14, T15, T16

- `REQ-QE-01`: reaching a seeded-in-test limit exactly succeeds; the next attempt is rejected
  with the quota error — for all three hooks (unit, amenity_reservation, visitor).
- `REQ-QE-02`: a tenant blocked at a `trial`-scope limit succeeds once its scope becomes
  `active` and no (or an unlimited) `active`-scope row limits the resource — drive this via
  whatever `SubscriptionStateService`/Cashier test fixture the sibling `stripe-native-*` suites
  already use to flip a tenant from trialing to active (reuse, do not reinvent).
- `REQ-QE-03`: full payload assertion on a 422 — `code`, `resource`, `limit`, `used`, `scope`,
  `plan_name`, and a tú-form Mexican Spanish message naming the resource and the limit.
- `REQ-QE-04`: both fail-open scenarios (no resolvable quota row; no tenancy initialized).
- **`REQ-QE-05` — its own test method**, seeding NOTHING (relying purely on the shipped `-1`
  seed from `PlanQuotaSeeder`, which must have run via the test's `RefreshDatabase` +
  `DatabaseSeeder`/tenant seeding path): create units up to and past what would be a band, make
  reservations, register visits — assert every operation's admit/reject outcome matches
  pre-change behavior (the plan band on units keeps rejecting exactly as today; nothing else is
  ever rejected). This is the safety-property test the orchestrator flagged as needing to run
  with REAL seeded values, not test-seeded ones.

### [x] T19 [P] — Feature test: `unit-limit-enforcement` delta (REQ-12/REQ-13 backend half)
**File:** `backend/tests/Feature/Billing/TrialQuotaUnitLimitDeltaTest.php` (new)
**Satisfies:** REQ-12 (REQ-13 frontend half covered in T22)
**Depends:** T14

- Cover the 4 scenarios verbatim from `specs/unit-limit-enforcement/spec.md`: trial quota
  stricter than the band blocks first (422, quota error contract, active count unchanged);
  band stricter than the quota still governs (rejected by the band exactly as before — assert
  the response carries `UNIT_LIMIT_EXCEEDED`, not the quota code); deleting a unit frees quota
  room (soft-delete then a subsequent create succeeds); unlimited quota (`-1` or no row) leaves
  band-only admissions/rejections byte-identical to pre-change behavior.

---

## Phase 9 — Visibility

### [x] T20 — `ConfigurationController::getSubscription()`: expose `quotas`
**File:** `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` (modify, `:562-583`)
**Satisfies:** REQ-QV-01
**Depends:** T8

- Add `'quotas' => app(QuotaService::class)->summary(liveUnitCount: $unitCount)` to the `data`
  array (reusing `$unitCount` already computed at `:568` — do not re-query unit count).

### [x] T21 [P] — Feature test: `quota-visibility` REQ-QV-01 (backend half)
**File:** `backend/tests/Feature/Billing/TrialQuotaVisibilityTest.php` (new)
**Satisfies:** REQ-QV-01
**Depends:** T20

- Seed `visitor` quota 5, consume 3; `GET /config/subscription` payload reports `visitor` with
  limit 5 and usage 3; a resource seeded `-1` is reported as unlimited (explicit flag/`null`
  limit, not a large fake number).

---

## Phase 10 — Frontend (independent track — no backend dependency once T6's exact `CODE` string is known; safe to start any time after T6)

### [x] T22 — `businessCodes.ts` (shared constant, D9)
**File:** `frontend/src/shared/api/businessCodes.ts` (new)
**Satisfies:** REQ-QV-02, REQ-13
**Depends:** T6 (needs the exact `QuotaExceededException::CODE` string)

- `export const BUSINESS_CODES = { SUBSCRIPTION_INACTIVE: 'SUBSCRIPTION_INACTIVE',
  DELINQUENT_RESTRICTED: 'DELINQUENT_RESTRICTED', UNIT_LIMIT_EXCEEDED: 'UNIT_LIMIT_EXCEEDED',
  QUOTA_EXCEEDED: 'QUOTA_EXCEEDED' } as const;` — verify each value is byte-identical to its
  backend emitter (`SubscriptionGuard`/whatever middleware emits `SUBSCRIPTION_INACTIVE`,
  `DelinquencyRestrictedException` for `DELINQUENT_RESTRICTED`, `UnitLimitExceededException::CODE`
  for `UNIT_LIMIT_EXCEEDED`, `QuotaExceededException::CODE` for `QUOTA_EXCEEDED`).

### [x] T23 [P] — `apiClient.ts`: use `BUSINESS_CODES`
**File:** `frontend/src/shared/api/apiClient.ts` (modify, `:209`, `:220`)
**Satisfies:** REQ-QV-02
**Depends:** T22

- `errorData?.code === 'SUBSCRIPTION_INACTIVE'` → `errorData?.code === BUSINESS_CODES.SUBSCRIPTION_INACTIVE`;
  same for `'DELINQUENT_RESTRICTED'` → `BUSINESS_CODES.DELINQUENT_RESTRICTED`. Add the import.
- Do NOT add a `QUOTA_EXCEEDED` branch here — it is a 422 handled by `rejectWithApiError` /
  `ApiError.payload`, not `handle403` (D9: "no global interceptor" for this code). Confirm
  `apiClient.test.ts`'s existing `DELINQUENT_RESTRICTED` assertion (`:32`) still passes
  unmodified (it asserts against the literal string in a mocked payload, not the import).

### [x] T24 [P] — `UnitForm.tsx`: fix the code mismatch
**File:** `frontend/src/modules/unit/UnitForm.tsx` (modify, `:188`)
**Satisfies:** REQ-13
**Depends:** T22

- `err.payload?.code === 'unit_limit_reached'` → `err.payload?.code === BUSINESS_CODES.UNIT_LIMIT_EXCEEDED`.
  Add the import.

### [x] T25 [P] — Frontend test: upgrade dialog opens on `UNIT_LIMIT_EXCEEDED`
**File:** `frontend/src/modules/unit/UnitForm.test.tsx` (new — no existing test file for this component)
**Satisfies:** REQ-13
**Depends:** T24

- Mock a 422 submit response with `payload.code = BUSINESS_CODES.UNIT_LIMIT_EXCEEDED`; assert
  `onUnitLimitReached` fires (the upgrade dialog opens) and the generic error toast does NOT.
  Scope this test narrowly to the error-code branch — do not attempt full `UnitForm` coverage.

---

## Phase 11 — Full regression (the safety gate — run LAST, after every prior phase is complete)

### [x] T26 — Backend regression with the REAL seeded `-1` values
**Depends:** T13, T17, T18, T19, T20, T21

From `/Users/rodriguez/code/residia/backend`, single process, `DB_HOST=127.0.0.1 DB_PORT=5440
DB_USERNAME=rodriguez DB_PASSWORD=password`:

- `php artisan test --filter=Billing` — must be **≥ 297 passed / ≥ 1171 assertions** (297 + this
  change's new Billing-named tests).
- `php artisan test tests/Feature/Billing` — must be **≥ 286 / ≥ 1133**.
- `php artisan test --filter=UnitLimit` — byte-identical pass/fail outcomes to baseline (this is
  where REQ-QE-05's "shipped seed changes nothing" property gets its strongest external check —
  a suite this change did NOT write, exercising the exact hook this change modified).
- `php artisan test --filter=Housing`, `--filter=AmenityReservations`, `--filter=Access` — all
  green, no regressions.
- `vendor/bin/pint --dirty --format agent` — clean.

### [x] T27 — Frontend regression
**Depends:** T23, T24, T25

From `frontend/`:

- `pnpm vitest run` — must be **≥ 100 files / ≥ 773 tests**. If the `AmenityReservations` suite
  fails, RE-RUN before concluding a regression (known flaky per the orchestrator's baseline
  note — one run failed 7 tests, the next two passed clean).
- `pnpm exec tsc -b` — clean.
- `pnpm lint` — **0 errors** (must stay at 0, not just "no new errors").

### [x] T28 — Close-out: confirm REQ-QE-05 end to end and record baselines met
**Depends:** T26, T27

- Re-read the final counts from T26/T27 against the baselines in this task file's header and
  the spec's REQ-QE-06; confirm every named suite is at or above baseline with NO suite going
  from green to red.
- Confirm the two migrations are additive-only (no existing table altered) and the seeder never
  overwrote a non-default `limit_value` during any test run (grep test output/assertions for
  this if any test exercised a reseed).
- This is the explicit "shipped seeds change nothing" sign-off the orchestrator asked to be the
  LAST thing verified in this change.

---

## Review Workload Forecast

| Area | Files | Est. changed/added lines |
|---|---|---|
| Migrations (T1, T2) | 2 new | ~75 |
| Models (T3, T4) | 2 new | ~70 |
| DTO + Exception (T5, T6) | 2 new | ~100 |
| SubscriptionStateService (T7) | 1 modified | ~40 |
| QuotaService + provider registration (T8, T9) | 2 (1 new, 1 modified) | ~220 |
| QuotaService unit test (T10) | 1 new | ~110 |
| Seeder + wiring (T11, T12) | 2 (1 new, 1 modified) | ~45 |
| Catalog feature test (T13) | 1 new | ~130 |
| Three hooks (T14, T15, T16) | 3 modified | ~35 |
| Consumption feature test (T17) | 1 new | ~160 |
| Enforcement feature test (T18) | 1 new | ~150 |
| Unit-limit delta feature test (T19) | 1 new | ~100 |
| ConfigurationController (T20) | 1 modified | ~10 |
| Visibility feature test (T21) | 1 new | ~60 |
| businessCodes.ts + apiClient.ts + UnitForm.tsx (T22–T24) | 3 (1 new, 2 modified) | ~30 |
| UnitForm frontend test (T25) | 1 new | ~45 |
| **Total** | **~24 files (16 new / 8 modified)** | **~1,380** |

Exceeds the 800-line review budget — already flagged and accepted under `delivery_strategy:
exception-ok` (size:exception pre-approved this session; do not re-ask). No commits/branches/
push/worktrees at any point (HARD constraint carried into every task above).
