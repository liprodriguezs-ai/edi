# Plan 039: Treasury `Expense` and Projects `CommunityProject` relation JSON keys are snake_case

> **Executor instructions**: Follow step by step, run every verification command,
> honor STOP conditions, update this plan's row in `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Treasury Modules/Projects`
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/expense src/modules/communityProject`
> On any mismatch with the "Current state" excerpts, treat as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (parallel to 034 — different models/modules)
- **Category**: bug
- **Planned at**: backend `fb2062b`, frontend `0887d5a`, 2026-06-20

## Why this matters

The same camelCase-relation anti-pattern that plan 034 fixes in Collections also
exists on two other models: `Treasury\Expense` (`paymentMethod()`, `bankAccount()`)
and `Projects\CommunityProject` (`amountMode()`). The Expense one is a confirmed
user-visible bug: `ExpenseListPage` reads `expense.bank_account?.name`
(snake_case) but the backend serializes the relation as `bankAccount` (camelCase),
so the "Cuenta" column shows `—` on every expense row (list view at line 148 and
the mobile card at line 208). Renaming the relations to snake_case fixes the bug
and brings both models into line with the `laravel-backend-architecture` §3 rule 4 convention.

This was split out of plan 034 because these are independent relations on
different models — renaming Collections' `Charge`/`Payment`/`ChargeConcept`
relations does not touch them.

## Current state

**Treasury — Expense (`backend/Modules/Treasury/app/Models/Expense.php:84,89`):**
```php
public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class, 'payment_method_id'); }
public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'bank_account_id'); }
```
- `backend/Modules/Treasury/app/Http/Controllers/ExpenseController.php:27-28` — `'paymentMethod'`, `'bankAccount'` in the `includes()` array.
- `frontend/src/modules/expense/ExpenseListPage.tsx:78` — `{ relation: 'bankAccount' }` (the include REQUEST).
- `frontend/src/modules/expense/ExpenseListPage.tsx:148,208` — READS `expense.bank_account?.name` (already snake_case — the target; do NOT change these).

**Projects — CommunityProject (`backend/Modules/Projects/app/Models/CommunityProject.php:82`):**
```php
public function amountMode(): BelongsTo { ... }
```
- `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php:33` — `'amountMode'` in `includes()`.
- The frontend `communityProject` module only uses the `amount_mode_id` COLUMN
  (`CommunityProjectListPage.tsx:133`, `schema.ts:43`); a repo grep found NO
  frontend read of the `amountMode`/`amount_mode` RELATION. So renaming
  `CommunityProject::amountMode` → `amount_mode` is convention cleanup with no
  current frontend consumer — but confirm with the Step 4 grep before assuming.

**Convention exemplar**: sibling relations on these same models already use
snake_case-or-single-word names (`Expense::provider()`, `Expense::status()`).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHP syntax | `php -l <file>` | `No syntax errors detected` |
| Backend format | `cd backend && vendor/bin/pint Modules/Treasury Modules/Projects` | exit 0 |
| Backend tests | `cd backend && php artisan test --filter=Expense && php artisan test --filter=CommunityProject` | all pass |
| Frontend build | `pnpm -C frontend build` | exit 0 |

## Scope

**In scope:**
- `backend/Modules/Treasury/app/Models/Expense.php`
- `backend/Modules/Treasury/app/Http/Controllers/ExpenseController.php`
- `backend/Modules/Projects/app/Models/CommunityProject.php`
- `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php`
- `frontend/src/modules/expense/ExpenseListPage.tsx` (the `{ relation: 'bankAccount' }` string ONLY)
- `backend/Modules/Treasury/app/Services/ExpenseService.php` — **added 2026-06-21 after the first execution attempt's STOP**: it eager-loads the renamed relation at line 119 (`->load('status', 'bankAccount', 'category', 'beneficiaryType')`, register/create flow) and line 229 (`->load('status', 'bankAccount')`, void flow). Both throw `RelationNotFoundException` after the model rename. Rename `'bankAccount'`→`'bank_account'` on those two lines ONLY. Do NOT touch `$bankAccountService` (line 75), `adjustBankBalance`/`$bankAccountId` (line 270), or `BankAccount::where` (line 272) — those are not relations.
- Any additional consumer the Step 4 sweep surfaces (see STOP conditions).

**Out of scope:**
- Plan 034's Collections files (handled separately).
- Service properties/locals that merely contain the substring (e.g. `$bankAccountId`, `$paymentMethodId`, `*_id` columns, `paymentMethodIsEnabled`).
- Frontend READS `expense.bank_account?.name` — already the correct snake_case target.

## Git workflow

Submodules `backend/` and `frontend/`. Branch `advisor/039-expense-project-relation-keys`. Conventional commit, no AI attribution. `--no-gpg-sign --no-verify` if signing/hooks fail. Do NOT push or merge unless instructed.

## Steps

### Step 1: Rename Expense relations + its include whitelist

In `Expense.php` rename `paymentMethod`→`payment_method`, `bankAccount`→`bank_account`
(keep the FK-column second argument). In `ExpenseController.php:27-28` change the
`includes()` strings to `'payment_method'`, `'bank_account'`.

**Verify**: `php -l backend/Modules/Treasury/app/Models/Expense.php` → no errors;
`grep -n "paymentMethod\|bankAccount" backend/Modules/Treasury/app/Models/Expense.php backend/Modules/Treasury/app/Http/Controllers/ExpenseController.php` → zero matches.

### Step 2: Rename CommunityProject relation + its include whitelist

In `CommunityProject.php:82` rename `amountMode`→`amount_mode`; in
`CommunityProjectController.php:33` change `'amountMode'`→`'amount_mode'`.

**Verify**: `grep -n "amountMode" backend/Modules/Projects/app/Models/CommunityProject.php backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php` → zero matches.

### Step 3: Update the Expense frontend include request

In `ExpenseListPage.tsx:78` change `{ relation: 'bankAccount' }` → `{ relation: 'bank_account' }`.
Do NOT touch the `expense.bank_account?.name` reads.

**Verify**: `grep -n "relation: 'bankAccount'" frontend/src/modules/expense/ExpenseListPage.tsx` → zero matches.

### Step 4: Repo-wide sweep, format, build, test

Confirm no other consumer of these specific relations remains:
- `grep -rn "->paymentMethod\b\|'paymentMethod'\|->bankAccount\b\|'bankAccount'\|->amountMode\b\|'amountMode'" backend/Modules backend/app | grep -v "Collections"`
- `grep -rn "relation: '\(bankAccount\|paymentMethod\|amountMode\)'\|\.bankAccount\b\|\.amountMode\b" frontend/src | grep -viE "bankAccounts\b|amountModeId\|resolveAmountFlags"`

**Verify**:
- both sweeps return only files already in scope (or nothing);
- `cd backend && vendor/bin/pint Modules/Treasury Modules/Projects` → exit 0;
- `cd backend && php artisan test --filter=Expense && php artisan test --filter=CommunityProject` → all pass;
- `pnpm -C frontend build` → exit 0.

## Test plan

- Rely on existing Expense / CommunityProject feature tests staying green.
- Manual: open the expenses list (`/expense`) → the "Cuenta" column shows the bank
  account name instead of `—`.

## Done criteria

- [ ] `grep -rn "function paymentMethod\|function bankAccount" backend/Modules/Treasury/app/Models/Expense.php` → zero; `function amountMode` in CommunityProject → zero
- [ ] Expense include whitelist + frontend include request use `bank_account`/`payment_method`
- [ ] `cd backend && php artisan test --filter=Expense && php artisan test --filter=CommunityProject` → all pass
- [ ] `pnpm -C frontend build` exits 0
- [ ] Step 4 sweeps return only in-scope files
- [ ] No files outside Scope modified
- [ ] `plans/README.md` row updated

## STOP conditions

- The Step 4 sweep finds a consumer in a module NOT in Scope — list it and report.
- A "Current state" excerpt does not match the live code (drift).
- A previously-green Expense/CommunityProject test fails after the rename — report which and the relation.
- You find a frontend component reading `expense.bankAccount` (camelCase) — that would break after the rename; report instead of guessing.

## Maintenance notes

- After this lands, all three modules (Collections via 034, Treasury, Projects)
  follow the snake_case belongsTo convention. Any new single relation must match.
- `CommunityProject::amount_mode` has no current frontend consumer; if a future
  Projects screen needs the amount-mode label, it must request
  `{ relation: 'amount_mode' }` and read `project.amount_mode?.name`.
