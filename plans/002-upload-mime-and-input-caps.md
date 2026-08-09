# Plan 002: Restrict upload MIME types and cap free-text inputs

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If a
> "STOP condition" occurs, stop and report — do not improvise. When done,
> update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- Modules/Collections/app/Http/Controllers/PaymentController.php Modules/Configuration/app/Http/Controllers/ConfigurationController.php`
> If either file changed since this plan was written, compare the "Current
> state" excerpts to the live code first; mismatch = STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

Two file-upload endpoints accept **any MIME type**. The payment-receipt upload
(`receipt`) and the legal-document upload validate only `['file', 'max:...]`,
so a resident or admin can upload a `.php`, `.svg`, or `.html` file disguised as
a comprobante. Stored in Spatie Media and later served or opened in a browser,
an SVG/HTML payload becomes stored XSS; a `.php` becomes an execution vector if
the storage path is ever web-served. Separately, the payment `notes` field is an
unbounded `string` (no `max:`), which is stored and read back into a push
notification — an oversized value is a cheap storage/abuse vector. The repo
already has the correct pattern: `BoardPeriodController.php:121` uses
`mimetypes:application/pdf`; `UserController.php:349` uses `mimes:pdf,jpeg,png`.
This plan brings the three weak rules up to that standard.

## Current state

- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php`:
  - `register()` validation (~line 60-64):
    ```php
    'physical_receipt_number' => ['nullable', 'string', 'max:100'],
    'notes' => ['nullable', 'string'],
    'receipt' => ['nullable', 'file', 'max:10240'],
    ```
  - `submit()` validation (~line 118-121):
    ```php
    'reference' => ['nullable', 'string', 'max:255'],
    'notes' => ['nullable', 'string'],
    'receipt' => ['nullable', 'file', 'max:10240'],
    ```
- `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php`:
  - legal-document upload (~line 600) — a `'file'` rule with no MIME restriction. Read the method around that line to find the exact field name (it is the legal/constitutive document upload).

**Exemplar to match** (`backend/Modules/Board/app/Http/Controllers/BoardPeriodController.php:121`):
```php
'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:...'],
```

## Commands you will need

| Purpose   | Command (from `backend/`)         | Expected           |
|-----------|-----------------------------------|--------------------|
| Lint      | `php -l <file>`                    | `No syntax errors` |
| Format    | `vendor/bin/pint --dirty`         | exit 0             |
| Tests     | `php artisan test tests/Feature/Collections tests/Feature/Configuration` | all pass |

## Scope

**In scope**:
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php`
- `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php`
- `backend/tests/Feature/Collections/` — extend or add a test (see Test plan)

**Out of scope**:
- The Spatie Media collection config / storage disks — only the validation rules change here.
- Any change to which methods accept uploads, or to the success response.

## Git workflow

- Branch: `advisor/002-upload-mime-and-input-caps`
- Conventional commit: `fix(security): restrict upload MIME types and cap notes length`
- Do not push/PR unless instructed. `backend/` is a submodule — commit inside it.

## Steps

### Step 1: Restrict the `receipt` MIME types in both payment endpoints

In `register()` and `submit()`, change the `receipt` rule to allow only the
formats a payment receipt can legitimately be:

```php
'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
```

### Step 2: Cap the `notes` field in both payment endpoints

Change both `notes` rules:

```php
'notes' => ['nullable', 'string', 'max:2000'],
```

### Step 3: Restrict the legal-document upload MIME type

In `ConfigurationController.php` around line 600, add a MIME restriction to the
document-upload rule. Legal documents are PDFs (and possibly images):

```php
'<field>' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
```

Keep the existing field name and `max:` if one is already present; only add the
`mimes:` token. If the existing `max:` differs, keep the existing value.

**Verify** (after steps 1–3):
- `php -l Modules/Collections/app/Http/Controllers/PaymentController.php` → `No syntax errors`
- `php -l Modules/Configuration/app/Http/Controllers/ConfigurationController.php` → `No syntax errors`
- `grep -c "mimes:pdf" Modules/Collections/app/Http/Controllers/PaymentController.php` → `2`

### Step 4: Format

`vendor/bin/pint --dirty` → exit 0.

### Step 5: Test (see Test plan).

## Test plan

Add to an existing Collections test file (the receipt upload is exercised in the
payment tests) or create `backend/tests/Feature/Collections/PaymentUploadValidationTest.php`,
modeled on the tenant+token setup in `CollectionsModuleTest.php`. Use Laravel's
`Illuminate\Http\UploadedFile::fake()`:

Cases:
- **Valid PDF receipt → accepted** (`UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')`).
- **`.php` receipt → 422** (`UploadedFile::fake()->create('r.php', 10, 'application/x-php')`), asserting a validation error on `receipt`.
- **`notes` longer than 2000 chars → 422** on `register()`.

**Verify**: `php artisan test --filter PaymentUploadValidationTest` → all pass.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `grep -rn "'receipt' =>" Modules/Collections/app/Http/Controllers/PaymentController.php` — every match includes `mimes:`.
- [ ] `grep -rn "'notes' =>" Modules/Collections/app/Http/Controllers/PaymentController.php` — every match includes `max:`.
- [ ] The legal-document rule in `ConfigurationController.php` includes `mimes:`.
- [ ] `php artisan test tests/Feature/Collections tests/Feature/Configuration` exits 0.
- [ ] New upload-validation test passes.
- [ ] `vendor/bin/pint --test` clean on in-scope files.
- [ ] No out-of-scope files modified (`git status`).
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match live code (drift).
- The legal-document upload around `ConfigurationController.php:600` turns out to
  legitimately accept formats beyond pdf/images (e.g. Word docs the product
  requires) — report and ask before narrowing.
- An existing test breaks because a fixture uploads a now-disallowed type —
  report it (the fixture should be updated to a valid type, but confirm intent).

## Maintenance notes

- If the product later needs to accept additional receipt formats (e.g. HEIC
  from iPhones), widen the `mimes:` list rather than dropping the restriction.
- A reviewer should confirm `mimes:` (extension+content sniff) is used, not just
  a filename-extension check, and that the fake-file test asserts the rejection,
  not just a non-200.
