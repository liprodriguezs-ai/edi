# Proposal: Plan Downgrade Guard

## Intent

`unit-limit-enforcement` (backend `f25114e`) closed the entry door: units cannot be added past the plan band. The exit window is still open: a cluster admin can self-serve switch to a SMALLER band and keep every unit — an Avanzado tenant (101–200, $1,550/mo) with 200 units drops to Básico (1–25) at $450/mo. Direct revenue leak; this is the guard the dead `subscription_plan.unit_limit` docblock promised via a `TenantService::changePlan` that never existed. Not in production yet → close it now, no grandfathering.

## Scope

### In Scope
- Inverse check on `UnitLimitService`: does the CURRENT active-unit count fit a TARGET `BillingPackage.suggested_unit_max`? NULL = unlimited = always fits. Reuse `UnitLimitCheck` DTO + `UnitLimitExceededException` (`ShouldntReport`, self-rendering 422) — no parallel types.
- Block in `ConfigurationController::changeBillingPlan` (:1340) — the cluster-admin apply path.
- Warning surfaced in `ConfigurationController::previewBillingPlanChange` (:1388), so the admin sees it BEFORE applying.
- Frontend plan-change UI renders the preview warning and the 422. Copy in Mexican Spanish (tú) with concrete numbers, e.g.: "Tu clúster tiene 200 unidades activas; el plan Básico admite hasta 25. El plan que se ajusta es Avanzado (101–200)."
- Backend block/pass test matrix + frontend coverage.

### Out of Scope
- Super-admin preferential swap (`PreferentialSwapController`) — deliberately EXEMPT (negotiated commercial tool). Its `tokenCan('update.tenant')` constructor gate (:37–45) already bounds the exemption; change nothing there.
- Backfill/grandfathering for over-band tenants (not in production).
- Enterprise/unlimited tier (deferred; NULL handling future-proofs it).
- Harmonizing with `unit-limit-enforcement`'s no-bypass design — both asymmetries are intentional user decisions.
- Any refactor of `ConfigurationController`.

## Capabilities

### New Capabilities
- `billing-plan-downgrade-guard`: cluster-admin billing-plan changes to a band smaller than the current active-unit count are blocked and pre-warned in preview; super admin bypasses.

### Modified Capabilities
None (no `openspec/specs/` tree exists yet).

## Approach

Guard at the CONTROLLER, never inside `PreferentialSwapService`: both actors funnel into the same `applyCard` (:88) / `applyManual` (:141), so a service-level guard would block the super admin and violate the locked bypass decision. The codebase already draws actor lines at this exact seam — `is_public` is "enforced here, not in the service" per the controller docblock. This is the INVERSE of the sibling change, where the model-observer choke point was right precisely because it had to catch every actor. Block condition: target `suggested_unit_max` non-NULL AND < current active count. Upgrades and same-band changes are never blocked.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` | Modified | New target-fits method (reuses tenant_id-filtered count) |
| `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` | Modified | Guard call in the two methods — minimal insertion, no refactor |
| `frontend/src/modules/configuration/**` (plan-change UI) | Modified | Render preview warning + 422 copy |
| `backend/Modules/SubscriptionPlan/tests/**` + Configuration feature tests | New | Block/pass matrix |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `ConfigurationController.php` carries uncommitted parallel-session WIP (shared checkout; superproject shows `M backend`; executor had no shell for `git status`) | Med | Anchors :1340–1409 verified intact at proposal time; smallest possible touch; re-diff before commit |
| Future route calls `PreferentialSwapService` directly, skipping the controller guard | Low | Spec scenario + service docblock document the deliberate actor-aware placement |
| Units added between preview and apply | Low | Apply re-checks; preview is advisory |

## Rollback Plan

Revert the commit(s). Purely additive — no schema change, no data migration, no config. Removing the guard calls + service method restores prior behavior exactly.

## Dependencies

- `unit-limit-enforcement` (backend `f25114e`) — already on `main`.

## Success Criteria

- [ ] Cluster admin applying an under-band plan gets 422 with concrete-numbers copy; plan unchanged.
- [ ] Preview surfaces the same warning before apply.
- [ ] Upgrades, same-band, and NULL-max targets are never blocked.
- [ ] Super-admin swap + preview behave exactly as before (bypass, both paths).
- [ ] `UnitLimitServiceTest` baseline (11/11) stays green; new guard tests pass.
