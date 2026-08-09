---
name: delinquency-dashboard-filters-seam
description: Server-side delinquency filters (GET /delinquency + /delinquency/report) — params/units/shape aligned; 2 WARNINGs (silent 422 on the dashboard query, unit_id nullability drift).
metadata:
  type: project
---

# Delinquency dashboard filters seam (reviewed 2026-07-19)

## Contract (verified aligned)

- `GET /delinquency` + `GET /delinquency/report` share `validateDelinquencyFilters()`
  (PaymentController:454): `search` (max:255), `charge_concept_ids[]` (uuid, exists
  tenant-scoped, max:50), `min_months_overdue` (int 1–120), `min_amount`/`max_amount`
  (int minor units, 0–MoneyLimit::MAX). FE `DelinquencyQueryParams` matches name-for-name.
- Arrays: apiClient has NO custom `paramsSerializer` → axios default emits
  `charge_concept_ids[]=a&...` which Laravel parses as an array. Correct; do not "fix".
- Money: UI captures PESOS, converts with `pesosToMinorUnits` (Math.round → true int,
  survives the `integer` rule even with step 0.01). Response money is TenantMoney
  objects (`outstanding`, `total_outstanding`); FE reads via `displayMoney`/`moneyToMinorUnits`.
- Response: flat custom JSON `{units_with_debt, total_outstanding, data[]}` (no Orion
  envelope). `data[]` rows carry `unit_full_identifier` (accessor, eager-loaded
  unitType+tower to avoid N+1).
- Export parity: BOTH table query and `delinquencyReport` use the same `debouncedParams`
  object → file always matches the on-screen (debounced) table. Pattern to keep.
- No cross-field min>max rule ON PURPOSE (yields empty set, parity with Orion range
  filters) — do not flag as missing validation.

## Known gaps (flagged 2026-07-19, WARNING)

1. **Silent 422 on the dashboard query**: `useQuery` in DelinquencyPage has no
   `isError`/toast, and `keepPreviousData` freezes the stale table. FE inputs don't
   clamp what the backend rejects: RangeFilter `type=number` allows negatives
   (min:0 fails), search Input has no maxLength=255. Typing "-5" in Mín $ → silent
   422, table stops updating with zero feedback. Export path IS handled (blob error
   JSON re-parsed by apiClient, generic toast).
2. **`unit_id` nullability drift**: backend row shape is `unit_id: string|null`
   (charge whose unit was soft-deleted → `unit` relation null); FE `DelinquencyUnit.unit_id`
   is non-null `string` and calls `unit.unit_id.slice(0,8)` + uses it as row key /
   statement link → TypeError on that edge. Backend export map already null-guards
   (`?? ''`), FE type does not.

## Pattern

Recurring: RangeFilter + pesosToMinorUnits is now the standard "pesos UI ↔ minor-units
API" filter bridge (same as delinquency-settings PUT). When reviewing future money
filters, check: Math.round int, min:0 clamp client-side, and 422 surfacing on GET queries.
