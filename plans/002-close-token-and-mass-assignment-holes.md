# Plan 002: Reject refresh tokens and deactivated users on every authenticated route, and stop mass-assigning protected user attributes on create

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat dbe3682..HEAD -- backend/Modules/*/routes/api.php backend/Modules/User/app backend/bootstrap/app.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW (both changes are additive restrictions; the only breakage mode is a client relying on behavior that is itself the bug)
- **Depends on**: plans/001-activate-ci-and-verification-loop.md (soft — for the `make` verification commands; the plan is executable without it using the raw commands below)
- **Category**: security
- **Planned at**: commit `dbe3682`, 2026-08-25

## Why this matters

Two confirmed authorization gaps, both on a system holding child health records:

**Part A — token/deactivation gap.** The middleware aliases `rejectRefreshToken` (blocks a 7-day refresh token from being used as an access token) and `authActive` (blocks users an admin deactivated) exist and are applied on the admin modules (User, Tenant, Role, Audit, Notification, SubscriptionPlan) — but on **none** of the clinical modules. The `permission:` middleware checks role permissions, never token abilities, and the custom screening actions (`score`, `complete`, `invalidate`, …) never call Orion's `tokenCan()` path. Net effect: a leaked refresh token, or a deactivated clinician's still-valid access token, can read and write every child record and screening in the tenant.

**Part B — user-create mass assignment.** `POST /api/user` passes the raw request body into `User::fill()`. The *update* path strips a documented list of protected attributes (`auth_active`, `email_verified_at`, `verification_code`, audit attribution columns, …) — the *create* path does not. Any holder of `create.user` (the tenant `administrator` role) can mint accounts with pre-verified email, forced activation, chosen verification code, and forged audit attribution.

## Current state

### Part A

- `backend/bootstrap/app.php:39-49` registers the aliases:

```php
        $middleware->alias([
            'permission' => VerifyPermission::class,
            ...
            'rejectRefreshToken' => RejectRefreshToken::class,
            'authActive' => EnsureUserIsActive::class,
            ...
        ]);
```

- Exemplar of the CORRECT pattern — `backend/Modules/User/routes/api.php:41`:

```php
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);
```

- Exemplar of the WRONG pattern — `backend/Modules/Screening/routes/api.php` (all groups):

```php
    Orion::resource('screening', ScreeningController::class)
        ->only(['search', 'index', 'show', 'update'])
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Route::middleware(['auth:sanctum', 'initializeTenancy'])->group(function () {
```

- Enumeration (verified 2026-08-25): occurrences of `auth:sanctum` vs `rejectRefreshToken` per route file. Every file where the counts differ has the gap:

| File (under `backend/`) | auth:sanctum | rejectRefreshToken |
|---|---|---|
| `Modules/ChildRecord/routes/api.php` | 10 | 0 |
| `Modules/Stimulation/routes/api.php` | 14 | 0 |
| `Modules/Instrument/routes/api.php` | 8 | 0 |
| `Modules/Screening/routes/api.php` | 4 | 0 |
| `Modules/FollowUp/routes/api.php` | 4 | 0 |
| `Modules/Reports/routes/api.php` | 3 | 0 |
| `Modules/Clinic/routes/api.php` | 2 | 0 |
| `Modules/Company/routes/api.php` | 2 | 0 |
| `Modules/System/routes/api.php` | 2 | 0 |
| `Modules/Country/routes/api.php`, `Dashboard`, `Email`, `Module`, `Municipality`, `Setting`, `Tracking`, `UserProfile` | 1 each | 0 |
| `Modules/User/routes/api.php` | 12 | 1 (partial!) |
| `Modules/SubscriptionPlan/routes/api.php` | 8 | 6 (partial) |
| `Modules/Notification/routes/api.php` | 3 | 1 (partial) |
| `Modules/Role/routes/api.php` | 2 | 1 (partial) |
| `Modules/Tenant/routes/api.php` | 2 | 1 (partial) |
| `Modules/Audit/routes/api.php` | 1 | 1 (complete) |
| `routes/api.php` (app root) | 3 | 2 — **leave as is**, see Scope |

### Part B

- `backend/Modules/User/app/Http/Controllers/UserController.php:148-179`:

```php
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(UserService::class)->create($request->all());
        ...
    }

    private const UPDATE_PROTECTED_ATTRIBUTES = [
        'auth_active',
        'email_verified_at',
        'verification_code',
        'update_password',
        'user_register_status_id',
        'last_active_tenant_id',
        'onboarding',
        'onboarding_completed_at',
        'user_created_id',
        'user_updated_id',
        'user_deleted_id',
        'user_restored_id',
    ];

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        parent::performUpdate($request, $entity, Arr::except($attributes, self::UPDATE_PROTECTED_ATTRIBUTES));
    }
```

- `backend/Modules/User/app/Services/UserService.php:39-41` does `$entity = new User; $entity->fill($request); $entity->save();` on the array it receives. Role escalation is already guarded above it (`Role::canAssignSlug`) — do NOT touch that logic.

## Commands you will need

Run from `backend/`, with the dev stack up (`./start.sh`). The env vars are mandatory (phpunit.xml points at a Docker-internal hostname):

| Purpose | Command | Expected |
|---|---|---|
| Focused suite | `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=$USER DB_PASSWORD=password php artisan test --compact tests/Feature/Security` | all pass |
| Module suites | same prefix + `php artisan test --compact Modules/Screening/tests Modules/ChildRecord/tests` | all pass |
| User suite | same prefix + `php artisan test --compact tests/Feature/User` | all pass |
| Format | `./vendor/bin/pint --dirty` | no violations left |

## Scope

**In scope**:
- Every `backend/Modules/*/routes/api.php` listed in the table with a gap (complete or partial)
- `backend/Modules/User/app/Http/Controllers/UserController.php`
- `backend/Modules/User/app/Services/UserService.php` (only if needed for Part B wiring)
- New test file(s) under `backend/tests/Feature/Security/`
- `plans/README.md` (status row)

**Out of scope** (do NOT touch):
- `backend/routes/api.php` (app root): its auth-without-reject route is the **refresh endpoint itself**, which must accept refresh tokens. Changing it breaks login refresh.
- `backend/bootstrap/app.php`: a global `appendToGroup('api', ...)` approach was considered and rejected for this plan — it needs an allowlist for `auth/*` and changes ordering semantics; the per-route approach matches the existing convention. (Recorded as a possible future consolidation in maintenance notes.)
- `Role::canAssignSlug` and all role-escalation logic in `UserService::create` — already correct.
- `RejectRefreshToken.php` / `EnsureUserIsActive.php` middleware implementations.

## Git workflow

The working tree holds ~218 uncommitted files of unrelated in-flight work. Do NOT run `git add`, `git commit`, `git stash`, or create branches. Leave your edits uncommitted; report the file list.

## Steps

### Step 1: Write the failing security tests (TDD)

Create `backend/tests/Feature/Security/RefreshTokenRejectionTest.php`, modeled structurally on the existing `backend/tests/Feature/Security/TenantlessUserIsolationTest.php` (setup style, factories). Cases:

1. A token created with abilities `['refresh']` (mirror how `AuthController` issues it — check `backend/app/Http/Controllers/AuthController.php` around line 471 for the exact ability list) gets **401/403** on `GET /api/screening` and on `POST /api/screening/{id}/score`.
2. A user with `auth_active = false` and a normal access token gets **401/403** on `GET /api/child`.
3. (Guard rail) The refresh endpoint still accepts the refresh token (assert the pre-existing refresh flow test still passes, or add a smoke assertion).

Create `backend/tests/Feature/Security/UserCreateMassAssignmentTest.php`: acting as a user with `create.user` permission, `POST /api/user` with a payload containing `email_verified_at`, `auth_active => true`, `verification_code => 'HACK'`, `user_created_id => <other-id>`; assert the created row has NONE of those values honored (null / default / actor id respectively).

**Verify**: run the two files — the new assertions FAIL (red) against current code. If any unexpectedly passes, re-read the cited code before proceeding (STOP condition).

### Step 2: Part A — add the two aliases to every gapped middleware array

For each file in the table with `rrt` < `auth`, edit every middleware array that contains `'auth:sanctum'` so it reads `'auth:sanctum', 'rejectRefreshToken', 'authActive', ...` (insert immediately after `'auth:sanctum'`, preserving whatever follows — `initializeTenancy`, `permission:*`, `termsAccepted`). This includes the partial files (User, SubscriptionPlan, Notification, Role, Tenant) — bring every array up to the pattern.

**Verify**:

```bash
for f in backend/Modules/*/routes/api.php; do a=$(grep -c "auth:sanctum" $f); r=$(grep -c "rejectRefreshToken" $f); [ "$a" != "$r" ] && echo "GAP: $f $a/$r"; done
```

→ no output (no gaps). Same loop with `authActive` → no output.

### Step 3: Part B — apply the protected-attribute filter on create

In `UserController.php`: rename `UPDATE_PROTECTED_ATTRIBUTES` to `PROTECTED_ATTRIBUTES` (update the docblock: applies to create AND update) and change `performStore` to:

```php
$created = app(UserService::class)->create(Arr::except($request->all(), self::PROTECTED_ATTRIBUTES));
```

Keep `performUpdate` using the same constant. `Arr` is already imported (used by `performUpdate`).

**Verify**: `grep -n "PROTECTED_ATTRIBUTES" backend/Modules/User/app/Http/Controllers/UserController.php` → definition + two usages (store, update). No `UPDATE_PROTECTED_ATTRIBUTES` remains anywhere: `grep -rn UPDATE_PROTECTED_ATTRIBUTES backend/` → empty.

### Step 4: Run the suites

**Verify** (all from `backend/` with the env prefix):
1. `php artisan test --compact tests/Feature/Security` → all pass, including the new files.
2. `php artisan test --compact tests/Feature/User tests/Feature/Auth` → all pass (catches a legitimate flow that relied on a stripped attribute — if one fails, that's a STOP, not a hack-around).
3. `php artisan test --compact Modules/Screening/tests Modules/ChildRecord/tests Modules/Stimulation/tests Modules/FollowUp/tests Modules/Reports/tests` → all pass (proves the middleware addition doesn't break authenticated module tests).
4. `./vendor/bin/pint --dirty` → clean.

## Test plan

Covered by Steps 1 and 4. New tests: `RefreshTokenRejectionTest` (3 cases), `UserCreateMassAssignmentTest` (1 case, 4 assertions). Pattern file: `tests/Feature/Security/TenantlessUserIsolationTest.php`.

## Done criteria

- [ ] Gap-enumeration loops (Step 2) print nothing for both aliases
- [ ] `backend/routes/api.php` is byte-identical to its pre-plan state (`git diff -- backend/routes/api.php` → empty)
- [ ] New security tests exist and pass; suites in Step 4 pass
- [ ] `grep -rn UPDATE_PROTECTED_ATTRIBUTES backend/` → empty
- [ ] `git status` shows modifications only to route files, `UserController.php`, (optionally `UserService.php`), the two new test files — beyond the pre-existing unrelated changes
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back if:

- A Step-1 test unexpectedly PASSES before the fix (the hole may already be closed elsewhere — e.g., a global middleware was added since `dbe3682`).
- Any pre-existing Auth/Invitation/onboarding test fails after Part B — a legitimate flow may be setting a protected attribute through create; report which attribute and which flow instead of removing it from the constant.
- A route file contains an `auth:sanctum` group that clearly must accept refresh tokens (comment says so, or path contains `refresh`/`logout`) — leave it, list it in your report.
- The middleware alias names in `bootstrap/app.php` no longer match the excerpt.

## Maintenance notes

- Every NEW module's `routes/api.php` must include the two aliases; the Step-2 gap loop is a one-line audit — a reviewer can run it on any PR. Consider (future, operator decision) promoting both middleware into the `api` group in `bootstrap/app.php` with an explicit auth-route allowlist so new routes inherit them by default.
- Reviewer scrutiny: the diff should show ONLY inserted alias strings in route files — any other route change is out of scope.
- Related latent finding (backlog, not this plan): `EmailVerificationNotification::toLog()` writes the verification code + email to the log; it is currently dispatched by nothing, but strip the `log` channel before anyone wires it up.
