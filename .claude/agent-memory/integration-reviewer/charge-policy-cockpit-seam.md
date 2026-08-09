---
name: charge-policy-cockpit-seam
description: ChargePolicy cockpit back↔front contract — routes, includes, direct-mode payload, batch/charge filters all verified aligned
metadata:
  type: project
---

The ChargePolicy cockpit (`ChargePolicyCockpitPage` + `CockpitBatchList` + `CockpitDirectScope` + `BatchUnitChargesTable`) seam was reviewed 2026-06-25 and is ALIGNED with the backend.

Verified contracts (all Orion resources under Collections module):
- **Routes**: frontend `ChargePolicyResource` basePath `/charge_policy`, `ChargeBatchResource` `/charge_batch`, `ChargeResource` `/charge` — all match `Orion::resource(...)` registrations.
- **Permission key**: routes gated `PermissionRoute permissionEntity="charge_policy"`; controller `$keyForPermission = 'charge_policy'`; CSV permissions exist (`read/create/update/delete/restore/report.charge_policy`).
- **show() includes**: cockpit `ChargePolicyResource.show(id, { include: 'concept,amount_mode,periodicity' })` — all 3 in controller `includes()`. Same `include` query-param pattern as the established `ChargeBatchDetailPage.show(id, { include: 'policy,policy.concept,status' })`.
- **Batch filter**: `CockpitBatchList` searches `charge_batch` with `filters: [{ field: 'charge_policy_id', operator: '=', value }]` + `includes: [{ relation: 'status' }]`. Both whitelisted (`filterableBy` has `charge_policy_id`, `includes()` has `status`).
- **Charge filter (drill)**: `BatchUnitChargesTable` searches `charge` with `filters: [{ field: 'charge_batch_id', operator: '=', value }]` + `includes: [{ relation: 'unit' }, { relation: 'charge_status' }]`. All whitelisted. `unit.full_identifier` is an `$appends` accessor on Unit → serializes automatically.
- **Direct-mode payload**: `buildPolicyCreatePayload` sends `target_type/target_selector/issued_at/due_date` for direct, `valid_from/valid_to` for periodic — exactly the create-request's prohibited_if/required_if contract. `ChargePolicyService::create` reads those keys and calls `generateDirectBatch` only when `is_active`. `by_share` sends `target_selector: null` → `TargetResolver` resolves it to all-active (null selector OK).
- **Money**: amounts converted pesos→minor units via `pesosToMinorUnits`; request validates `integer` minor units. Aligned with money-contract.
- **limit: 100** in both batch/charge searches == Orion `maxLimit()` (boundary, accepted).

**Why:** The cockpit replaced the old fragmented policy-form + batch-views; confirming the seam prevents the classic "both sides green individually, contract broken" failure.
**How to apply:** When extending the cockpit (e.g. wiring the recargos slot to `surcharge_rules`), the create/update requests already validate `surcharge_rules.*` — the frontend just needs to send the array; no new endpoint. See [[charge-policy-selects-mapper]] for the `/selects` vs Orion-includes distinction.
