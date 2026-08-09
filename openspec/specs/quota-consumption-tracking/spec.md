# quota-consumption-tracking Specification

**Change:** `trial-usage-quotas` · **Status:** spec · **Date:** 2026-08-07

## Purpose

Lifetime consumption accounting for the ACCUMULATING resources: `amenity_reservation` and `visitor`. Spent is spent. (`unit` is NOT tracked here — it counts live rows; see the `unit-limit-enforcement` delta.)

## Requirements

### Requirement: REQ-CT-01 — Lifetime counter per tenant and resource

The system MUST maintain a per-tenant, per-resource lifetime consumption count for `amenity_reservation` and `visitor`, incremented by exactly 1 each time the resource is successfully created. No counter exists (or is needed) for `unit`.

#### Scenario: creating a reservation consumes one

- GIVEN a trial tenant with 2 consumed `amenity_reservation`
- WHEN a resident successfully creates a reservation
- THEN the tenant's `amenity_reservation` consumption becomes 3

### Requirement: REQ-CT-02 — A rolled-back transaction consumes zero quota

The consumption increment MUST be atomic with the creation of the resource it accounts for: if the creating transaction rolls back for ANY reason, the counter MUST remain unchanged. This is the highest-risk correctness property of this change and MUST have an explicit automated test.

#### Scenario: failed reservation leaves the counter untouched

- GIVEN a trial tenant with 2 consumed `amenity_reservation`
- WHEN a reservation attempt fails after the quota check (the enclosing transaction rolls back)
- THEN no reservation exists
- AND the tenant's `amenity_reservation` consumption is still 2

### Requirement: REQ-CT-03 — Deletion never refunds consumption

Deleting (or cancelling) an `amenity_reservation` or a `visitor` MUST NOT decrement the counter. Otherwise delete/recreate would stretch a trial forever (locked user decision).

#### Scenario: deleting a visit does not free quota

- GIVEN a trial tenant at its `visitor` limit
- WHEN an admin deletes an existing visit registration
- THEN the tenant's `visitor` consumption is unchanged
- AND registering a new visit is still blocked

### Requirement: REQ-CT-04 — One visit registration = one consumption; guard-initiated entries are free

Only the resident-initiated visit registration (QR generation) consumes `visitor` quota. Subsequent physical entries/exits against that visit MUST NOT consume. A visitor record created by the gate guard's own entry-request flow MUST NOT consume quota (locked user decision: it is not resident-initiated).

#### Scenario: physical entries after the QR cost nothing

- GIVEN a resident registered a visit (1 consumed)
- WHEN the visitor enters and exits the cluster three times
- THEN the tenant's `visitor` consumption is still 1

#### Scenario: guard-initiated visitor consumes nothing

- GIVEN a trial tenant at its `visitor` limit
- WHEN the gate guard initiates an entry request that creates a visitor record
- THEN the operation succeeds
- AND the tenant's `visitor` consumption is unchanged

### Requirement: REQ-CT-05 — Concurrent consumption cannot over-admit

Two concurrent creation attempts MUST NOT both be admitted when only one slot remains: increments for the same (tenant, resource) MUST be serialized so the counter never under-counts admitted resources.

#### Scenario: one slot, two racers, one winner

- GIVEN a trial tenant with quota 3 and 2 consumed `amenity_reservation`
- WHEN two reservation requests race concurrently
- THEN exactly one succeeds and the other is rejected with the quota error
- AND the final consumption is 3
