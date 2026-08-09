# Plan 011: Add a concurrency/idempotency test for payment application

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- Modules/Collections/app/Services/PaymentService.php tests/Feature/Collections`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (test only)
- **Depends on**: none
- **MUST land before**: any refactor of `PaymentService` (it's the guard for that refactor)
- **Category**: tests
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

`PaymentService` applies payments to charges FIFO and uses `lockForUpdate()` in
~14 places, with comments like "prevent double-application in concurrent
requests" and "two concurrent registers serialize on the row lock". The locking
design is sound — but **no test verifies it**. A future refactor that drops a
`lockForUpdate()` (or reorders a check-then-act) would keep every existing test
green while reintroducing the exact race the locks prevent: double-applied money.
This is a financial-correctness invariant with zero coverage. This plan adds a
test that pins the invariant — so the locks can't be silently removed.

## Current state

- `backend/Modules/Collections/app/Services/PaymentService.php` — FIFO
  application with `lockForUpdate()` at lines ~27, 195, 263, 361, 376, 462, 583,
  658. Entry point for registering a payment is `register(...)`; FIFO consumption
  and credit handling (`applyUnitCreditFifo`) live here.
- `backend/tests/Feature/Collections/` — 10+ test files exercising the happy
  paths (single payment, FIFO ordering, partial, overpay→credit) but **none**
  asserts that applying two payments that together exceed the charge can never
  push `paid_amount` over `expected_amount`, nor that no duplicate
  `payment_application` rows are created for the same charge.
- Money is stored as bigInteger minor units; `paid_amount` and `expected_amount`
  are Money casts (read the integer via the stored attribute).

## Commands you will need

| Purpose       | Command (from `backend/`)                       | Expected     |
|---------------|-------------------------------------------------|--------------|
| Run new test  | `php artisan test --filter PaymentConcurrency`  | passes       |
| Run Collections | `php artisan test tests/Feature/Collections`  | all pass     |
| Format        | `vendor/bin/pint --dirty`                       | exit 0       |

## Scope

**In scope**:
- `backend/tests/Feature/Collections/PaymentConcurrencyTest.php` (create)

**Out of scope**:
- `PaymentService.php` and any production code. This plan only pins existing
  behavior. If the test reveals the invariant is ALREADY violated, that's a STOP
  condition (a bug to fix separately), not a code change here.

## Git workflow

- Branch: `advisor/011-payment-concurrency-test`
- Conventional commit: `test(collections): pin no-double-application invariant on payments`
- No push/PR unless instructed. `backend/` is a submodule.

## Steps

### Step 1: Understand the existing payment-test harness

Read `tests/Feature/Collections/CollectionsModuleTest.php` (or whichever
Collections test sets up a tenant, a unit, a charge, and registers a payment).
Reuse its setup to arrive at: a tenant with one unit that has one open charge of
a known `expected_amount` (e.g. $300 = 30000 minor units).

### Step 2: Write the invariant test

True parallel DB transactions are hard to orchestrate deterministically in
PHPUnit, so test the invariant at the **service/data layer** with a sequential
"check-then-act" simulation plus a real assertion on the end state. Two
complementary cases:

1. **No over-application across two payments**: register a payment that fully
   covers the charge, then register a second payment for the same unit. Assert:
   - the charge's `paid_amount` never exceeds `expected_amount`;
   - the surplus from the second payment becomes `credit_balance` (not an
     over-applied charge);
   - there is exactly one `payment_application` per (payment, charge) pair — no
     duplicates for the same charge from a single payment.

2. **Idempotency-ish double register**: register the *same* logical payment twice
   (same unit, same amount, same `paid_at`) and assert the charge's
   `paid_amount` reflects two distinct payments correctly (or, if the service is
   meant to dedupe, that it deduped) — encode whatever the CURRENT behavior is,
   so a future change that breaks it is visible. Read `register()` to determine
   the intended semantics before asserting; do not assert a behavior the code
   doesn't implement.

If you can drive two real concurrent transactions (e.g. via two DB connections
with `lockForUpdate` and a barrier), do so — but only if it's deterministic.
Otherwise the sequential end-state assertions above are sufficient and stable.

### Step 3: Run and format

- `php artisan test --filter PaymentConcurrency` → passes.
- `php artisan test tests/Feature/Collections` → still all pass.
- `vendor/bin/pint --dirty` → exit 0.

## Test plan

New file `PaymentConcurrencyTest.php` with at least:
- `test_second_payment_surplus_becomes_credit_not_overapplication`
- `test_no_duplicate_payment_application_per_charge`

Both assert on concrete minor-unit integers. Model setup on the existing
Collections tests.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `PaymentConcurrencyTest.php` exists with ≥2 passing cases asserting the no-over-application / no-duplicate-application invariants.
- [ ] `php artisan test --filter PaymentConcurrency` exits 0.
- [ ] `php artisan test tests/Feature/Collections` exits 0.
- [ ] The test asserts on `paid_amount <= expected_amount` and on `payment_application` row counts (machine-checkable invariants, not prose).
- [ ] No production code modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- Writing the test reveals the invariant is **already violated** (paid_amount can
  exceed expected_amount, or duplicate applications appear) — STOP, do not weaken
  the assertion; report it as a real bug needing its own fix plan.
- The intended double-register semantics can't be determined from `register()` —
  report what you found and ask, rather than asserting a guessed behavior.
- The Collections setup helpers have drifted from "Current state" — report before
  reimplementing.

## Maintenance notes

- This test is the gate for the eventual `PaymentService` simplification — keep
  it green through any refactor; if it must change, that change should be
  scrutinized as hard as a production diff.
- A reviewer should confirm the assertions are on the financial invariant
  (amounts, application counts), not incidental fields, so the test fails for the
  right reason.
