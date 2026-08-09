# Plan 034: Collections relation JSON keys are snake_case end-to-end (status pills, payer, method, bank render)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If
> anything in "STOP conditions" occurs, stop and report — do not improvise. When
> done, update the status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Collections`
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (touches the serialized API payload keys for charge/payment — but the app is already broken under the current camelCase keys, so the net change is broken → working)
- **Depends on**: none
- **Category**: bug
- **Planned at**: backend `fb2062b`, frontend `0887d5a`, 2026-06-20

## Why this matters

The Collections backend declares its `belongsTo` relations with **camelCase**
method names (`chargeStatus`, `payerUser`, `paymentMethod`, `bankAccount`,
`paymentStatus`, `amountMode`). Eloquent serializes a relation under the EXACT
method name, so the API returns `payment.paymentStatus`, `charge.chargeStatus`,
etc. But the frontend TypeScript interfaces and every component read those
relations in **snake_case** (`payment.payment_status`, `charge.charge_status`).
Result: the status pills, payer name, payment method and bank account columns
resolve to `undefined` and render `"—"`/blank across PaymentListPage,
TreasurerHome, ChargeListPage, ChargeBatchDetailPage, StatementPage and the
RegisterPayment FIFO preview. The include REQUESTS happen to use camelCase (so
the data IS returned and there is no 422) — it is purely a read-key mismatch.

The project convention (`laravel-backend-architecture` skill §2.4 + §3 rule 4) is that
`belongsTo`/single relations use **snake_case** method names so the JSON key
matches the column. The frontend already follows it. This plan brings the
backend into compliance, which simultaneously fixes the blank-UI bug.

## Current state

**Backend — relation methods are camelCase (must become snake_case):**

- `backend/Modules/Collections/app/Models/Charge.php:69`
  ```php
  public function chargeStatus(): BelongsTo
  {
      return $this->belongsTo(ChargeStatus::class, 'charge_status_id');
  }
  ```
- `backend/Modules/Collections/app/Models/Payment.php:61,67,72,77`
  ```php
  public function payerUser(): BelongsTo { ...->belongsTo(User::class, 'payer_user_id')->withoutGlobalScope(UserTenantScope::class); }
  public function paymentMethod(): BelongsTo { ...->belongsTo(PaymentMethod::class, 'payment_method_id'); }
  public function bankAccount(): BelongsTo { ...->belongsTo(BankAccount::class, 'bank_account_id'); }
  public function paymentStatus(): BelongsTo { ...->belongsTo(PaymentStatus::class, 'payment_status_id'); }
  ```
- `backend/Modules/Collections/app/Models/ChargeConcept.php:60` `public function amountMode(): BelongsTo`

**Backend — every eager-load / include / read of those relations:**

- `backend/Modules/Collections/app/Http/Controllers/ChargeController.php:30`
  `return ['concept', 'chargeStatus', 'batch', 'unit', 'paymentApplications'];`
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:27`
  `return ['unit', 'paymentStatus', 'paymentMethod', 'bankAccount', 'applications', 'payerUser', 'media'];`
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:267`
  `->with(['concept', 'chargeStatus', 'paymentApplications'])` (statement)
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php:309`
  `->with(['unit', 'chargeStatus'])` (delinquency)
- `backend/Modules/Collections/app/Http/Controllers/ChargeConceptController.php:17`
  `return ['category', 'amountMode', 'periodicity'];`
- `backend/Modules/Collections/app/Services/ChargeService.php:71` and `:129`
  `return $locked->refresh()->load('chargeStatus');`
- `backend/Modules/Collections/app/Services/CollectionsReportService.php:81`
  `->with(['unit', 'chargeStatus'])`
- `backend/Modules/Collections/app/Services/CollectionsReportService.php:155`
  `'label' => $charge->chargeStatus?->name ?? 'Desconocido',`
- `backend/Modules/Collections/app/Services/PaymentService.php:97,173,219,283,457`
  `->load(..., 'paymentStatus', 'paymentMethod')` / `->load('paymentStatus')`
- `backend/Modules/Collections/app/Jobs/GenerateReceiptPdfJob.php:42`
  `Payment::with(['unit', 'paymentMethod', 'applications.charge.concept'])`
- `backend/Modules/Collections/resources/views/pdf/receipt.blade.php:32`
  `{{ $payment->paymentMethod?->name ?? '—' }}`

**Frontend — include REQUESTS are camelCase (must become snake_case). The READS are
already snake_case and must NOT change:**

- `frontend/src/modules/charge/ChargeListPage.tsx:155` `{ relation: 'chargeStatus' }`
- `frontend/src/modules/chargeBatch/ChargeBatchDetailPage.tsx:53` `includes: [{ relation: 'unit' }, { relation: 'chargeStatus' }]`
- `frontend/src/modules/chargeConcept/ChargeConceptListPage.tsx:59` `{ relation: 'amountMode' }`
- `frontend/src/modules/payment/PaymentListPage.tsx:159-161` `{ relation: 'paymentStatus' }`, `{ relation: 'paymentMethod' }`, `{ relation: 'payerUser' }`
- `frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx:353` `{ relation: 'payerUser' }, { relation: 'paymentStatus' }`

Reads that confirm the target snake_case keys (do NOT touch — they become correct once the backend renames):
`frontend/src/modules/payment/schema.ts:40-43` (`payer_user`, `payment_method`, `payment_status`, `bank_account`), `frontend/src/modules/charge/schema.ts:48` (`charge_status`), and consumers in `PaymentListPage.tsx:287`, `TreasurerHome.tsx:87`, `ChargeListPage.tsx:316`, `ChargeBatchDetailPage.tsx:195`, `StatementPage.tsx:193`.

**Convention exemplar**: other relations in the same files already follow
snake_case where the name is single-word (`unit`, `concept`, `category`,
`periodicity`, `batch`) — match that. Collection relations stay plural
(`applications`, `paymentApplications`) and are OUT of scope (they already match
the frontend).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHP syntax | `php -l <file>` | `No syntax errors detected` |
| Backend format | `cd backend && vendor/bin/pint Modules/Collections` | exit 0 |
| Backend tests | `cd backend && php artisan test --filter=Payment && php artisan test --filter=Charge` | all pass |
| Frontend typecheck+build | `pnpm -C frontend build` | exit 0 |
| Frontend lint | `pnpm -C frontend lint` | no NEW errors vs baseline |

## Scope

**In scope (backend):**
- `backend/Modules/Collections/app/Models/{Charge,Payment,ChargeConcept}.php`
- `backend/Modules/Collections/app/Http/Controllers/{ChargeController,PaymentController,ChargeConceptController}.php`
- `backend/Modules/Collections/app/Services/{ChargeService,CollectionsReportService,PaymentService}.php`
- `backend/Modules/Collections/app/Jobs/GenerateReceiptPdfJob.php`
- `backend/Modules/Collections/resources/views/pdf/receipt.blade.php`
- `backend/Modules/Reports/app/Services/ReportService.php` — **added 2026-06-20 after the first execution attempt's Step 5 sweep.** It eager-loads and reads the renamed `Charge`/`Payment` relations (lines 352 `chargeStatus`, 358 `paymentStatus`/`paymentMethod`, 375 `$c->chargeStatus?->name`, 381 `$p->paymentMethod?->name`, 383 `$p->paymentStatus?->name`, 447 `paymentMethod`/`paymentStatus`, 455/457 reads). Confirmed broken by `ReportsCatalogTest`. The reports map relations into FLAT arrays (`'status'`, `'method'` keys), so NO frontend reports change is needed.

**In scope (frontend):**
- `frontend/src/modules/charge/ChargeListPage.tsx`
- `frontend/src/modules/chargeBatch/ChargeBatchDetailPage.tsx`
- `frontend/src/modules/chargeConcept/ChargeConceptListPage.tsx`
- `frontend/src/modules/payment/PaymentListPage.tsx`
- `frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx`
- Any additional consumer the Step 5 repo-wide grep surfaces (see STOP conditions).

**Explicitly a SEPARATE concern → plan 039 (do NOT touch here):** `Expense::paymentMethod()`/`bankAccount()` (Treasury), `CommunityProject::amountMode()` (Projects) and their controllers/frontends. These are the SAME anti-pattern on DIFFERENT models — renaming Collections' `Charge`/`Payment`/`ChargeConcept` relations does NOT break them, so they are handled in plan 039, not here.

**Out of scope (do NOT touch, even though the substring matches):**
- Service property/method names that merely contain the token, NOT relations:
  `$this->chargeStatusService`, `recomputeUnitAggregate`, `resolveStatusId`,
  `effectiveExpected`, `refreshAllOpenCharges`, `paymentMethodIsEnabled`,
  `requiresReceipt`, `$paymentMethodId`, `PaymentMethod::CASH`,
  `incrementBankBalance`/`decrementBankBalance`/`adjustBankBalance`,
  `$bankAccountId`, `BankAccount::where`, `amountModeIs`.
- Column names and filters: `payment_status_id`, `payment_method_id`,
  `bank_account_id`, `charge_status_id`, `filterableBy()` arrays — already snake_case, leave them.
- Frontend non-relation tokens: `chargeStatusTone(...)`, `chargeStatusId`,
  `resolveAmountFlags(amountModeId)`, the `bankAccounts` catalog variable,
  `ChargeBatchDetailPage.tsx:43`'s `{ include: 'concept,status' }`.
- The `applications` / `paymentApplications` collection relations (already plural and aligned).
- Frontend TS interfaces and component reads — they are already the correct snake_case target.

## Git workflow

- `backend/` and `frontend/` are git submodules; commit inside each submodule.
  Branch suggestion: `advisor/034-relation-key-drift` in each.
- Conventional commits, **no AI attribution / no Co-Authored-By** (repo rule).
- If a commit fails on signing/hooks, use `--no-gpg-sign --no-verify`.
- Do NOT push or open a PR unless the operator instructs it.

## Steps

### Step 1: Rename the backend relation methods to snake_case

In `Charge.php` rename `chargeStatus` → `charge_status`; in `Payment.php` rename
`payerUser`→`payer_user`, `paymentMethod`→`payment_method`, `bankAccount`→`bank_account`,
`paymentStatus`→`payment_status`; in `ChargeConcept.php` rename `amountMode`→`amount_mode`.
Keep the second `belongsTo` argument (the FK column string) and the
`withoutGlobalScope(UserTenantScope::class)` on `payer_user` exactly as-is.

**Verify**: `php -l backend/Modules/Collections/app/Models/Charge.php` (and Payment, ChargeConcept) → `No syntax errors detected`.

### Step 2: Update every backend eager-load / include / read

Edit each site listed in "Current state → Backend eager-loads": the `includes()`
arrays (ChargeController:30, PaymentController:27, ChargeConceptController:17),
the `->with([...])` calls (PaymentController:267,309; CollectionsReportService:81),
the `->load(...)` calls (ChargeService:71,129; PaymentService:97,173,219,283,457),
the read `$charge->chargeStatus?->name` (CollectionsReportService:155), the job
`Payment::with([...])` (GenerateReceiptPdfJob:42), and the blade
`$payment->paymentMethod?->name` (receipt.blade.php:32). Replace ONLY the relation
tokens with their snake_case form.

**Verify**:
`grep -rn "chargeStatus\|payerUser\|paymentMethod\b\|bankAccount\|paymentStatus\|amountMode" backend/Modules/Collections/app backend/Modules/Collections/resources`
→ the ONLY remaining matches are the out-of-scope items (`chargeStatusService`,
`paymentMethodIsEnabled`, `requiresReceipt`, `$paymentMethodId`, `PaymentMethod::CASH`,
`incrementBankBalance`/`decrementBankBalance`/`adjustBankBalance`, `$bankAccountId`,
`BankAccount::where`, `amountModeIs`). NO relation definition, `includes()`,
`->with()`, `->load()`, or `?->name` read should remain camelCase.

### Step 2b: Update the Reports consumer of Charge/Payment relations

In `backend/Modules/Reports/app/Services/ReportService.php`, rename the renamed
relations in every `->with([...])` and `?->name` read: `chargeStatus`→`charge_status`
(lines ~352, ~375), `paymentStatus`→`payment_status` (lines ~358, ~383, ~447, ~457),
`paymentMethod`→`payment_method` (lines ~358, ~381, ~447, ~455). Do NOT touch the
flat output array keys (`'status'`, `'method'`) — only the relation tokens.

**Verify**: `grep -rn "chargeStatus\|paymentStatus\|paymentMethod" backend/Modules/Reports/app/Services/ReportService.php` → zero matches.

### Step 3: Update the frontend include request strings to snake_case

Change the `{ relation: '...' }` strings in the 5 frontend files listed in
"Current state → Frontend include requests" to snake_case (`charge_status`,
`amount_mode`, `payment_status`, `payment_method`, `payer_user`). Do NOT touch any
read, interface, helper, or the `{ include: 'concept,status' }` string.

**Verify**:
`grep -rn "relation: '\(chargeStatus\|payerUser\|paymentMethod\|paymentStatus\|amountMode\)'" frontend/src`
→ zero matches.

### Step 4: Format, typecheck, test

**Verify**:
- `cd backend && vendor/bin/pint Modules/Collections` → exit 0
- `cd backend && php artisan test --filter=Payment && php artisan test --filter=Charge` → all pass
- `pnpm -C frontend build` → exit 0
- `pnpm -C frontend lint` → no NEW errors beyond the known pre-existing baseline

### Step 5: Repo-wide sweep for stragglers OUTSIDE Collections

Other modules (Reports, dashboards, unit detail) may eager-load or read these
relations. Find them:
- Backend: `grep -rn "chargeStatus\|payerUser\|paymentMethod\b\|bankAccount\|paymentStatus\|amountMode" backend/Modules backend/app | grep -iv "Service\|Isenabled\|requiresReceipt\|_id\|::CASH\|BankBalance\|BankAccount::\|amountModeIs"`
- Frontend: `grep -rn "\.paymentStatus\b\|\.payerUser\b\|\.paymentMethod\b\|\.bankAccount\b\|\.chargeStatus\b\|\.amountMode\b\|relation: '\(chargeStatus\|payerUser\|paymentMethod\|paymentStatus\|amountMode\|bankAccount\)'" frontend/src | grep -v "Tone\|StatusId\|resolveAmountFlags\|bankAccounts"`

If either sweep returns matches in files NOT in this plan's Scope, see STOP conditions.

**Verify**: both sweeps return only files already in scope (or nothing).

## Test plan

- This is a mechanical rename of serialization keys; rely on the existing
  Collections feature tests (`php artisan test --filter=Payment`,
  `--filter=Charge`) staying green.
- Add ONE assertion to the payment list/show test (model after the existing
  Payment feature test in `backend/Modules/Collections/tests/Feature/`): after
  loading a payment with its includes, assert the serialized array has key
  `payment_status` (not `paymentStatus`). If no such test exists, note it in the
  status row rather than inventing a new harness.
- Manual UI confirmation (the real proof): with the dev server running, open
  `/payment` and `/charge` and confirm status pills, payer name, method and bank
  columns render real values instead of `—`.

## Done criteria

ALL must hold:

- [ ] `grep -rn "function chargeStatus\|function payerUser\|function paymentMethod\|function bankAccount\|function paymentStatus\|function amountMode" backend/Modules/Collections/app/Models` → zero matches
- [ ] Step 2 and Step 3 greps return zero in-scope camelCase relation matches
- [ ] `cd backend && php artisan test --filter=Payment && php artisan test --filter=Charge` → all pass
- [ ] `pnpm -C frontend build` exits 0
- [ ] Step 5 repo-wide sweeps return only in-scope files (or were converted to follow-up findings, not silently left)
- [ ] No files outside the Scope list modified (`git -C backend status`, `git -C frontend status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report (do not improvise) if:

- The Step 5 sweep finds consumers in modules NOT listed in Scope (e.g. Reports
  or a dashboard reading `payment.paymentStatus`). Renaming the relation changes
  the key for THOSE too — list them and report so the scope can be widened
  deliberately rather than leaving a second broken surface.
- Any "Current state" excerpt does not match the live code (the codebase drifted).
- A backend test that was green before now fails after the rename (a hidden
  consumer relied on the camelCase key) — report which test and the relation.
- You find a frontend component that reads a relation as **camelCase**
  (`payment.paymentStatus`) — that would BREAK after this change; report it
  instead of guessing.

## Maintenance notes

- After this lands, the canonical rule is enforced: any NEW `belongsTo` relation
  in Collections must use a snake_case method name matching its column, and the
  matching frontend interface field + include request must use the same string.
- `applications` (Payment) and `paymentApplications` (Charge) were intentionally
  left as-is: they are collection relations, already plural, and already match
  the frontend. Do not "normalize" them in this plan.
- Reviewer should scrutinize that no service-internal identifier
  (`chargeStatusService`, `paymentMethodIsEnabled`, the `*BankBalance` helpers)
  was renamed by an over-eager find-replace.
- Plan 035 also edits `PaymentController::statement()`'s `->with()` array; if 035
  runs after this, that line already reads `charge_status`.
