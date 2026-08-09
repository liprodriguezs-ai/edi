# Verification Report — Amenity Payment Hardening

**Change:** `amenity-payment-hardening`
**Mode:** Full spec-driven verification (proposal + spec + design + tasks all present)
**Verdict:** **PASS**
**Executive summary:** 0 CRITICAL, 0 WARNING, 1 SUGGESTION. All 7 spec requirements
implemented and covered by passing tests; all blast-radius suites green; build,
typecheck, vitest, and pint clean.

## Completeness

Every implementation task checkbox across slices A–E is checked. The only
unchecked boxes are the per-slice `Reviewers:` lines (intentional convention —
reviewers run separately, not implementation tasks) and the end-to-end
"Verification Gate" section (that IS this verify step). No CRITICAL for
incomplete tasks.

## Spec Compliance Matrix

| Requirement | Status | Implementation evidence | Covering tests (passed) |
|---|---|---|---|
| Expiry Cron — Grace for Pending-Validation | PASS | `ReservationService::isExpirable()` early-returns false on a PENDING_VALIDATION earmark (:363); `AmenityReservationPaymentRejectionObserver::updated()` calls `extendDeadlineAfterPaymentRejection()` (:69); command adds `whereDoesntHave('earmark_payments', PENDING_VALIDATION)` prefilter (:150); `extendDeadlineAfterPaymentRejection()` never shortens (:796) | `test_pending_validation_payment_holds_off_expiry_past_deadline`, `test_reservation_becomes_expirable_again_after_payment_rejection`, `test_payment_rejection_extends_payment_deadline_by_one_day`, `test_payment_rejection_extends_balance_due_date_for_a_confirmed_reservation` |
| Pending Payment Visibility + Action Gating | PASS | `withExists(['earmark_payments as has_pending_payment' => PENDING_VALIDATION])` on `buildIndexFetchQuery`/`buildShowFetchQuery` (single EXISTS subquery, no N+1) (:84-105); `'has_pending_payment' => 'boolean'` cast (Model:72); FE `canPay`/`canRegisterPayment` require `!has_pending_payment` + "Pago en revisión" pill (MyReservationsPage:58-66, AmenityReservationDetailPage:223-245) | `test_has_pending_payment_is_true_on_index_and_show_with_a_pending_validation_earmark_payment`, `test_has_pending_payment_is_false_without_a_pending_validation_earmark_payment`; FE MyReservationsPage vitest |
| Earmark Money Flow — No resident cash | PASS | Interface param `validateSubmission(array, bool $isSelfSubmission=false)` (:39); `submit()` passes `true` (PaymentService:205), `register()` stays default (PaymentService:87); handler guard rejects cash when self-submitted (EarmarkHandler:53); Contribution handler signature-only update (:35); FE filters CASH out of methods, comprobante always required, default TRANSFER (SubmitReservationPaymentSheet:56,165,193) | `test_resident_submit_with_cash_method_is_rejected`, `test_admin_register_with_cash_method_is_allowed_and_confirmed`; FE sheet vitest |
| Earmark Money Flow — No-deposit confirm gate | PASS | `syncFromChargeApplication`: deposit-confirm branch gated `if ($depositRaw > 0 && $settledRaw >= $depositRaw)` (:906); no-deposit confirms only via outstanding===0 branch (:889); terminal-status guard prevents retroactive touch of CONFIRMED rows (:866) | `test_no_deposit_amenity_token_partial_payment_does_not_confirm`, `test_no_deposit_amenity_confirms_only_once_fully_paid` |
| Cancellation Reversal — Rejects Pending Too | PASS | `rejectPendingEarmarkPayments()` called after `reverseConfirmedEarmarkPayments()` in both `cancel()` (:280) and `expire()` (:329), status flipped to CANCELLED first; confirmed-reversal path unchanged (:739) | `test_cancel_rejects_a_pending_validation_earmark_payment_no_orphan`, `test_cancel_after_partial_deposit_payment_reverses_it` (confirmed-reversal regression guard) |
| Balance payment defaults to outstanding | PASS | `suggestedPaymentMinor()` returns deposit only on the first payment, outstanding otherwise (reserveWizard:114); sheet consumes it (SubmitReservationPaymentSheet:116) | `reserveWizard.test.ts` suggestedPaymentMinor cases; SubmitReservationPaymentSheet vitest |
| No Payment Timing Selector | PASS | Model `PaymentTiming.php` deleted; `payment_timing_id` removed from Amenity fillable/relation/requests/factory/includes; `CatalogSeeder`/`AngularController` mapper stripped; FE `CatalogType.PAYMENT_TIMING`, filter hook, schema, form fields all removed. Grep: zero references outside migrations. Drop migration `2026_07_10_100000` removes column+table at runtime | Full AmenityReservation suite green post-teardown; FE vitest + typecheck + build green |

## Build / Test / Formatter Evidence

| Command (host php; Postgres 127.0.0.1:5432; NOT sail) | Result |
|---|---|
| `php artisan test --filter=AmenityReservation` | 173 passed (678 assertions), exit 0 |
| `php artisan test --filter=Payment` (Collections earmark blast-radius) | 169 passed (738 assertions), exit 0 |
| `php artisan test --filter=Contribution` (Projects earmark blast-radius) | 14 passed (70 assertions), exit 0 |
| `pnpm typecheck` (frontend) | Clean, exit 0 |
| `pnpm build` (frontend) | Success — built in 2.08s, PWA 281 precache entries, exit 0 |
| `npx vitest run src/modules/reservation src/modules/amenity` | 16 files, 103 passed, exit 0 |
| `vendor/bin/pint --test --dirty` | passed |
| `npx eslint <21 touched FE files>` | 0 errors, 1 warning (see SUGGESTION) |

## Design Coherence

The `PaymentEarmarkHandler` contract change (added `bool $isSelfSubmission`)
ripples to exactly the two implementors design predicted — AmenityReservations
(enforces the cash guard) and Projects/Contribution (signature-only, no cash
restriction). Both blast-radius suites are green, confirming the seam change is
non-breaking. The D-slice migration-history deviation (module already shipped →
kept the two original create-migrations, added a forward drop-migration instead
of rewriting history) is documented in tasks.md and consistent with design
decision 7.

## Issues

### CRITICAL
None.

### WARNING
None.

### SUGGESTION
- `frontend/src/modules/reservation/AmenityReservationDetailPage.tsx` is 503
  lines, over the project's 500-line soft `max-lines` ESLint threshold (warn,
  not error). Slice B's pending-payment gating pushed it 3 lines past the limit.
  Consider extracting a subcomponent (e.g. the payment header/actions block).
  Non-blocking.

## Verdict

**PASS** — ready for `sdd-archive`. No CRITICAL issues block archival.
