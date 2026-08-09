# Spec — unit-limit-enforcement

**Change:** `unit-limit-enforcement`
**Module:** `Modules/Housing` + `Modules/SubscriptionPlan` + `Modules/DataImport` + `frontend/src/modules/unit` + `frontend/src/modules/import`
**Status:** spec
**Date:** 2026-07-15
**Based on proposal:** `openspec/changes/unit-limit-enforcement/proposal.md`

---

## Overview

This spec describes what MUST be true after `unit-limit-enforcement` is applied. It covers ONE capability: **unit-limit-enforcement** — a billing-band cap on a tenant's active unit count (`billing_package.suggested_unit_max`, resolved via the tenant's active `tenant_entitlement`), enforced on every path that can increase that count, with a pre-execution warning in the Excel importer. It does NOT prescribe implementation; see the design artifact for architectural decisions (e.g. observer vs per-endpoint guard, batch pre-flight vs per-row backstop, locking strategy). All requirements below are additive to existing Housing/DataImport behavior — see "Domain contracts unchanged" for explicit regression guards.

---

## Capability: unit-limit-enforcement

### REQ-01 — `POST /unit` is capped by the tenant's band

**Requirement:** Creating a single unit via `POST /api/unit` MUST be rejected when it would push the tenant's active unit count above `billing_package.suggested_unit_max` for its currently active plan.

**Invariants:**
- The active count MUST be computed as `deleted_at IS NULL` units for the tenant, mirroring the liveness rule already used by `unit_identity_unique`.
- Reaching the limit exactly (band_max reached, not exceeded) MUST succeed.
- The band MUST resolve from the tenant's ACTIVE `tenant_entitlement` → `billing_package.suggested_unit_max`.

#### Scenario: single create at the limit succeeds

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 24 active units
- WHEN an admin submits `POST /api/unit` for one new unit
- THEN the unit is created and the tenant now has 25 active units

#### Scenario: single create over the limit is rejected

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 25 active units
- WHEN an admin submits `POST /api/unit` for one new unit
- THEN the request is rejected with a Spanish-language error naming the plan, its limit, and the current count
- AND the active unit count remains 25

### REQ-02 — `POST /unit/batch` is capped, and is atomic

**Requirement:** Creating multiple units via `POST /api/unit/batch` in a single request MUST be rejected as a whole when the batch would push the tenant's active unit count above the band limit. Partial admission (accepting units up to the cap and rejecting the rest) MUST NOT occur.

**Invariants:**
- The check MUST evaluate the batch's total size against the limit BEFORE any unit in the batch is persisted, or the persistence MUST be rolled back entirely on violation — the net effect for the caller is identical: all-or-nothing.
- A batch that fits within the remaining room MUST succeed in full.

#### Scenario: batch over the limit admits zero units

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 20 active units
- WHEN an admin submits `POST /api/unit/batch` with 10 new units
- THEN the entire batch is rejected
- AND the tenant's active unit count remains 20 (not 25, not any partial value)

#### Scenario: batch within the remaining room succeeds

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 20 active units
- WHEN an admin submits `POST /api/unit/batch` with 5 new units
- THEN all 5 units are created
- AND the tenant's active unit count becomes 25

### REQ-03 — `POST /unit/{id}/restore` is capped

**Requirement:** Restoring a single soft-deleted unit via `POST /api/unit/{unit}/restore` MUST be rejected when it would push the tenant's active unit count above the band limit.

**Invariants:**
- Restoring counts as an increase to the active count exactly like creating — the guard applies identically even though no new row is inserted.

#### Scenario: restore at the limit is rejected

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 25 active units and 1 additional soft-deleted unit
- WHEN an admin submits `POST /api/unit/{id}/restore` for the soft-deleted unit
- THEN the request is rejected with a Spanish-language error naming the plan, its limit, and the current count
- AND the unit remains soft-deleted

### REQ-04 — `POST /unit/batch/restore` is capped, and is atomic

**Requirement:** Bulk-restoring soft-deleted units via `POST /api/unit/batch/restore` MUST be rejected as a whole when the batch would push the tenant's active unit count above the band limit. Partial restoration MUST NOT occur.

#### Scenario: bulk restore over the limit restores zero units

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 20 active units and 8 soft-deleted units
- WHEN an admin submits `POST /api/unit/batch/restore` for all 8 soft-deleted units
- THEN the entire batch is rejected
- AND all 8 units remain soft-deleted
- AND the tenant's active unit count remains 20

### REQ-05 — Excel importer warns BEFORE executing, not after

**Requirement:** `POST /import/validate` MUST report a BLOCKING error when `current_active_units + units_to_create > band_max` for the tenant's current plan, BEFORE the import is executed. The error MUST name the plan, its limit, and how many new units the file would create.

**Invariants:**
- `units_to_create` MUST be the value already computed by `ImportRunner::calculateDistinctCounters()` (distinct NEW units implied by valid rows) — see REQ-10 for the no-per-row-query guard.
- The check MUST run during `/import/validate`, not only surface as a failure at `/import/execute` time.
- When the file's `units_to_create` fits within the remaining room, validation MUST NOT report this error (other validation rules are unaffected).

#### Scenario: dev fixture — file exceeds remaining room, validate blocks

- GIVEN tenant "Privada Los Encinos (Dev)" on plan Básico (`suggested_unit_max = 25`) with 6 active units
- AND an import file whose valid rows imply 30 distinct NEW units
- WHEN `POST /import/validate` runs
- THEN the response includes a blocking error stating the plan (Básico), its limit (25), the current active count (6), and the number of new units the file would create (30)
- AND the response marks the import as blocked (cannot proceed to execute)

#### Scenario: dev fixture — file within remaining room, validate passes

- GIVEN tenant "Privada Los Encinos (Dev)" on plan Básico (`suggested_unit_max = 25`) with 6 active units
- AND an import file whose valid rows imply 15 distinct NEW units
- WHEN `POST /import/validate` runs
- THEN the response does NOT include a unit-limit blocking error
- AND (assuming no other validation errors) the import may proceed to execute

### REQ-06 — Soft-deleted units never consume the band

**Requirement:** A tenant's active unit count for the purpose of this cap MUST exclude soft-deleted units (`deleted_at IS NOT NULL`).

#### Scenario: deleting a unit frees a slot

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with 25 active units
- WHEN one unit is soft-deleted
- THEN the tenant's active unit count becomes 24
- AND a subsequent `POST /api/unit` for one new unit succeeds, bringing the count back to 25

### REQ-07 — Non-liveness-changing updates are never blocked

**Requirement:** Updating an existing, currently-active unit (e.g. `PUT /api/unit/{id}` changing its name, floor, features, or any attribute that does not restore it from a soft-deleted state) MUST NOT be subject to this cap.

**Invariants:**
- The guard applies ONLY to operations that change a unit's liveness from absent/deleted to active (create, restore) — never to in-place updates of an already-active unit.

#### Scenario: updating an active unit at the limit succeeds

- GIVEN a tenant on plan Básico (`suggested_unit_max = 25`) with exactly 25 active units
- WHEN an admin submits `PUT /api/unit/{id}` to rename one of the existing active units
- THEN the update succeeds
- AND the active unit count remains 25

### REQ-08 — Top-band copy degrades gracefully (no dead-end upgrade link)

**Requirement:** When a tenant on the top band (Premium, `suggested_unit_max = 300`) hits its limit, the block message MUST NOT direct the admin to upgrade to a next plan, because no such plan exists among the 5 seeded bands.

**Invariants:**
- The message-building logic MUST NOT assume a "next band" always exists above the caller's current band.
- Bands below Premium MAY continue to name the next fitting band as an upgrade hint (not required by this spec, but must not regress if already planned); Premium MUST NOT.

#### Scenario: Premium tenant hits the ceiling

- GIVEN a tenant on plan Premium (`suggested_unit_max = 300`) with 300 active units
- WHEN an admin submits `POST /api/unit` for one new unit
- THEN the request is rejected with a Spanish-language error naming the plan (Premium), its limit (300), and the current count (300)
- AND the message does NOT reference or link to any plan beyond Premium

### REQ-09 — Messages are Mexican Spanish and name concrete numbers

**Requirement:** Every block message produced by this guard (across all 5 paths) MUST be written in Mexican Spanish (tú-form, not vos) and MUST name the concrete numbers involved: the plan name, its band limit, the tenant's current active count, and the size of the attempted increase (1 for a single create/restore, N for a batch or import).

**Invariants:**
- No generic, numberless message (e.g. "límite alcanzado") satisfies this requirement on its own — the plan name and the three numbers MUST be present.
- Grammar and second-person voice MUST use "tú" forms (e.g. "Tu plan Básico permite..."), never "vos" forms.

#### Scenario: batch rejection message names all four values

- GIVEN a tenant on plan Inicial (`suggested_unit_max = 50`) with 48 active units
- WHEN an admin submits `POST /api/unit/batch` with 5 new units
- THEN the rejection message names the plan (Inicial), the limit (50), the current count (48), and the attempted increase (5)
- AND the message is phrased in tú-form Mexican Spanish

### REQ-10 — No per-row queries on the importer's validate path (regression guard)

**Requirement:** Adding the unit-limit check to `/import/validate` MUST NOT introduce per-row database queries. The preview MUST reuse the already-computed `units_to_create` value from `ImportRunner::calculateDistinctCounters()`.

**Invariants:**
- The band resolution and active-count query MUST run once per validate request, not once per row.
- `calculateDistinctCounters()`'s existing constant-query-count contract MUST NOT regress.

### REQ-11 — Domain contracts unchanged (regression guard)

**Requirement:** This change MUST NOT alter `/import/execute` behavior, chunking topology, FIFO payment application, or the existing `UnitObserver::created` auto-charge behavior for units that are successfully created or restored.

**Invariants:**
- `ImportRunner`/`RowImporter` execute-path behavior, chunk sizing, and job dispatch MUST behave exactly as before this change.
- `UnitObserver::created`'s existing auto-charge-generation side effect MUST continue to fire, unchanged, for every unit that passes this guard and is actually created.
- No migration, schema change, or data backfill is introduced by this spec (locked user decision: not in production yet, no grandfathering).

---

## Open Questions (left for design to settle — do NOT invent behavior here)

These are explicitly unresolved at the spec level; the design artifact MUST pick one option for each and justify it.

- **Unresolvable band** (a tenant with no active `tenant_entitlement`, or with more than one distinct `billing_package_id` across active entitlements — not observed in current data, but not structurally impossible): design MUST decide fail-open-with-log vs fail-closed, and where that decision is logged/surfaced.
- **Super-admin bypass**: the proposal assumed NO exemption in v1 (super admins are subject to the same cap as tenant admins when acting within a tenant). Design MUST confirm or override this, with justification.
- **Concurrency**: two requests (e.g. two overlapping batches, or a batch racing a single create) that would each individually fit but together exceed the band. Design MUST decide the locking/transaction strategy that prevents both being admitted.

---

## Out-of-scope (confirmed, do NOT test or enforce)

- The downgrade guard in `ConfigurationController::changeBillingPlan` / `PreferentialSwapService` (moving a tenant to a smaller band while already over it) — explicit user decision, separate change.
- Creating an Enterprise/unlimited tier — separate change; this spec is built against the 5 existing seeded bands (Básico, Inicial, Esencial, Avanzado, Premium).
- Stripe subscription quantity / usage reporting (hardcoded to 1 across `MultiItemSubscriptionService`, `PreferentialSwapService`, `EntitlementService` today) — not touched by this change.
- Backfill or grandfathering for tenants already over their band's limit — confirmed not in production yet, so no such tenants exist.
- Resurrecting the dropped `subscription_plan.unit_limit` column/table — dead trail, table no longer exists.
