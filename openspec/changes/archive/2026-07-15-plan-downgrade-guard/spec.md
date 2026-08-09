# Spec — plan-downgrade-guard

**Change:** `plan-downgrade-guard`
**Module:** `Modules/SubscriptionPlan` + `Modules/Configuration` + `frontend/src/modules/configuration`
**Status:** spec
**Date:** 2026-07-15
**Based on proposal:** `openspec/changes/plan-downgrade-guard/proposal.md`

---

## Overview

This spec describes what MUST be true after `plan-downgrade-guard` is applied. It covers ONE new capability: **billing-plan-downgrade-guard** — closing the exit door that `unit-limit-enforcement` left open. A cluster admin MUST NOT self-serve downgrade to a billing plan whose unit band is smaller than the tenant's current active unit count; a super admin's preferential swap deliberately MUST bypass this guard. It does not prescribe implementation (e.g. exact `UnitLimitService` method signature); see the design artifact for that.

---

## Capability: billing-plan-downgrade-guard

### REQ-01 — Cluster-admin apply is blocked by band mismatch

`POST /api/config/subscription/change-billing-plan` MUST reject a plan change when the target `billing_package.suggested_unit_max` is non-NULL and strictly less than the tenant's current active unit count. The tenant's plan MUST remain unchanged on rejection.

**Invariants:** the active count MUST be computed identically to `UnitLimitService`'s existing entry-guard count (`deleted_at IS NULL`, `tenant_id`-filtered) — the two doors must agree.

#### Scenario: downgrade below current units is rejected

- GIVEN a tenant on Avanzado (101–200) with 200 active units
- WHEN the admin submits `change-billing-plan` targeting Básico (max 25)
- THEN the request is rejected, naming 200 current units, Básico's limit of 25, and Avanzado as the smallest fitting plan
- AND the tenant's active plan remains Avanzado

### REQ-02 — Preview reports the block before apply

`POST /api/config/subscription/change-billing-plan/preview` MUST report the identical block condition and message for the same target plan, before any apply attempt.

#### Scenario: preview warns before apply

- GIVEN the same 200-unit Avanzado tenant
- WHEN the admin calls preview targeting Básico
- THEN the response reports the same block, numbers, and fitting-plan name as REQ-01
- AND no plan change occurs

### REQ-03 — Super admin bypasses via preferential swap (deliberate)

`POST /api/tenant/{tenantId}/preferential-swap` (and its preview) MUST NOT apply this guard. This is a deliberate, bounded exemption — intentionally asymmetric with `unit-limit-enforcement`, which has NO super-admin exemption. Do not harmonize the two.

**Invariants:** the exemption is bounded by the existing `update.tenant`/`*` token-ability gate already in `PreferentialSwapController`'s constructor — no new authorization surface is introduced.

#### Scenario: super admin swap to under-band plan succeeds

- GIVEN the same 200-unit Avanzado tenant
- WHEN a super admin submits `preferential-swap` targeting Básico
- THEN the swap succeeds and the tenant's plan becomes Básico despite exceeding Básico's limit

### REQ-04 — Upgrades and same-band changes are never blocked

The guard MUST NOT trigger when target `suggested_unit_max` is NULL, or is `>=` the current active unit count.

#### Scenario: upgrade succeeds

- GIVEN 200 active units on Avanzado
- WHEN the admin targets Premium (max 300)
- THEN the change succeeds

#### Scenario: same-band reselect succeeds

- GIVEN 200 active units on Avanzado
- WHEN the admin re-targets Avanzado (max 200)
- THEN the change succeeds

### REQ-05 — Exactly-at-the-limit fits

The comparison MUST be strict less-than (`max < current`), never `<=`. A count exactly equal to the target's max MUST fit.

#### Scenario: exact fit succeeds

- GIVEN 25 active units
- WHEN the admin targets Básico (max 25)
- THEN the change succeeds

### REQ-06 — NULL `suggested_unit_max` means unlimited

A target plan with NULL `suggested_unit_max` MUST always fit, regardless of current unit count (future-proofs the deferred Enterprise tier).

#### Scenario: NULL-max target always fits

- GIVEN 200 active units on Avanzado
- WHEN the admin targets a plan with NULL `suggested_unit_max`
- THEN the change succeeds

### REQ-07 — Messages are Mexican Spanish and name concrete numbers

Every block/warn message (apply and preview) MUST be written in Mexican Spanish tú-form (never vos) and MUST name: the current active unit count, the target plan, its limit, and the smallest plan that fits.

#### Scenario: message names all values

- GIVEN a 200-unit Avanzado tenant targeting Básico
- WHEN the block/warn message is produced
- THEN it states "200" units, Básico's limit "25", names Avanzado as the fitting plan, and is phrased in tú-form

### REQ-08 — Guard lives at the controller, not inside the shared service

The guard MUST NOT be placed inside `PreferentialSwapService::applyCard()`/`applyManual()`. Both `ConfigurationController::changeBillingPlan` and `PreferentialSwapController::swap` funnel into these same shared methods — a service-level guard would silently block the super admin and violate REQ-03. The check MUST live in `ConfigurationController::changeBillingPlan`/`previewBillingPlanChange`.

### REQ-09 — No regression to sibling systems

This change MUST NOT alter `unit-limit-enforcement`'s entry guard, the Excel importer, `PreferentialSwapService`'s existing apply/preview behavior, or Stripe proration.

**Invariants:** `UnitLimitServiceTest` baseline (11/11) stays green; any new `tenant_entitlement` read MUST explicitly filter `tenant_id` (it is a CENTRAL table, no `BelongsToTenant` — an unfiltered read leaks other tenants' plans).

---

## Open Questions (left for design to settle — do NOT invent behavior here)

- `changeBillingPlan` and `previewBillingPlanChange` already `catch (InvalidArgumentException) → 422`. Whether the new `UnitLimitExceededException` self-renders (it implements `ShouldntReport` + `render()`) or is caught locally to keep the preview payload shape consistent is a DESIGN call.

---

## Out-of-scope (confirmed, do NOT test or enforce)

- The Enterprise/unlimited tier (deferred; NULL handling in REQ-06 future-proofs it).
- Backfill/grandfathering for tenants already over-band (not in production yet).
- Auto-selecting or preselecting the fitting plan in the UI (inform-only).
- Preemptively badging/disabling unfitting plan cards in the selector.
- Stripe subscription quantity/usage reporting.
- Harmonizing this guard's asymmetric bypass with `unit-limit-enforcement`'s no-bypass design — both are deliberate, separate user decisions.
