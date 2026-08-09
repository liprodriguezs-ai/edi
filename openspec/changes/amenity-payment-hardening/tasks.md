# Tasks — Amenity Payment Hardening

> **Mode:** Standard Mode (tests ALONGSIDE code; `strict_tdd = false`).
> **Delivery:** `exception-ok` — sequential PRs to `main`, one per slice (A→B→C→D→E),
> each targeting < 400 changed lines (design estimate: D ≈ 300 worst case, all others
> well under). If a slice's real diff exceeds 400 lines, ship it anyway (user-approved
> exception) rather than splitting further.
> **Backend root:** `backend/Modules/AmenityReservations/` (+ `backend/Modules/Collections/`
> for the earmark contract in slice C). **Frontend:** `frontend/src/modules/{amenity,reservation,payment}/`.
> **Runners:** backend `php artisan test` (host php, Postgres host `127.0.0.1:5432` —
> NOT sail; do NOT run `_quarantine/`); frontend `pnpm test` (vitest) + `pnpm typecheck` +
> `pnpm lint`. Formatter: `vendor/bin/pint --dirty` on every touched PHP file before commit.
> **Ordering constraint:** A and D are mutually independent and backend-only/DB-only
> respectively. **B strictly depends on A** — its `withExists(['earmark_payments as ...'])`
> call requires the `earmark_payments()` relation A adds to already exist on the model,
> so B cannot land before A merges. C spans both back and front (the earmark contract +
> the submission sheet) and is independent of A/B/D. E is FE-only and touches the SAME
> file as C (`SubmitReservationPaymentSheet.tsx`) — do it after C to avoid rebasing a
> half-finished file. **Order: A → B → C → D → E.**

## Slice A — Backend money lifecycle [M] (dep: —) — FIRST PR

Requirements covered: "Cancellation Reversal — Rejects Pending Payments Too", "Expiry
Cron — Grace for Pending-Validation Payments", and the confirm-gate half of "Earmark
Money Flow — Submission Method and Confirm Gate" (the no-deposit-partial-doesn't-confirm
rule).

- [x] `Modules/AmenityReservations/app/Models/AmenityReservation.php` — add
      `earmark_payments(): HasMany` (`Payment::class`, `'earmark_id'`, constrained
      `->where('earmark_type', AmenityReservationEarmarkHandler::EARMARK_TYPE)`).
      Mirrors the existing `surcharges()` relation's shape on the same model.
- [x] `Modules/AmenityReservations/app/Services/ReservationService.php:778` — confirm
      gate fix: `if ($settledRaw >= $depositRaw)` → `if ($depositRaw > 0 && $settledRaw >= $depositRaw)`.
      This is the ONLY line that changes the confirm semantics; with no deposit snapshot
      (`$depositRaw === 0`), a `PENDING_PAYMENT` reservation can now confirm ONLY through
      the sibling `$outstandingRaw === 0` branch above it — never on a token partial.
      TRANSITION RULE: do not touch any already-`CONFIRMED` row; this only changes future
      `syncFromChargeApplication` runs.
- [x] `ReservationService.php` — new private method `rejectPendingEarmarkPayments(AmenityReservation $reservation, string $reason): void`,
      querying `Payment::where('earmark_type', AmenityReservationEarmarkHandler::EARMARK_TYPE)->where('earmark_id', $reservation->id)->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)->get()->each(fn ($p) => app(PaymentService::class)->reject($p, $reason))`
      — mirrors the existing `reverseConfirmedEarmarkPayments()` right above it in the
      same file. Call it in `cancel()` and `expire()` immediately after the existing
      `reverseConfirmedEarmarkPayments(...)` call, inside the same transaction, AFTER the
      status has already flipped to `CANCELLED` (same ordering rationale as the existing
      comment on that line: the observer's terminal-status guard must see `CANCELLED`
      first so it never resurrects `amount_outstanding`).
- [x] `ReservationService.php` — new public method `extendDeadlineAfterPaymentRejection(string $reservationId): void`
      + `private const REJECTION_GRACE_DAYS = 1;`. Lock the reservation
      (`lockReservation($id)`), skip (return) unless status is in `ACTIVE_STATUSES`.
      If `PENDING_PAYMENT`: `payment_deadline = max(payment_deadline, now($tz)->addDay())`
      (never SHORTEN an existing deadline). If `CONFIRMED` with `amount_outstanding > 0`:
      `balance_due_date = max(balance_due_date, today->addDay())`. Save inside the lock.
- [x] `ReservationService.php::isExpirable()` — add an early `return false` when the
      reservation has a `PENDING_VALIDATION` earmark payment (query via the new
      `earmark_payments()` relation: `$reservation->earmark_payments()->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)->exists()`), BEFORE the existing
      `PENDING_PAYMENT`/`CONFIRMED` deadline checks — re-verified under the SAME lock
      `expire()` already takes, so this is race-safe the same way the rest of the method is.
- [x] `Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php::expireOverdueReservations()` —
      add `->whereDoesntHave('earmark_payments', fn ($q) => $q->where('payment_status_id', PaymentStatus::PENDING_VALIDATION))`
      to the candidate query (prefilter only — `isExpirable()` remains the final authority
      per the method's existing docblock).
- [x] `Modules/AmenityReservations/app/Observers/AmenityReservationPaymentRejectionObserver.php::updated()` —
      call `app(ReservationService::class)->extendDeadlineAfterPaymentRejection($reservation->id)`
      right before the existing `notifyEvent(...)` call, inside the same guarded branch
      (straight `PENDING_VALIDATION → CANCELLED` only — the existing status checks above
      already scope this correctly, no new guard needed).
- [x] Tests — extend `Modules/AmenityReservations/tests/Feature/CancelExpiredReservationsCommandTest.php`:
      new `test_pending_validation_payment_holds_off_expiry_past_deadline` (reservation
      past `payment_deadline` with a `PENDING_VALIDATION` earmark payment is NOT expired by
      the command); new `test_reservation_becomes_expirable_again_after_payment_rejection`
      (reject the pending payment, re-run the command, now it expires); new
      `test_payment_rejection_extends_payment_deadline_by_one_day` (assert `payment_deadline`/`balance_due_date`
      moved forward by exactly `REJECTION_GRACE_DAYS`, and does NOT shorten an already-later deadline).
- [x] Tests — extend `Modules/AmenityReservations/tests/Feature/AmenityReservationEarmarkTest.php`:
      new `test_no_deposit_amenity_token_partial_payment_does_not_confirm` (deposit_amount
      null/0, `cost_amount = 15000`, validate a `1000` payment, assert reservation STAYS
      `PENDING_PAYMENT` with `amount_outstanding = 14000`); new
      `test_no_deposit_amenity_confirms_only_once_fully_paid` (validate the remaining
      `14000`, assert `CONFIRMED`); new `test_cancel_rejects_a_pending_validation_earmark_payment_no_orphan`
      (reservation with a `PENDING_VALIDATION` payment gets cancelled, assert that payment
      is now `CANCELLED` and its `cancellation_reason` is set — no orphan); confirm the
      existing `test_cancel_after_partial_deposit_payment_reverses_it` (CONFIRMED reversal
      path) still passes unmodified (regression guard for A2 not touching A1's territory).
- [x] `vendor/bin/pint --dirty` on all four touched PHP files.
- [ ] Reviewers: `@laravel-architecture-reviewer`, `@tenancy-reviewer` (cron stays
      octane-safe / per-tenant scoped).

## Slice B — Pending-payment visibility [S] (dep: A)

Requirement covered: "Pending Payment Visibility and Action Gating" (ADDED).

- [x] `Modules/AmenityReservations/app/Http/Controllers/AmenityReservationController.php::buildIndexFetchQuery()`
      and `::buildShowFetchQuery()` — append
      `->withExists(['earmark_payments as has_pending_payment' => fn ($q) => $q->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)])`
      to the query each already returns (after the existing `whereIn('unit_id', ...)`
      resident-scoping block). Exact precedent: `ChargePolicyController::has_billed`.
      Do NOT touch `buildSearchFetchQuery` — Orion's `search()` delegates to `index()`
      (vendor `HandlesStandardOperations.php:144-147`), so a separate override there
      would be a vestigial no-op.
- [x] `Modules/AmenityReservations/app/Models/AmenityReservation.php` — add
      `'has_pending_payment' => 'boolean'` to the `casts()` array.
- [x] `frontend/src/modules/reservation/schema.ts` — add `has_pending_payment?: boolean`
      to `IAmenityReservation`.
- [x] `frontend/src/modules/reservation/MyReservationsPage.tsx` — in `ReservationRowActions`
      (around the existing `canPay` computation), also require `!reservation.has_pending_payment`;
      when `has_pending_payment` is true, render a `StatusPill` reading "Pago en revisión"
      (tone `y`) in place of the outstanding-amount cell instead of the peso amount.
      DEVIATION (team-lead's explicit apply instruction overrode this): the pill renders
      in `ReservationRowActions` itself, replacing the "Pagar" button slot, NOT the
      outstanding-amount cell — the amount cell still shows the real peso saldo. See
      apply-progress for the rationale.
- [x] `frontend/src/modules/reservation/AmenityReservationDetailPage.tsx` — in
      `ReservationHeader` (around the existing `canRegisterPayment` computation), also
      require `!reservation.has_pending_payment`; same "Pago en revisión" pill treatment
      in the balance-due display area.
- [x] Tests (backend) — new feature test asserting `has_pending_payment` is `true` on
      both `index` and `show` responses when a `PENDING_VALIDATION` earmark payment
      exists, and `false` otherwise (mirror `ChargePolicyScopeUpdateTest:349`'s pattern;
      add to `AmenityReservationEarmarkTest.php` or a new
      `AmenityReservationPendingPaymentVisibilityTest.php` if it reads cleaner standalone).
- [x] Tests (frontend) — extend `frontend/src/modules/reservation/__tests__/MyReservationsPage.test.tsx`:
      "Pagar" button is absent and "Pago en revisión" pill is shown when
      `has_pending_payment: true`; unaffected when `false`.
- [x] `vendor/bin/pint --dirty` on the touched controller/model.
- [ ] Reviewers: `@laravel-architecture-reviewer`, `@react-frontend-reviewer`.

## Slice C — Cash restriction for resident submissions [M] (dep: —, independent of A/B)

Requirement covered: the cash-rejection half of "Earmark Money Flow — Submission
Method and Confirm Gate".

- [x] `Modules/Collections/app/Contracts/PaymentEarmarkHandler.php` — change the
      interface signature to `public function validateSubmission(array $data, bool $isSelfSubmission = false): void;`
      and update its docblock to explain the new parameter (true when the payer is the
      resident themself via `payment/submit`, false for admin-initiated `payment/register`).
- [x] `Modules/Collections/app/Services/PaymentService.php:204` (`submit()`) — change the
      call to `...->validateSubmission($data, true)`. Leave `register()`'s call at `:87`
      completely unchanged (defaults to `false`).
- [x] `Modules/Projects/app/Handlers/ContributionEarmarkHandler.php::validateSubmission()` —
      signature-only update to match the new interface (`bool $isSelfSubmission = false`
      param added, unused in this handler's body — Projects has no cash restriction).
- [x] `Modules/AmenityReservations/app/Handlers/AmenityReservationEarmarkHandler.php::validateSubmission()` —
      add the `bool $isSelfSubmission = false` param; near the top (after the existing
      module-flag-disabled check, before the reservation lookup), add:
      `if ($isSelfSubmission && ($data['payment_method_id'] ?? null) === PaymentMethod::CASH) { throw new \InvalidArgumentException('Los residentes no pueden pagar en efectivo; usa transferencia, depósito o cheque.'); }`
      Import `Modules\Collections\Models\PaymentMethod`.
- [x] `frontend/src/modules/reservation/components/SubmitReservationPaymentSheet.tsx` —
      remove the `isCash` branch entirely: comprobante is now ALWAYS required (delete the
      `!isCash &&` conditionals around the `FieldDescription` and the `submit()`'s
      `if (!isCash && !receipt)` guard — collapse it to always `if (!receipt)`). Filter the
      `paymentMethods` list fetched from `CatalogType.PAYMENT_METHOD` down to
      `TRANSFER`/`DEPOSIT`/`CHECK` only (drop `CASH` from what's rendered in the
      `RadioGroup`). Change `buildDefaultValues`'s `payment_method_id` default from
      `PAYMENT_METHOD.CASH` to `PAYMENT_METHOD.TRANSFER`. Leave
      `RegisterReservationPaymentSheet.tsx` (admin path) completely untouched — admin cash
      registration stays allowed and is born `confirmed` per spec.
- [x] Tests (backend) — extend `AmenityReservationEarmarkTest.php`: new
      `test_resident_submit_with_cash_method_is_rejected` (422, assert the Spanish message);
      new `test_admin_register_with_cash_method_is_allowed_and_confirmed` (uses
      `payment/register`, asserts the payment is born `confirmed`); confirm an existing
      transfer-method submit test still passes unmodified. DEVIATION (two-part, both
      unavoidable regressions from the contract change, not scope creep):
      (1) changed `submitPayload()`'s default `payment_method_id` from `CASH` to
      `TRANSFER` in `AmenityReservationEarmarkTest.php` AND in two Slice A/B test files
      that share the identical helper pattern — `AmenityReservationNotificationWiringTest.php`
      and `AmenityReservationPendingPaymentVisibilityTest.php` — plus 6 inline resident-submit
      payloads in `CancelExpiredReservationsCommandTest.php` (Slice A) — every resident-submit
      test across these 4 files defaulted to CASH, which the new guard now rejects.
      (2) Discovered switching to TRANSFER trips a SEPARATE pre-existing controller gate
      (`PaymentController::submit`/`ResolvesPaymentMethodRules::requiresReceipt` — §8.7.1 r5:
      any non-cash method requires an attached comprobante, unrelated to Slice C but only
      reachable now that cash is off the table) — fixed by baking a fake receipt
      (`UploadedFile::fake()->image(...)`; `::create()` reports a size/mime without real
      bytes, which Spatie MediaLibrary's real mime-sniffing rejects) into each affected
      payload/helper and switching those calls from `postJson()` to `post()` (required for
      multipart/file encoding). Full `--filter=AmenityReservation` suite verified green
      (174 passed) after both fixes; Projects' `PaymentEarmarkTest.php` (Contribution earmark
      coverage) also verified green (15 passed, unaffected by the interface change).
- [x] Tests (frontend) — new/extended test on `SubmitReservationPaymentSheet` (co-located
      `__tests__` or inline with the sheet's existing coverage): cash is not offered as a
      method option; comprobante is required regardless of the selected method.
- [x] `vendor/bin/pint --dirty` on the four touched PHP files.
- [ ] Reviewers: `@laravel-architecture-reviewer` (registry contract change touches two
      implementors), `@react-frontend-reviewer`, `@integration-reviewer` (FE↔payment
      contract).

## Slice D — `payment_timing` teardown [M] (dep: —, independent; DB-touching) — ~101 occurrences / 38 files

Requirement covered: "No Payment Timing Selector" (ADDED).

> **Guard (design decision 7):** this slice edits the ORIGINAL `create` migrations
> because the module is still unshipped/uncommitted. Before starting, re-verify that
> `Modules/AmenityReservations` has NOT shipped to any deployed environment. If it has,
> switch to a normal additive `drop_payment_timing_table` + `drop_column` migration pair
> instead of editing history.
>
> **DEVIATION (apply time, 2026-07-10):** re-verified per the guard above —
> `git log --oneline -- Modules/AmenityReservations` in the `backend` submodule shows
> `e8a1003` merged into `origin/main` via merge commit `5951569`, with the local `main`
> branch up to date with `origin/main` (pushed). The module HAS shipped. Switched to
> the guard's fallback: kept the two original `create` migrations
> (`2026_07_06_100003_create_payment_timing_table.php`,
> `2026_07_06_100004_create_amenity_table.php`) untouched, and added a new
> `2026_07_10_100000_drop_payment_timing_from_amenity_table.php` that drops the
> `payment_timing_id` FK+column from `amenity` then drops `payment_timing` (with a
> symmetric `down()` that recreates both). Ran clean against the dev DB via
> `php artisan migrate --path=...`.

- [x] ~~Delete `Modules/AmenityReservations/database/migrations/2026_07_06_100003_create_payment_timing_table.php` entirely.~~ NOT deleted — module shipped (see deviation note); original migration kept, removal done via new drop migration `2026_07_10_100000_drop_payment_timing_from_amenity_table.php`.
- [x] ~~`Modules/AmenityReservations/database/migrations/2026_07_06_100004_create_amenity_table.php` — remove the `payment_timing_id` uuid column (line 44) and its FK (line 45).~~ NOT edited — same deviation; column+FK dropped by the new migration instead.
- [x] Delete `Modules/AmenityReservations/app/Models/PaymentTiming.php` entirely.
- [x] `Modules/AmenityReservations/app/Models/Amenity.php` — remove `'payment_timing_id'`
      from `$fillable`; remove the `payment_timing(): BelongsTo` relation method.
- [x] `Modules/AmenityReservations/app/Http/Controllers/AmenityController.php::includes()` —
      remove `'payment_timing'` from the returned array.
- [x] `Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php` and
      `AmenityUpdateRequest.php` — remove the `payment_timing_id` validation rule from
      `rules()` in both.
- [x] `Modules/AmenityReservations/database/factories/AmenityFactory.php` — remove the
      `PaymentTiming` import and the `'payment_timing_id' => PaymentTiming::PAY_LATER`
      line from `definition()`.
- [x] `backend/database/seeders/CatalogSeeder.php` — remove the `PaymentTiming` import
      (`:13`), the `$this->seedPaymentTimings();` call (`:109`), and the
      `seedPaymentTimings()` method body (`:834-840`).
- [x] `backend/app/Http/Controllers/AngularController.php` — remove the `PaymentTiming`
      import (`:16`) and the `'PaymentTiming' => PaymentTiming::class` mapper entry (`:147`).
- [x] Rename `Modules/AmenityReservations/tests/Unit/Seeders/CatalogSeederReservationModeAndPaymentTimingTest.php`
      → `CatalogSeederReservationModeTest.php`; strip every `PaymentTiming`-related
      assertion, keep the `ReservationMode` coverage intact.
- [x] Remove the `'payment_timing_id' => PaymentTiming::PAY_LATER` (or equivalent) factory
      arg from every Feature test file referencing it (16 files at apply time, not 15 —
      `AmenityReservationPendingPaymentVisibilityTest.php` was added by Slice B after this
      task list was written, plus `AmenityOccupancyEndpointTest.php` had TWO occurrences):
      `AmenityReservationTransitionsTest.php`, `AmenityReservationSurchargeTest.php`,
      `AmenityReservationReadScopingTest.php`, `AmenityReservationNotificationWiringTest.php`,
      `AmenityReservationEarmarkTest.php`, `AmenityReservationDoubleBookingIndexTest.php`,
      `AmenityReservationCreationTest.php`, `AmenityPermissionAndFlagGateTest.php`,
      `AmenityOrionCrudTest.php`, `AmenityOccupancyEndpointTest.php`,
      `AmenityDomainModelTest.php`, `AmenityCreateValidationTest.php`,
      `AmenityAvailabilityServiceTest.php`, `AmenityAvailabilityEndpointTest.php`,
      `CancelExpiredReservationsCommandTest.php`, `AmenityReservationPendingPaymentVisibilityTest.php`.
      Removed the now-unused `PaymentTiming` import from each.
- [x] `frontend/src/shared/hooks/useCatalogs.ts` — remove the `PAYMENT_TIMING` entry from
      `CatalogType`.
- [x] `frontend/src/modules/reservation/hooks/useAmenityFilterOptions.ts` — remove the
      `payment_timing` catalog usage.
- [x] `frontend/src/modules/amenity/schema.ts` (+ `schema.test.ts`) — remove the
      `payment_timing_id` field from `IAmenity`/the Zod form schema and its test coverage.
- [x] `frontend/src/modules/amenity/components/AmenityCostDepositFields.tsx` — remove the
      payment-timing selector field.
- [x] `frontend/src/modules/amenity/components/AmenityForm.tsx` — remove the
      `payment_timing_id` field wiring (register/Controller/default value).
- [x] `frontend/src/modules/amenity/buildAmenityPayload.ts` (+ `.test.ts`) — remove
      `payment_timing_id` from the built payload and its test.
- [x] `frontend/src/modules/amenity/AmenityFormPage.tsx` and `AmenityBrowseDetailPage.tsx` —
      remove any `payment_timing`/`payment_timing_id` display or prop-passing.
- [x] `frontend/src/modules/amenity/__tests__/AmenityListPage.test.tsx` and
      `__tests__/AmenityCatalogPage.test.tsx` — drop `payment_timing` from test fixtures.
- [x] Dev DB routine: ran the new drop migration directly (`php artisan migrate --path=...`)
      instead of `migrate:fresh --seed` — module already shipped so this is a normal
      forward migration, not a fresh reseed. `billing:sync-stripe` not needed (unrelated
      to this slice's schema change).
- [x] Tests: full `Modules/AmenityReservations` suite green after the rename + file edits;
      `pnpm test` (amenity + reservation modules), `pnpm typecheck`, `pnpm lint` green on
      the frontend.
- [x] `vendor/bin/pint --dirty` on every touched PHP file (the deleted file needs no pint).
- [ ] Reviewers: `@laravel-architecture-reviewer` (migration-history deviation, scoped
      per decision 7), `@react-frontend-reviewer`, `@database-architect` (schema removal
      sanity check).

## Slice E — Frontend payment-suggestion polish [S] (dep: —, do after C — shares `SubmitReservationPaymentSheet.tsx`)

Requirement covered: the "Balance payment defaults to outstanding, not deposit" and
"Payment deadline is visible" scenarios of the modified/added requirements.

- [x] `frontend/src/modules/reservation/reserveWizard.ts` — new exported function
      `suggestedPaymentMinor(inputs: ReserveCostInputs, outstandingMinor: number): number`:
      when `outstandingMinor === reserveDepositHint(inputs)?.totalMinor` (i.e. this is the
      FIRST payment — nothing settled yet) AND a deposit hint exists, return
      `hint.minimumMinor` (the deposit); in every other case (a second/balance payment, or
      no deposit at all) return `outstandingMinor` unchanged. Add a doc comment explaining
      why: `reserveDepositHint().minimumMinor` alone is deposit-agnostic-of-payment-order
      and was being reused for the balance sheet's default too, which is the bug this fixes.
- [x] `frontend/src/modules/reservation/components/SubmitReservationPaymentSheet.tsx` —
      replace the current inline `suggestedMinor` computation (`Math.min(hint.minimumMinor, outstandingMinor) || outstandingMinor`)
      with a call to the new `suggestedPaymentMinor(costInputsFromReservation(reservation), outstandingMinor)`.
      Add a client-side deposit-floor check in `submit()` mirroring
      `AmenityReservationEarmarkHandler.php:86`: when this is the first payment
      (`outstandingMinor === totalMinor`) and a deposit is configured and the entered
      amount is below it, `toast.error(...)` and return before calling `mutation.mutate`.
      DEVIATION (mechanical, apply time): converted `defaultValues` from
      `useMemo(() => buildDefaultValues(suggestedMinor), [suggestedMinor])` to a plain
      `const` — the React Compiler ESLint plugin flagged `react-hooks/preserve-manual-memoization`
      once `suggestedMinor`'s source (`costInputs`) was also read later inside the
      `submit()` closure (for the new deposit-floor check); `useForm`'s `defaultValues` is
      only read once at mount, so dropping the memo is behavior-neutral and fixes the lint
      error cleanly.
- [x] `frontend/src/modules/reservation/schema.ts` — new helper
      `reservationPaymentDueDate(reservation: Pick<IAmenityReservation, 'reservation_status_id' | 'payment_deadline' | 'balance_due_date'>): string | null` —
      returns `payment_deadline` when `reservation_status_id === RESERVATION_STATUS.PENDING_PAYMENT`,
      `balance_due_date` when `CONFIRMED`, `null` otherwise.
- [x] `frontend/src/modules/reservation/MyReservationsPage.tsx` — add a "Vence" column to
      both the table header/row and the card-fields renderer, showing
      `reservationPaymentDueDate(reservation)` formatted via `displayFormatDate`, or an
      em-dash when null.
- [x] Tests — extend `frontend/src/modules/reservation/reserveWizard.test.ts`:
      `suggestedPaymentMinor` returns the deposit on a first payment, returns full
      outstanding on a second/balance payment, and returns full outstanding when there is
      no deposit at all. Extend `frontend/src/modules/reservation/__tests__/MyReservationsPage.test.tsx`:
      the "Vence" column shows `payment_deadline` for a `PENDING_PAYMENT` row and
      `balance_due_date` for a `CONFIRMED` row. Also extended
      `frontend/src/modules/reservation/components/__tests__/SubmitReservationPaymentSheet.test.tsx`
      (not explicitly listed above but the natural home for sheet-level coverage): a
      balance payment defaults to the full outstanding amount, and a first payment below
      the deposit minimum is blocked client-side with the same amount shown in the header.
- [ ] Reviewers: `@react-frontend-reviewer`.

## Verification Gate (end-to-end, after slice E merges)

- [ ] Backend (host php, Postgres host — NOT sail): `cd backend && php artisan migrate:fresh --seed && php artisan billing:sync-stripe` then `php artisan test` (do NOT run `_quarantine/`).
- [ ] Frontend: `cd frontend && pnpm build && pnpm lint && pnpm typecheck`.
- [ ] Manual smoke: resident cash submission rejected client+server; no-deposit amenity
      token partial no longer confirms; cancel a reservation with a pending payment and
      confirm no orphaned payment remains; payment_timing absent everywhere (schema,
      catalog list, both FE forms); balance payment sheet defaults to the outstanding
      balance, not the deposit.
- [ ] `vendor/bin/pint --dirty` clean across the whole branch before opening the PR chain.

## Review Workload Forecast

| Slice | Files touched | Est. changed lines | 400-line budget risk | Notes |
|---|---|---|---|---|
| A | 4 backend (+2 test files extended) | ~230–260 | Low | Mostly new small methods + 6 new test cases; no file rewritten wholesale. |
| B | 2 backend + 2 frontend (+1–2 test files) | ~110–140 | Low | Two one-line-ish query hooks + a boolean cast + two small FE gating tweaks. Cleanly separable if A slips — only merge conflict risk is the model file A also touches (`AmenityReservation.php`), so land strictly after A merges, not in parallel. |
| C | 4 backend + 1 frontend (+ tests) | ~140–170 | Low | Interface signature change ripples to exactly 2 implementors (verified) — bounded blast radius. |
| D | ~15 backend test files (mechanical, 1 line each) + 8 backend source files + 2 migrations + 8 frontend files + renamed test | ~280–320 | **Medium-high** — closest to the 400 budget and the widest file count (38) of any slice. | Highest chained-PR risk: if slices A/B/C/E are still open as separate branches, D's edits to shared files (`CatalogSeeder.php`, any of the 15 Feature test files that ALSO gained new test methods in slice A) will conflict. **Recommendation: land slices A, B, C fully merged to `main` before opening D's branch**, so D's mechanical removals apply to the final versions of those files instead of racing them. E can follow D or be developed in parallel on its own branch since it only touches FE reservation-module files D doesn't touch. |
| E | 3 frontend files (+2 test files) | ~90–110 | Low | Shares `SubmitReservationPaymentSheet.tsx` with slice C only — sequencing E after C (not in parallel) avoids a rebase on that file. |

Overall: no slice is forecast to exceed 400 lines, so the `exception-ok` delivery
strategy should not need to be invoked — but D is the one to watch if the real diff
(post-grep-reverify at apply time) turns up more than the 15 currently-matching test
files. The `exception-ok` allowance covers exactly that scenario if it materializes.
