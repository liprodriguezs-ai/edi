---
name: collections-seam
description: Collections module seam review (2026-06-20): relation key drift pattern, money contract, custom-action envelopes, grace_days cap mismatch.
metadata:
  type: project
---

# Collections seam — findings (2026-06-20)

## ⛔ CORRECTION (2026-06-25): the "relation key drift" finding below was WRONG

EMPIRICALLY DISPROVEN. Laravel's `HasAttributes::$snakeAttributes = true` (the
default, NOT overridden in BaseModel) makes `relationsToArray()` SNAKE-CASE every
relation key on serialization. Verified by booting the app and serializing a model:
`setRelation('paymentStatus', …)->toArray()` yields the key `payment_status`, NOT
`paymentStatus`. So the FE snake_case interfaces (`payment_status`, `charge_status`,
`bank_account`, `charge_batches`, …) were ALWAYS CORRECT — values do NOT resolve to
undefined. The premise of plan 034 and laravel-backend-architecture skill §2.4 ("Eloquent serializes
under the EXACT method name") is FALSE.

THE REAL CONTRACT: relation JSON keys are ALWAYS snake_case. But the INCLUDE REQUEST
must use the exact METHOD name (camelCase if the method is camelCase, e.g. request
`include=chargeBatches`) — Orion/Eloquent resolve includes by method name, the
response then serializes them as `charge_batches`. The two casings legitimately
differ for camelCase methods. A real "—"/blank bug here would come from the
include REQUEST casing or the include not being whitelisted, never from the read key.

The original (incorrect) finding is kept verbatim below for history — DO NOT act on it.

---

## (HISTORICAL, INCORRECT) Relation key drift (snake_case interface vs camelCase JSON)

Eloquent serializes relation names EXACTLY as the PHP method name. This project
deliberately uses camelCase method names for all Payment relations and for
Charge.chargeStatus. The TS interfaces use snake_case for these keys instead
of camelCase, causing every relation value to be silently `undefined`.

### Affected pairs

| Backend model | PHP method | JSON key on wire | TS interface key | Reads null? |
|---------------|------------|-----------------|------------------|-------------|
| Payment | `payerUser()` | `payerUser` | `payer_user` | YES |
| Payment | `paymentMethod()` | `paymentMethod` | `payment_method` | YES |
| Payment | `paymentStatus()` | `paymentStatus` | `payment_status` | YES |
| Payment | `bankAccount()` | `bankAccount` | `bank_account` | YES |
| Charge | `chargeStatus()` | `chargeStatus` | `charge_status` | YES |

### Files
- Backend: `backend/Modules/Collections/app/Models/Payment.php` (L63,71,75,79,83)
- Backend: `backend/Modules/Collections/app/Models/Charge.php` (L70)
- Frontend: `frontend/src/modules/payment/schema.ts` (L40-44)
- Frontend: `frontend/src/modules/charge/schema.ts` (L48)
- Consumer: `frontend/src/modules/payment/PaymentListPage.tsx` (L278-333)
- Consumer: `frontend/src/modules/payment/StatementPage.tsx` (L193-219)
- Consumer: `frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx` (L77-116)
- Consumer: `frontend/src/modules/chargeBatch/ChargeBatchDetailPage.tsx` (L195-234)

### Impact
- Payment status pill always shows "—" (silent undefined on `payment_status?.name`)
- Payer name column always empty
- Payment method column always "—"
- Bank account always "—"
- Charge status pill always "—" across ChargeList, BatchDetail, StatementPage

## Validation cap mismatch

Backend grace_days: max:365 (`ChargeConceptCreateRequest.php:38`)
Frontend schema: max:60 (`chargeConcept/schema.ts:68`)

FE will reject valid values that backend would accept (61-365).

## TreasurerHome: `bank_account_balances` field consumed but not in any backend endpoint

TreasurerHome reads `summary?.bank_account_balances` from `ExpenseResource.publicSummary()`
(treasury module, not collections). This is out of this reviewer's scope but noted.

## Confirmed alignments

- All custom action HTTP verbs and paths match (POST, path patterns correct)
- Money: all send/receive minor units correctly. Charge concept form converts pesos→minor units at send; Charge batch uses `amount_override.fixed_amount` (integer minor units) correctly.
- Pagination: Orion envelope consumed correctly on list/search calls
- Error handling: 422 from custom actions parsed via `formatApiErrorMessage(ApiError)` correctly
- Statement response shape matches schema definition exactly (unit_id, charges, credit_balance_minor_units, totals)
- Delinquency response shape matches (units_with_debt, total_outstanding_minor_units, data[])
- Aging report (collections/aging): ICobranzaPayload shape matches CollectionsReportService output
- Permission entity keys: `charge`, `charge_batch`, `charge_concept`, `payment` all match `$keyForPermission`
- cancel.charge / discount.charge abilities exist in permissions seed and are checked correctly in frontend PermissionGuard
- create.payment_submission ability exists in seed and in backend submit() check
- All Orion includes whitelisted in includes() match what frontend requests
