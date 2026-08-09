# Plan 046: Access backend hardening batch (immutability guards, audit FK, fillable, prune)

> **Executor instructions**: Each sub-item is independent; apply all, verify
> after each. Honor STOP conditions. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat fb2062b..HEAD -- Modules/Access`

## Status

- **Priority**: P3
- **Effort**: S
- **Depends on**: none (independent of 040–045; can run anytime)
- **Category**: bug / tech-debt (correctness hardening)
- **Planned at**: backend submodule `fb2062b`, 2026-06-21

## Why this matters

A cluster of small backend correctness gaps in the Access service/controllers. None is
a showstopper, but each is a real defect with a one-line fix, and they protect the
immutable-log and audit guarantees the module is built on.

1. **`checkout`/`addCorrection` can corrupt a DENIED row**: `AccessLogController::checkout`
   (line 162) guards only `exited_at`, not `denied_at`. Calling checkout on a blacklist-
   denied log stamps an `exited_at` on a row that never had `entered_at` → a row with
   both `denied_at` and `exited_at`. Same for `addCorrection` (no entry-state guard).
2. **`approveEntry` discards the approver FK**: `AccessService::approveEntry` (line 321)
   stamps `'guard_user_id' => null`, storing the approver only as a string in
   `authorized_by`. §14.5 r4 wants the digital authorization to be FK-traceable.
3. **`qr_expires_at` editable by anyone with update.visitor**: `VisitorUpdateRequest:21`
   lets the QR validity be extended post-creation, and `VisitorUpdateRequest` lacks the
   `CallerBelongsToUnit` rule that `VisitorCreateRequest` has on `unit_id` (so a visitor
   can be reassigned to an arbitrary tenant unit).
4. **`exited_at`/`correction` in `$fillable`**: the immutable `access_log` keeps these
   mass-assignable; the service sets them via direct property assignment anyway, so
   removing them from `$fillable` prevents accidental future mass-assignment without
   breaking current code.
5. **`pruneExpiredQr` never prunes used TIME_BASED/EVENT visitors**: `whereDoesntHave('access_logs')`
   excludes any visitor that entered once, so expired multi-use QRs accumulate forever.
6. **`generateUniqueToken` busy-loop has no attempt cap**: a theoretical infinite loop
   if a tenant's token space saturates; a bounded retry that throws is safer.

## Current state

`backend/Modules/Access/app/Http/Controllers/AccessLogController.php`:
```php
// checkout (line 156-169)
if ($accessLog->exited_at !== null) { return ...422 'ya fue registrada'; }   // no denied_at guard
// addCorrection (line 174-184) — no entry-state guard
```
`backend/Modules/Access/app/Services/AccessService.php`:
```php
// approveEntry, AccessLog::create(...) line 309-322:
'authorized_by' => $approver->name,
'guard_user_id' => null,
// pruneExpiredQr line 591-595:
->whereNotNull('qr_expires_at')->where('qr_expires_at','<',$cutoff)->whereDoesntHave('accessLogs')->get();
// generateUniqueToken line 554-561: do { ... } while (exists()); — no cap
```
`backend/Modules/Access/app/Models/AccessLog.php:26-43` — `$fillable` includes
`'exited_at'` and `'correction'`.
`backend/Modules/Access/app/Http/Requests/VisitorUpdateRequest.php` — `unit_id` has no
`CallerBelongsToUnit`; `qr_expires_at` is editable.

> NOTE: if plan 040 has already run, the relation is named `access_logs` (snake). If 040
> has NOT run yet, it is `accessLogs`. Check the live method name before editing item 5.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Backend lint | `cd backend && vendor/bin/pint Modules/Access` | exit 0 |
| Backend tests | `cd backend && php artisan test --filter=Access` | pass (or none) |

## Scope

**In scope**:
- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php`
- `backend/Modules/Access/app/Services/AccessService.php`
- `backend/Modules/Access/app/Models/AccessLog.php`
- `backend/Modules/Access/app/Http/Requests/VisitorUpdateRequest.php`

**Out of scope**:
- Relation renames (plan 040).
- Tenant-scope validation of visitor_motive (plan 042).
- Notification logic.

## Steps

### Step 1: Guard checkout/correction against denied rows

In `checkout`, after `findOrFail`, add: if `$accessLog->denied_at !== null` return
`response()->json(['message' => 'No se puede registrar salida de un acceso denegado.'], 422);`
In `addCorrection`, allow corrections but reject if the row is neither an entry nor a
denial — i.e. require `entered_at !== null || denied_at !== null` (both are real log
rows; a row with neither shouldn't exist, but guard anyway). Keep the existing
double-exit guard.

**Verify**: `cd backend && vendor/bin/pint Modules/Access` → exit 0.

### Step 2: Record the approver FK in approveEntry

In `AccessService::approveEntry`, set `'guard_user_id' => $approver->id` (keep
`'authorized_by' => $approver->name` as the human snapshot).

**Verify**: lint exit 0; `grep -n "guard_user_id' => null" Modules/Access/app/Services/AccessService.php` shows it only in paths where there is genuinely no actor (the blacklist `denyEntry` QR path), not in `approveEntry`.

### Step 3: Lock down VisitorUpdateRequest

Add `new CallerBelongsToUnit` to the `unit_id` rule (mirror `VisitorCreateRequest:18`,
including `bail` ordering). Remove `qr_expires_at` from `VisitorUpdateRequest` (a QR's
validity should not be silently extended post-creation) — OR, if product wants
extension, restrict it to staff; default to removal. Import the rule class.

**Verify**: lint exit 0; `grep -n "CallerBelongsToUnit" Modules/Access/app/Http/Requests/VisitorUpdateRequest.php` → present.

### Step 4: Remove exited_at/correction from AccessLog $fillable

Delete `'exited_at'` and `'correction'` from the `$fillable` array. The service sets
them via `$accessLog->exited_at = now()` / `$accessLog->correction = ...` (direct
property assignment, which bypasses `$fillable`), so nothing breaks.

**Verify**: `grep -nE "'(exited_at|correction)'" Modules/Access/app/Models/AccessLog.php` → no matches in `$fillable`. Run `php artisan test --filter=Access` (if any) → pass.

### Step 5: Prune expired used multi-use QRs

In `pruneExpiredQr`, broaden the query so expired visitors are pruned when they have NO
access log OR their status is EXPIRED/USED/CANCELLED (i.e. they can no longer be used).
Keep the rule that the immutable `access_log` is NEVER deleted — only the `visitor` row.
Use the correct relation name (`access_logs` post-040, else `accessLogs`).

**Verify**: lint exit 0. Add/extend a test asserting an expired TIME_BASED visitor with
an access log IS pruned while a still-pending unexpired one is NOT.

### Step 6: Cap the token-generation loop

In `generateUniqueToken`, bound the loop to e.g. 10 attempts; if exhausted, throw a
`RuntimeException('No se pudo generar un token único de QR.')`. (Astronomically rare,
but turns a hang into a clear error.)

**Verify**: lint exit 0.

## Test plan

- `backend/Modules/Access/tests/Feature/` (create if absent, model after a Collections
  feature test): checkout on a denied log → 422; approveEntry sets `guard_user_id`;
  pruneExpiredQr removes an expired-used visitor but keeps its access_log; an unexpired
  pending visitor survives.
- Verification: `cd backend && php artisan test --filter=Access` → pass.

## Done criteria

- [ ] checkout & addCorrection reject denied/invalid rows (422)
- [ ] approveEntry stamps `guard_user_id = approver id`
- [ ] VisitorUpdateRequest enforces `CallerBelongsToUnit`; `qr_expires_at` not freely editable
- [ ] `exited_at`/`correction` removed from AccessLog `$fillable`
- [ ] pruneExpiredQr prunes expired-but-used visitors (log preserved)
- [ ] generateUniqueToken is bounded
- [ ] `cd backend && vendor/bin/pint Modules/Access` exits 0
- [ ] `cd backend && php artisan test --filter=Access` passes
- [ ] `plans/README.md` row updated

## STOP conditions

- Removing `qr_expires_at` from the update request breaks an existing UI flow that edits
  it (grep the frontend for a visitor-edit form using `qr_expires_at`) → keep the field
  but gate it, and note it.
- The prune relation name is ambiguous (040 partially applied) → resolve 040 first.

## Maintenance notes

- These guards reinforce the §14.5 r6 immutability contract — a reviewer should confirm
  no NEW write path to `access_log` was introduced.
- If `authorized_by` (string) is later dropped in favor of the FK, update reports that
  read it.
