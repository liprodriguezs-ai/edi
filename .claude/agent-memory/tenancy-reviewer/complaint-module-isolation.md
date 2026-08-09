---
name: complaint-module-isolation
description: Complaint (Denuncias) module tenancy audit 2026-07-19 — SAFE, no criticals; classification map, exclusion-scope safety, and the two warnings found
metadata:
  type: project
---

# Complaint module isolation (audit 2026-07-19) — SAFE

**Classification** (all verified in migrations + models):
- Tenant-scoped (`BaseModel::columns($table, true)` + `BelongsToTenant`): `complaint`, `complaint_message`, `complaint_sequence` (BaseModel), `complaint_category` + `complaint_priority` (TenantCatalogModel → per-tenant cache prefix).
- Central: `complaint_status` (CentralCatalogModel, fixed UUID constants a9001001-…, seeded by CatalogSeeder, routes `->only(['index','show'])`).
- Per-tenant partial uniques: `(tenant_id, name)`, `(tenant_id, year)`, `(tenant_id, folio)` — all `WHERE deleted_at IS NULL`.

**Patterns done right (reusable canon):**
- Custom routes use string `{complaintId}` + `findOrFail` inside the controller AFTER initializeTenancy — Package implicit-binding lesson applied verbatim and documented in routes/api.php comments.
- Confidential-exclusion (`scopeVisibleTo` / `excludedUserIdsFor`) queries `UnitUser` which has `BelongsToTenant` → pluck is tenant-scoped; `against_unit_id` is validated with `Rule::exists('unit','id')->where('tenant_id', tenant('id'))` so the linkage can never reference another tenant's unit.
- `ComplaintAssignRequest` validates assignee via `Rule::exists('user_tenant','user_id')->where('tenant_id', tenant('id'))` — user_tenant is a HARD pivot (no deleted_at), so no soft-delete staleness. `User::find` is additionally covered by UserTenantScope (global scope on User).
- Folio counter mirrors ReceiptSequence: `ComplaintSequence` row read with lockForUpdate under TenantScope; backfill `Complaint::withTrashed()->max('folio')` stays tenant-scoped (withTrashed removes only SoftDeletingScope).
- Notifications: `userIdsWithRole` (NotifiesResidents) intersects global Spatie roles with user_tenant membership and fails CLOSED (returns [] + warning log) without tenancy.
- Settings: `complaint.*` seeded per-tenant by `TenantCatalogSeeder::seedComplaintSettings` (firstOrCreate slug-only under TenantScope — same canon as [[package-wu3-policies-config]]); ConfigurationController get/updateComplaintSettings behind initializeTenancy.

**Warnings found (not isolation breaches):**
1. §6 exclusion NOT applied to Orion update/destroy/restore fetch queries — only buildIndexFetchQuery/buildShowFetchQuery are overridden with `visibleTo`. An excluded manager (member of the denounced unit) with update.complaint can PATCH the complaint directly (even set against_unit_id=null to unhide it). Within-tenant confidentiality drift.
2. `nextFolio` has no tenancy-initialized guard: if ComplaintService::register were ever reused from a job/command without tenant context, TenantScope no-ops and the query could lock/increment ANOTHER tenant's counter row. Currently unreachable (only route behind initializeTenancy), but flag it if the service gets reused.

**Test:** ComplaintTenancyTest proves cross-tenant show → 404 through the REAL middleware chain (X-Tenant-Id validated against membership by ActiveTenantResolver). Does not cover folio-sequence independence, cross-tenant catalog-id rejection, or custom-action (transition/assign/message) cross-tenant 404s.
