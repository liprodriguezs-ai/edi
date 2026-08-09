---
name: access-module-isolation
description: Access Control module tenant-isolation audit — QR scan scope, visitor/access_log/blacklist_entry model traits, cron tenancy iteration, custom-action IDOR, CallerBelongsToUnit rule, blacklist check, visitor_motive classification mismatch.
metadata:
  type: pattern
---

# Access Control module — tenant isolation (audited 2026-06-21)

## Tenant-scoped models (correct)
- `visitor` — BelongsToTenant + `BaseModel::columns($table, true)` + unique(['tenant_id','qr_token'])
- `access_log` — BelongsToTenant + `BaseModel::columns($table, true)`
- `blacklist_entry` — BelongsToTenant + `BaseModel::columns($table, true)`

## Central catalogs (correct)
- `visitor_status`, `qr_type`, `access_entry_type`, `access_verification`, `blacklist_type`
  — all extend `CentralCatalogModel`, `BaseModel::columns($table, false)`, fixed UUID constants.
  All appropriately central: code branches on these (FSM/behavior selectors).

## CRITICAL: QR scan is per-tenant-scoped (SAFE by unique constraint design)
AccessService::scanQr (line 75) does `Visitor::where('qr_token', $token)->lockForUpdate()->first()`.
Since Visitor has `BelongsToTenant`, TenantScope adds `WHERE tenant_id = <current>` automatically.
This means a token from tenant B is invisible to tenant A's scan call. Cross-tenant token collision
is structurally impossible because the unique index is `(tenant_id, qr_token)` — the same token
string can appear in two different tenants without conflict, and TenantScope ensures each scan
resolves only within the current tenant. SAFE by design. No withoutGlobalScope on this path.

## CRITICAL: visitor_motive classification MISMATCH (HIGH gap)
- Migration `create_visitor_motive_table` uses `BaseModel::columns($table, false)` → NO tenant_id in DB.
- Model `VisitorMotive` extends `TenantCatalogModel` → BelongsToTenant applied at ORM level.
- `TenantCatalogModel` expects a `tenant_id` column that does NOT exist in the schema.
- Result: `TenantScope` on VisitorMotive adds `WHERE visitor_motive.tenant_id = ?` against a
  non-existent column → runtime error (or silent null if column ignored); `Visitor::create` stores
  a `visitor_motive_id` that is not verified as belonging to the current tenant.
- Also: `Rule::exists('visitor_motive','id')` in VisitorCreateRequest has NO `->where('tenant_id',...)`
  but that is a secondary issue; the root is the schema/model mismatch.

## Crons — correct per-tenant iteration
Both commands iterate `Tenant::all()` then `$tenant->run(fn)` — Stancl sets + ends tenancy per
iteration. AccessService::pruneExpiredQr (Visitor::query) and alertForTenant (AccessLog::query)
inside run() are TenantScope-scoped. Periodic queries are safe; no unscoped query runs outside run().

## Custom action IDOR analysis
- cancelQr: `Visitor::findOrFail($id)` at VisitorController:89 — Visitor has BelongsToTenant;
  TenantScope ensures the findOrFail is tenant-scoped. Secondary auth (board vs own) correct.
- approve/reject: same — `Visitor::findOrFail($id)` is scoped; authorizeUnitMembership() checks
  UnitUser membership (tenant-isolated via BelongsToTenant on UnitUser indirectly).
- checkout/addCorrection: `AccessLog::findOrFail($id)` at AccessLogController:160/180 — AccessLog
  has BelongsToTenant; scoped. No IDOR.
- scanQr: token lookup is scoped (see above). Safe.
- manualEntry / entryRequest: unit_id validated with `Rule::exists('unit','id')->where('tenant_id',tenant('id'))`. Safe.

## CallerBelongsToUnit rule (MEDIUM gap — unscoped UnitUser query)
CallerBelongsToUnit::validate (line 31) does `UnitUser::where('unit_id', $value)->where('user_id',...)->whereNull('end_date')->exists()`.
UnitUser must have BelongsToTenant to be scoped; if it does NOT have BelongsToTenant, this query
runs without TenantScope → a user from tenant B who somehow shares a unit_id UUID with tenant A's
unit could pass. Risk depends on whether UnitUser has BelongsToTenant (verify in Housing module).
Per this audit this is flagged as MEDIUM pending Housing module verification.

## Blacklist check scope (HIGH gap)
AccessService::checkBlacklist (line 469-487) queries BlacklistEntry with TenantScope (model has
BelongsToTenant). This means blacklist checks are correctly per-tenant. However, at entry the
method is called in the QR scan path with the CALLER's tenant initialized — correct. In the cron
(SendLongStayAlertsCommand) the method is NOT called in the alert path (it only queries AccessLog
for long-stay). No cross-tenant blacklist check occurs in crons.

## visitor_motive — exists rule unscoped (LOW)
`Rule::exists('visitor_motive','id')` in VisitorCreateRequest (line 21) and in AccessLogController
manualCaptureRules (line 201) lacks `->where('tenant_id', tenant('id'))`. If visitor_motive were
genuinely central this would be correct. If the intent is tenant-scoped (TenantCatalogModel), this
validation check should be tenant-scoped too — secondary to the schema/model mismatch.
