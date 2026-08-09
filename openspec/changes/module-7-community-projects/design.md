# Module 7 — Community Projects — Design Decisions

## Collections reuse

`ProjectActivationService` depends on `Collections\TargetResolver` and
`Collections\ChargeStatusService` via constructor injection. It does NOT duplicate target
resolution logic or charge-status writing. This keeps the single source of truth for both
concerns in Collections.

For mandatory projects, a `ChargeConcept` is auto-created with `clave = 'PROJ_{project.id}'`
using `ChargeConceptCategory.KEY_PROJECT` and `Periodicity.KEY_EVENTUAL`. The concept is
created once and reused on subsequent calls (`firstOrCreate` logic in
`resolveProjectChargeConcept`). This avoids needing an explicit concept association on the
project entity.

## Amount computation for charges (mandatory mode)

`computeExpectedAmount` maps the project's `amount_mode` key to a raw integer:
- `fixed_required` / `fixed_optional` → `fixed_amount` raw int
- `range` → `ideal_amount` ?? `min_amount` raw int
- `free` → `null` (no charge generated for free-mode mandatory projects with no amount)

This mirrors the Collections `AmountCalculator` logic for fixed/range, keeping by-share
calculations in Collections (not used for projects in v1).

## Contribution status written transactionally

Unlike Collections charge status (which uses a dedicated singleton `ChargeStatusService`),
contribution status is resolved and written inline in `ContributionService` methods. The simpler
6-state graph (no time-based transitions, no cron) does not justify a separate service.

## Fully funded transition

`recomputeFullyFunded` reads the raw `goal_amount` integer. If it is 0 or null, the check is
skipped (no goal set). The transition fires only when the project is still active — fully_funded
is a terminal upgrade from active, not from other states.

## Cross-module FK strategy

The columns `expense.community_project_id` and `charge.community_project_id` were added by
Treasury and Collections in earlier modules without FK constraints (the Projects table didn't
exist yet). Projects adds the FK constraints in two separate migrations that check
`Schema::hasColumn` before adding the foreign key. This pattern avoids migration order conflicts
while keeping referential integrity once the Projects table exists.

Both FKs are `nullOnDelete`, not `cascadeOnDelete`, preserving the financial records
(expenses and charges) even if the community project is removed.

## Project comment hiding

`ProjectComment` has a `hidden` boolean column (similar to `ChatMessage.hidden`). The
`hide` custom action sets the flag without deleting the row, preserving moderation audit trail.

## Unique contribution guard

`unique(tenant_id, community_project_id, unit_id)` enforces at the DB level that each unit
can have at most one contribution row per project. The activation idempotency check uses a
`pluck('unit_id')->flip()` in-memory map to avoid per-unit queries.

## Accepted limitations / deferrals

- No reversal of charges when a mandatory project is cancelled. The operator must cancel
  individual charges via the Collections charge-cancel endpoint.
- `by_share` amount mode on projects uses the same rounding as Collections (`PHP_ROUND_HALF_UP`
  in minor units) but is not tested specifically for projects (covered by Collections tests).
- Free-mode mandatory projects generate no charges (expected_amount = null). This is a valid
  use case (honor-system mandatory) but the UI should display guidance to operators.
- PDF/Excel export of project summaries is deferred.
- Push notification to residents on activation is deferred (Notification module cross-cut).
