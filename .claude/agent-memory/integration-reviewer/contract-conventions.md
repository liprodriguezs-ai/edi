---
name: contract-conventions
description: Project-wide back↔front contract conventions for Kivo7 (Orion envelope, validation error shape, money minor-units, UUID ids). Apply as review criteria.
metadata:
  type: project
---

# Kivo7 contract conventions (back ↔ front)

- **IDs**: all UUID strings (`HasUuids`). FE types ids as `string`. Never `number`.
- **Money**: bigInteger MINOR UNITS (cents) on the wire. FE divides by 100 for display (`Intl.NumberFormat es-MX MXN`). E.g. import `bank_balance_delta[].delta` and `formatMoney(minorUnits/100)`.
- **Validation errors (422)**: `{ message, errors: { "field.path": [msg, ...] } }`. DataImport keys row errors as `rows.<n>.<col>` to match the FE `ApiError.payload.errors` contract. FE `apiClient` throws `ApiError` carrying `.payload`.
- **Custom (non-Orion) endpoints** return hand-rolled JSON `{ message, data }` or bespoke shapes — these do NOT auto-track model columns, so FE union/interface drift is a real risk on every backend change. DataImport's 4 endpoints (template/validate/execute/status) are all custom.
- **Orion list endpoints**: `{ data: [], meta: { current_page, per_page, total, total_records_database } }` (afterSearch injects total_records_database).
- **Relation key casing (VERIFIED 2026-06-25, supersedes any "exact method name" claim)**: `$snakeAttributes=true` (Laravel default, not overridden in BaseModel) → relation JSON keys are ALWAYS serialized **snake_case** via `relationsToArray()`. Proven empirically: `setRelation('paymentStatus',…)->toArray()` ⇒ key `payment_status`. So FE relation interfaces are snake_case and CORRECT, even when the BE relation METHOD is camelCase (`chargeBatches`→`charge_batches`, `payerUser`→`payer_user`). BUT the include REQUEST string must match the exact METHOD name (e.g. `include=chargeBatches`), because Orion resolves includes by method name. Casing differs between request (method name) and response key (snake) for camelCase methods — this is correct, not drift. laravel-backend-architecture skill §2.4 and plan 034 state the opposite and are WRONG; do not trust them on this point.
- **A 422 can be a NORMAL result, not an error**: import `/validate` returns 422 with `blocked > 0` and the FE deliberately catches it, narrows the payload (`isValidateResponse`), and treats it as a successful dry-run preview. Do not flag this as a swallowed error — it's intentional.
- **Report endpoints (tableros-contables pattern)**: flat JSON object (no Orion envelope, no pagination). FE typed via dedicated `schema.ts` per dashboard. All money fields carry `_minor` suffix. Permission ability used is `read.report_*`. FE permission check pattern: `usePermissions(['read.report_X', 'report.report_X'])` — BOTH abilities ORed. Backend `authorize('read.report_X', ModelClass::class)`.
- **Report endpoint drift risk pattern**: backend services compute only what is specified in the PHP docblock. Computed derived fields that ONLY exist in the FE schema (margin_pct, avg_days_overdue, account_count, current_month_inflow/outflow_minor, recurring_pct, provider_count) are a recurring pattern — the FE schema anticipates fields that the backend does not return, causing silent `undefined` for those KPI tiles.
