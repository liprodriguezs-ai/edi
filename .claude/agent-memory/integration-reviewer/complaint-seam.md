---
name: complaint-seam
description: Complaint (Denuncias) module seam review 2026-07-19 — 0 CRITICALs; business-403 PermissionsModal double-surface; assign UI offers non-managers; FE FSM matrix duplication
metadata:
  type: project
---

# Complaint (Denuncias) seam — reviewed 2026-07-19 (uncommitted WU)

Verdict: ⚠️ DRIFT (0 CRITICAL, 3 WARNING). Core contract fully aligned.

## Aligned (verified file-to-file)
- 4 custom endpoints (`POST transition/assign/message`, `GET messages`) paths+methods+`{data}` envelope match `ComplaintResource` customMethods 1:1; custom routes declared BEFORE `Orion::resource` (Package precedent, string `{complaintId}` not implicit binding — tenancy-safe).
- `filterableBy` = exactly the 4 `buildFilters` fields; `searchableBy` folio/title/against_description; sort `created_at`.
- `includes()` whitelist covers detail include `category,priority,status,reporter,assignee,against_unit,media`; relation method `against_unit` snake_case = include key (house rule).
- Nullability: `against_unit_id` nullable both sides; `against_description` required both; `resolved_at` cleared on reopen (`ComplaintService::changeStatus` line ~155) and FE `agingLabel` handles both branches.
- FE FSM `TRANSITIONS` map (ComplaintDetailPage:32) matches backend `isValidTransition` matrix EXACTLY incl. reopen from terminal, no resolved↔dismissed direct swap.
- Config seam `/config/complaint-settings`: 5 flat keys match the LOCAL `ComplaintSettings` interface in ComplaintConfigSection (deliberately local, parallel build); partial PUT (`sometimes` rules) ↔ `useSectionEdits` edits-only payload; `min:1` role arrays mirrored client-side (last checkbox disabled); defaults triple-mirrored (ConfigurationController ↔ ComplaintService ↔ TenantCatalogSeeder::seedComplaintSettings).
- /selects: `Str::studly('complaint_category')` → mapper keys `ComplaintCategory|ComplaintPriority|ComplaintStatus` (AngularController:151-153); complaint_status rows seeded with key+color in CatalogSeeder — consumed by ComplaintStatusBadge.
- Permissions verb.entity (`create.complaint`, `update.complaint`) consistent: FE PermissionGuard ↔ backend `authorize()` ↔ permissions.csv; menu routes `/complaint`, `/complaint-category`, `/complaint-priority` match App.tsx; `Estados de Denuncia` hidden + status resource `only(index,show)`.

## Findings to re-check in future reviews
1. **Business-rule 403 → global PermissionsModal double-surface** (recurring pattern, see [[delinquency-restrictions-seam]]): complaint calls do NOT set `skipPermissionsModal` nor have a code opt-out (like `DELINQUENT_RESTRICTED`). Reporter-role gate on create, manager/exclusion gates on transition/assign/messages all abort(403) with business messages → modal + local toast simultaneously.
2. **Assign UI offers all users** (`UserResource.searchByTerm`) but backend 422s non-managers/excluded — reachable 422 through normal UI; FE should filter by `manager_role_keys`.
3. **Latent**: `withSoftDeletes()` on category/priority registers restore routes but permissions.csv has NO `restore.complaint_category|_priority` rows (only 6 for complaint, 4 for each catalog, 1 for status). FE never calls restore, so dormant.
4. Minor Zod laxness: catalog `order` lacks `.min(0)`, `name` lacks `.max(100)` → server 422 instead of inline error.
