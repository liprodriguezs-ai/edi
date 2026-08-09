---
name: custom-report-export-review
description: Tenancy review of POST/GET /customReport REPORT_MODEL_MAP export (2026-07-06) — 3 CRITICALs (case-variant Audit bypass, fail-open uninitialized tenancy for the 14 tenant models, temp-file name collision race)
metadata:
  type: project
---

Reviewed 2026-07-06. Uncommitted diff: `core/src/Http/Controllers/ReportController.php` (REPORT_MODEL_MAP, mappedModelReport, applyRequestFilters, applyAuditTenantGuard), `reportColumns()` added on 15 models, `tests/Feature/CustomReportTest.php`.

## Verdict: BREACH RISK — 3 CRITICALs found

1. **Case-variant `_model` bypass (merge-blocking)**: `isset(REPORT_MODEL_MAP[$m])` is case-sensitive; `_model='audit'` falls to the legacy path which builds `Modules\audit\Models\audit`. PHP class resolution is case-insensitive for already-loaded classes, and `config('audit.implementation')` = `Modules\Audit\Models\Audit` (loaded on every audited write — always warm under Octane). Legacy default case runs `ApiHandler::parseMultiple($model)->getBuilder()->get()` with NO applyAuditTenantGuard → full cross-tenant audits dump. `report.audit` is a tenant-admin ability (only audit WRITES are super-admin-only). The diff itself armed this: `Audit::reportColumns()` is new — before, the default case threw BadMethodCallException. Audit is the unique gadget: only central-table model whose FQCN matches `Modules\{X}\Models\{X}` AND has reportColumns AND whose report ability is tenant-grantable (Tenant needs report.tenant = super-admin-only; Company has no reportColumns; Notification/Collaborator are BelongsToTenant).

2. **Fail-open for the 14 mapped tenant models when tenancy uninitialized**: Stancl `TenantScope::apply()` silently no-ops when `!tenancy()->initialized`. `ActiveTenantResolver` returns null for a REGULAR user with zero memberships (detached/baja) — middleware never aborts. A stale access token (abilities baked in, 60-min TTL) or a retained global Spatie role with `report.charge`/etc. → export of ALL tenants' rows. Audit fails closed here; the 14 fail open. The test docblock claiming "a regular user always resolves to a tenant" is FALSE (resolver docblock says the opposite).

3. **Streamed temp-file name collision race**: `Str::slug($title,'_').'_'.Ymd_His.'.xlsx'` — second granularity, no tenant/user/random component, shared `storage/app/temp/reports`. Two tenants, same title, same second, both >5000 rows → second save() overwrites → tenant A downloads tenant B's file; deleteFileAfterSend cross-deletes. Low probability, real vector under Octane concurrency.

## Verified-safe patterns (reusable in future reviews)

- `applyAuditTenantGuard` is applied to `$builder` BEFORE `count()` (clone), `lazy(500)` and `get()` — all three paths guarded; mirrors `AuditController::buildFetchQuery` exactly.
- `applyRequestFilters` cannot widen: AND-only where/whereIn/whereNotIn, whitelist = own fillable + id/timestamps, dotted fields rejected. `Audit` has `$guarded=[]` → `getFillable()` = [] → only id/created_at/updated_at filterable; `tenant_id` is in NO mapped model's fillable. Laravel nests user wheres when applying global scopes, so even orWhere can't escape TenantScope.
- Production tokens NEVER carry `'*'` (`AuthController::getPermissions` = permission names via roles) → the guard's wildcard-unscoped branch is production-dead; super admin without X-Tenant-Id gets ZERO audit rows (coherent with AuditController) but ALL rows of the 14 tenant models (TenantScope no-op) — deliberate platform policy per stancl-tenancy §6, but asymmetric.
- Audit query correctly skips ApiHandler (Parser only calls withTrashed when `active` param is boolean, NOT unconditionally — the diff comment overstates it).
- `generateReportAsync`/`downloadReport`/`report.download` signed route are NOT registered in routes/api.php — the stale `getReportData()` Audit mapping (`App\Modules\Audit\Audit`, dead class) is unreachable today but is unguarded rot.

## Classification decisions

- `audits` = CENTRAL table, nullable `tenant_id` stamped on write by `QCH\Core\Models\Audit::stampTenantId()`; read isolation is MANUAL (guard in every reader). Now TWO live readers (AuditController + ReportController) + one dead one (getReportData).
- Company = CENTRAL by design (documented in model) despite tenant_id column — no TenantScope; any future reportColumns() on it would need a manual guard.

## RE-REVIEW 2026-07-06 (uncommitted fixes) — ALL 3 RESOLVED, no new leak

- **C1 RESOLVED**: `canonicalizeMappedModel()` runs BEFORE the map isset() check, uses `strcasecmp` over map keys → every pure case variant of `audit` routes to guarded `mappedModelReport`. Whitespace/namespace variants (`'Audit '`, `'\Audit'`) miss strcasecmp BUT then break legacy `class_exists('Modules\Audit \Models\Audit ')` (whitespace is NOT case-folded) → fall to `app('Modules\Catalog\Entities\Audit ')` which THROWS (no leak). Defense-in-depth: legacy default branch now throws AuthorizationException unless model is in `$legacyAllowList` (user/person/event/eventserviceperson/notification/collaborator) OR catalog OR uses BelongsToTenant — so Audit (central) is blocked even if canonicalize were skipped. Test: `test_case_variant_model_name_still_routes_through_the_guarded_audit_path` (lowercase `audit`, non-wildcard `report.audit`, asserts only tenant A rows).
- **C2 RESOLVED**: `mappedModelReport` throws when `!tenancy()->initialized && !tokenCan('*')` for every non-Audit mapped model, placed BEFORE builder build / count / get / lazy → covers both styled and streamed paths. Audit stays independently fail-closed via `applyAuditTenantGuard` (`whereRaw('1=0')`). Test: `test_non_audit_mapped_model_export_fails_closed_when_tenancy_is_not_initialized` (deprovisioned regular user, `report.charge`, asserts 403).
- **C3 RESOLVED**: stored filename now `slug_Ymd_His_{tenantFrag?}{Str::random(8)}.xlsx` — `Str::random(8)` is ALWAYS appended (outside the tenancy ternary), so collision-resistant even without tenant fragment; download name stays clean slug. Test: `test_streamed_export_filenames_are_unique_across_back_to_back_requests` (2 distinct files).
- **Whitelist widening (getFillable → Schema::getColumnListing) is SAFE**: `applyRequestFilters` is AND-only (where/whereIn/whereNotIn/whereNull/whereNotNull/whereBetween, no orWhere). User-supplied `tenant_id=other` AND-combines with TenantScope (14 models) or the Audit guard (`applyRequestFilters` runs BEFORE `applyAuditTenantGuard`, both plain `where`) → zero rows, never another tenant's. Filters can only NARROW.
- Verified: Parser only calls `withTrashed()`, does NOT strip global scopes (TenantScope survives on styled path). `cleanOldReportFiles` only deletes files >30min old (won't touch in-flight). Core Audit extends OwenIt Audit (no BelongsToTenant, confirmed central); Charge uses BelongsToTenant (confirmed tenant).
- Minor (informational, NOT isolation): `checkPermission` runs `Str::snake` on RAW `_model` before canonicalize → `_model='accesslog'` demands `report.accesslog` and is denied for a legit `report.access_log` holder (usability false-negative, fails closed — safe).
