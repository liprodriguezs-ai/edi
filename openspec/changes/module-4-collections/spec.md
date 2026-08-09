# Module 4 — Collections — Spec

## Requirements

### R1 — Charge concepts
A `charge_concept` defines a billing template: `clave` (uppercase, unique per tenant via
COALESCE-aware partial index), `name`, category FK, `amount_mode` FK, money fields
(`fixed_amount`, `min/ideal/max_amount`, `prorate_total_amount`), `grace_days`,
`periodicity` FK, validity window, `is_active`.

### R2 — Charge batches (cobros)
A `charge_batch` is a billing run for a concept and a target audience. `target_type` ∈
{all, by_type, by_tower, by_floor, manual, by_feature, by_share, single_unit} with a JSON
`target_selector`. Status ∈ {draft, active, cancelled}. A partial unique index on
`(tenant_id, charge_concept_id, year, month) WHERE deleted_at IS NULL AND month IS NOT NULL`
prevents the monthly cron from double-creating a batch.

### R3 — Charges (cargos)
Activating a draft batch generates one `charge` per resolved unit with computed
`expected_amount`, `paid_amount=0`, snapshot `grace_days`, and a STORED `charge_status_id`.
Ad-hoc charges may have `charge_batch_id = null`.

### R4 — Payments
A `payment` has a generated `receipt_number` (`R-YYYY-NNNNNN`, unique per tenant), a
method, a destination bank account, status ∈ {confirmed, pending_validation, cancelled}.
Payments are never deleted, only cancelled (with reason). Confirmed payments increase the
bank account `current_balance`.

### R5 — Payment application (FIFO + credit)
On register, the payment is applied FIFO: existing `credit_balance` consumed first, then
open charges ordered by `due_date` ASC, `issued_at` ASC, `id` ASC. Each application creates
a `payment_application` row and bumps `charge.paid_amount`. Remainder → `credit_balance`
(one row per unit). Manual application is supported (validate sum ≤ total, leftover → credit).

### R6 — Charge status (stored)
`ChargeStatusService` computes and WRITES `charge.charge_status_id` on every mutation:
- completed: paid ≥ expected (and not overpaid)
- overpaid: paid > expected × (1 + overpayment_pct) OR unit has positive credit
- overdue: unpaid/partial after due_date + grace_days
- due_soon: within due_soon_days before due_date, unpaid
- partial: 0 < paid < expected within term
- pending: no movement
Unit aggregate (`unit.charge_status_id`) = worst-wins:
overdue > partial > due_soon > overpaid > completed > pending.

### R7 — Cancellation reversal
Cancelling a payment reverses every application (decrement paid_amount, delete application),
removes credit that came from that payment, recomputes statuses + batch progress, and
decrements the bank balance (if it was confirmed).

### R8 — Cron
`collections:generate-monthly` (per tenant): finds active monthly concepts, creates+activates
a batch per concept; idempotent via the R2 partial unique index.
`collections:refresh-statuses` (per tenant): recomputes time-based transitions for open charges.

## Scenarios (acceptance — the 4 spec flows §8.8)

- **A. Mass monthly generation**: monthly concept $700; activate a batch over N units →
  N charges, each expected_amount = 70000 minor units, status pending/due_soon per dates.
- **B. Annual prepay**: 12 monthly charges of $700; one payment of $8,400 → 12 applications
  of 70000, all 12 completed, credit = 0, receipt `R-2026-000001`.
- **C. Overpayment → credit**: 2 charges totaling $1,400; pay $1,500 → both completed,
  credit = 10000; new $700 charge → next payment consumes credit first.
- **D. Single-unit fine**: ad-hoc/single_unit charge for one unit → exactly 1 charge, other
  units unaffected.
