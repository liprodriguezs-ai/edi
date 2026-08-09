---
name: data-import-review
description: Tenancy review findings for Modules/DataImport payment importer (2026-06-13) — result: SAFE with two WARNINGs
metadata:
  type: project
---

Reviewed on 2026-06-13. Module: `Modules/DataImport` — payment importer (Excel rows → Unit + User + UnitUser + Payment).

**Result: SAFE. No CRITICAL findings. Two WARNINGs, two SUGGESTIONs.**

## Findings summary

### WARNINGs

1. **TemplateGenerator catalog queries are NOT tenant-scoped (RowImporter.php, TemplateGenerator.php)**
   - `UnitType::query()->orderBy('order')...` — no `->where('tenant_id', tenant('id'))`.
   - `UnitStatus::query()->orderBy('name')...` — no `->where('tenant_id', tenant('id'))`.
   - `BankAccount::query()->orderBy('name')...` — no `->where('tenant_id', tenant('id'))`.
   - These models have `BelongsToTenant` so `TenantScope` fires ONLY if tenancy is initialized. Since the template endpoint IS behind `initializeTenancy` middleware, TenantScope will fire. However, the code relies on implicit scoping with no explicit defensive `->where('tenant_id', tenant('id'))` — risky if route middleware order ever changes or if a super-admin calls without init.
   - Fix: add explicit `->where('tenant_id', tenant('id'))` on all three queries in TemplateGenerator.

2. **ImportRunner::buildBankBalanceDelta uses `BankAccount::find($accountId)` without tenant scoping (ImportRunner.php:243)**
   - `BankAccount` has `BelongsToTenant` so TenantScope applies at this point (job is in tenant context). BUT: `find()` by PK bypasses `TenantScope` in Stancl v3 when called without active tenancy — this runs after all rows, in the same job, so tenancy IS initialized. Low actual risk but the ID was accumulated from the same tenant context so it will resolve correctly. Still: the lookup is cosmetic (for the report label), not a data write, so the blast radius is zero. Document, don't escalate to critical.

### Correctly handled (COMPLIANT)

- Payment idempotency: both folio and natural-key lookups include `->where('tenant_id', tenant('id'))`. CLEAN.
- Unit find-or-create: `Unit::where('tenant_id', tenant('id'))...` explicit. CLEAN.
- UnitUser find-and-create: `UnitUser::where('tenant_id', tenant('id'))...` explicit. CLEAN.
- BankAccount find-or-create: `BankAccount::where('tenant_id', tenant('id'))...` explicit. CLEAN.
- User lookup: `User::withoutGlobalScope(UserTenantScope::class)->where('email', ...)` — correct pattern for central User table. CLEAN.
- User-to-tenant attachment after creation: `$user->tenants()->attach(tenant('id'))` — correct. CLEAN.
- UserProfile creation: `UserProfile` has `BelongsToTenant` so `BelongsToTenant` stamps `tenant_id` on create. CLEAN.
- ImportRun model: `use BelongsToTenant` + `BaseModel::columns($table, true)`. Migration correct. CLEAN.
- `GET /import/{id}/status` endpoint: `ImportRun::findOrFail($id)` — `BelongsToTenant` adds `TenantScope` so cross-tenant read is blocked. CLEAN.
- RunImportJob: `QueueTenancyBootstrapper` carries tenant_id into queue payload and re-initializes on worker side before any query runs. CLEAN.
- ReferenceResolver memoization: cache is instance-scoped per job run (`private array $cache = []`), one instance per `RunImportJob` via IoC. No cross-tenant bleed between runs. CLEAN.
- Routes: all four endpoints behind `['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']`. Middleware order correct (auth before tenancy init). CLEAN.
- ReferenceResolver central catalogs (PaymentMethod, UnitRelationship): correctly NOT scoped to tenant_id. CLEAN.

## Decisions documented

- `User` is central (no `tenant_id`) — email lookup is INTENTIONALLY global. Cross-tenant user identity collapsing is design intent (same email = same person).
- After finding an existing user, `tenants()->attach(tenant('id'))` ensures the user is linked to the current cluster even if they existed from another cluster — this is correct and expected multi-cluster onboarding behavior.
