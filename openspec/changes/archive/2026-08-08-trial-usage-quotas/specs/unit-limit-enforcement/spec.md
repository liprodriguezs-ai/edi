# Delta for unit-limit-enforcement

**Change:** `trial-usage-quotas` · **Status:** spec · **Date:** 2026-08-07
**Base spec:** `openspec/changes/archive/2026-07-15-unit-limit-enforcement/spec.md` (REQ-01 … REQ-11)

The existing band enforcement (REQ-01 … REQ-11) is unchanged. This delta layers a second, possibly stricter cap on the same paths and fixes a frontend contract bug.

## ADDED Requirements

### Requirement: REQ-12 — Effective unit cap is the stricter of the plan band and the trial quota

On every path already guarded by REQ-01 … REQ-05 (single create, batch create, restore, batch restore, import validate), the effective cap MUST be the STRICTER of the plan band (`suggested_unit_max`, `null` = unlimited) and the resolved unit quota for the tenant's scope (`-1` = unlimited). `unit` remains an inventory resource: the count is LIVE rows only, so soft-deleting a unit frees quota (REQ-06 semantics apply to both caps). A quota of `-1` or no resolvable quota row MUST leave band-only behavior byte-identical to today. The band resolution itself MUST NOT be duplicated — its existing behavior and tests remain authoritative.

#### Scenario: trial quota stricter than the band blocks first

- GIVEN a trial tenant on plan Básico (band 25) with a seeded-by-test unit quota of 2 and 2 active units
- WHEN an admin submits `POST /api/unit`
- THEN the request is rejected with the quota error contract (422, stable code)
- AND the active unit count remains 2

#### Scenario: band stricter than the quota still governs

- GIVEN a trial tenant on plan Básico (band 25) with a unit quota of 100 and 25 active units
- WHEN an admin submits `POST /api/unit`
- THEN the request is rejected by the band exactly as before this change

#### Scenario: deleting a unit frees quota room

- GIVEN a trial tenant blocked at a unit quota of 2 with 2 active units
- WHEN one unit is soft-deleted
- THEN a subsequent `POST /api/unit` succeeds (live count back to 2)

#### Scenario: unlimited quota leaves the band alone

- GIVEN a trial tenant whose resolved unit quota is `-1`
- WHEN units are created up to and past the band
- THEN admissions and rejections are identical to pre-change band behavior

### Requirement: REQ-13 — The unit-limit upgrade dialog opens on the code the backend actually emits

The frontend MUST compare against the exact machine code the backend emits for a band rejection (`UNIT_LIMIT_EXCEEDED`). Today it compares `'unit_limit_reached'`, so the upgrade dialog NEVER opens and the user gets a generic toast — this MUST be fixed, and the new quota code MUST NOT inherit this class of mismatch (see `quota-visibility` REQ-QV-02).

#### Scenario: band rejection opens the upgrade dialog

- GIVEN a tenant at its plan band limit
- WHEN the unit form submits and the backend answers 422 with code `UNIT_LIMIT_EXCEEDED`
- THEN the plan-upgrade dialog opens
- AND the generic error toast is NOT shown
