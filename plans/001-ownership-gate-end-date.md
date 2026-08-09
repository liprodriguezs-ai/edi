# Plan 001: Active-membership gates reject ex-residents (`end_date IS NULL`)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- Modules/Collections/app/Http/Controllers/PaymentController.php Modules/Projects/app/Http/Controllers/ProjectContributionController.php Modules/Projects/app/Http/Controllers/CommunityProjectController.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

This is a multi-tenant SaaS for residential clusters. A resident's link to a
unit lives in the `unit_user` pivot, and that link is **closed by setting
`end_date`** (the row is never deleted — it's kept for history). Four
authorization gates check membership with `UnitUser::where('unit_id', ...)
->where('user_id', ...)->exists()` but **forget `whereNull('end_date')`**, so a
former resident (moved out, evicted, sold the unit) still passes the gate. The
concrete cost: an ex-resident can submit pending-validation payments against
their old unit (operational noise + attaches files) and **read the full
financial statement** (charges, credit balance, payment history) of whoever
lives there now. The correct pattern already exists elsewhere in the codebase —
this plan aligns the four stragglers to it.

## Current state

Four membership gates omit the active-link filter. The correct pattern is used
in `backend/Modules/Access/app/Http/Controllers/VisitorController.php:164`
(`->whereNull('end_date')`) — match it.

- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` — payment endpoints. Two gates:
  - `submit()` around line 125 (resident self-capture of own payment):
    ```php
    // Authorize: the caller must be a member of the unit (owner/renter/etc).
    $belongsToUnit = UnitUser::where('unit_id', $validated['unit_id'])
        ->where('user_id', $user->id)
        ->exists();
    ```
  - `statement()` around line 254 (read a unit's account statement):
    ```php
    $belongsToUnit = $user && UnitUser::where('unit_id', $unitId)
        ->where('user_id', $user->id)
        ->exists();
    ```
- `backend/Modules/Projects/app/Http/Controllers/ProjectContributionController.php` — around line 126, same `UnitUser::where(...)->where(...)->exists()` shape gating a contribution action.
- `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php` — around line 298, same shape.

**Convention**: these are plain `UnitUser` Eloquent queries; `UnitUser` is
tenant-scoped (global `TenantScope` already filters `tenant_id`), so you only
need to add the `end_date` clause — do NOT add a manual `tenant_id` filter.

## Commands you will need

| Purpose   | Command (run from `backend/`)                                  | Expected on success |
|-----------|----------------------------------------------------------------|---------------------|
| Lint a file | `php -l <file>`                                              | `No syntax errors`  |
| Format    | `vendor/bin/pint --dirty`                                       | exit 0              |
| Run tests | `php artisan test tests/Feature/Collections tests/Feature/Projects` | all pass       |
| Run one test | `php artisan test --filter <TestName>`                      | passes              |

## Scope

**In scope**:
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php`
- `backend/Modules/Projects/app/Http/Controllers/ProjectContributionController.php`
- `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php`
- `backend/tests/Feature/Collections/` — add ONE new test file (see Test plan)

**Out of scope** (do NOT touch):
- `backend/Modules/Collections/app/Services/PaymentService.php` — already correct.
- `backend/Modules/Access/app/Http/Controllers/VisitorController.php` — the exemplar; already correct.
- Any change to response shapes or status codes.

## Git workflow

- Branch: `advisor/001-ownership-gate-end-date`
- Conventional commits (repo style): e.g. `fix(security): exclude ex-residents from unit membership gates`
- Do NOT push or open a PR unless the operator instructed it. The `backend/` directory is a git submodule — commit inside it.

## Steps

### Step 1: Add `whereNull('end_date')` to the four gates

In each of the four locations listed in "Current state", insert
`->whereNull('end_date')` into the `UnitUser` query chain (right after the
`->where('user_id', ...)` line, before `->exists()`). Example for the `submit()`
gate:

```php
$belongsToUnit = UnitUser::where('unit_id', $validated['unit_id'])
    ->where('user_id', $user->id)
    ->whereNull('end_date')
    ->exists();
```

Apply the equivalent edit to all four. Do not change variable names or
surrounding logic.

**Verify**:
- `php -l Modules/Collections/app/Http/Controllers/PaymentController.php` → `No syntax errors detected`
- `php -l Modules/Projects/app/Http/Controllers/ProjectContributionController.php` → `No syntax errors detected`
- `php -l Modules/Projects/app/Http/Controllers/CommunityProjectController.php` → `No syntax errors detected`
- `grep -c "whereNull('end_date')" Modules/Collections/app/Http/Controllers/PaymentController.php` → `2` or more

### Step 2: Format

Run `vendor/bin/pint --dirty` → exit 0.

### Step 3: Add the regression test (see Test plan), then run it.

## Test plan

Create `backend/tests/Feature/Collections/PaymentOwnershipGateTest.php`. Model
its structure after an existing tenant-aware HTTP test —
`backend/tests/Feature/Collections/CollectionsModuleTest.php` shows the tenant +
user + token setup pattern (`makeTenant`, `seedTenant`, token via
`createToken`, `withToken(...)->withHeader('X-Tenant-Id', ...)`).

Cases to cover:
- **Active resident → 200/201**: a `unit_user` row with `end_date = null` can
  GET `/api/unit/{id}/statement` and POST `/api/payment/submit` for that unit.
- **Ex-resident → 403**: the same user with the `unit_user` row's `end_date` set
  to a past date is rejected (403) on both `statement` and `payment/submit`.
- (If feasible) the same active/ex split for the Projects contribution gate.

**Verify**: `php artisan test --filter PaymentOwnershipGateTest` → all pass
(at least the 4 cases above).

## Done criteria

ALL must hold (run from `backend/`):

- [ ] `grep -rn "UnitUser::where('unit_id'" Modules/Collections/app/Http/Controllers/PaymentController.php Modules/Projects/app/Http/Controllers/ProjectContributionController.php Modules/Projects/app/Http/Controllers/CommunityProjectController.php` — every match's query chain includes `whereNull('end_date')` (read each).
- [ ] `php artisan test tests/Feature/Collections tests/Feature/Projects` exits 0.
- [ ] `php artisan test --filter PaymentOwnershipGateTest` passes with the new cases.
- [ ] `vendor/bin/pint --test` reports no issues on the in-scope files.
- [ ] No files outside the in-scope list are modified (`git status`).
- [ ] `plans/README.md` status row updated.

## STOP conditions

Stop and report back (do not improvise) if:

- The excerpts in "Current state" don't match the live code (drift).
- A gate's surrounding logic already filters `end_date` some other way (e.g. a
  scope or a different column) — then it may not be a real omission; report it.
- The new test can't reproduce the ex-resident bypass because the endpoint
  rejects ex-residents through some other mechanism — report that the finding
  may be already mitigated.

## Maintenance notes

- Any NEW endpoint that gates on `unit_user` membership must use
  `whereNull('end_date')`. Consider extracting a query scope
  (`UnitUser::scopeActive`) in a follow-up so this can't be forgotten again —
  deferred out of this plan to keep the change minimal and low-risk.
- A reviewer should confirm the test actually exercises an ex-resident (an
  `end_date` in the past), not just an absent membership row.
