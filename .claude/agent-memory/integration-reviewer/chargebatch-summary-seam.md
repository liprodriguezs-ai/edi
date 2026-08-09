---
name: chargebatch-summary-seam
description: ChargeBatch summary KPI endpoint + by-unit charges pagination seam (2026-06-26). Server-side aggregation replaced client reduce. All 6 checks PASS.
metadata:
  type: project
---

# ChargeBatch summary + pagination seam (2026-06-26)

Server-side KPI aggregation `GET /charge-batch/{id}/summary` replaced a client-side
reduce over a 100-row charge sample; real server pagination added to the by-unit table.
All six contract checks PASS — no blocking issues. See [[contract-conventions]],
[[collections-seam]].

## Verified contract facts (reusable)

- **Custom-action path vs Orion basePath**: ChargeBatchResource `basePath: '/charge_batch'`
  (underscore, Orion resource) but ALL custom methods hit `/charge-batch/...` (hyphen).
  Backend routes match: `Orion::resource('charge_batch', …)` + `Route::*('charge-batch/{id}/…')`.
  This underscore/hyphen split is INTENTIONAL and correct — do not flag it.
- **Envelope unwrap**: custom GET `summary` returns `response()->json(['data'=>[...]])`;
  FE does `const { data } = await apiClient.get<{data:T}>(...); return data.data`. apiClient
  response interceptor (apiClient.ts:177-190) returns response AS-IS (no envelope unwrap),
  so one axios `.data` + one `.data` envelope = single correct unwrap. Same pattern as
  `show()` (resourceApi.ts:97), `activate`, `cancelBatch`. Orion list/search return the
  envelope directly (data+meta) — those do NOT do `.data.data`.
- **Money double-source pattern (IMPORTANT)**: TWO correct money paths coexist on the same
  page. (a) Summary KPIs come from `selectRaw` → RAW integer minor units → FE renders with
  `formatMinorUnits()` (divides by 100). NO `moneyToMinorUnits`. (b) Per-row table charges
  come through Orion → MoneyIntegerCast serializes `{amount,currency,formatted}` object → FE
  `toChargeRows` uses `moneyToMinorUnits(charge.expected_amount)` to read `.amount`. Both
  correct because different serialization. `formatMinorUnits` = for raw ints; `moneyToMinorUnits`
  = for the cast object. Do not "fix" one to match the other.
- **Outstanding formula parity**: summary SQL and FE `toChargeRows` BOTH compute
  `max(0, expected - discount - paid)`. Per-row faltante sums to the summary's `outstanding`.
- **Pagination param placement**: `resourceApi.search()` extracts `page` (and `active`) into
  the QUERY STRING (`/charge/search?page=N`), leaves `limit`+`filters`+rest in the POST body.
  Orion/Laravel `Paginator::resolveCurrentPage()` reads `page` via `request->input()` which
  merges query+body → query placement works. `limit` in body also read via `get()`/`input()`.
  FE `lastPage = ceil(meta.total / pageSize)`; `response.meta.total` is the field (Orion
  envelope `meta.total`). `is_cancelled=false` filter makes meta.total = non-cancelled count,
  which equals summary `total` for the same user.

## Resident scoping parity (check 5)

Both summary (ChargeBatchController::summary) and the table (ChargeController::buildSearchFetchQuery)
apply IDENTICAL resident scoping via `ScopesToResidentUnits`:
`lacksBoardReadAccess()` (lacks `read.payment` AND `*`) → `whereIn('unit_id', ownUnitIds())`.
KPIs and rows agree for a resident.

### Latent divergence (NOT exercised here — note for future)
- ChargeController::buildSearchFetchQuery ALSO calls `applyOwnerFilter` (owner_ids filter);
  the summary does NOT. The batch-detail table (useBatchUnitCharges) does NOT send owner_ids,
  so no divergence today. IF this table ever adds an owner_ids filter, the summary KPIs would
  drift from the rows. Flag then.
- Summary authorizes `read.charge_batch`; table authorizes `read.charge` (different abilities).
  Resolves to fine: loading ChargeBatchDetail already requires `read.charge_batch` (the batch
  fetch), so summary adds no NEW gate. Residents hold `read.charge`.

## Invalidation (check 6) — PASS
Summary queryKey `['charges','batch',id,'summary']` and table `['charges','by-batch',id,page]`
both carry the `['charges']` prefix → `invalidateQueries({queryKey:['charges']})` after batch
activation refetches both.
