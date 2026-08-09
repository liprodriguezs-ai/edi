---
name: collections-module-isolation
description: Collections module tenant-isolation audit — model traits, central vs tenant catalogs, per-tenant unique indexes, cron tenancy, payer_user_id handling, and the one residual data-integrity gap.
metadata:
  type: pattern
---

# Collections module — tenant isolation (audited 2026-06-20)

## Tenant-scoped models (all correct)
charge, charge_batch, payment, payment_application, credit_balance, charge_concept,
charge_concept_category, receipt_sequence — all use `BelongsToTenant` (charge_concept_category
via `TenantCatalogModel`). All `create_*` migrations call `BaseModel::columns($table, true)`.

## Central catalogs (all correct)
charge_status, payment_status, charge_batch_status, amount_mode, periodicity, payment_method
— extend `CentralCatalogModel` (NO BelongsToTenant), `BaseModel::columns($table, false)`, fixed
UUID constants seeded once by CatalogSeeder. These FSM/behavior catalogs are CORRECTLY central
in this project (note: differs from older domain.md text — verify against CatalogSeeder, which
is the contract). `ChargeStatus` model has a STALE/misleading comment "seeded per tenant (NOT
shared UUIDs)" — the model is actually central; comment is wrong, not the code.

## charge_concept_category classification (RESOLVED — not a bug)
Its `create` migration (2026_05_31_154415) uses `BaseModel::columns($table, false)` and a table
comment "Global catalog". BUT central migration
`backend/database/migrations/2026_06_11_120000_make_editable_catalogs_tenant_scoped.php`
retro-fits tenant_id + FK + index + unique(['tenant_id','name']) onto 12 editable catalogs
INCLUDING charge_concept_category, converting it to tenant-scoped. Final schema matches the
TenantCatalogModel. The original create migration's `false` + "Global catalog" comment are now
STALE/misleading (MEDIUM doc-drift), not a leak. Seeded per-tenant by TenantCatalogSeeder.

## Unique indexes (all per-tenant)
- charge_concept: `CREATE UNIQUE INDEX ... (tenant_id, clave) WHERE deleted_at IS NULL`
- charge_batch monthly: partial unique (tenant_id, charge_concept_id, year, month) WHERE not deleted & month not null
- payment receipt: `(tenant_id, receipt_number)` — NOTE: NO `WHERE deleted_at IS NULL` (intentional;
  cancelled payments keep their folio; payment uses no soft-delete on receipt reuse). Per-tenant → safe.
- credit_balance: `unique(['tenant_id','unit_id'])`
- receipt_sequence: `unique(['tenant_id','year'])`
- charge batch-unit: `(charge_batch_id, unit_id)` partial — tenant-isolated via FK to tenant-scoped charge_batch.

## payer_user_id (central User) — handled correctly
Payment.payerUser() does `->withoutGlobalScope(UserTenantScope::class)` (central user accessed
from tenant table). bare uuid column, nullable, no FK. submit() sets it to auth user; register()
takes it optional. No cross-tenant user leak.

## Crons — all per-tenant via $tenant->run()
GenerateMonthlyChargesCommand, RefreshChargeStatusesCommand, SendChargeRemindersCommand all do
`Tenant::all()` then `$tenant->run(fn)` (Stancl TenantRun concern → initializes+ends tenancy per
tenant). Periodicity (central) is hoisted OUTSIDE run() correctly. All Charge/Payment/Unit queries
inside run() are TenantScope-scoped. No global-scope iteration over charges/payments.

## Queries — no scope escapes
Only `withoutGlobalScope` in module = Payment::payerUser (UserTenantScope, justified). All `DB::`
usages are `DB::transaction` wrappers (approved). One `whereRaw('paid_amount < (expected_amount -
discount_amount)')` = read-side column comparison on already-scoped Charge::query() — safe. All
service entry points (PaymentService, ChargeGenerationService, ChargeStatusService, TargetResolver,
CollectionsReportService) go through Eloquent tenant models. Reports use Charge::query() scoped.
Resident read-scoping via ScopesToResidentUnits + UnitUser pivot (tenant-scoped).

## RESIDUAL GAP (MEDIUM, data-integrity not leak)
PaymentController::register validates `unit_id`/`bank_account_id` as bare `['required','uuid']`
with NO tenant-scoped `Rule::exists`. PaymentService::register does Payment::create(['unit_id'=>...])
which STAMPS current tenant_id but does NOT verify the unit/bank belong to the current tenant.
A treasurer could submit a foreign unit_id → payment row stamped to own tenant referencing another
tenant's unit (orphan-ish FK). Does NOT leak foreign data to attacker (no foreign rows returned),
but breaks integrity. Other write paths are safe: submit() checks UnitUser ownership; ChargeCreateRequest
uses `Rule::exists('unit','id')->where('tenant_id', tenant('id'))`; manual_applications charges are
scoped by `whereIn(id)->where('unit_id')` + TenantScope. Fix: add tenant-scoped exists to register.

## due_soon_days per-policy override (audited 2026-06-25) — SAFE

### New tables/columns
- `charge_policy.due_soon_days` smallInteger nullable — ALTER migration
  `2026_06_25_000001_add_due_soon_days_to_charge_policy_table.php`. Table was originally
  created with `BaseModel::columns($table, true)`. ALTER adds only the column; no `tenant_id`
  needed in the ALTER itself. ChargePolicy already has `BelongsToTenant`. ✅
- `charge.due_soon_days` smallInteger nullable — ALTER migration
  `2026_06_25_000002_add_due_soon_days_to_charge_table.php`. Same pattern; `charge` table
  already tenant-scoped. ✅

### Snapshot at generation time
ChargeGenerationService::activateBatch() reads `$batch->policy` (eager-loaded, BelongsToTenant
+ TenantScope) and snapshots `$policy->due_soon_days` → `charge.due_soon_days`. Both batch and
policy are same-tenant by construction. No cross-tenant query. ✅

### resolveStatusKey per-charge override
`$charge->due_soon_days ?? $thresholds['due_soon_days']` — reads integer from the charge's own
column (no new query). `$thresholds['due_soon_days']` is already tenant-resolved (via
Setting::getValue inside $tenant->run()). ✅

### Setting::getValue is tenant-scoped
Setting model uses `BelongsToTenant`; `static::query()` auto-adds TenantScope. Called inside
$tenant->run() in both the cron (RefreshChargeStatusesCommand) and HTTP requests. ✅

### ChargeStatusService has no shared mutable state
No instance properties cache thresholds. `$thresholds` is a local in `refreshAllOpenCharges()`.
Same service instance reused across tenants in cron is safe. ✅

### New models in this change
- ChargePolicy: `BelongsToTenant` ✅ — also confirmed in migration with `BaseModel::columns($table,true)`
- ChargeSurchargeRule: `BelongsToTenant` ✅
- surcharge_rules eager-load in refreshAllOpenCharges: scoped inside $tenant->run() ✅

### CollectionSettings helper
New `Modules/Collections/app/Support/CollectionSettings.php` — pure constants class. No queries,
no state. Eliminates slug-string drift between cron, service, seeder, and config controller. ✅

## Note (out of Collections scope)
Tenant model still uses HasDatabase + TenantWithDatabase despite single-DB. Central Tenant module,
not Collections — flagged only as context.
