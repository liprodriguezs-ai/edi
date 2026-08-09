# Delta for Amenity Reservations — Visibility Target

> Base: no `openspec/specs/` baseline exists yet; this delta applies against
> `openspec/changes/amenity-reservations/spec.md` (R1–R11 + Scenarios table).
> Only R11 (Authorization) changes; R1–R10 are unaffected.

## MODIFIED Requirements

### Requirement: Authorization

Creating a reservation requires active `UnitUser` membership for the target
unit (pattern `ScopesToResidentUnits::ownUnitIds()`) AND the target unit MUST
fall within the amenity's read-target (per the `amenity-visibility`
capability) — a resident with valid unit membership but an out-of-target unit
MUST be rejected. The target check is ABSOLUTE and applies to ALL callers:
unlike the unit-membership check, it has NO manager bypass — even a caller
holding `update.amenity_reservation` cannot create a reservation for a unit
outside the amenity's target (an out-of-target unit is invalid data regardless
of who books). Payment
validation is admin only (the `payment/{id}/validate` treasurer action); reads
are scoped to the resident's own unit (a foreign `show` returns 404); lifecycle
actions are gated by Orion policies.
(Previously: unit membership was the only create-time check; no
target/visibility gate existed.)

#### Scenario: Create for an in-target unit (unchanged)
- GIVEN a resident with active membership in a unit that matches the amenity's target
- WHEN they create a reservation for that unit
- THEN it succeeds per the existing FSM rules (R1)

#### Scenario: Create for an out-of-target unit is rejected
- GIVEN a resident with active membership in a unit that does NOT match the amenity's target
- WHEN they attempt to create a reservation for that unit
- THEN the request is rejected; no reservation row is created

#### Scenario: Even a bypass holder cannot create for an out-of-target unit
- GIVEN a caller holding `update.amenity_reservation` (admin/board)
- WHEN they create a reservation for a unit outside the amenity's target
- THEN the request is rejected (422); no reservation row is created — the target check has no manager bypass

#### Scenario: Resident validates a payment (unchanged)
- GIVEN a non-admin resident
- WHEN they attempt `payment/{id}/validate`
- THEN forbidden (admin only)

## ADDED Requirements

### Requirement: Target Narrowing Is Not Retroactive

Narrowing or changing an amenity's `target_type`/`target_selector` MUST NOT
alter, cancel, or hide any reservation that already exists — visibility
scoping governs only new reads/creates going forward.

#### Scenario: Existing reservation survives a target narrowing
- GIVEN a resident's confirmed reservation for an amenity currently `target_type='all'`
- WHEN the admin narrows the amenity's target to exclude that resident's unit
- THEN the existing reservation is unaffected (still visible/manageable via `/my-reservations`, unchanged status); only future list/show/availability/create calls are gated by the new target
