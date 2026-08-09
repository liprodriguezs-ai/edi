# Amenity Visibility Specification

## Purpose

Read-target scoping for amenities: a resident only sees, reads, and checks
availability for amenities whose target includes one of their active units.
Any `update.amenity` holder (admin/board) always sees everything. Rollout
default (`target_type='all'`) reproduces current behavior exactly.

## Requirements

### Requirement: Read-Target Columns and Default

The `amenity` table MUST carry `target_type` (string, default `'all'`) and
`target_selector` (nullable jsonb array), mirroring `chat_channel`'s read-target
columns. An amenity with `target_type='all'` (or `null`) MUST be visible to
every active resident of the tenant.

#### Scenario: Default amenity is universally visible
- GIVEN an amenity with `target_type='all'`, `target_selector=null` (the default)
- WHEN any resident of the tenant lists, shows, or checks availability for it
- THEN it is returned exactly as before this change (zero behavior change)

### Requirement: Target Membership Resolution

`AmenityVisibilityService` MUST determine whether a user matches an amenity's
target using the same unit-based vocabulary as Collections' `TargetResolver`:
`null`/`'all'`/`'by_share'` match unconditionally (short-circuited, no resolver
query); `by_type`/`by_tower`/`by_floor`/`by_feature`/`single_unit`/`manual`/other
`TargetResolver` types match when the user has an active `unit_user` (no
`end_date`) in a unit the target resolves. `by_feature` MUST require the unit
to hold ALL selected `unit_feature_ids` (intersection) — never a match on any
single feature.

#### Scenario: Multi-unit resident, one unit in-target
- GIVEN a resident with active `unit_user` rows on unit A (out-of-target) and unit B (in-target)
- WHEN their access to the amenity is evaluated
- THEN it is visible (matched via unit B)

#### Scenario: by_feature requires all selected features
- GIVEN an amenity targeted `by_feature` with two `unit_feature_ids` selected
- WHEN a resident's unit has only one of the two features
- THEN the unit does NOT match; the amenity is hidden from that resident

### Requirement: Manager Bypass

A user holding `update.amenity` MUST see every amenity regardless of its
target, on list, show, and availability.

#### Scenario: Admin sees an out-of-target amenity
- GIVEN an amenity targeted to exclude the admin's own unit(s), or the admin holding no unit at all
- WHEN an `update.amenity` holder lists/shows/checks availability
- THEN the amenity is returned normally

### Requirement: Server-Side Enforcement at Resident Read Seams

For a resident who does not match an amenity's target and does not hold
`update.amenity`: the amenity MUST be absent from `GET /amenity` results;
`GET /amenity/{id}` MUST return 404; `GET /amenity/{id}/availability` MUST
return 404. Enforcement MUST happen server-side (query/route level), never
left to client-side filtering.

#### Scenario: Out-of-target resident lists amenities
- GIVEN a resident with no active unit in the amenity's target
- WHEN they call `GET /amenity`
- THEN the amenity is absent from the response

#### Scenario: Out-of-target resident reads or checks availability
- GIVEN the same resident
- WHEN they call `GET /amenity/{id}` or `GET /amenity/{id}/availability`
- THEN both return 404

#### Scenario: In-target resident reads and checks availability
- GIVEN a resident with an active unit in the amenity's target
- WHEN they call `GET /amenity`, `GET /amenity/{id}`, and `GET /amenity/{id}/availability`
- THEN all three succeed and return the amenity's data

### Requirement: Unknown Target Type Fails Closed

An unrecognized `target_type` MUST be rejected by validation when an amenity
is created or updated (never persisted). If an already-persisted amenity
somehow carries an unrecognized `target_type`, visibility matching MUST treat
it as NOT matched for any non-bypass resident (fail closed) — it must never
be silently exposed.

#### Scenario: Invalid target_type on write
- GIVEN an admin submits `target_type='bogus'` on create or update
- WHEN the request is validated
- THEN it is rejected (422); the amenity is not persisted with that value

#### Scenario: Unrecognized persisted target_type fails closed on read
- GIVEN an amenity somehow carries an unrecognized `target_type`
- WHEN a non-bypass resident's access is evaluated
- THEN they do NOT match (hidden); an `update.amenity` holder still sees it

### Requirement: Admin Target Editing

`AmenityForm` MUST let an `update.amenity` holder set and change
`target_type`/`target_selector`, using the shared target-selector component;
on edit, the current target MUST be pre-populated (round-trip).

#### Scenario: Admin sets a target on create
- GIVEN an admin creating an amenity
- WHEN they choose a non-`'all'` target and selector values
- THEN the amenity persists with that `target_type`/`target_selector`

#### Scenario: Admin narrows an existing 'all' amenity
- GIVEN an amenity currently `target_type='all'` with existing reservations from residents across the cluster
- WHEN the admin narrows it to a target that excludes some of those residents' units
- THEN those residents lose visibility going forward (list/show/availability/create), but their EXISTING reservations remain untouched — no retroactive change (see `amenity-reservations`: Target Narrowing Is Not Retroactive)
