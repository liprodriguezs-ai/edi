# Design: Trial Usage Quotas

## Technical Approach

One new guard — `QuotaService` + `QuotaCheck` DTO + `QuotaExceededException` in `Modules/SubscriptionPlan` — cloning `UnitLimitService`'s `check*/assert*/build*Message` shape and its fail-open criterion. Two new tables: `plan_quota` (central limit catalog) and `tenant_quota_usage` (per-tenant lifetime counter, modeled on `receipt_sequence`). Enforcement wires into the three existing choke points via the injected-guard precedent (`DelinquencyRestrictionService`). Seed = `-1` on all three resources: the machinery ships built and OFF.

## Architecture Decisions

### D1: Trial/active scope — new cheap method on the state service

**Choice**: add `SubscriptionStateService::quotaScopeFor(Tenant $tenant): string` (`'trial'|'active'`), extracting the EXACT trial precedence already in `entitledNarrative()` (`SubscriptionStateService.php:143-191`) into one shared private predicate: Cashier `stripe_status === STATUS_TRIALING` via the existing `ownerSubscription()` (owner memoized by `AccountOwnerResolver`) → `trial`; else all active entitlements `source=trial` → `trial`; else `active`. `gateFor()`/`EnsureSubscriptionActive` untouched.
**Cost control**: `QuotaService` resolves `plan_quota` rows FIRST; when the resource's limits are unlimited under BOTH scopes (the seeded state), it allows without resolving scope — shipped-OFF costs ~1 catalog query per guarded write, zero Cashier reads. With finite rows: 1 entitlement query + memoized owner + 1 subscription query, only on the three write endpoints.
**Rejected**: `narrativeFor()` (full payload in a write path — wasteful); reading `tenant_entitlement` metadata inside QuotaService (re-derives the trial signal — the drift Change A eliminated).

### D2: `tenant_quota_usage` is BelongsToTenant, like `receipt_sequence`

**Choice**: `BaseModel::columns($table, true)` + `BelongsToTenant` — identical to `ReceiptSequence` (verified `ReceiptSequence.php:19`).
**Rejected**: `tenant_entitlement`'s central-with-explicit-`tenant_id` shape. That shape exists because Stripe webhooks/super-admin flows write it WITHOUT tenancy initialized. This counter is only touched inside the three tenant-scoped hooks where tenancy IS initialized, so `TenantScope` makes a cross-tenant read/increment structurally impossible and the trait stamps `tenant_id`. The fail-open branch (`! tenancy()->initialized`) returns BEFORE any counter query — the model is never queried unscoped. stancl-tenancy checklist: unique index includes `tenant_id`; no `DB::` facade for tenant data.

### D3: Concurrency — row lock + savepoint create + re-read (PaymentService::nextReceiptSequence, verbatim shape)

Inside the CALLER's transaction:
1. `TenantQuotaUsage::where('resource_key', $key)->lockForUpdate()->first()`
2. Miss (first use) → create `used_count = 0` in a nested `DB::transaction` (Postgres SAVEPOINT) catching `UniqueConstraintViolationException` (a concurrent winner aborts only the savepoint) → re-read `lockForUpdate()->firstOrFail()`
3. `used + $delta <= limit` ? increment + save : throw `QuotaExceededException`.

Two simultaneous reservations for one tenant serialize on the row lock; a rolled-back caller transaction rolls back the increment for free. `consume()` throws `RuntimeException` when `DB::transactionLevel() === 0`, making the inside-a-transaction invariant self-enforcing. `ReservationService::create` already has a transaction (`:100`, insert `:135`). `AccessService::generateQr` has NONE (verified `:145-177`) → the hook wraps guard + `Visitor::create` in a new `DB::transaction` (custom routes own their transactions; Orion auto-wraps only its CRUD).

### D4: `plan_quota` NULL-uniqueness — two partial unique indexes

```sql
CREATE UNIQUE INDEX plan_quota_global_unique ON plan_quota (scope, resource_key)
  WHERE billing_package_id IS NULL AND deleted_at IS NULL;
CREATE UNIQUE INDEX plan_quota_package_unique ON plan_quota (billing_package_id, scope, resource_key)
  WHERE billing_package_id IS NOT NULL AND deleted_at IS NULL;
-- counter table:
CREATE UNIQUE INDEX tenant_quota_usage_tenant_resource_unique ON tenant_quota_usage (tenant_id, resource_key)
  WHERE deleted_at IS NULL;
```

Via `DB::statement` (schema builder cannot express partial indexes); `WHERE deleted_at IS NULL` mirrors `receipt_sequence_tenant_year_unique`. `down()` drops indexes then tables.

### D5: `unit` composes band + quota by running BOTH checks

`UnitObserver::creating()/restoring()` swap `UnitLimitService::assertCanIncrease(1)` for `QuotaService::assertCanRegisterUnits(1)`, which (a) delegates `UnitLimitService::checkIncrease($delta)` and throws `UnitLimitExceededException::forIncrease($check)` unchanged — same code/payload, band tests keep passing, `UnitLimitService.php` untouched; then (b) when scope=trial and the `unit` quota is finite, compares `UnitLimitService::activeUnitCount() + $delta` (live rows — deleting a unit frees quota by construction, no counter row for `unit`) against the quota → `QuotaExceededException`. Sequential checks ARE `min(band, quota)`; band-first preserves today's UX. Accepted v1 nuance: batch pre-flights (`UnitController`, `ImportRunner`) still check band only; the observer enforces the trial quota per-row inside the batch transaction — invisible while seeded `-1`.

### D6: `-1` never leaves QuotaService

`QuotaService::resolveQuota(string $resource, string $scope): ?int` is the SINGLE translation point: picks the most-specific `plan_quota` row (tenant's active `billing_package_id` beats the `IS NULL` global row; package ids resolved with the same entitlement query shape as `UnitLimitService::resolveBand()`), then maps `limit_value === -1` AND row-absent → `null` — the project's unlimited convention. Downstream (`QuotaCheck->limit`, exception payload, `/config/subscription`) speaks only null-unlimited. `PlanQuota::UNLIMITED = -1` exists for the seeder and this method alone.

### D7: Seeder is firstOrCreate-only — no update path exists

`PlanQuotaSeeder` in `Modules/SubscriptionPlan/database/seeders/`, called from `DatabaseSeeder` right after `PlanModuleMatrixSeeder` (`DatabaseSeeder.php:34`). Three rows: `PlanQuota::firstOrCreate(['billing_package_id' => null, 'scope' => 'trial', 'resource_key' => $key], ['limit_value' => PlanQuota::UNLIMITED])`. `firstOrCreate` NEVER updates an existing row: a production-tuned limit survives every `start.sh:419` `db:seed`; nothing is nulled or deleted; rerun is a byte-level no-op. The `stripe_price_id` incident's shape (update with partial attributes) is unrepresentable here.

### D8: `/config/subscription` exposure — ≤3 constant added queries

`getSubscription()` adds `'quotas' => $quotaService->summary(liveUnitCount: $unitCount)`, reusing the `$unitCount` already computed (`ConfigurationController.php:568`). `summary()`: 1 query for all `plan_quota` rows + 1 `whereIn(resource_key)` for the tenant's usage rows + scope via `quotaScopeFor` (owner already memoized by this endpoint's presenters). Payload per resource: `{resource, scope, limit, used, remaining}`, `null` = unlimited. Constant regardless of resource count.

### D9: Business codes get ONE frontend home

New `frontend/src/shared/api/businessCodes.ts`: `export const BUSINESS_CODES = { SUBSCRIPTION_INACTIVE, DELINQUENT_RESTRICTED, UNIT_LIMIT_EXCEEDED, QUOTA_EXCEEDED } as const`. `apiClient.ts:209/:220` swap literals for constants; `UnitForm.tsx:188` fixes `'unit_limit_reached'` → `BUSINESS_CODES.UNIT_LIMIT_EXCEEDED` (the upgrade dialog finally opens). `QUOTA_EXCEEDED` is a 422 — no global interceptor: the Spanish MX message reaches callers via `ApiError.payload`; surfaces wanting the upgrade dialog key on `payload.code` against the shared constant.

## Data Flow (accumulating resource)

    ReservationService.create (DB::transaction :100)
      ├─ delinquency guard (:115)
      ├─ QuotaService.assertAndConsume('amenity_reservation')
      │    ├─ resolveQuota() → plan_quota (both scopes unlimited? → allow, stop)
      │    ├─ SubscriptionStateService.quotaScopeFor() → trial|active
      │    └─ tenant_quota_usage: lockForUpdate → [savepoint create] → check → increment
      └─ AmenityReservation::create (:135)         ← rollback undoes increment

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `Modules/SubscriptionPlan/database/migrations/*_create_plan_quota_table.php` | Create | Central (`BaseModel::columns($table, false)`); cols `billing_package_id` (nullable FK), `scope`, `resource_key`, `limit_value`; table + every column `->comment()` inside the closure |
| `Modules/SubscriptionPlan/database/migrations/*_create_tenant_quota_usage_table.php` | Create | `BaseModel::columns($table, true)`; `resource_key`, `used_count`; partial unique; comments |
| `Modules/SubscriptionPlan/app/Models/PlanQuota.php` | Create | Central, NO `BelongsToTenant`; class constants `SCOPE_TRIAL/SCOPE_ACTIVE`, `RESOURCE_*`, `UNLIMITED = -1` + `SCOPES`/`RESOURCES` arrays (no PHP enums, laravel-backend-architecture rule 14) |
| `Modules/SubscriptionPlan/app/Models/TenantQuotaUsage.php` | Create | `BelongsToTenant`, `#[ObservedBy([GlobalObserver::class])]` |
| `Modules/SubscriptionPlan/app/Services/QuotaService.php` | Create | Guard; registered `scoped()` with tenant-keyed memo (DelinquencyRestrictionService precedent) |
| `Modules/SubscriptionPlan/app/Services/QuotaCheck.php` | Create | DTO beside the service (module convention) |
| `Modules/SubscriptionPlan/app/Exceptions/QuotaExceededException.php` | Create | `final`, `ShouldntReport`, `render()` → 422 |
| `Modules/SubscriptionPlan/database/seeders/PlanQuotaSeeder.php` | Create | D7 |
| `database/seeders/DatabaseSeeder.php` | Modify | Call `PlanQuotaSeeder` after `PlanModuleMatrixSeeder` |
| `app/Services/SubscriptionStateService.php` | Modify | Add `quotaScopeFor()`; extract shared trial predicate from `entitledNarrative()` |
| `Modules/Housing/app/Observers/UnitObserver.php` | Modify | `:33`/`:65` → `assertCanRegisterUnits(1)` |
| `Modules/AmenityReservations/app/Services/ReservationService.php` | Modify | Consume after `:118`, inside existing transaction |
| `Modules/Access/app/Services/AccessService.php` | Modify | `generateQr`: wrap in `DB::transaction`, consume before `Visitor::create` (`:161`); `requestEntry()` untouched |
| `Modules/Configuration/.../ConfigurationController.php` | Modify | `getSubscription()` adds `quotas` |
| `frontend/src/shared/api/businessCodes.ts` | Create | D9 |
| `frontend/src/shared/api/apiClient.ts` | Modify | Use `BUSINESS_CODES` |
| `frontend/src/modules/unit/UnitForm.tsx` | Modify | `:188` code fix |
| `backend/tests/Feature/Billing/TrialQuota*Test.php` | Create | Named under `Billing` so the `--filter=Billing` baseline counts them |

## Interfaces / Contracts

```php
final class QuotaCheck {
    public function __construct(
        public readonly bool $allowed,
        public readonly string $resource,
        public readonly ?string $scope,     // null when fail-open
        public readonly ?int $limit,        // null = unlimited (never -1)
        public readonly int $used,
        public readonly int $attempted,
        public readonly ?string $planName,
    ) {}
}
// QuotaService: checkConsumption(resource, delta=1): QuotaCheck
//   assertAndConsume(resource, delta=1): void   // requires open transaction
//   assertCanRegisterUnits(delta): void         // band + trial quota (D5)
//   summary(?int liveUnitCount): array
// 422 payload: { message, code: 'QUOTA_EXCEEDED', resource, limit, used, scope, plan_name }
```

Fail-open (same criterion as `UnitLimitService`): no tenancy initialized, or unresolvable/unlimited quota → allow, no numbers resolved. Deliberate — never harden.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `resolveQuota` most-specific resolution; `-1`/absent → unlimited; fail-open; D5 min-composition | Seed rows per test (never rely on seeded `-1`) |
| Feature | Each hook blocks at limit (seed limit=2); rolled-back reservation consumes zero; deleting reservation/visitor does NOT refund; deleting unit DOES free; `requestEntry()` costs nothing; trial end → trial rows stop applying; `-1` never blocks; seeder rerun idempotent | `tests/Feature/Billing/TrialQuota*Test.php`, single process |
| Frontend | `BUSINESS_CODES` wiring; UnitForm upgrade dialog opens on `UNIT_LIMIT_EXCEEDED` | Vitest |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

Additive only: two new tables + seeded `-1` rows = zero behavior change (plan scenario 7). Enabling a quota later = editing `limit_value`, no code. Rollback: drop the two migrations, revert hunks by hunk (shared uncommitted WIP with the sibling change — never revert whole files). Never touch `backend/.claude/worktrees/fix+billing-base-scope-menu/`.

## Open Questions

- [ ] None blocking. Noted for tasks: batch pre-flight messages for the `unit` trial quota arrive per-row, not pre-flight (D5, accepted v1); `scope='active'` rows are supported by resolution but not seeded in v1.
