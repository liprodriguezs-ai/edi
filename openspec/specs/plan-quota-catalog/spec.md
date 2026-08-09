# plan-quota-catalog Specification

**Change:** `trial-usage-quotas` · **Status:** spec · **Date:** 2026-08-07

## Purpose

Quota definition rows: which resource a plan may consume, per subscription scope, with `-1` meaning unlimited. Covers definition, resolution, uniqueness, and the shipped seed values. Enforcement behavior lives in `quota-enforcement`.

## Requirements

### Requirement: REQ-QC-01 — Quota definitions are keyed by plan-or-global, scope, and resource

The system MUST store quota definitions in a central catalog keyed by (`billing_package` or global, `scope` ∈ {`trial`, `active`}, `resource_key`) with an integer `limit_value`. v1 resources are exactly `unit`, `amenity_reservation`, `visitor` — users/residents MUST NOT be quota'd. The catalog is central (shared, no tenant column); tenant roles MUST NOT be able to edit it.

#### Scenario: a quota row expresses a trial cap

- GIVEN a global row (`scope=trial`, `resource_key=visitor`, `limit_value=5`)
- WHEN quota is resolved for any trial tenant for `visitor`
- THEN the resolved limit is 5

### Requirement: REQ-QC-02 — Most-specific row wins

When both a plan-specific row and a global row exist for the same (`scope`, `resource_key`), resolution MUST pick the plan-specific row for tenants on that plan; tenants on other plans get the global row. When no row resolves, the outcome is fail-open (see `quota-enforcement` REQ-QE-04).

#### Scenario: plan row overrides global row

- GIVEN a global row (`trial`, `amenity_reservation`, `limit_value=3`)
- AND a row for plan Premium (`trial`, `amenity_reservation`, `limit_value=10`)
- WHEN quota is resolved for a trial tenant on Premium
- THEN the resolved limit is 10
- AND for a trial tenant on any other plan the resolved limit is 3

### Requirement: REQ-QC-03 — `-1` means unlimited and never blocks

A resolved `limit_value` of `-1` MUST be treated as unlimited: the guard MUST NOT block, regardless of usage. The legacy `null` = unlimited encoding of `billing_package.suggested_unit_max` MUST remain untouched; no consumer outside the quota guard may need to know both encodings.

#### Scenario: unlimited never blocks at any usage level

- GIVEN a resolved quota of `-1` for `visitor` and a tenant with 100,000 lifetime visits consumed
- WHEN a resident registers a new visit
- THEN the operation is allowed

### Requirement: REQ-QC-04 — Duplicate global rows are impossible at the database level

Two rows with no plan for the same (`scope`, `resource_key`) MUST be rejected by the database itself, not only by application code. (Postgres treats NULLs as distinct in a plain unique index, so a naive unique constraint does NOT satisfy this requirement.) Plan-specific rows MUST be unique per (`billing_package`, `scope`, `resource_key`).

#### Scenario: second global row for the same key is rejected

- GIVEN an existing global row (`trial`, `visitor`)
- WHEN a second global row (`trial`, `visitor`) is inserted
- THEN the database rejects the insert with a uniqueness violation

### Requirement: REQ-QC-05 — Shipped seed is `-1` × 3 and reruns are harmless

The seeder MUST ship exactly the three v1 resources at `limit_value = -1` in `trial` scope (machinery fully built, OFF). Because `db:seed` reruns on every deploy, a rerun MUST create only missing rows and MUST NOT overwrite the `limit_value` of an existing row — turning a quota on later is a data edit that deploys MUST NOT revert — and MUST NOT null or mutate any other existing data.

#### Scenario: rerun preserves an operator-edited value

- GIVEN the seeded row (`trial`, `visitor`) later edited by an operator to `limit_value=5`
- WHEN the seeder runs again
- THEN the row still has `limit_value=5`
- AND no duplicate row exists
