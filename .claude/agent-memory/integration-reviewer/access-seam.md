---
name: access-seam
description: Access Control module seam review. 2026-06-21: relation key drift + response shapes. 2026-06-27: filter/filterableBy seam review — all fields aligned, two WARNING on date-to-bound.
metadata:
  type: project
---

# Access Control seam — filter/filterableBy review (2026-06-27)

## UPDATE 2026-06-27: All prior relation-key drift CRITICALs confirmed FIXED
All three controllers now use snake_case includes() strings (access_entry_type, guard_user,
created_by_user, blacklist_type) — matching both the PHP model method names and the TS interface
keys. The camelCase drift from 2026-06-21 is resolved.

## filter/filterableBy cross-check — ALL fields ALIGNED (zero 422 risk)

### AccessLogController.filterableBy()
`['unit_id','visitor_id','access_entry_type_id','guard_user_id','entered_at','exited_at','denied_at','visitor_motive_id','access_verification_id','plate']`
Frontend emits: unit_id(in), plate(like+wildcards), access_entry_type_id(in),
visitor_motive_id(in), entered_at(>=,<=), exited_at(is_null/is_not_null), denied_at(is_null/is_not_null)
— all present ✓

### VisitorController.filterableBy()
`['unit_id','visitor_status_id','qr_type_id','created_by_user_id','visitor_motive_id','arrives_by_vehicle','qr_expires_at','created_at','plate']`
Frontend emits: visitor_status_id(in), unit_id(in), plate(like+wildcards),
visitor_motive_id(in), qr_type_id(in), arrives_by_vehicle(= bool), qr_expires_at(>=,<=)
— all present ✓

### BlacklistEntryController.filterableBy()
`['blacklist_type_id','is_active','created_at']`
Frontend emits: blacklist_type_id(in), is_active(= bool), created_at(>=,<=)
— all present ✓

## WARNING: date "to" bound too narrow for timestamp columns

`resolveDateRange` returns bare YYYY-MM-DD strings. Both affected columns are TIMESTAMP type.
Postgres casts `'YYYY-MM-DD'` to `TIMESTAMP '00:00:00'` so `<= 'YYYY-MM-DD'` excludes
records from that day after midnight.

- VisitorListPage.tsx buildVisitorFilters: `qr_expires_at <= 'YYYY-MM-DD'` — visitors with
  QR expiring in the afternoon of the selected "to" date are silently excluded.
- BlacklistListPage.tsx buildBlacklistFilters: `created_at <= 'YYYY-MM-DD'` — entries created
  in the afternoon of the selected "to" date are silently excluded.
  
Fix: append `' 23:59:59'` to the "to" date string for timestamp columns, or use `< 'date+1day'`.
The `>=` (from) bound is correct for both.

## Operator validity — all correct
- `in` for UUID FK arrays ✓
- `like` with manual `%x%` wildcards ✓ (Orion does NOT auto-add wildcards)
- `>=`/`<=` for timestamps ✓ (with caveat above for to-bound)
- `is_null`/`is_not_null` for nullable timestamps ✓
- `=` with JS boolean (true/false) for boolean columns ✓ (PHP deserialized correctly for Postgres)

## AccessLog date filter format (entered_at tabs) — ALIGNED
buildDateFilters() uses toISOString().slice(0,19).replace('T',' ') which produces UTC datetime
strings like '2024-01-15 06:00:00'. Laravel sets Postgres session to UTC → comparison is correct.
The `<=` bound uses local 23:59:59 → UTC equivalent next day, which correctly caps the day window.

# Original findings (2026-06-21)

## CRITICAL: Relation key drift — AccessLog

Backend model `AccessLog.php` defines camelCase method names for entryType, guardUser.
Frontend `IAccessLog` interface reads snake_case keys `entry_type` and `guard_user`.
Orion serializes keys EXACTLY as the PHP method name → these arrive as `entryType` / `guardUser` on the wire.

| Backend method | JSON key on wire | TS interface key | Reads null? |
|---|---|---|---|
| `entryType()` (L64) | `entryType` | `entry_type` (schema.ts:49) | YES |
| `guardUser()` (L79) | `guardUser` | `guard_user` (schema.ts:51) | YES |

Includes whitelist in AccessLogController:
- `'entryType'` (L21) — serialized as `entryType` ✓ but FE reads `entry_type` ✗
- `'guardUser'` (L21) — serialized as `guardUser` ✓ but FE reads `guard_user` ✗

Impact: "Tipo" column always "—", "Vigilante" column always "—" in AccessLogListPage and MyAccessLogPage.

Fix (choose one): rename backend methods to snake_case (entry_type/guard_user) OR rename TS interface
keys to camelCase AND update includes whitelist strings consistently.

## CRITICAL: Relation key drift — Visitor.createdByUser

Backend method: `createdByUser()` (Visitor.php:57) → JSON key `createdByUser`
Frontend reads: `created_by_user` (IVisitor interface, schema.ts:34)
Include whitelist: `'createdByUser'` (VisitorController:24) ✓ but FE reads wrong key.

Impact: "Registró:" sub-line in VisitorListPage.tsx:224 always empty/hidden.

## CRITICAL: Relation key drift — Visitor.qrType

Backend method: `qrType()` (Visitor.php:68) → JSON key `qrType`
Frontend: IVisitor interface has NO `qrType` field at all (schema.ts:20-35). NOT read anywhere.
Include whitelisted in controller. Harmless currently but orphaned include.

## WARNING: BlacklistEntry include key mismatch

Backend: `BlacklistEntryController.includes()` returns `['blacklistType']` (camelCase)
Frontend BlacklistListPage.tsx:46: `includes: [{ relation: 'blacklistType' }]` — matches.
Backend model method: `blacklistType()` (BlacklistEntry.php:34) — matches.
IBlacklistEntry interface: reads `blacklist_type?.name` (schema.ts:69).

This is the SAME class of drift as Collections — the JSON key will be `blacklistType`
but the TS interface reads `blacklist_type`. Currently the BlacklistListPage column
`render: (entry) => entry.blacklist_type?.name ?? '—'` will always show "—".

## CRITICAL: approve/reject response shape — frontend discards the data

Backend `VisitorController.approve()` returns:
  `{ data: { visitor: Visitor, access_log: AccessLog } }` (L134)
Backend `VisitorController.reject()` returns:
  `{ data: { visitor: Visitor, access_log: AccessLog } }` (L150-151)

Frontend `VisitorResource.approve()` and `reject()` (AccessResource.ts:12-13):
  `async (id) => (await apiClient.post(...)).data` → returns `{ visitor, access_log }`
But the return type is declared as `Promise<unknown>`.

VisitorListPage `actionMutation` (L141-153) discards the result entirely (calls `refresh()`).
Not a UI bug but the typed interface `Promise<unknown>` makes this fragile and un-typed.

## WARNING: `now` endpoint — `currentlyInside()` loads limited relations

Backend `AccessService.currentlyInside()` eager-loads `['visitor', 'unit', 'entryType']` (L574)
Frontend AccessNowPage reads `log.motive?.name` (L99) — `motive` is NOT loaded in `currentlyInside()`.
The `now` endpoint response will have `motive: null` always → motive name always missing in the "dentro ahora" cards.

## INFO: `manualEntry` 422 (blacklist denial) not shown as special UI

Backend `manualEntry()` returns 422 when denied (L80-83).
Frontend `ManualEntryModal` mutation onError shows generic `formatApiErrorMessage`.
This works correctly because the 422 payload has `{ message, data }` and the error message
is human-readable. However `data` (the denied AccessLog) is dropped silently by `apiClient`.
Not a seam bug but worth noting for future audit trail display.

## INFO: `entryRequest` missing `id_document_type` / `id_document_number` in FE schema

Backend `manualCaptureRules` in `entryRequest` accepts `id_document_type` and `id_document_number`
(via the merged `manualCaptureRules`). FE `entryRequestSchema` does NOT include these fields.
The guard's entry-request form cannot capture ID document info — by design or oversight unknown.
No breaking bug since fields are nullable on backend, but fields exist in backend but not in FE form.

## Confirmed alignments

- URL/verb alignment: all custom routes match frontend calls perfectly
  - POST /access/scan-qr ✓, POST /access/manual-entry ✓, POST /access/entry-request ✓
  - GET /access/now ✓, GET /access/my-log ✓
  - POST /access/{id}/checkout ✓, POST /access/{id}/correction ✓
  - POST /visitor/{id}/cancel ✓, POST /visitor/{id}/approve ✓, POST /visitor/{id}/reject ✓
  - GET /visitor/mine route exists — NOT consumed by any frontend component (dead backend route)
- `myLog` pagination shape: backend returns Laravel Paginator (data[], current_page, last_page, total);
  FE reads data.data[], data.last_page, data.total → ALIGNED
- `scanQr` response: backend returns `{valid, reason, visitor, access_log, blacklist_match}`,
  FE IScanResult has all 5 keys → ALIGNED
- `addCorrection` payload `{ note }` matches backend validate `['note' => required]` → ALIGNED
- `checkout` returns `{ data: AccessLog }`, FE reads `.data.data` as `IAccessLog` → ALIGNED
- Permission abilities: create.access_log, update.access_log, read.access_log, create.visitor,
  update.visitor — all match `$keyForPermission` values in controllers
- `blacklistFormSchema` reason max:500 vs BlacklistEntryCreateRequest reason max:1000 — MINOR mismatch
  (FE rejects 501-1000 char reasons that backend would accept)
- `manualEntrySchema` authorized_by max:255, plate max:20, id_document_number max:100 — ALIGNED
- `id_document_type` enum values `['ine','passport','driver_license','other']` match on both sides
- `visitorFormSchema` plate required_if arrives_by_vehicle:true — matches backend VisitorCreateRequest
- VisitorMotive table name for Rule::exists: 'visitor_motive' (central, no tenant scoping) — correct
- access_verification_id Rule::exists('access_verification','id') — correct (no tenant scoping, central)
