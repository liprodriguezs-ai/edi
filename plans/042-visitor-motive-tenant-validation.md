# Plan 042: Tenant-scope `visitor_motive` validation rules + add Update uniqueness

> **Executor instructions**: Follow step by step, verify each step, honor STOP
> conditions, update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Access/app/Http`
> Compare excerpts below to live code on any change.

## Status

- **Priority**: P2
- **Effort**: S
- **Depends on**: none
- **Category**: bug / security (tenant integrity)
- **Planned at**: backend submodule `fb2062b`, 2026-06-21

## Why this matters

`visitor_motive` is a TENANT-SCOPED catalog: the central ALTER migration
`backend/database/migrations/2026_06_11_120000_make_editable_catalogs_tenant_scoped.php:80`
adds `tenant_id` + per-tenant uniqueness, the model extends `TenantCatalogModel`
(`BelongsToTenant`), and `VisitorMotiveCreateRequest` correctly scopes its unique
rule by `tenant('id')`. BUT three validation sites treat it as if it were a CENTRAL
catalog and validate existence WITHOUT a tenant filter, and the Update request omits
the uniqueness rule entirely:

1. `VisitorCreateRequest.php:21` — `Rule::exists('visitor_motive','id')` (no tenant
   scope) + a misleading comment claiming it is CENTRAL.
2. `VisitorUpdateRequest.php:15` — same unscoped `Rule::exists`.
3. `AccessLogController.php:201` (`manualCaptureRules`) — same unscoped `Rule::exists`.
4. `VisitorMotiveUpdateRequest.php:15` — `name` has no uniqueness rule, so renaming a
   motive to a name already used in the tenant hits the DB `unique(tenant_id,name)` →
   **500** instead of a clean 422.

`Rule::exists` runs a raw query that bypasses `TenantScope`, so today a payload can
reference another tenant's `visitor_motive_id` and pass validation (cross-tenant data
reference; the relation later resolves to null in the scoped Eloquent read). This is
the same class as plan 036 (payment tenant-scoped exists) and plan 038 (category
update unique). Low blast radius, trivial fix, removes a real integrity hole and a 500.

> Note: `qr_type`, `access_verification`, `access_entry_type`, `visitor_status`,
> `blacklist_type` ARE central catalogs (no tenant_id) — their `Rule::exists` must
> stay UNSCOPED. Only `visitor_motive` changed classification.

## Current state

`backend/Modules/Access/app/Http/Requests/VisitorCreateRequest.php:20-21`:
```php
// visitor_motive / qr_type are CENTRAL catalogs (no tenant_id).   <-- comment WRONG for visitor_motive
'visitor_motive_id' => ['required', 'uuid', Rule::exists('visitor_motive', 'id')],
'qr_type_id' => ['required', 'uuid', Rule::exists('qr_type', 'id')],   // qr_type IS central — leave as-is
```

`backend/Modules/Access/app/Http/Requests/VisitorUpdateRequest.php:15`:
```php
'visitor_motive_id' => ['sometimes', 'uuid', Rule::exists('visitor_motive', 'id')],
```

`backend/Modules/Access/app/Http/Controllers/AccessLogController.php:200`:
```php
'visitor_motive_id' => ['nullable', 'uuid', Rule::exists('visitor_motive', 'id')],
```

`backend/Modules/Access/app/Http/Requests/VisitorMotiveUpdateRequest.php:12-17`:
```php
public function rules(): array {
    return ['name' => ['sometimes', 'required', 'string', 'max:255']];
}
```

Exemplar of the correct scoped pattern — `VisitorMotiveCreateRequest.php:20`:
```php
Rule::unique('visitor_motive', 'name')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
```

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Backend lint | `cd backend && vendor/bin/pint Modules/Access` | exit 0 |
| Backend tests | `cd backend && php artisan test --filter=Access` | pass (or none) |

## Scope

**In scope**:
- `backend/Modules/Access/app/Http/Requests/VisitorCreateRequest.php`
- `backend/Modules/Access/app/Http/Requests/VisitorUpdateRequest.php`
- `backend/Modules/Access/app/Http/Requests/VisitorMotiveUpdateRequest.php`
- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php`

**Out of scope**:
- `qr_type`, `access_verification`, and other CENTRAL catalog `Rule::exists` calls — they must stay unscoped.
- The model / migration (already correct).

## Steps

### Step 1: Tenant-scope the three `visitor_motive` exists rules

In the three files, change `Rule::exists('visitor_motive', 'id')` to
`Rule::exists('visitor_motive', 'id')->where('tenant_id', tenant('id'))`. Fix the
misleading comment in `VisitorCreateRequest.php:20` to read that `visitor_motive` is
TENANT-scoped while `qr_type` is central.

**Verify**: `cd backend && grep -rn "exists('visitor_motive'" Modules/Access` → every
match includes `->where('tenant_id', tenant('id'))`.

### Step 2: Add tenant-scoped uniqueness to `VisitorMotiveUpdateRequest`

Add to the `name` rule (mirroring the Create request, plus `->ignore(...)` for the row
being updated):
```php
Rule::unique('visitor_motive', 'name')
    ->where('tenant_id', tenant('id'))
    ->ignore($this->route('visitor_motive'))
    ->whereNull('deleted_at'),
```
Import `Illuminate\Validation\Rule`. Confirm `$this->route('visitor_motive')` is the
correct route-parameter name (Orion resource `visitor_motive` → the param is the
model key; verify against the route registration in `routes/api.php`).

**Verify**: `cd backend && vendor/bin/pint Modules/Access` → exit 0.

## Test plan

- Add a feature test `backend/Modules/Access/tests/Feature/VisitorMotiveTest.php`
  (model after any existing tenant-scoped catalog test, e.g. the Collections
  `ChargeConceptCategory` tests referenced by plan 038):
  - renaming a motive to a name used by ANOTHER row in the same tenant → 422 (not 500).
  - creating a visitor with a `visitor_motive_id` from a different tenant → 422.
- If no test harness for Access exists, document that and rely on lint + manual.
- Verification: `cd backend && php artisan test --filter=VisitorMotive` → pass.

## Done criteria

- [ ] `cd backend && grep -rn "exists('visitor_motive'" Modules/Access` shows all calls tenant-scoped
- [ ] `VisitorMotiveUpdateRequest` has a tenant-scoped, ignore-self unique rule on `name`
- [ ] `qr_type`/`access_verification` exists rules remain UNSCOPED (`grep -rn "exists('qr_type'\|exists('access_verification'" Modules/Access` shows no tenant filter)
- [ ] `cd backend && vendor/bin/pint Modules/Access` exits 0
- [ ] `plans/README.md` row updated

## STOP conditions

- `tenant('id')` is not available in the request context (it is, via `initializeTenancy`
  middleware on these routes) — if a test shows it null, report.
- The route parameter for `VisitorMotiveUpdateRequest` is not `visitor_motive` — fix the
  `->ignore()` accordingly and note it.

## Maintenance notes

- If `visitor_motive` is ever reverted to central, these scopes must be removed again —
  keep them consistent with the model's base class.
- Reviewer: confirm only `visitor_motive` was scoped, not the central catalogs.
