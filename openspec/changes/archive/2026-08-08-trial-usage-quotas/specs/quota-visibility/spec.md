# quota-visibility Specification

**Change:** `trial-usage-quotas` · **Status:** spec · **Date:** 2026-08-07

## Purpose

Surfacing quota state to the frontend: remaining quota in the existing subscription-config endpoint, and interception of the new business code in the shared API client. No dedicated quota screen in v1.

## Requirements

### Requirement: REQ-QV-01 — `GET /config/subscription` exposes remaining quota

The existing subscription-config endpoint MUST include, for each of the three v1 resources, the resolved limit for the tenant's current scope, the current usage, and whether it is unlimited — enough for the UI to warn before the wall. Unlimited (`-1` or no resolvable row) MUST be represented explicitly, not as a fake large number.

#### Scenario: trial tenant sees its quota state

- GIVEN a trial tenant with `visitor` quota 5 and 3 consumed
- WHEN the frontend fetches `GET /config/subscription`
- THEN the payload reports `visitor` with limit 5 and usage 3
- AND resources with `-1` are reported as unlimited

### Requirement: REQ-QV-02 — The quota code has ONE shared home shared by front and back

The stable quota-exceeded code MUST live in a single shared frontend constant alongside the other business codes, byte-identical to the code the backend emits. This is what prevents the new code from inheriting the `UNIT_LIMIT_EXCEEDED` / `unit_limit_reached` mismatch class of bug (see the `unit-limit-enforcement` delta), where a hand-typed literal silently disabled the branch it gated.

The backend's 422 message MUST be user-presentable Mexican Spanish on its own, so the generic error surface conveys the real reason without any quota-specific frontend branch.

#### Scenario: quota 422 reaches the user with the real reason

- GIVEN a trial tenant at its `amenity_reservation` quota
- WHEN the reservation form submits and the backend answers 422 with the quota code
- THEN the user sees the backend's Mexican-Spanish quota message naming the resource and the limit
- AND the code string the frontend holds is byte-identical to the one the backend emitted

> **AMENDED after verify.** The original wording required routing the code to a
> quota-specific handler with an upgrade path, mirroring `UnitForm`'s
> `onUnitLimitReached` dialog. The implementation followed design decision D9
> instead (shared constant, no dedicated interceptor), and verify correctly
> flagged the divergence: `BUSINESS_CODES.QUOTA_EXCEEDED` has zero consumers.
>
> The requirement is amended rather than the code, deliberately: **quotas ship
> seeded at `-1` (off), so this path is unreachable today**, and the upgrade
> dialog would mean new UI in two separate module forms (reservations and
> visitors) for an error nobody can currently trigger. The backend message
> already names the resource and the limit in Spanish, so the user is not left
> guessing.
>
> **Follow-up, required before quotas are switched on:** add the quota branch to
> the reservation and visitor forms, mirroring `UnitForm.tsx`'s
> `handleCrudError` → upgrade-dialog pattern, so a blocked resident gets a route
> to fix it instead of only being told no.
