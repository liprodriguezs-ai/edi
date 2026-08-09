---
name: payment-detail-seam
description: GET /payment/{id} includes contract — PaymentController vs PaymentDetailPage (2026-06-28 review)
metadata:
  type: project
---

Reviewed 2026-06-28.

## Backend includes (PaymentController.php:29-34)

```
'unit', 'unit.unitType', 'unit.tower',
'payment_status', 'payment_method', 'bank_account',
'applications', 'applications.charge', 'applications.charge.concept', 'applications.charge.batch',
'payer_user', 'media',
```

## Frontend PAYMENT_DETAIL_INCLUDE (PaymentDetailPage.tsx:27-40)

Identical list (character-for-character).

## Auth gate

- Frontend route `/payment/:id` at App.tsx:331 is under `PermissionRoute permissionEntity="payment_application"`.
- Backend `authorize('read.payment')` checks token ability or falls back to unit-ownership pivot check (PaymentController.php:57-78).
- Intentional design (documented in App.tsx comment 325-328): residents have `read.payment_application` but not `read.payment`; backend ownership check covers the resident path.

## Key field mappings

- `payment.unit?.full_identifier` ← `unit.unitType` + `unit.tower` accessor (no N+1)
- `formatChargePeriod(charge)` reads `charge.batch?.month/year` ← `applications.charge.batch`; IChargeBatch has both `year: number` and `month?: number | null`
- Media: `collection_name` + `file_name` + `id` — standard Spatie fields, included via `media`

## Status: ALIGNED

[[click-action-seam]]
