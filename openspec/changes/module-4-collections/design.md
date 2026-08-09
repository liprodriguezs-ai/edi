# Module 4 — Collections — Design Decisions

## Money representation
All monetary columns are `bigInteger` minor units (centavos) cast with
`Cknow\Money\Casts\MoneyIntegerCast`. Tests assert integer storage via
`$model->getAttributes()['col']`.

**Critical gotcha:** `MoneyIntegerCast::set` calls `Money::parse()` which throws
`InvalidArgumentException("Invalid data provided")` if given a `DB::raw` Expression.
Therefore every money arithmetic mutation uses a locked read-modify-write:
`lockForUpdate()->first()`, read `(int) getAttributes()['col']`, assign, `save()`.
NO `DB::raw("col + N")` on any money column. This also provides the per-row concurrency
guard required for safe concurrent payment application.

## FIFO order
`credit_balance` consumed first (oldest charges), then open charges ordered by
`due_date ASC, issued_at ASC, id ASC`. Remainder of the payment becomes credit.
`applyToCharge()` is the single shared mutation path (create application row, bump
paid_amount via locked RMW, recompute charge status, recompute batch progress).

## Credit scope
One `credit_balance` row per unit per tenant (`unique(tenant_id, unit_id)`), a running
balance in minor units. On payment cancel, the credit GIVEN by that payment
(`total_amount − sum(applications)`) is subtracted back (floored at 0).

## Status thresholds source
`due_soon_days` (default 7) and `overpayment_pct` (default 1%) are read from the Setting
module (`collections.due_soon_days`, `collections.overpayment_pct`) with graceful fallback
to defaults when Settings is unavailable (e.g. test context). Not hardcoded in business logic.

## Status is stored, not computed-on-read
Per the user's explicit decision, the Estados Visuales Globales are catalog tables
(`charge_status`, `payment_status`, `charge_batch_status`) referenced by FK. `charge.charge_status_id`
and `unit.charge_status_id` are WRITTEN by `ChargeStatusService` inside the same transaction
as the mutation. `ChargeStatusService` uses an INSTANCE-level id cache (not static) so it is
reset per resolution via the container — a static cache leaks stale UUIDs across tenant DBs
in the test suite.

## by_share rounding
`expected = round(prorate_total_amount × ownership_share / 100, 0, PHP_ROUND_HALF_UP)` in
minor units. Half-up can overshoot the prorated total by ≤1 cent for tenants with an odd
unit count; acceptable for v1, noted for future reconciliation.

## Monthly cron idempotency
A partial unique index on `(tenant_id, charge_concept_id, year, month) WHERE deleted_at IS
NULL AND month IS NOT NULL` guarantees one monthly batch per concept/period. The cron
catches `UniqueConstraintViolationException` and skips gracefully.

## Capture default
Tesorero/admin captures payments. Default status = confirmed (cash in hand; transfer/deposit
verified before capture). Resident self-submission (pending_validation) is seeded but not a
v1 flow.

## Undo guard (deferred)
There is no accounting-month-close workflow yet. Undo (payment cancel) is allowed whenever
the payment is not already cancelled. Noted as a deferral.

## Migration ordering
Catalog tables → charge_concept → charge_batch → charge → payment → payment_application →
credit_balance → add unit.charge_status_id. Files renamed so dependent tables migrate after
their FK targets. Nullable-column uniques use COALESCE partial indexes (Postgres treats NULL
as distinct); plain uniques on non-null columns otherwise.

## Test deadlock fix
Tenant-scoped test classes using `$connectionsToTransact = []` (truncate strategy) MUST call
`tenancy()->end()` in `tearDown()`. Otherwise a lingering tenant connection races with
RefreshDatabase's `TRUNCATE tenant CASCADE` and Postgres deadlocks nondeterministically.
The wider FK graph (`unit.charge_status_id → charge_status`) widened the cascade and surfaced
this. Added tearDown ending tenancy → 177 passed, 0 deadlocks across repeated runs.
