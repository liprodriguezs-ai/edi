# Module 10 — Access Control — Spec

## Requirements

### R1 — Visitor entity
`visitor` has: FK `unit_id`, `created_by_user_id` (nullable, withoutGlobalScope), `name`,
FK `visitor_motive_id`, `arrives_by_vehicle` (boolean), `plate` (nullable), `vehicle_model`
(nullable), `qr_token` (unique per tenant), `qr_type_id` (FK), `qr_expires_at` (datetime,
nullable), FK `visitor_status_id`, `notes` (nullable).

### R2 — Access log entity (append-only)
`access_log` has: `visitor_id` (nullable FK — null for manual entries), FK `unit_id`,
`visitor_name` (string snapshot), FK `access_entry_type_id`, FK `access_verification_id`,
`authorized_by` (nullable string), `entered_at` (datetime), `exited_at` (datetime, nullable),
`guard_user_id` (nullable, withoutGlobalScope), `correction` (text, nullable).

Access logs do NOT have a general update path. The only permitted mutations are:
- `checkout`: sets `exited_at = now()`.
- `addCorrection`: appends `[timestamp] note` to the `correction` text (accumulative, not
  replacing previous corrections).

### R3 — Blacklist entry
`blacklist_entry` has: FK `blacklist_type_id`, `value` (string, the blocked name or plate),
`is_active` (boolean), `notes` (nullable).

### R4 — QR generation
`AccessService.generateQr(data)`:
- Generates unique token `XXXX-XXXX` (9 chars, uppercase alphanumeric + dash).
  Uniqueness guaranteed by do-while loop checking `Visitor::where('qr_token', $token)`.
- Resolves expiry per QrType key:
  - `single_entry`: expires at `now()->endOfDay()`.
  - `time_based`: uses `data['qr_expires_at']` or `now()->addHours(24)`.
  - `event`: uses `data['qr_expires_at']` or `null`.
- Creates `Visitor` with `visitor_status_id = pending`.

### R5 — QR scan
`AccessService.scanQr(token, context)`:
- Returns `{valid, reason, visitor, access_log, blacklist_match}`.
- Invalid cases (returns `valid=false`): QR not found; status already `used`; status
  `cancelled`; `qr_expires_at` in the past (also transitions status to `expired`).
- Valid case: creates `AccessLog` with `entered_at = now()`, entry_type = `qr`,
  verification = `qr`. For `single_entry` type, flips `visitor_status` to `used`.
- Always runs `checkBlacklist` on `visitor.name` and `visitor.plate`; sets `blacklist_match`.
  Blacklist match does NOT prevent entry; it is advisory.

### R6 — Manual entry
`AccessService.manualEntry(data)`: creates `AccessLog` with `visitor_id = null`,
entry_type = `manual`, the provided `access_verification_id` and `authorized_by`.

### R7 — Checkout
`AccessService.checkout(accessLog)`: sets `exited_at = now()`, saves.

### R8 — Correction
`AccessService.addCorrection(accessLog, note)`: appends `[{timestamp}] {note}` to
`correction`. Multiple corrections accumulate separated by newlines.

### R9 — Cancel QR
`AccessService.cancelQr(visitor)`: if `visitor_status = pending`, flips to `cancelled`.
If status is not pending (already used, expired), returns as-is (no-op).

### R10 — Blacklist check
`AccessService.checkBlacklist(name, plate)`: case-insensitive LOWER() comparison against
active `blacklist_entry` rows of type `visitor` (for name) and `vehicle` (for plate).

## Scenarios (acceptance — from tests in AccessModuleTest.php)

- **A. Catalog seeding**: 5 visitor motives, 4 visitor statuses, 3 QR types, 2 entry types,
  3 verifications, 2 blacklist types per tenant.
- **B. Generate QR**: creates visitor with unique 9-char token (XXXX-XXXX format);
  single_entry type sets `qr_expires_at` to end of day.
- **C. Token uniqueness**: 5 generated visitors in same tenant all have unique tokens.
- **D. Scan valid single_entry QR**: `valid=true`, `access_log` created with `entered_at`,
  `exited_at=null`; visitor status flips to `used`.
- **E. Second scan of used QR**: `valid=false`, reason contains "usado".
- **F. Scan nonexistent token**: `valid=false`.
- **G. Access log immutability**: only checkout sets `exited_at`; correction appends;
  second correction accumulates (both notes present in `correction`).
- **H. Blacklist match**: visitor name on blacklist → `valid=true` but `blacklist_match=true`.
- **I. Manual entry**: creates access_log with `visitor_id=null`, entry_type=`manual`,
  `entered_at` set.
- **J. Cancel pending QR**: flips status to `cancelled`; subsequent scan returns `valid=false`.
- **K. Tenant isolation**: visitor from tenant A not visible in tenant B; same token allowed
  in different tenants (unique constraint is `(tenant_id, qr_token)`).
