# Plan 010: Add cross-tenant isolation tests for Housing, Treasury, Projects

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report — especially if a test reveals a real leak. Update
> this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- tests/Feature/Security tests/Feature/Housing tests/Feature/Treasury tests/Feature/Projects`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW (tests only; but may surface a real bug — see STOP)
- **Depends on**: none
- **Category**: tests
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

This is a single-database multi-tenant SaaS: every tenant's rows live in shared
tables, isolated only by a `tenant_id` column + the global `TenantScope` (from
`BelongsToTenant`). The entire security model rests on that scope never being
bypassed. Cross-tenant isolation is tested today for Collections, Chat,
Announcements, Board and Access — but **Housing** (`unit`, `tower`, `section`,
`vehicle`, `unit_user`…), **Treasury** (`bank_account`, `expense`…) and
**Projects** (`community_project`, `project_contribution`…) have no test that a
user from cluster A is denied (404, not 200) when requesting a resource by an ID
belonging to cluster B. A single forgotten scope on a relationship in any of
these modules would leak another cluster's financial or residential data with no
test to catch it. This plan closes that gap.

## Current state

- `backend/tests/Feature/Security/AuthorizationGuardTest.php` — contains the
  established cross-tenant test patterns (two tenants, a user attached to tenant
  A, asserts denial when touching tenant B's resources). Read it for the
  `makeTenant` / `seedTenant` / token / `withHeader('X-Tenant-Id', ...)` helpers
  and the assertion style.
- `backend/tests/Feature/Housing/`, `tests/Feature/Treasury/`,
  `tests/Feature/Projects/` — module tests exist but none asserts the
  "resource of another tenant → 404" property (verified: grep for
  `cross.tenant` / `another.tenant` / `isolation` returns nothing in these dirs).
- Orion resource routes return **404** (not 403) for out-of-scope IDs because the
  `TenantScope` makes the row invisible — so the assertion is `assertStatus(404)`
  on `GET /api/<resource>/{id-from-other-tenant}`.

## Commands you will need

| Purpose         | Command (from `backend/`)                              | Expected         |
|-----------------|--------------------------------------------------------|------------------|
| Run new tests   | `php artisan test --filter TenantIsolation`            | all pass         |
| Run module suites | `php artisan test tests/Feature/Housing tests/Feature/Treasury tests/Feature/Projects` | all pass |
| Format          | `vendor/bin/pint --dirty`                              | exit 0           |

## Scope

**In scope**:
- `backend/tests/Feature/Security/TenantIsolationTest.php` (create) — OR extend
  `AuthorizationGuardTest.php` if that's the established home; prefer a new file
  to keep it focused.

**Out of scope**:
- Any production code. This is tests only. If a test FAILS because a real leak
  exists, that's a STOP condition (a separate fix plan), not a code change here.
- Re-testing the modules already covered (Collections, Chat, Announcements,
  Board, Access).

## Git workflow

- Branch: `advisor/010-tenant-isolation-tests`
- Conventional commit: `test(security): cross-tenant isolation for Housing/Treasury/Projects`
- No push/PR unless instructed. `backend/` is a submodule.

## Steps

### Step 1: Study the existing isolation pattern

Read `AuthorizationGuardTest.php` end to end. Identify: how it creates two
tenants, seeds catalogs/permissions, creates a user attached to tenant A, mints a
token, and issues a request with `X-Tenant-Id`. Reuse these helpers (copy the
trait/setup or extend the same base) — do not invent a new harness.

### Step 2: Write isolation tests for the three modules

Create `TenantIsolationTest.php`. For each module, the shape is:
1. Create tenant A and tenant B; seed both.
2. Create a resource in tenant B (e.g. a `unit`, a `bank_account`, a
   `community_project`) using tenant B's context.
3. Authenticate as an admin/owner of tenant A.
4. Assert `GET /api/<resource>/{B_resource_id}` → **404**.
5. Where a custom (non-Orion) route exists that takes an ID
   (`/api/unit/{id}/statement` is Collections — already covered; but Housing's
   unit detail, Treasury's expense show, Projects' contribution show), assert the
   same.

Cover at minimum:
- **Housing**: `unit` (and ideally `tower` or `vehicle`).
- **Treasury**: `bank_account` (and ideally `expense`).
- **Projects**: `community_project` (and ideally `project_contribution`).

Use the resource route names actually registered — confirm them in each
module's `routes/api.php` (e.g. `Orion::resource('unit', ...)`), don't guess the
path.

### Step 3: Run and format

- `php artisan test --filter TenantIsolation` → all pass.
- `vendor/bin/pint --dirty` → exit 0.

## Test plan

This plan IS the test. The new file must contain at least 3 passing isolation
assertions (one per module minimum; 6 preferred). Each asserts a tenant-A actor
gets 404 on a tenant-B resource ID. Model structure and helpers on
`AuthorizationGuardTest.php`.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `tests/Feature/Security/TenantIsolationTest.php` exists with ≥3 isolation cases (Housing, Treasury, Projects).
- [ ] `php artisan test --filter TenantIsolation` exits 0.
- [ ] `php artisan test tests/Feature/Housing tests/Feature/Treasury tests/Feature/Projects` still exits 0.
- [ ] `vendor/bin/pint --test` clean on the new file.
- [ ] No production code modified (`git status` shows only the test file).
- [ ] `plans/README.md` status row updated.

## STOP conditions

- A new isolation test **fails** — i.e. a tenant-A actor receives 200 (not 404)
  for a tenant-B resource. This is a **real cross-tenant data leak**. STOP
  immediately, do NOT weaken the test to make it pass, and report the exact
  module/route/resource. It needs its own urgent fix plan.
- The Orion routes return 403 (not 404) for out-of-scope IDs in some module —
  adjust the assertion to match the actual (still-denied) status and note the
  inconsistency; denial is what matters, but report the status difference.
- The test harness in `AuthorizationGuardTest.php` has drifted and no longer
  matches the helpers described — report it before reimplementing.

## Maintenance notes

- Every new tenant-scoped module should get an isolation case in this file at
  creation time — it's the cheapest guard against the highest-severity bug class
  this architecture has.
- A reviewer should confirm each test actually creates the resource in the OTHER
  tenant (not the actor's tenant) — a test that accidentally creates it in
  tenant A would pass trivially and prove nothing.
