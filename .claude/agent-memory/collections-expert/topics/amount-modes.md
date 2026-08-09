# Amount modes vs morosity (2026-06-30)

KEY: FSM (`ChargeStatusService::resolveStatusKey` ~256-312) and `PaymentService` are `amount_mode`-AGNOSTIC. Mode matters once, at charge birth, in `AmountCalculator::compute` (`:31-47`). After that a charge is just `expected_amount` vs `paid_amount`. Payments NEVER mutate `expected_amount` (immutable after first payment, `create_charge_table.php:22`); register/submit/FIFO touch only `paid_amount`/`applied_amount`.

- `FIXED_REQUIRED` → `expected=fixed_amount`. Full FSM.
- `FIXED_OPTIONAL` ("fijo sugerido") → same arm as FIXED_REQUIRED (`:36-37`). "ajustable al pagar" NOT implemented (no min/max in PaymentService); underpay → PARTIAL→OVERDUE on full fixed_amount. GAP.
- `RANGE` → `expected=ideal_amount` (`:39`). `min/max_amount` in `charge_policy` are ONLY config-time validation (`ChargeConceptRangeRule`, `ChargePolicyCreateRequest:47-48,115`) — NEVER read by calculator/FSM/PaymentService. Paying min leaves PARTIAL→OVERDUE. GAP.
- `FREE` ("voluntario") → `expected=0`. `resolveStatusKey` returns COMPLETED when `effectiveExpected===0` (`:277-279`) → never moroso. `openChargesFor` excludes $0 (`PaymentService.php:732`) → FIFO never funds it. NUANCE: born COMPLETED; in `worstWins` (OVERDUE0>PARTIAL1>DUE_SOON2>OVERPAID3>COMPLETED4>PENDING5) COMPLETED(4) outranks PENDING(5) → a $0 voluntary charge can MASK a real PENDING in `unit.charge_status_id`.
- `BY_SHARE` ("por indiviso") → per-unit `round(prorate_total × ownership_share/100)` HALF_UP (`computeByShare :57-67`); whole-batch activation uses zero-drift Hamilton (`computeByShareAllocation :81-125`). Then behaves like fixed. Edges: share 0/null → expected 0 → born COMPLETED; `generateForNewUnit` mid-period uses per-unit HALF_UP not Hamilton (`ChargeGenerationService.php:422`) → centavo drift. `prorate_total_amount` in `charge_policy` (`create_charge_policy_table.php:39`).
- AmountMode UUIDs (`AmountMode.php`): FIXED_REQUIRED `502ed9b1…`, FIXED_OPTIONAL `17eba951…`, RANGE `d02597fe…`, FREE `494676a2…`, BY_SHARE `4918da56…`. Default fallback in compute() = fixed_amount.

Open gaps: (1) fijo sugerido "ajustable" not impl; (2) range min-payment leaves moroso; (3) FREE masks unit status + can't be FIFO-paid; (4) by_share share 0/null → silent $0 completed; (5) mid-period new by_share uses non-zero-drift rounding.
