# Billing Plan Modules Specification

## Purpose

Defines the plan × module "offering" fact — `is_free` and `trial_days` — as a single row keyed
by `(billing_package_id, billable_module_id)`, independent of billing interval. Covers the new
`plan_module` table, the price-matrix admin contract v2, trial resolution, Stripe sync exclusion,
the offered-by-plan predicate, and the resulting data-integrity guarantees. The three public
billing read contracts (plan-catalog, plan-quote, tenant billing) are pinned unchanged.

## Requirements

### Requirement: `plan_module` is the single source of truth for offering flags

The system MUST provide a central table `plan_module` (no `tenant_id` — platform billing data),
unique on `(billing_package_id, billable_module_id)`, owning `is_free` (boolean, default
`false`) and `trial_days` (nullable smallint), with both FKs cascading on delete of their
parent. `plan_module_price` MUST drop `is_free`/`trial_days` while keeping its own
`(billing_package_id, billable_module_id, interval)` unique constraint and remaining columns
unchanged.

#### Scenario: Duplicate pair rejected
- GIVEN a `plan_module` row exists for `(billing_package_id=P, billable_module_id=M)`
- WHEN a second row is inserted for the same pair
- THEN the insert is rejected by the unique constraint

#### Scenario: Flags removed from the price table
- GIVEN `plan_module_price` after this change
- WHEN its columns are inspected
- THEN `is_free` and `trial_days` are absent; interval-specific columns are unchanged

### Requirement: Backfill preserves every pair's existing flags before columns are dropped

A migration MUST copy `is_free`/`trial_days` into `plan_module` before the columns are dropped
from `plan_module_price`. For a pair with both month and year rows, the month row wins. For a
pair with only one interval row, that row wins. The migration MUST be a no-op on an empty
database.

#### Scenario: Pair with both intervals present
- GIVEN month (`is_free=true`) and year (`is_free=false`) rows for `(P, M)`
- WHEN the backfill runs
- THEN `plan_module` has one row for `(P, M)` with `is_free=true`

#### Scenario: Month-only or year-only pair
- GIVEN only a year row exists for `(P, M)` with `trial_days=7`
- WHEN the backfill runs
- THEN `plan_module` has one row for `(P, M)` with `trial_days=7`

#### Scenario: Empty database
- GIVEN `plan_module_price` has zero rows
- WHEN the backfill runs
- THEN `plan_module` stays empty and the migration succeeds

### Requirement: Price-matrix v2 moves flags to the module row

`GET billing/plan/{billingPackage}/price-matrix` MUST return `is_free`/`trial_days` on each
module row, sourced from `plan_module`; `cells.{month,year}` MUST carry only amount, currency,
`stripe_price_id`, `is_active`. `PUT` MUST mirror this shape — one entry per module with
`is_free`/`trial_days` plus a `cells` object carrying amounts only — so a mismatched
per-interval flag value cannot be expressed in the payload.

#### Scenario: Valid v2 payload round-trips
- GIVEN a payload with one module entry (`is_free=true`, month+year cells with amounts only)
- WHEN the PUT request is submitted
- THEN HTTP 200 is returned and the module's `plan_module` row has `is_free=true`

#### Scenario: Per-cell flag field is rejected
- GIVEN a payload where a `cells.month` entry includes an `is_free` field
- WHEN the PUT request is submitted
- THEN HTTP 422 is returned and no `plan_module`/`plan_module_price` row changes

### Requirement: Public billing read contracts stay byte-identical

`GET /billing/plan-catalog`, `POST /billing/plan-quote`, and `GET /tenant/{id}/billing` MUST
return the same JSON keys and nesting as before this change, with `is_free` still present per
interval, now derived from `plan_module`.

#### Scenario: Catalog response is byte-identical
- GIVEN a plan with a free module, before and after this change
- WHEN `GET /billing/plan-catalog` is called in each version
- THEN both responses are byte-identical, including `is_free` nested per interval

### Requirement: Trial length resolves from `plan_module`

Trial-days resolution for a plan × module pair MUST read `trial_days` from the `plan_module`
row, falling back to `config('billing.trial_days')` when null, without depending on
`orderBy('interval')` over `plan_module_price`.

#### Scenario: Per-module override applies
- GIVEN `plan_module.trial_days=7` for `(P, M)` and a platform default of 14
- WHEN a trial is started for `(P, M)`
- THEN the trial length is 7 days

#### Scenario: Falls back to the platform default
- GIVEN `plan_module.trial_days=null` for `(P, M)`
- WHEN a trial is started for `(P, M)`
- THEN the trial length equals `config('billing.trial_days')`

### Requirement: Stripe sync excludes free modules and never silently syncs an unflagged one

Stripe sync MUST exclude price rows whose module is marked free in `plan_module`. A price row
whose `(billing_package_id, billable_module_id)` has no `plan_module` row MUST also be
excluded from sync, never silently treated as paid (exact mechanism — hard fail vs. quiet
exclude-and-log — is a design decision; the invariant is the MUST above).

#### Scenario: Free module excluded
- GIVEN `plan_module.is_free=true` for `(P, M)` and an active price row for `(P, M)`
- WHEN Stripe sync runs
- THEN the `(P, M)` price row is not in the syncable set

#### Scenario: Missing `plan_module` row excluded, not defaulted to paid
- GIVEN an active price row for `(P, M)` and no `plan_module` row for `(P, M)`
- WHEN Stripe sync runs
- THEN the `(P, M)` row is excluded from the syncable set

### Requirement: Offered-by-plan uses the predicate matching the question's interval scope

(Design-owned default per proposal §5 — sdd-design MAY finalize a different predicate.)
Interval-independent checks (module in plan, trial eligibility) SHOULD use `plan_module`
existence. Interval-specific paths (quote cells, catalog amounts, Stripe row selection) SHOULD
keep using price-row existence, with flags joined from `plan_module`.

#### Scenario: Trial eligibility uses plan_module existence
- GIVEN a `plan_module` row exists for `(P, M)` with no corresponding price row yet
- WHEN trial eligibility for `(P, M)` is evaluated
- THEN the module is treated as offered by the plan

### Requirement: Seeder writes `plan_module` and stops writing dropped columns

`PlanModuleMatrixSeeder` MUST upsert one `plan_module` row per `(billing_package_id,
billable_module_id)` pair it seeds and MUST NOT write `is_free`/`trial_days` onto
`plan_module_price` rows.

#### Scenario: Seeded pair has a plan_module row
- GIVEN the seeder runs for plan `P` and module `M` with `is_free=true`
- WHEN `plan_module` is queried for `(P, M)`
- THEN exactly one row exists with `is_free=true`

### Requirement: Orphaned price rows are a defined, tested state

Any active `plan_module_price` row whose pair has no matching `plan_module` row MUST resolve to
a defined result wherever flags are read, never an unhandled exception. The offered-by-plan
predicates defined above MUST agree, for every pair, once the backfill has run.

#### Scenario: Drift is detectable, not a crash
- GIVEN an active price row for `(P, M)` with no corresponding `plan_module` row
- WHEN the pair is queried through the flag-resolution path
- THEN a defined result is returned and the condition is assertable in a test

#### Scenario: Predicates agree after backfill
- GIVEN a plan/module pair after the backfill has run
- WHEN "is module offered" is evaluated via `plan_module` existence and via price-row existence
- THEN both evaluations agree for that pair
