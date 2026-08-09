# Plan 035: Unit statement includes `batch` so charge period labels render

> **Executor instructions**: Follow step by step, run every verification command,
> honor STOP conditions, update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Collections/app/Http/Controllers/PaymentController.php`
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/payment/StatementPage.tsx`
> On any mismatch with the "Current state" excerpts, treat as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 034 (soft — both edit the same `->with()` line in `statement()`)
- **Category**: bug
- **Planned at**: backend `fb2062b`, frontend `0887d5a`, 2026-06-20

## Why this matters

`GET /unit/{id}/statement` builds each charge's relations with
`->with(['concept', 'chargeStatus', 'paymentApplications'])` — it omits `batch`.
The StatementPage renders each row's period label from `charge.batch?.month` and
`charge.batch?.year`; with `batch` always `null`, every charge falls through to
the literal `'Eventual'` regardless of its real month/year. The account statement
is a primary resident-facing screen, so every period reads wrong.

## Current state

- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:265-269`
  ```php
  $charges = Charge::query()
      ->where('unit_id', $unitId)
      ->with(['concept', 'chargeStatus', 'paymentApplications'])
      ->orderBy('due_date')
      ->get();
  ```
  (If plan 034 has already landed, the status key reads `charge_status` here — fine.)
- `frontend/src/modules/payment/StatementPage.tsx:20-24`
  ```ts
  function chargePeriod(charge: ICharge): string {
    const batch = charge.batch
    if (batch?.month && batch?.year) return `${MONTHS[batch.month]} ${batch.year}`
    if (batch?.year) return String(batch.year)
    return 'Eventual'
  }
  ```
- The `batch` relation is already defined on the Charge model and already
  whitelisted in `ChargeController::includes()` (line 30) — only the custom
  `statement()` endpoint's direct `->with()` is missing it.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHP syntax | `php -l backend/Modules/Collections/app/Http/Controllers/PaymentController.php` | `No syntax errors detected` |
| Backend tests | `cd backend && php artisan test --filter=Statement` (fallback `--filter=Payment`) | all pass |
| Backend format | `cd backend && vendor/bin/pint Modules/Collections/app/Http/Controllers/PaymentController.php` | exit 0 |

## Scope

**In scope:** `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` (the `statement()` method only).

**Out of scope:** the StatementPage frontend (it already reads `charge.batch` correctly — the fix is purely adding the include); the `delinquency()` method; any other `->with()` call.

## Git workflow

Submodule `backend/`. Branch `advisor/035-statement-batch`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail.

## Steps

### Step 1: Add `'batch'` to the statement eager-load

In `PaymentController::statement()`, add `'batch'` to the `->with([...])` array so
it becomes `->with(['concept', 'chargeStatus', 'batch', 'paymentApplications'])`
(or `'charge_status'` in place of `'chargeStatus'` if plan 034 already landed).
Change nothing else in the method.

**Verify**: `grep -n "->with(\['concept'" backend/Modules/Collections/app/Http/Controllers/PaymentController.php` shows `'batch'` present in the statement array.

### Step 2: Format + test

**Verify**: `cd backend && vendor/bin/pint Modules/Collections/app/Http/Controllers/PaymentController.php` exit 0; `php artisan test --filter=Payment` all pass.

## Test plan

- If a statement feature test exists in `backend/Modules/Collections/tests/Feature/`,
  add an assertion that a returned charge has a non-null `batch` with `month`/`year`
  when it belongs to a monthly batch. Model after the existing statement/payment
  test in that directory.
- Manual: open a unit statement in the UI for a unit with monthly charges →
  period column shows e.g. "Mar 2025", not "Eventual".

## Done criteria

- [ ] `statement()`'s `->with()` array contains `'batch'`
- [ ] `cd backend && php artisan test --filter=Payment` passes
- [ ] Only `PaymentController.php` modified (`git -C backend status`)
- [ ] `plans/README.md` row updated

## STOP conditions

- The `statement()` excerpt does not match (drift, or plan 034 changed more than
  the status key) — compare and report.
- Adding `batch` makes a statement test fail (unexpected serialization change) — report.

## Maintenance notes

- `batch` is nullable for ad-hoc charges (`charge_batch_id` null) — the frontend
  already handles `batch?.year` falling through to "Eventual", which is correct
  for genuinely eventual charges. This fix only restores it for batch-born charges.
