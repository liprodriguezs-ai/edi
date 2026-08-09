# Plan 078: Make an amenity deposit impossible to misconfigure, and make the expiry cron report the truth

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- \
>   Modules/AmenityReservations/app/Http/Requests \
>   Modules/AmenityReservations/app/Handlers/AmenityReservationEarmarkHandler.php \
>   Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php \
>   Modules/AmenityReservations/app/Services/ReservationService.php
> ```
> On any change, compare against the "Current state" excerpts; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

Two independent defects in the same new module, both cheap to fix, grouped
because they share a test file and a reviewer's context.

**A — a deposit larger than the cost makes reservations permanently unpayable.**
`cost_amount` and `deposit_amount` are validated independently, so an admin can
save an amenity where the deposit exceeds the cost. The payment handler then
enforces two guards that cannot both be satisfied: a payment must be **at most**
the outstanding balance (which equals the cost) and **at least** the deposit. The
resident gets two contradictory error messages, cannot pay at all, and the nightly
cron eventually cancels the reservation stamping "payment deadline expired" —
blaming them for a failure the configuration created.

**A′ — a related hole**: `requires_deposit` without `has_cost` snapshots a deposit
onto a reservation whose outstanding balance is zero and whose status goes
straight to CONFIRMED, so the configured deposit is silently never collected.

**B — the expiry cron's only operational signal is wrong by construction.** It
reports the number of *candidates* it looked at, not the number it actually
expired. Reservations that were paid off in the meantime, cancelled by an admin,
or that threw an exception are all counted as expired. Anyone investigating "why
did N reservations get cancelled last night" gets a number that matches nothing.

## Current state

### A — the two amounts are validated independently

```php
// backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php:36-41
            'has_cost' => ['nullable', 'boolean'],
            'cost_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->boolean('has_cost'))],

            'requires_deposit' => ['nullable', 'boolean'],
            'deposit_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->boolean('requires_deposit'))],
            'deposit_due_days' => ['nullable', 'integer', 'min:0', 'max:365', Rule::requiredIf(fn (): bool => $this->boolean('requires_deposit'))],
```

`$money` is `['nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS]`
— identical for both. Nothing relates `deposit_amount` to `cost_amount`, and
`requires_deposit` does not imply `has_cost`.
`AmenityUpdateRequest.php:45-50` has the same shape.

### A — the snapshot that creates the deadlock

```php
// backend/Modules/AmenityReservations/app/Services/ReservationService.php:146-148
                    'cost_amount' => $amenity->has_cost ? ($amenity->getAttributes()['cost_amount'] ?? null) : null,
                    'deposit_amount' => $amenity->requires_deposit ? ($amenity->getAttributes()['deposit_amount'] ?? null) : null,
                    'amount_outstanding' => $amenity->has_cost ? (int) ($amenity->getAttributes()['cost_amount'] ?? 0) : 0,
```

`amount_outstanding` comes from `cost_amount` only; `deposit_amount` is
snapshotted independently.

### A — the two unsatisfiable guards

```php
// backend/Modules/AmenityReservations/app/Handlers/AmenityReservationEarmarkHandler.php:76-99
        $totalAmountRaw = (int) ($data['total_amount'] ?? 0);
        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];

        if ($totalAmountRaw > $outstandingRaw) {
            throw new \InvalidArgumentException('El monto del pago supera el saldo pendiente de la reservación.');
        }
        ...
        $costRaw = (int) ($reservation->getAttributes()['cost_amount'] ?? 0);
        $isFirstPayment = $outstandingRaw === $costRaw;
        $depositRaw = (int) ($reservation->getAttributes()['deposit_amount'] ?? 0);

        if ($isFirstPayment && $depositRaw > 0 && $totalAmountRaw < $depositRaw) {
            throw new \InvalidArgumentException(
                'El primer pago debe cubrir al menos el depósito de '.TenantMoney::format($depositRaw).'.'
            );
        }
```

With `outstanding == cost < deposit`, a first payment must be `<= cost` **and**
`>= deposit`. No value satisfies both.

### A′ — deposit with no cost is never collectible

```php
// backend/Modules/AmenityReservations/app/Services/ReservationService.php:1143-1156
    private function resolveInitialStatus(Amenity $amenity, string $timezone): array
    {
        if ($amenity->requires_approval) {
            return [ReservationStatus::PENDING_APPROVAL, null];
        }

        if ($amenity->has_cost) {
            $days = $amenity->deposit_due_days ?? self::DEFAULT_PAYMENT_DEADLINE_DAYS;

            return [ReservationStatus::PENDING_PAYMENT, now($timezone)->addDays($days)->utc()];
        }

        return [ReservationStatus::CONFIRMED, null];
    }
```

`requires_deposit=true, has_cost=false` → status CONFIRMED, outstanding 0, and
`assertPayable()` rejects every payment for having no balance.

### B — the cron counts candidates

```php
// backend/Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php:158-169
        foreach ($candidates as $reservation) {
            try {
                $reservationService->expire($reservation);
            } catch (\Throwable $e) {
                Log::error("[amenities:cancel-expired-reservations] Failed to expire reservation {$reservation->id} — skipped, sweep continues.", [
                    'reservation_id' => $reservation->id,
                    'exception' => $e,
                ]);
            }
        }

        return $candidates->count();
```

```php
// backend/Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php:82-85
                    $expiredCount = $this->expireOverdueReservations($reservationService, $timezone);
                    $remindedCount = $this->sendPaymentReminders($reservationService, $timezone);

                    $this->info("[{$label}] {$expiredCount} reservation(s) expired, {$remindedCount} reminder(s) sent.");
```

And `ReservationService::expire()` (`:322-354`) is **deliberately a silent no-op**
when its `isExpirable()` re-check fails under the lock (`:327-329`) — an expected
outcome, not an error. So the count is inflated by design, not only by exceptions.

### Repo conventions that apply

- Money is `bigInteger` minor units; comparisons are integer. Do not introduce
  float arithmetic.
- Validation messages in Spanish, in `messages()`.
- Module tests: `backend/Modules/AmenityReservations/tests/Feature/` (wired by
  `phpunit.xml:15-16`). Existing relevant files:
  `AmenityReservationSurchargeTest.php`, `AmenityReservationEarmarkTest.php`,
  `CancelExpiredReservationsCommandTest.php`. Read the last one before touching
  the cron.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Module tests | `cd backend && php artisan test --filter=Amenity` | all pass |
| Cron test | `cd backend && php artisan test --filter=CancelExpiredReservations` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php`
- `backend/Modules/AmenityReservations/app/Http/Requests/AmenityUpdateRequest.php`
- `backend/Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php`
- `backend/Modules/AmenityReservations/app/Services/ReservationService.php` (part B
  only — the `expire()` return type; see Step 4)
- `backend/Modules/AmenityReservations/tests/Feature/AmenityDepositConfigTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `AmenityReservationEarmarkHandler` — its two guards are individually correct.
  The fix is to stop the impossible configuration reaching them, not to relax a
  money guard.
- `resolveInitialStatus()` — do not change the FSM. Part A′ is fixed by
  validation (a deposit requires a cost), not by adding a new status branch.
- The `PENDING_PAYMENT` deadline arithmetic.
- Any change to how `amount_outstanding` is seeded.
- The reminder query's performance (a separate, known item — not this plan).

## Git workflow

- Branch: `advisor/078-amenity-deposit-and-cron`
- Suggested commits:
  - `fix(amenity-reservations): require a deposit to fit within the amenity cost`
  - `fix(amenity-reservations): report actual expirations, not candidates`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Check for already-broken amenities before adding the rule

Tightening validation does not fix rows that already violate it, and if any
exist their reservations are currently unpayable. Run read-only:

```sql
SELECT id, name, cost_amount, deposit_amount, has_cost, requires_deposit
FROM amenity
WHERE deleted_at IS NULL
  AND (
    (requires_deposit AND deposit_amount IS NOT NULL AND has_cost AND cost_amount IS NOT NULL AND deposit_amount > cost_amount)
    OR (requires_deposit AND NOT has_cost)
  );
```

Record the result. If any rows come back, that is a STOP condition — the operator
needs to fix the data, and there may be live unpayable reservations to unblock.

**Verify**: you have the row count in your report.

### Step 2: Add the relational rules to the create request

In `AmenityCreateRequest`, add to `deposit_amount`:

- `'lte:cost_amount'` — the deposit is a first installment **on** the cost
  (the earmark handler's comment says exactly this: "deposit = anticipo, never a
  separate garantía"), so it can never exceed it.

And make `requires_deposit` imply `has_cost`. Express it as a rule on
`requires_deposit`, e.g. `Rule::prohibitedIf(fn (): bool => ! $this->boolean('has_cost'))`
when it is truthy — or, if that reads awkwardly, a `withValidator` check. Pick
whichever matches the file's existing style and say which you chose.

Add Spanish messages:

```php
            'deposit_amount.lte' => 'El depósito no puede ser mayor que el costo de la amenidad: es un anticipo sobre ese costo, no un cargo aparte.',
            'requires_deposit.prohibited' => 'Para exigir un depósito la amenidad debe tener costo.',
```

(Adjust the message keys to match the rules you actually used.)

**Verify**:
```
cd backend && php -l Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php
grep -n "lte:cost_amount" backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php
```
→ present.

### Step 3: Mirror the rules in the update request

`AmenityUpdateRequest` is the harder case: a PATCH may send `deposit_amount`
without `cost_amount`, in which case `lte:cost_amount` has nothing to compare
against and would pass vacuously.

Resolve the **effective** cost the same way the file already resolves other
conditional state — read `AmenityUpdateRequest` fully first; it has an
`effectiveHasCost()` helper (referenced in the module's own code) that is the
pattern to follow. Add the equivalent for the amount: use the submitted
`cost_amount` when present, otherwise the persisted one from the route's amenity.

Then apply the same two constraints against those effective values.

**Verify**:
```
cd backend && php -l Modules/AmenityReservations/app/Http/Requests/AmenityUpdateRequest.php
```
→ no syntax errors. And confirm by reading that a PATCH sending only
`deposit_amount` is compared against the **stored** `cost_amount`.

### Step 4: Make `expire()` report whether it did anything

In `ReservationService::expire()`, change the signature from `void` to `bool`:
return `true` when the reservation was actually expired, `false` when the
`isExpirable()` re-check under the lock declined. The method already
distinguishes these internally via a nullable local, so this is surfacing an
existing fact, not new logic.

Update the docblock to state that `false` is an expected, non-error outcome.

**Verify**:
```
cd backend && grep -n "function expire" backend/Modules/AmenityReservations/app/Services/ReservationService.php
```
→ signature now returns `bool`.

### Step 5: Count the three outcomes separately in the cron

In `expireOverdueReservations`, replace `return $candidates->count();` with three
counters — `candidates`, `expired`, `failed` — incrementing `expired` only when
`expire()` returned `true`, and `failed` in the catch block.

Return a small array or a tiny value object, and update the `info()` line at
`:85` to report all three:

```php
                    $this->info("[{$label}] {$expired['expired']} expired of {$expired['candidates']} candidate(s), {$expired['failed']} failed; {$remindedCount} reminder(s) sent.");
```

Keep the per-reservation `Log::error` exactly as it is — the isolation behavior
is correct and must not change.

**Verify**:
```
cd backend && php -l Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php
grep -n "candidates\|failed" backend/Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php
```
→ all three counters present.

### Step 6: Write the tests

Create `backend/Modules/AmenityReservations/tests/Feature/AmenityDepositConfigTest.php`,
modelled on the fixture in `AmenityReservationEarmarkTest.php`.

Cases:

1. **Create with `deposit_amount > cost_amount` → 422**, keyed on
   `deposit_amount`, and no `amenity` row created.
2. **Create with `deposit_amount == cost_amount` → 2xx.** Control case: a deposit
   equal to the full cost is legitimate (pay it all up front).
3. **Create with `requires_deposit=true, has_cost=false` → 422.**
4. **Update that would raise the deposit above the stored cost → 422**, PATCHing
   only `deposit_amount`. This is the case Step 3's effective-value resolution
   exists for.
5. **Regression proof for the deadlock**: create a valid amenity where
   `deposit_amount <= cost_amount`, place a reservation, and submit a first
   payment equal to the deposit → succeeds. This is the flow that was
   unsatisfiable when the config was invalid.

Then extend `CancelExpiredReservationsCommandTest.php` (do not create a new file
for the cron — read the existing one and add to it):

6. **The reported expired count excludes a non-expirable candidate.** Set up two
   candidates past their deadline, make one no longer expirable (e.g. paid off or
   already cancelled) before the sweep, run the command, and assert the output
   reports `1 expired of 2 candidate(s)`.

**Verify**:
```
cd backend && php artisan test --filter=AmenityDepositConfig
cd backend && php artisan test --filter=CancelExpiredReservations
```
→ all pass.

### Step 7: Regression check

```
pgrep -f "artisan test"
cd backend && php artisan test --filter=Amenity
```

**Verify**: passes, with your new tests added to the count.

## Test plan

- **New tests**: `AmenityDepositConfigTest` — 5 cases; plus 1 case added to the
  existing `CancelExpiredReservationsCommandTest`.
- **Structural patterns**: `AmenityReservationEarmarkTest.php` for the
  reservation + payment fixture; `CancelExpiredReservationsCommandTest.php` for
  the cron harness.
- **Case 2 is the control that matters**: `deposit == cost` must stay legal, or
  the rule would forbid "pay in full up front", which is a normal configuration.
- **Case 5 is the actual regression test** — it exercises the flow that the
  misconfiguration made impossible, rather than only asserting the validator.
- **Verification**: `php artisan test --filter=Amenity` — all pass.

## Done criteria

ALL must hold:

- [ ] Step 1's data-check row count is recorded in the report
- [ ] `grep -c "lte:cost_amount" backend/Modules/AmenityReservations/app/Http/Requests/AmenityCreateRequest.php` returns `1`
- [ ] `AmenityUpdateRequest` compares against the **effective** cost (stored value when not submitted) — verified by reading
- [ ] `ReservationService::expire()` returns `bool`
- [ ] The cron reports candidates, expired and failed as three distinct numbers
- [ ] `cd backend && php artisan test --filter=AmenityDepositConfig` passes
- [ ] `cd backend && php artisan test --filter=CancelExpiredReservations` passes
- [ ] `cd backend && php artisan test --filter=Amenity` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Step 1's query returns rows.** Live amenities are already misconfigured,
  which means there may be reservations residents cannot pay right now. That
  needs an operator decision (fix the amenity, or refund/cancel the affected
  reservations) before a validation rule locks the configuration in place.
  Report the count and the affected amenity ids; do not include money values
  beyond what is needed.
- **`expire()` has other callers** that rely on its `void` return. Grep before
  changing the signature (`grep -rn "->expire(" backend/`). If a caller would
  break, report it.
- **An existing test asserts the cron's exact `info()` string.** Changing the
  message will break it. Update the test to the new format only if it is
  asserting the *format*; if it is asserting the *count semantics*, report it —
  that test may encode the buggy behavior.
- **`deposit == cost` fails your new rule.** `lte` must be less-than-or-equal;
  if you used `lt` the control case will fail. Fix the operator, do not weaken
  the test.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`. `pgrep`, kill, re-run
  once.

## Maintenance notes

- **Why validation and not a handler change**: the earmark handler's two guards
  are each individually right, and both are load-bearing for real money. The
  contradiction only exists because an impossible configuration was allowed
  upstream. Fixing it upstream keeps the money guards intact.
- **What a reviewer should scrutinize**: the update request's effective-cost
  resolution. A PATCH that sends only `deposit_amount` is the realistic way this
  gets misconfigured in production, and a vacuous `lte` there would leave the bug
  half-open.
- **Deliberately deferred**: the reminder half of the same cron loads every open
  reservation per tenant and filters in PHP rather than in SQL. It is a real
  infra-cost item and it sits in the file you are editing — but changing the
  query shape while also changing the counting would make a regression hard to
  attribute. Left for its own change.
- **The cron's error isolation is correct** and must survive this edit: one
  reservation throwing must not stop the sweep for the rest of the tenant.
