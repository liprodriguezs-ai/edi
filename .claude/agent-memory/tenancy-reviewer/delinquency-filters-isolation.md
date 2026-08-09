---
name: delinquency-filters-isolation
description: SAFE pattern — server-side filters on GET /delinquency(+/report) with tenant-scoped Rule::exists on client ids + whereHas-only search chain
metadata:
  type: pattern
---

# Delinquency server-side filters (PaymentController) — reviewed 2026-07-19, SAFE

- `validateDelinquencyFilters()` (~:454): `charge_concept_ids.*` uses `Rule::exists('charge_concept','id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')` → foreign-tenant concept = 422, indistinguishable from non-existent (no existence oracle). Double-safe: even if it slipped, `Charge::whereIn('charge_concept_id',…)` is TenantScoped so foreign concepts filter nothing. If tenancy uninitialized, `tenant('id')` = null → `where tenant_id = null` never matches on a NOT NULL column → fail-closed.
- `delinquencyRows()` search (~:520): pure Eloquent `whereHas` chain — Charge/Unit/Tower/UnitUser all `BelongsToTenant`, UnitType is `TenantCatalogModel`; every subquery inherits TenantScope. `whereRaw('LOWER(col) LIKE ?', [$term])` inside whereHas = parameterized binding, adds a condition without dropping the scope. UnitUser.user strips `UserTenantScope` (canon §9) — safe because reachability is bounded by tenant-scoped unit_user rows.
- Export `/delinquency/report` reuses the exact same `delinquencyRows(validateDelinquencyFilters())` path + `report.payment` gate; cannot drift.
- Routes in `$customActionMiddleware` group: auth:sanctum → rejectRefreshToken → authActive → initializeTenancy. Correct order.
- Known non-issues to not re-flag: unescaped `%`/`_` LIKE wildcards (tenant-bounded, cosmetic); super-admin without X-Tenant-Id runs unscoped by design (validation fail-closes anyway).
- Test `DelinquencyFilterTest::test_invalid_filters_are_rejected_with_422` genuinely covers the cross-tenant 422 (two real tenants, foreign concept id).
