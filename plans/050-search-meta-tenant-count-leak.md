# Plan 050: Stop `/search` responses from leaking cross-tenant global row counts

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If a
> "STOP condition" occurs, stop and report. When done, update this plan's row in
> `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- core/src/Http/Controllers/Controller.php`
> If it changed, compare the excerpt below against the live code before editing.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

Every Orion `/search` response injects `total_records_database` computed as an
**unscoped** `Model::count()`. For CENTRAL models (no `BelongsToTenant`, so no
`TenantScope`), this is the row count across ALL tenants/installs. `User` is central
and `read.user` is a COMMON permission (not super-admin-exclusive), so a plain
`account.owner` or `company_admin` of any single cluster can `POST /api/user/search`
and read the platform-wide user total in the response metadata — a cross-tenant /
cross-install business-intelligence disclosure. For tenant-scoped models queried by a
row-restricted resident, it likewise leaks the tenant-wide total instead of the
resident's visible subset. This was flagged in the 2026-06-12 QA report and is still
present.

## Current state

- `backend/core/src/Http/Controllers/Controller.php` (the whole file, 24 lines):
  ```php
  class Controller extends BaseController
  {
      use AppliesCentralUserSearch;
      use TraitController;

      // Este hook se ejecuta justo antes de enviar la respuesta
      protected function afterSearch(Builder $query, &$entities)
      {
          // Contamos cuántos registros hay en la tabla REAL (sin filtros)
          $totalInTable = $this->getModel()::count();

          // Inyectamos el dato en los metadatos de la respuesta de Orion
          $this->with['total_records_database'] = $totalInTable;
      }
  }
  ```
  This base controller is extended by (almost) every module's Orion controller, so
  the leak is repo-wide on any searchable resource.
- Orion already passes the **scoped** `Builder $query` (the same builder that produced
  the paginated, tenant- and row-scoped result set) as the first argument — it is
  available and unused.
- `backend/app/Models/User.php` — `User` extends `Authenticatable`, no
  `BelongsToTenant` (central). `database/seeders/RolePermissionTableSeeder.php` — `read.user`
  sits in the common permission set (grep for `read.user` to confirm it is NOT inside
  `$permissionsOnlySuperAdmin`).

Convention: custom controller responses use Orion's `$this->with[...]` meta bag; keep
using it. Money/tenancy rules don't apply here — this is pure query scoping.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l core/src/Http/Controllers/Controller.php` | `No syntax errors detected` |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=SearchMeta` (new test) plus one existing search test, e.g. `--filter=RolePermissionBoundary` | all pass |

> Host php, shared `testing` DB, ONE process at a time (see repo CLAUDE.md).

## Decision: scope the count, do not silently drop it

The frontend may read `total_records_database`. Grep first:
`git -C ../frontend grep -n "total_records_database" src || true` and
`grep -rn "total_records_database" backend --include=*.php | grep -v afterSearch`.

- If there are **no consumers**, remove the meta entirely (simplest, closes the leak).
- If there ARE consumers, replace the unscoped count with a **scoped** count derived
  from the builder Orion passed in, so the number becomes "rows visible to this
  caller in this tenant" instead of the global total.

Both options close the leak; pick based on the grep result and record which in the PR.

## Steps

### Step 1: Determine consumers

Run the two greps above.

**Verify**: you have a definitive yes/no on frontend + backend consumers. Write it in
the PR description.

### Step 2a: If no consumers — remove the leak

Delete the `afterSearch` override entirely (the base Orion controller's default does
not add this key).

### Step 2b: If consumers exist — scope the count

Replace the body with a scoped count off the passed-in builder:

```php
protected function afterSearch(Builder $query, &$entities)
{
    // Count only rows visible to THIS request (tenant scope + controller row
    // filters already applied to $query). NEVER Model::count() — that is unscoped
    // and leaks cross-tenant/global totals for central models like User.
    $this->with['total_records_database'] = (clone $query)->toBase()->getCountForPagination();
}
```

**Verify**: `cd backend && php -l core/src/Http/Controllers/Controller.php` → `No syntax errors detected`.

### Step 3: Regression test

Add a feature test (model it after `tests/Feature/RolePermissionBoundary*` for the
multi-tenant, multi-role login setup):
- Seed 2 tenants with a known, DIFFERENT number of users each.
- Log in as an `account.owner` of tenant A (real login token, not `createToken` — those
  carry `*`; see QA report note).
- `POST /api/user/search`.
- Assert: if the key is kept, `total_records_database` equals tenant A's user count,
  NOT the sum across both tenants; if removed, assert the key is absent.

**Verify**: `cd backend && php artisan test --filter=SearchMeta` → passes; confirm it FAILS against the old unscoped code.

### Step 4: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Grep result for consumers recorded in the PR
- [ ] `php -l` clean
- [ ] New test asserts a tenant-scoped (or absent) count and fails against the old code
- [ ] One pre-existing search test still green (e.g. `--filter=RolePermissionBoundary`)
- [ ] `vendor/bin/pint --dirty` exit 0
- [ ] Only `Controller.php` + the new test modified (`git -C backend status`)
- [ ] `plans/README.md` row updated

## STOP conditions

- The `afterSearch` code differs from the excerpt (drift).
- `getCountForPagination()` is unavailable on the base builder for some model (report;
  fall back to `(clone $query)->count()`).
- A frontend consumer relies on the GLOBAL total for a legitimate super-admin screen —
  if so, gate that ONE screen's number behind an explicit super-admin endpoint rather
  than re-globalizing the shared hook; report before doing that.

## Maintenance notes

- Any new Orion controller inherits this base — the fix protects all of them at once.
- Reviewer: confirm the replacement uses the passed-in `$query`, not `getModel()`.
- If a future central model exposes a genuinely public count, add a dedicated endpoint
  for it rather than reopening the shared hook.
