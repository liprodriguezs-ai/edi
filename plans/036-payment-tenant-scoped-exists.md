# Plan 036: Payment register/submit reject cross-tenant `unit_id` / `bank_account_id`

> **Executor instructions**: Follow step by step, run every verification command,
> honor STOP conditions, update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Collections/app/Http/Controllers/PaymentController.php`
> On any mismatch with the "Current state" excerpts, treat as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security (tenant data-integrity)
- **Planned at**: backend `fb2062b`, 2026-06-20

## Why this matters

`POST /payment/register` and `POST /payment/submit` validate `unit_id` and
`bank_account_id` as bare `['required', 'uuid']` with no tenant-scoped existence
check. `PaymentService` then stamps the current `tenant_id` onto the new payment
but never verifies that the referenced unit / bank account actually belong to
that tenant. A treasurer/admin (or a crafted resident submit) can therefore
create a payment in their OWN tenant that references ANOTHER tenant's `unit` or
`bank_account` UUID — a cross-tenant FK reference that corrupts ledger integrity.
It is not a data-leak (no foreign data is returned), which is why it is P1
integrity rather than a CRITICAL breach, but it must be closed. The sibling
`ChargeCreateRequest` already does exactly the right thing.

## Current state

- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:53-68` (`register()`):
  ```php
  $validated = $request->validate([
      'unit_id' => ['required', 'uuid'],
      'paid_at' => ['required', 'date'],
      'total_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
      'payment_method_id' => ['required', 'uuid'],
      'bank_account_id' => ['required', 'uuid'],
      // ...
  ```
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:112-122` (`submit()`):
  ```php
  $validated = $request->validate([
      'unit_id' => ['required', 'uuid'],
      'paid_at' => ['required', 'date'],
      'total_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
      'payment_method_id' => ['required', 'uuid'],
      'bank_account_id' => ['required', 'uuid'],
      // ...
  ```
- `Illuminate\Validation\Rule` is **NOT currently imported** in `PaymentController.php` — the executor must add `use Illuminate\Validation\Rule;`.

**Exemplar to copy (the correct pattern, same module):**
`backend/Modules/Collections/app/Http/Requests/ChargeCreateRequest.php:23`
```php
'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
```
`bank_account` is tenant-scoped (`Modules/Treasury/app/Models/BankAccount.php` uses `BelongsToTenant`), so the same `->where('tenant_id', tenant('id'))->whereNull('deleted_at')` filter applies to `bank_account_id` against the `bank_account` table.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHP syntax | `php -l backend/Modules/Collections/app/Http/Controllers/PaymentController.php` | `No syntax errors detected` |
| Backend tests | `cd backend && php artisan test --filter=Payment` | all pass |
| Backend format | `cd backend && vendor/bin/pint Modules/Collections/app/Http/Controllers/PaymentController.php` | exit 0 |

## Scope

**In scope:** `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` — the `use` block plus the two `$request->validate([...])` arrays in `register()` and `submit()`.

**Out of scope:** `PaymentService` (the validation belongs at the request boundary, not the service); `payer_user_id` (it points at the CENTRAL `User` table and is intentionally not tenant-scoped — leave it `['nullable', 'uuid']`); any other endpoint.

## Git workflow

Submodule `backend/`. Branch `advisor/036-payment-tenant-exists`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail.

## Steps

### Step 1: Import `Rule`

Add `use Illuminate\Validation\Rule;` to the `use` block of `PaymentController.php` (only if not already present).

**Verify**: `grep -n "use Illuminate\\\\Validation\\\\Rule;" backend/Modules/Collections/app/Http/Controllers/PaymentController.php` → one match.

### Step 2: Tenant-scope `unit_id` and `bank_account_id` in `register()` and `submit()`

In BOTH `$request->validate([...])` blocks, append the tenant-scoped `exists` to
`unit_id` and `bank_account_id`:
```php
'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
'bank_account_id' => ['required', 'uuid', Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
```
Leave every other rule (including `payer_user_id`) unchanged.

**Verify**: `grep -c "Rule::exists('unit', 'id')->where('tenant_id'" backend/Modules/Collections/app/Http/Controllers/PaymentController.php` → at least `2`; same for `bank_account`.

### Step 3: Format + test

**Verify**: `cd backend && vendor/bin/pint ...` exit 0; `php artisan test --filter=Payment` all pass.

## Test plan

- Add a feature test (model after `backend/Modules/Collections/tests/Feature/`'s
  existing payment-register test, and after plan 021's cross-tenant pattern):
  acting as tenant A, `POST /payment/register` with a `unit_id` that belongs to
  tenant B → expect `422` (validation), NOT `201`. Repeat for `bank_account_id`.
- Confirm the happy path (own-tenant unit + bank) still returns `201`.

## Done criteria

- [ ] `Rule` imported; both validate blocks tenant-scope `unit_id` and `bank_account_id`
- [ ] New test: cross-tenant `unit_id`/`bank_account_id` → 422; same-tenant → 201
- [ ] `cd backend && php artisan test --filter=Payment` passes
- [ ] Only `PaymentController.php` (+ the new test file) modified
- [ ] `plans/README.md` row updated

## STOP conditions

- A validate-block excerpt does not match (drift) — compare and report.
- `bank_account` is not actually tenant-scoped in this codebase (verify
  `grep -rn "BelongsToTenant" backend/Modules/Treasury/app/Models/BankAccount.php`
  returns a match before relying on the tenant filter) — if it does NOT, report
  instead of adding a filter that would reject everything.

## Maintenance notes

- This mirrors `ChargeCreateRequest`'s pattern. If payment capture ever moves
  from inline controller validation into a dedicated `PaymentRegisterRequest`
  FormRequest, carry these tenant-scoped rules over.
- `payer_user_id` stays un-scoped by design (central User). Do not "fix" it.
