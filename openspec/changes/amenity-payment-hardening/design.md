# Design: Amenity Payment Hardening

## Technical Approach

All fixes live inside the existing earmark seam. One new Eloquent relation —
`AmenityReservation::earmark_payments()` (hasMany `Payment` on `earmark_id`,
constrained to `earmark_type = 'amenity_reservation'`) — powers three needs with one
mechanism: the cron exclusion (A1), the terminal sweep (A2), and the computed
`has_pending_payment` flag (B). Backend gates are authoritative; FE mirrors them.
No new tables; only `payment_timing` is removed (D).

## Architecture Decisions

| # | Decision | Choice | Rejected | Rationale |
|---|----------|--------|----------|-----------|
| 1 | A1 grace mechanism | Live predicate: `whereDoesntHave('earmark_payments', pending)` in the cron candidate query AND an early `return false` in `isExpirable()` when a PENDING_VALIDATION earmark payment exists | `paused_at`/flag column | No schema churn; rejection "re-arms" expiry automatically because the predicate stops matching; `isExpirable` re-checks under lock so the race window is the same one the command already documents |
| 2 | Deadline after rejection | `ReservationService::extendDeadlineAfterPaymentRejection(id)`: lock, skip non-ACTIVE, then `payment_deadline = max(current, now(tz)+1d)` (PENDING_PAYMENT) / `balance_due_date = max(current, today+1d)` (CONFIRMED, outstanding>0). Called from `AmenityReservationPaymentRejectionObserver::updated()` (fires only on the straight PENDING_VALIDATION→CANCELLED transition, inside `reject()`'s tx) | No extension (reject at 00:59, cron at 01:00 = silent expiry before the resident sees the rejection); full fresh `deposit_due_days` window (over-generous) | Review time consumed the resident's window while expiry was paused; guarantee ≥1 calendar day to resubmit; `max()` never shortens an unexpired deadline. Observer delegates to the service — same placement pattern as `AmenityReservationChargeApplicationObserver::syncFromChargeApplication` |
| 3 | A2 placement | New private `rejectPendingEarmarkPayments($reservation, $reason)` in `ReservationService`, called in `cancel()` and `expire()` right after `reverseConfirmedEarmarkPayments()`; loops PENDING_VALIDATION earmark payments → `PaymentService::reject()` (nested tx = savepoint, no authz inside, symmetric: pending = status-flip only, zero ledger residue) | Folding into `reverseConfirmedEarmarkPayments` | Confirmed-reversal (full ledger) and pending-reject (status only) are different money operations; keep each narrow. Status flips CANCELLED first (existing ordering), so the observer's ACTIVE guard correctly skips the deadline extension |
| 4 | A3 confirm gate | `ReservationService.php:778`: `if ($depositRaw > 0 && $settledRaw >= $depositRaw)`. With no deposit snapshot, PENDING_PAYMENT confirms only via the `outstanding === 0` branch | Retroactive recompute of existing rows | Transition rule (explicit): rows already CONFIRMED under the old rule are NOT un-confirmed — the gate only changes future `syncFromChargeApplication` runs on PENDING_PAYMENT rows; no data migration. With-deposit flow untouched |
| 5 | B exposure | Append `->withExists(['earmark_payments as has_pending_payment' => fn($q) => $q->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)])` inside the EXISTING `buildIndexFetchQuery`/`buildShowFetchQuery` overrides + `'has_pending_payment' => 'boolean'` cast | Accessor (N+1); Orion aggregates param (client-optional) | Exact `ChargePolicyController::has_billed` precedent; single EXISTS subquery, always-on. Verified: Orion `search()` delegates to `index()` (vendor `HandlesStandardOperations.php:144-147`) — the `buildSearchFetchQuery` overrides in ChargePolicy/ChargeController are vestigial no-ops; do NOT copy them |
| 6 | C backend seam | Contract change: `PaymentEarmarkHandler::validateSubmission(array $data, bool $isSelfSubmission = false)`; `PaymentService::submit()` passes `true`, `register()` unchanged. Cash guard lives in `AmenityReservationEarmarkHandler`: `$isSelfSubmission && $data['payment_method_id'] === PaymentMethod::CASH` → 422 | Rule in `PaymentController::submit` (Collections would hardcode consumer knowledge); magic `$data['__context']` key | Registry principle: Collections never knows the consumer's policy; the amenity module owns its own cash rule. Touches both implementors (`ContributionEarmarkHandler` signature-only) |
| 7 | D migration strategy | Edit the ORIGINAL create migrations: delete `2026_07_06_100003_create_payment_timing_table.php`, remove `payment_timing_id` (col+FK) from `2026_07_06_100004_create_amenity_table.php` | Drop migration with `down()` | Module is unreleased/uncommitted — no deployed schema exists; a create+drop pair in the same unshipped series is noise, and the drop's `down()` would have to recreate a catalog + reseed fixed UUIDs. Deliberate, justified deviation from laravel-backend-architecture strict rule 12 (append-only applies to shipped history). Guard: if the module ships before apply, switch to a drop migration. Dev DBs: `migrate:fresh` + `billing:sync-stripe` (documented routine). Reversibility = git revert of the slice |
| 8 | Resident withdraw-pending | DEFER | Ship now (route + ownership guard + reuse `reject`) | A1 removes the expiry risk while a mis-submission awaits review; treasurer reject + observer notification + E's resubmit path un-stick it. Shipping it means the FIRST resident-facing mutation on a Payment row — a new authz surface deserving its own change. Residual accepted: a mis-submission blocks the corrected payment until the treasurer acts (deadline paused meanwhile) |

## Data Flow

    submit(cash?) ──[C: handler 422]      reject ──► RejectionObserver ──► extendDeadline (A1)
    pending payment ──► cron query/isExpirable EXCLUDE (A1) ──► never auto-expired
    cancel()/expire() ──► reverseConfirmed... ──► rejectPending... (A2) ──► no orphans
    withExists has_pending_payment (B) ──► FE hides Pagar, shows "Pago en revisión"

## File Changes (by PR slice; all < 400 lines, D ≈ 300 worst case)

**A — money lifecycle (backend)**: `AmenityReservation.php` (+`earmark_payments()`), `ReservationService.php` (isExpirable guard, `rejectPendingEarmarkPayments`, `extendDeadlineAfterPaymentRejection`, `REJECTION_GRACE_DAYS=1`, `:778` gate), `CancelExpiredReservationsCommand.php` (wrap the two status branches, add `whereDoesntHave`), `AmenityReservationPaymentRejectionObserver.php` (call extend before notify). Tests: `CancelExpiredReservationsCommandTest` (pending payment blocks expiry; expires after reject; deadline re-armed), `AmenityReservationEarmarkTest` (no-deposit partial stays PENDING_PAYMENT, full pays confirms; cancel rejects pending, no orphan; confirmed reversal unchanged).

**B — visibility**: `AmenityReservationController.php` (2 hooks + withExists), `AmenityReservation.php` (bool cast); FE `reservation/schema.ts` (`has_pending_payment?: boolean`), `MyReservationsPage.tsx:47` and `AmenityReservationDetailPage.tsx:220` (`&& !has_pending_payment` + "Pago en revisión" StatusPill tone `y`). Tests: backend index/show/search flag assertions (mirror `ChargePolicyScopeUpdateTest:349`); `MyReservationsPage.test.tsx`.

**C — cash restriction**: `PaymentEarmarkHandler.php`, `PaymentService.php:204`, `AmenityReservationEarmarkHandler.php` (guard), `ContributionEarmarkHandler.php` (signature only); FE `SubmitReservationPaymentSheet.tsx` (whitelist TRANSFER/DEPOSIT/CHECK, default TRANSFER, comprobante always required, drop `isCash`). `RegisterReservationPaymentSheet` untouched. Tests: submit CASH→422, register CASH→ok, submit TRANSFER→ok; FE sheet test.

**D — payment_timing teardown** (~101 occurrences / 38 files): delete `PaymentTiming.php` + migration `100003`; edit migration `100004`; `Amenity.php`, `AmenityController::includes()`, `AmenityCreateRequest`/`UpdateRequest`, `AmenityFactory`; `database/seeders/CatalogSeeder.php` (`:13`, `:109`, `seedPaymentTimings()` `:834-840`); `AngularController.php` (`:16`, `:147`); 16 feature-test files (factory args); rename `CatalogSeederReservationModeAndPaymentTimingTest` → `CatalogSeederReservationModeTest`. FE: `amenity/schema.ts`(+test), `AmenityCostDepositFields.tsx`, `AmenityForm.tsx`, `AmenityFormPage.tsx`, `AmenityBrowseDetailPage.tsx`, `buildAmenityPayload.ts`(+test), `useCatalogs.ts` (`CatalogType.PAYMENT_TIMING`), `useAmenityFilterOptions.ts`, 2 page tests.

**E — FE polish**: `reserveWizard.ts` — new `suggestedPaymentMinor(inputs, outstandingMinor)`: deposit ONLY when `outstandingMinor === totalMinor` (first payment), else outstanding; `SubmitReservationPaymentSheet.tsx` uses it + client deposit-floor check (first payment && amount < deposit → toast, mirrors `Handler:86`); `MyReservationsPage.tsx` deadline column via schema helper `reservationPaymentDueDate()` (payment_deadline for PENDING_PAYMENT, balance_due_date for CONFIRMED). Tests: `reserveWizard.test.ts`, `MyReservationsPage.test.tsx`.

Order: A → B → C → D → E (B needs nothing from A but reads cleaner after; C/D/E independent).

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Feature (host php, single process) | A1/A2/A3 lifecycle, B flag, C 422 | Extend the 4 named test files; factories + earmark submit/register/validate flows |
| Unit (Vitest) | E defaults/floor, D schema | `reserveWizard.test.ts`, `schema.test.ts`, page tests |

## Migration / Rollout

No data migration. A3 applies to new confirmations only (Decision 4). D per Decision 7. Every slice sits behind the per-tenant `amenity_reservations.enabled` flag — revert per PR.

## Risks

- Residual race: a payment submitted between `expire()`'s lock and commit can still orphan (ms window; `validateSubmission` reads unlocked). A2's sweep in `cancel()` covers the human path; treasurer reject clears the rest. Accepted.
- Serial garbage resubmissions extend the deadline 1 day per rejection — human-gated (each needs a treasurer reject). Accepted.
- `PaymentService::reject()` inside cancel/expire fires the generic "Pago rechazado… puedes subir un comprobante corregido" copy on a dead reservation — reason text explains; revisit only if confusing in QA.
- Decision 7 assumes the module is still unshipped at apply time — re-verify, else fall back to a drop migration.

## Open Questions

None blocking.
