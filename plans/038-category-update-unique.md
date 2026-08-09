# Plan 038: `ChargeConceptCategoryUpdateRequest` enforces tenant-unique `name` (rename → 422, not 500)

> **Executor instructions**: Follow step by step, run every verification command,
> honor STOP conditions, update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Collections/app/Http/Requests`
> On mismatch with the "Current state" excerpts, treat as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: backend `fb2062b`, 2026-06-20

## Why this matters

`ChargeConceptCategoryCreateRequest` validates that `name` is unique per tenant,
but the Update request does not. The DB has a `unique(tenant_id, name)` constraint
(added by the central tenant-scoping migration). So renaming a category to a name
that already exists in the same tenant bypasses validation and hits the DB
constraint, surfacing as an unhandled `500` instead of a clean `422` with a
field error. This is the same class of gap the Create request was written to
fix (its PHPDoc literally says "sin esto … reventaba con un 500").

## Current state

- `backend/Modules/Collections/app/Http/Requests/ChargeConceptCategoryUpdateRequest.php:14-17`
  ```php
  public function rules(): array
  {
      return [
          'name' => ['sometimes', 'required', 'string', 'max:255'],
      ];
  }
  ```
- **Exemplar — the Create request already does it right** (`ChargeConceptCategoryCreateRequest.php:17-23`):
  ```php
  return [
      'name' => [
          'required', 'string', 'max:255',
          Rule::unique('charge_concept_category', 'name')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
      ],
  ];
  ```
  It imports `use Illuminate\Validation\Rule;` (line 6) — the Update request does NOT yet.
- The Update request must additionally `->ignore()` the record being edited, or it
  would collide with the row's own current name. **There is no existing
  `->ignore()` usage in this codebase to copy** (verified by grep), and the route
  model-binding parameter for the Orion `charge_concept_category` resource must be
  confirmed before relying on it — see Step 2 + STOP conditions.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHP syntax | `php -l backend/Modules/Collections/app/Http/Requests/ChargeConceptCategoryUpdateRequest.php` | `No syntax errors detected` |
| Inspect route binding | `cd backend && php artisan route:list --path=charge_concept_category` | shows the `{param}` name in the URI |
| Backend tests | `cd backend && php artisan test --filter=ChargeConceptCategory` (fallback `--filter=Category`) | all pass |
| Backend format | `cd backend && vendor/bin/pint Modules/Collections/app/Http/Requests/ChargeConceptCategoryUpdateRequest.php` | exit 0 |

## Scope

**In scope:** `backend/Modules/Collections/app/Http/Requests/ChargeConceptCategoryUpdateRequest.php`.

**Out of scope:** the Create request (already correct); the controller; the model; the migration.

## Git workflow

Submodule `backend/`. Branch `advisor/038-category-update-unique`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail.

## Steps

### Step 1: Import `Rule`

Add `use Illuminate\Validation\Rule;` to the Update request's `use` block.

**Verify**: `grep -n "use Illuminate\\\\Validation\\\\Rule;" backend/Modules/Collections/app/Http/Requests/ChargeConceptCategoryUpdateRequest.php` → one match.

### Step 2: Determine the route-bound record id

Run `cd backend && php artisan route:list --path=charge_concept_category` and read
the URI segment name (e.g. `{charge_concept_category}`). That string is the key for
`$this->route('<name>')`. Orion may bind it to a model instance or a raw id —
`Rule::unique(...)->ignore($value)` accepts either a model or a key, so pass
`$this->route('<name>')` directly.

**Verify**: the param name is captured from the route list output (note it in the commit message).

### Step 3: Add the tenant-scoped unique rule with `->ignore`

Update the rule to:
```php
'name' => [
    'sometimes', 'required', 'string', 'max:255',
    Rule::unique('charge_concept_category', 'name')
        ->where('tenant_id', tenant('id'))
        ->whereNull('deleted_at')
        ->ignore($this->route('<param-from-step-2>')),
],
```

**Verify**: `php -l ...UpdateRequest.php` → `No syntax errors detected`.

### Step 4: Format + test

**Verify**: `cd backend && vendor/bin/pint ...` exit 0; `php artisan test --filter=ChargeConceptCategory` (or `--filter=Category`) all pass.

## Test plan

- Add a feature test (model after `backend/Modules/Collections/tests/Feature/`'s
  catalog/category tests if present):
  1. Update a category keeping its OWN name unchanged → expect `200` (the
     `->ignore()` must not flag the row against itself).
  2. Rename a category to a sibling category's name in the same tenant → expect `422`.
  3. (Optional, confirms tenant scoping) the same name existing in a DIFFERENT
     tenant must NOT block the rename → `200`.

## Done criteria

- [ ] `Rule` imported; Update `name` rule has the tenant-scoped `unique` + `->ignore`
- [ ] Test: same-name update → 200; same-tenant collision → 422
- [ ] `cd backend && php artisan test --filter=ChargeConceptCategory` (or fallback) passes
- [ ] Only the Update request (+ new test) modified
- [ ] `plans/README.md` row updated

## STOP conditions

- The route binding param name is ambiguous, or `->ignore($this->route(...))`
  causes the "same-name update → 200" test to instead return 422 (meaning the
  ignore target is wrong) — report with the `route:list` output rather than
  guessing alternative param names.
- The Update request excerpt does not match (drift) — compare and report.
- The DB does not actually have a `unique(tenant_id, name)` on
  `charge_concept_category` (it should, from the central tenant-scoping
  migration) — if a collision does NOT 500 today, re-confirm the premise before
  proceeding.

## Maintenance notes

- This is the standard Create/Update FormRequest parity gap. When auditing other
  tenant-scoped catalog Update requests in this module/codebase, check they carry
  the same `unique(...)->ignore(...)` rule.
