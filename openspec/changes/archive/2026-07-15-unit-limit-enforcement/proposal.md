# Proposal: Unit Limit Enforcement

## Intent

Plans are sold by cluster-size bands (`billing_package.suggested_unit_min/max`: Básico 1-25 · Inicial 26-50 · Esencial 51-100 · Avanzado 101-200 · Premium 201-300) but nothing enforces them — a Básico tenant can hold 5,000 units at Básico price. The Excel importer bulk-creates thousands per run; even `POST /unit` is unguarded. Locked decision: BLOCK over-band increases, with a clear warning BEFORE execution. Not in production → no grandfathering needed.

## Scope

### In Scope
- One central guard on `Unit` `creating`/`restoring` (existing `UnitObserver`) covering all 5 count-increasing paths: `POST /unit`, `POST /unit/batch`, `POST /unit/{id}/restore`, `POST /unit/batch/restore`, importer (`RowImporter::execute()`).
- Batch atomicity: N units in one request validated as a batch — 25/25 plus a batch of 10 admits none.
- Importer preview blocks pre-execution, reusing `ImportRunner::calculateDistinctCounters()` (`units_to_create`): fail when `current_active + units_to_create > band_max`. No per-row queries.
- Spanish (MX) block copy: plan name, band limit, current active count, attempted increase; names the next fitting band as upgrade hint; degrades gracefully at Premium (never link a nonexistent plan).
- Frontend surfacing: unit create/batch/restore errors + import validation preview warning.
- Backend tests: each path, batch atomicity, restore semantics, soft-delete exclusion, top-band copy.

### Out of Scope
- Downgrade guard in `ConfigurationController::changeBillingPlan` (user decision).
- Enterprise/unlimited tier — separate change; build with the 5 existing bands.
- Backfill/grandfathering for over-band tenants (not in production).
- Stripe quantity/usage reporting; auto-upgrade flows.
- Resurrecting `subscription_plan.unit_limit` (table dropped; dead trail).

## Capabilities

### New Capabilities
- `unit-limit-enforcement`: billing-band cap on the active unit count, enforced on every count-increasing path, with pre-execution warning in the importer.

### Modified Capabilities
- None (`openspec/specs/` has no merged specs yet).

## Approach

New `UnitLimitService` beside `PlanPricingService` (`Modules/SubscriptionPlan`): resolves the band via ACTIVE `tenant_entitlement` → `billing_package.suggested_unit_max` (defensive on 0 or >1 distinct packages), counts active units (`deleted_at IS NULL`, tenant-scoped — mirrors `unit_identity_unique` liveness), validates a requested delta. Hooked in `UnitObserver` (`creating`/`restoring`), throwing a domain exception; controllers/importer translate it to an Orion-envelope 422 with the Spanish copy. Orion-wrapped transactions roll back the whole batch on the first over-limit row. Design owns final wiring (batch pre-flight vs per-row backstop) and must justify any departure from the observer approach.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` | New | Band resolution + active count + validation |
| `backend/Modules/Housing/app/Observers/UnitObserver.php` | Modified | Guard on creating/restoring |
| `backend/Modules/Housing` (UnitController / requests) | Modified | Translate failure to Spanish 422 |
| `backend/Modules/DataImport/app/Services/{ImportRunner,RowImporter}.php` | Modified | Preview blocking error + execute backstop |
| `frontend/src/modules/` (unit + data-import UI) | Modified | Surface block message / preview warning |
| Housing + DataImport tests | New | Path coverage + atomicity |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Unresolvable band (no entitlement / >1 package) | Low | Defensive branch; `SubscriptionStateService` already blocks no-entitlement tenants; design picks fail-open+log vs fail-closed |
| Concurrent requests racing the count | Low | Transactional check; design decides locking; pre-production traffic |
| Observer trips seeders/factories | Low | Dev data fits bands (Encinos 6/25); adjust factories if needed |
| Execute-time count cost on big imports | Med | Preview uses 6 constant queries; count once per request, not per row |

## Rollback Plan

Code-only, no migration: revert the commit — remove the observer check, `UnitLimitService`, preview validation, and frontend copy. No data cleanup.

## Dependencies

- `ImportRunner::calculateDistinctCounters()` (commit `491b220`) — shipped.
- Seeded `billing_package.suggested_unit_max` bands — live.

## Success Criteria

- [ ] All 5 paths reject increases past `band_max` with Spanish copy; reaching exactly `band_max` succeeds.
- [ ] Over-cap batch admits zero units (atomic).
- [ ] Import preview warns BEFORE execution, e.g. "Tu plan Básico permite 25 unidades; este archivo crearía 60".
- [ ] Soft-deleted units never count; restores count against the band.
- [ ] Premium at 300 gets graceful copy, no upgrade link.
- [ ] Baselines green: DataImport 72 Unit + 47 Feature; Housing tests.
