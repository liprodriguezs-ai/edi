# quota-enforcement Specification

**Change:** `trial-usage-quotas` · **Status:** spec · **Date:** 2026-08-07

## Purpose

The guard: where quotas block, how scope is chosen, the fail-open rule, the error contract, and the safety/regression properties of this delivery.

## Requirements

### Requirement: REQ-QE-01 — Exactly three flows are guarded

Quota MUST be enforced on exactly: (a) unit creation and unit restore, (b) amenity reservation creation, (c) resident-initiated visit registration (QR generation). No other flow (physical entries/exits, guard-initiated entry requests, Package/Complaint/Chat/Announcement, user/resident invitations) may be quota-checked in v1. Consuming up to the limit exactly MUST succeed; the attempt that would exceed it MUST be rejected.

#### Scenario: reaching the limit succeeds, exceeding blocks

- GIVEN a trial tenant with `visitor` quota 2 and 1 consumed
- WHEN a resident registers a visit
- THEN it succeeds (2 consumed)
- AND the next registration attempt is rejected with the quota error

### Requirement: REQ-QE-02 — Scope follows the authoritative subscription state

While the tenant is in trial, `trial`-scope rows apply; once the tenant is paying, `active`-scope rows apply. The trial/active signal MUST come from the existing authoritative subscription-state source (established by `stripe-native-subscription-controls`) and MUST NOT be re-derived independently by the quota guard.

#### Scenario: quotas stop applying when the trial ends

- GIVEN a tenant blocked at its `trial` quota of 3 `amenity_reservation`
- AND no `active`-scope row limits `amenity_reservation` (or its row is `-1`)
- WHEN the tenant becomes a paying subscriber and retries the reservation
- THEN the reservation succeeds

### Requirement: REQ-QE-03 — Blocked attempts return 422 with a stable machine contract

A quota rejection MUST return HTTP 422 with a stable machine `code` and a payload carrying `resource`, `limit`, `used`, `scope`, and `plan_name`. The human message MUST be Mexican Spanish (tú-form) and MUST name the concrete resource and limit. A quota block is an expected business outcome and MUST NOT be reported as an application error/alert.

#### Scenario: rejection payload is complete

- GIVEN a trial tenant on plan Básico at its `amenity_reservation` quota of 3
- WHEN a reservation is attempted
- THEN the response is 422 with the stable code
- AND the payload carries resource `amenity_reservation`, limit 3, used 3, scope `trial`, plan name "Básico"
- AND the message is tú-form Mexican Spanish naming the resource and the limit

### Requirement: REQ-QE-04 — Fail-open: misconfiguration never bricks operations

When tenancy is not initialized, or no quota row resolves for (plan, scope, resource), the guard MUST allow the operation (same criterion as the existing unit-band guard). This is deliberate — daily operations (registering visits at the gate, creating units) MUST never be blocked by a missing or broken quota configuration. Implementations MUST NOT harden this to fail-closed.

#### Scenario: no resolvable quota allows

- GIVEN a trial tenant whose plan and the global level have NO row for (`trial`, `visitor`)
- WHEN a resident registers a visit
- THEN the operation is allowed

#### Scenario: no tenancy allows

- GIVEN a code path running without an initialized tenant context
- WHEN the guard is consulted
- THEN the operation is allowed

### Requirement: REQ-QE-05 — Safety property: shipped seeds change nothing

With the shipped seed values (`-1` for `unit`, `amenity_reservation`, `visitor` in `trial` scope), EVERY existing flow MUST behave exactly as before this change — same admissions, same rejections (the plan band on units keeps rejecting exactly as today), same payloads. Automated tests MUST seed their own quota values (e.g. 2) to exercise blocking, and an explicit test MUST pin that `-1` never blocks.

#### Scenario: seeded system is behaviorally invisible

- GIVEN a fresh system with only the shipped seed rows
- WHEN a trial tenant creates units, reservations, and visit registrations at any volume within its plan band
- THEN every operation succeeds or fails exactly as it did before this change

### Requirement: REQ-QE-06 — Regression floor

The following measured baselines MUST NOT regress: backend `--filter=Billing` 297 passed / 1171 assertions; `tests/Feature/Billing` 286 / 1133; frontend `pnpm vitest run` 100 files / 773 tests; `pnpm lint` 0 errors. The `UnitLimit`, `Housing`, `AmenityReservations`, and `Access` suites are the ones this change's hooks can break and MUST also stay green (single test process against the shared testing DB).
