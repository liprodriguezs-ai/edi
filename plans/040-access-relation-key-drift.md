# Plan 040: Access relation JSON keys snake_case end-to-end (fix blank "Tipo", "Vigilante", "Registró", blacklist "Tipo")

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Access` and
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Depends on**: none
- **Category**: bug
- **Planned at**: monorepo `b2c06dc`, backend submodule `fb2062b`, frontend submodule `0887d5a`, 2026-06-21

## Why this matters

This is the SAME systemic bug fixed for Collections in plan 034, now present in the
Access module (which plan 034 did NOT touch). Eloquent serializes a relation to a
JSON key EXACTLY equal to the PHP method name — there is NO camelCase↔snake_case
conversion. The Access models define `belongsTo` relations in camelCase
(`entryType`, `guardUser`, `createdByUser`, `blacklistType`, `qrType`), but the
frontend reads snake_case keys (`entry_type`, `guard_user`, `created_by_user`,
`blacklist_type`). Result: **four columns render blank ("—") in production today**:

- Bitácora de Accesos + Mi bitácora → "Tipo" (entry type) and "Vigilante" (guard) always "—".
- Visitantes list → "Registró: {name}" sub-line never shows.
- Lista Negra → "Tipo" column always "—".
- "Control de Accesos / dentro ahora" cards → "· {motivo}" never shows (separate cause: `currentlyInside()` doesn't eager-load `motive`).

The project convention (laravel-backend-architecture rule 4) is that single/`belongsTo` relations are
named in snake_case derived from their FK column. The fix renames every Access
`belongsTo` to that canonical form and aligns the `includes()` whitelists, all
`with()`/`load()` calls, and the frontend interfaces + reads. After this, the gate
and bitácora screens show their data and the module matches the codebase standard.

## Current state

### Backend relation methods (the method name IS the JSON key)

`backend/Modules/Access/app/Models/AccessLog.php`:
```php
public function visitor(): BelongsTo        // line 54 — OK (matches)
public function unit(): BelongsTo           // line 59 — OK
public function entryType(): BelongsTo       // line 64 — FK access_entry_type_id → should be access_entry_type()
public function verification(): BelongsTo     // line 69 — FK access_verification_id → should be access_verification()
public function motive(): BelongsTo          // line 74 — FK visitor_motive_id → should be visitor_motive()
public function guardUser(): BelongsTo        // line 79 — FK guard_user_id → should be guard_user()  (keeps ->withoutGlobalScope(UserTenantScope::class))
```

`backend/Modules/Access/app/Models/Visitor.php`:
```php
public function unit(): BelongsTo            // line 52 — OK
public function createdByUser(): BelongsTo    // line 57 — FK created_by_user_id → created_by_user()  (keeps ->withoutGlobalScope)
public function motive(): BelongsTo          // line 63 — → visitor_motive()
public function qrType(): BelongsTo          // line 68 — FK qr_type_id → qr_type()
public function status(): BelongsTo          // line 73 — FK visitor_status_id → visitor_status()
public function accessLogs(): HasMany        // line 78 — collection → access_logs()
```

`backend/Modules/Access/app/Models/BlacklistEntry.php`:
```php
public function blacklistType(): BelongsTo    // line 34 — FK blacklist_type_id → blacklist_type()
```

### Backend controllers — `includes()` whitelists (must match new method names)

- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php:21`
  `return ['visitor', 'unit', 'entryType', 'verification', 'guardUser', 'motive'];`
- `backend/Modules/Access/app/Http/Controllers/VisitorController.php:24`
  `return ['unit', 'motive', 'qrType', 'status', 'createdByUser', 'accessLogs'];`
- `backend/Modules/Access/app/Http/Controllers/BlacklistEntryController.php:16`
  `return ['blacklistType'];`

### Backend service/controller eager-loads (every `with(...)` / `load(...)` / `fresh(...)`)

`backend/Modules/Access/app/Services/AccessService.php`:
- line 81 `$visitor->load(['unit', 'status', 'qrType']);`
- line 145 `'visitor' => $visitor->fresh(['unit', 'status', 'motive']),`
- line 574 `->with(['visitor', 'unit', 'entryType'])` in `currentlyInside()` — **also ADD `'motive'`** (fixes blank motivo in "dentro ahora" cards)

`backend/Modules/Access/app/Http/Controllers/AccessLogController.php`:
- line 86 `->load(['unit', 'entryType', 'verification', 'motive'])`
- line 121 `->load(['unit', 'motive', 'status'])`
- line 146 `->with(['visitor', 'unit', 'entryType', 'verification', 'motive'])`

`backend/Modules/Access/app/Http/Controllers/VisitorController.php`:
- line 112 `->with(['unit', 'motive', 'status', 'accessLogs'])` in `mine()`
- line 134 `$visitor->fresh(['status'])` and line 151 `$visitor->fresh(['status'])` → `'visitor_status'`

### Frontend interfaces + reads

`frontend/src/modules/access/schema.ts`:
- `IVisitor` (lines 20–35): `motive`, `status`, `created_by_user` → must become `visitor_motive`, `visitor_status`, `created_by_user` stays the read but backend currently sends `createdByUser`. Target snake_case keys: `visitor_motive`, `visitor_status`, `created_by_user`, `qr_type` (add), `access_logs` (add per plan 047/CONV-04).
- `IAccessLog` (lines 37–53): `entry_type`, `verification`, `guard_user`, `motive` → target `access_entry_type`, `access_verification`, `guard_user`, `visitor_motive`.
- `IBlacklistEntry` (line 69): `blacklist_type` (already snake) — backend must send `blacklist_type`.

Frontend read sites (grep results to fix):
- `AccessLogListPage.tsx`: `log.entry_type?.name` (lines 113, 128), `log.guard_user?.name` (lines 116, 131); include strings lines 61–63 `{ relation: 'entryType' }`, `'guardUser'`, `'motive'`.
- `MyAccessLogPage.tsx`: `log.entry_type?.name`, reads `motive` — verify all reads.
- `AccessNowPage.tsx`: `log.motive?.name` (line 99).
- `VisitorListPage.tsx`: `visitor.created_by_user?.name` (lines 223, 225), `visitor.motive?.name` (lines 230, 252), `visitor.status?.name` (lines 234, 255); include strings lines 128–130 `{ relation: 'motive' }`, `'status'`, `'createdByUser'`.
- `VisitorQrModal.tsx`: `visitor.motive?.name` (line 42).
- `BlacklistListPage.tsx`: `entry.blacklist_type?.name` (line ~35) and include `{ relation: 'blacklistType' }` (line ~46).

> **Convention exemplar**: plan 034 did exactly this rename for Collections.
> See `backend/Modules/Collections/app/Models/Payment.php` for the canonical
> snake_case `belongsTo` naming (`charge_status()`, `payment_status()`, etc.) and
> how `includes()` + frontend interfaces mirror it.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Backend tests (Access) | `cd backend && php artisan test --filter=Access` | all pass (or "no tests" — Access has none yet) |
| Backend lint | `cd backend && vendor/bin/pint Modules/Access` | exit 0 |
| Frontend typecheck | `cd frontend && pnpm build` (runs `tsc -b`) | exit 0, no errors |
| Frontend lint | `cd frontend && pnpm lint` | no NEW errors vs baseline |
| Frontend access tests | `cd frontend && pnpm test src/modules/access` | all pass |
| Grep camelCase relics | see Done criteria | no matches |

## Scope

**In scope (backend)**:
- `backend/Modules/Access/app/Models/AccessLog.php`
- `backend/Modules/Access/app/Models/Visitor.php`
- `backend/Modules/Access/app/Models/BlacklistEntry.php`
- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php`
- `backend/Modules/Access/app/Http/Controllers/VisitorController.php`
- `backend/Modules/Access/app/Http/Controllers/BlacklistEntryController.php`
- `backend/Modules/Access/app/Services/AccessService.php`

**In scope (frontend)**:
- `frontend/src/modules/access/schema.ts`
- `frontend/src/modules/access/AccessLogListPage.tsx`
- `frontend/src/modules/access/MyAccessLogPage.tsx`
- `frontend/src/modules/access/AccessNowPage.tsx`
- `frontend/src/modules/access/VisitorListPage.tsx`
- `frontend/src/modules/access/components/VisitorQrModal.tsx`
- `frontend/src/modules/access/BlacklistListPage.tsx`

**Out of scope**:
- Any other module's relations — this plan is Access only.
- The `unit` and `visitor` relations (already correct snake_case).
- Permission/auth logic, business rules — not touched here.
- Migrations — relation method renames do not touch DB columns.

## Git workflow

- Branch: `advisor/040-access-relation-keys`. Commits land in the respective submodule.
- Conventional commit, NO AI attribution / NO Co-Authored-By (repo rule).
- The pre-commit hook may need `--no-verify`; commits sign via 1Password (use `--no-gpg-sign --no-verify` if it fails in a headless context).
- Do NOT push or open a PR unless the operator instructs it.

## Steps

### Step 1: Rename backend relation methods to FK-derived snake_case

In the three models, rename the methods listed in "Current state" (keep the SAME
`belongsTo`/`hasMany` body, FK argument, and any `->withoutGlobalScope(...)` chain;
only the method name changes):

- `AccessLog`: `entryType`→`access_entry_type`, `verification`→`access_verification`, `motive`→`visitor_motive`, `guardUser`→`guard_user`.
- `Visitor`: `createdByUser`→`created_by_user`, `motive`→`visitor_motive`, `qrType`→`qr_type`, `status`→`visitor_status`, `accessLogs`→`access_logs`.
- `BlacklistEntry`: `blacklistType`→`blacklist_type`.

**Verify**: `cd backend && vendor/bin/pint Modules/Access` → exit 0. Then
`grep -rnE "function (entryType|verification|guardUser|createdByUser|qrType|accessLogs|blacklistType)\(" Modules/Access/app/Models` → **no matches** (the only allowed `verification`/`motive`/`status` short names are gone).

### Step 2: Update every backend reference to the renamed methods

Update `includes()` whitelists (3 controllers) and every `with()`/`load()`/`fresh()`/`whereDoesntHave()` to the new names. Specifically:

- `AccessLogController::includes()` → `['visitor', 'unit', 'access_entry_type', 'access_verification', 'guard_user', 'visitor_motive']`
- `VisitorController::includes()` → `['unit', 'visitor_motive', 'qr_type', 'visitor_status', 'created_by_user', 'access_logs']`
- `BlacklistEntryController::includes()` → `['blacklist_type']`
- `AccessService.php`: line 81 `['unit', 'visitor_status', 'qr_type']`; line 145 `['unit', 'visitor_status', 'visitor_motive']`; line 574 `['visitor', 'unit', 'access_entry_type', 'visitor_motive']` (note **added** `visitor_motive`); line 594 `whereDoesntHave('accessLogs')` → `whereDoesntHave('access_logs')`.
- `AccessLogController.php`: line 86 `['unit', 'access_entry_type', 'access_verification', 'visitor_motive']`; line 121 `['unit', 'visitor_motive', 'visitor_status']`; line 146 `['visitor', 'unit', 'access_entry_type', 'access_verification', 'visitor_motive']`.
- `VisitorController.php`: line 112 `['unit', 'visitor_motive', 'visitor_status', 'access_logs']`; lines 134 & 151 `fresh(['visitor_status'])`.

**Verify**: `cd backend && grep -rnE "'(entryType|guardUser|createdByUser|qrType|accessLogs|blacklistType)'|\\b(entryType|guardUser|createdByUser|qrType|accessLogs|blacklistType)\b" Modules/Access/app` → **no matches**. Also `grep -rnE "(load|with|fresh)\(\[[^]]*'(motive|status|verification)'" Modules/Access/app` → no matches (all short forms upgraded).

### Step 3: Update frontend interfaces in `schema.ts`

- `IVisitor`: rename `motive?` → `visitor_motive?`, `status?` → `visitor_status?`; keep `created_by_user?`; ADD `qr_type?: INamed | null` and `access_logs?: IAccessLog[] | null`.
- `IAccessLog`: rename `entry_type?` → `access_entry_type?`, `verification?` → `access_verification?`, `motive?` → `visitor_motive?`; keep `guard_user?`.
- `IBlacklistEntry`: keep `blacklist_type?` (now backend matches).

**Verify**: `cd frontend && grep -nE "\b(entry_type|verification\?|\bmotive\?|\bstatus\?)\b" src/modules/access/schema.ts` → only the intended `access_*`/`visitor_*` names remain.

### Step 4: Update every frontend read site + include request string

For each file in the frontend scope, replace reads and `{ relation: '...' }` include
strings with the new snake_case keys (see "Current state" read sites). Examples:
`log.entry_type` → `log.access_entry_type`; `log.motive` → `log.visitor_motive`;
`visitor.motive` → `visitor.visitor_motive`; `visitor.status` → `visitor.visitor_status`;
`{ relation: 'entryType' }` → `{ relation: 'access_entry_type' }`;
`{ relation: 'createdByUser' }` → `{ relation: 'created_by_user' }`;
`{ relation: 'blacklistType' }` → `{ relation: 'blacklist_type' }`.
`created_by_user` and `guard_user` keep their read name but now resolve (backend fixed).

**Verify**: `cd frontend && pnpm build` → exit 0. Then
`grep -rnE "\.(entry_type|\bmotive\b|\bstatus\b)\??\.|relation: '(entryType|guardUser|createdByUser|qrType|blacklistType|motive|status)'" src/modules/access` → no matches.

## Test plan

- Update `frontend/src/modules/access/schema.test.ts` only if it asserts the renamed interface keys (it tests Zod form schemas, which are unaffected — likely no change).
- Add a small assertion-level check is optional; the real verification is the build + grep gates.
- Manual smoke (if a dev environment is available): open Bitácora de Accesos → "Tipo" and "Vigilante" columns show values; Visitantes → "Registró:" shows; Lista Negra → "Tipo" shows; Control de Accesos → card shows "· {motivo}".

## Done criteria

ALL must hold:

- [ ] `cd backend && vendor/bin/pint Modules/Access` exits 0
- [ ] `cd frontend && pnpm build` exits 0
- [ ] `cd backend && grep -rnE "entryType|guardUser|createdByUser|qrType|accessLogs|blacklistType" Modules/Access/app` returns no matches
- [ ] `cd frontend && grep -rnE "entryType|guardUser|createdByUser|qrType|blacklistType|relation: '(motive|status)'" src/modules/access` returns no matches
- [ ] `currentlyInside()` `with([...])` includes `visitor_motive`
- [ ] No files outside the in-scope list modified (`git status` in both submodules)
- [ ] `plans/README.md` status row updated

## STOP conditions

- A `belongsTo` you are about to rename is referenced by ANOTHER module outside
  Access (run `grep -rn "->motive\|->status\|->qrType" backend/Modules` excluding
  Access before finalizing). Access relations are module-private, but if a cross-module
  consumer exists, STOP and report — renaming would break it.
- The drift check shows Access files changed since `fb2062b`/`0887d5a` and the
  excerpts no longer match.
- `pnpm build` fails with errors unrelated to this rename (pre-existing) — report
  rather than fixing out-of-scope code.

## Maintenance notes

- Future Access relations MUST be named in FK-derived snake_case from day one.
- A reviewer should confirm `->withoutGlobalScope(UserTenantScope::class)` is preserved
  on `guard_user()` and `created_by_user()` (these point at the central `User`).
- This closes the Access half of the same drift class plan 034 fixed for Collections;
  the repo-wide sweep in plan 034's STOP step should now find Access clean too.
