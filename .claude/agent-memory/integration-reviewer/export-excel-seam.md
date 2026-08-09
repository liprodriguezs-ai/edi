---
name: export-excel-seam
description: /customReport xlsx export seam (export-excel-lists) — body-only JSON contract, what backend applies vs silently drops, operator vocab mismatch, blob error-shape loss
metadata:
  type: project
---

# /customReport export seam (reviewed 2026-07-06, uncommitted)

Frontend `useBaseList.onReport()` POSTs `{_model,_type:'xlsx',_title,page,limit,...buildParams()}` as JSON body via `resourceApi.report()` (responseType blob, timeout 0). Backend `ReportController::customReport()` (core/src/Http/Controllers/ReportController.php).

**Aligned (verified):**

- All 15 REPORT_MODEL_MAP keys match FE `_model` strings exactly (PascalCase model names); Collaborator goes legacy switch.
- Ability = `report.` + `Str::snake(_model)` — all 16 FE PermissionGuard keys match, incl. multi-word (charge_batch, bank_account, unit_user, access_log, blacklist_entry, community_project, project_contribution). All 16 seeded in permissions.csv.
- Both response paths (styled `Excel::download` ≤ threshold, streamed `response()->download` above `config('excel.exports.sync_row_threshold')`) are attachment binaries; FE names the file itself so header differences don't matter.
- Filters body shape `{field, operator, value}` matches `applyRequestFilters` parsing.

**Known drift (recurring risk — recheck on any export change):**

- `ApiHandler::parseMultiple` reads ONLY the query string; FE sends everything in the JSON body. Backend applies ONLY body `filters` (own-fillable + id/timestamps, dotted skipped). Body `search`, `sort`, `scopes`, `owner_ids`, `user_id`, `page`, `limit` are silently IGNORED → export ≠ visible list when search term / owner filter active; row order forced `created_at desc`.
- Operator vocabulary mismatch: FE `FilterOperator` union uses Orion underscore forms (`not_like`, `not_in`, `is_null`, `is_not_null`, `between`); backend whitelist uses spaced `'not like'`/`'not in'` and lacks null/between → those filters silently dropped. Concrete victim: AccessLog inside/exited/denied status filters (`is_null`/`is_not_null`).
- `AccessLog.$fillable` lacks `exited_at`; core `Audit` has no `$fillable` → only id/timestamps filterable on Audit exports.
- Blob error shape: with `responseType:'blob'`, 403/422 JSON error bodies arrive as Blob → apiClient's `data.message`/`data.code` reads are undefined → generic PermissionsModal text, and `SUBSCRIPTION_INACTIVE` paywall redirect can never trigger on export requests.
- Empty dataset: backend always emits headings workbook → `blob.size <= 0` info-toast branches (useBaseList.onReport, useContributionsList.exportXlsx) are dead code.

Test pinning the seam: backend/tests/Feature/CustomReportTest.php (403, ability, wildcard, audit tenant guard fail-closed, body filters narrow, stream threshold, pdf 422).

## Re-review 2026-07-06 (uncommitted fixes)

- C1 sort → RESOLVED: FE sends `sort:[{field,direction}]` in body (buildSearchExtras/mergeFilterParams); backend `resolveSortField()` applies it (whitelisted col + `id` tie-break). Test test_export_applies_the_requested_sort_field.
- C1 search-term toast → RESOLVED: useBaseList.onReport (ts:214) fires `toast.info` from paramsSearchRef.search.value; useContributionsList.exportXlsx from search.trim().
- C1 owner_ids → **NOT-RESOLVED (surviving silent drop)**: ReportController NEVER reads `owner_ids` (grep empty). Charge list sends it (ChargeListPage.tsx:285) via body but export ignores it AND no toast warns (info toast only covers search term). Charge export w/ active billing-owner filter → exports ALL charges ≠ on-screen list. Only remaining C1-class mismatch.
- C2 operator vocab → RESOLVED: FE FilterOperator union (list.types.ts:80-93) = 13 ops all handled by normalizeFilterOperator (not_like/not_in aliased, is_null/is_not_null/between added). `between` never emitted by any FE list (dates use >=/<= pairs) but handler correct (expects [v0,v1]).
- C2 AccessLog is_null → RESOLVED: whitelist now Schema::getColumnListing (exited_at/denied_at filterable); test uses real payload {field:exited_at,operator:is_null,value:null} = AccessLogListPage.tsx:126.
- W1 blob error shape → RESOLVED: apiClient normalizeBlobErrorResponse awaited FIRST in async rejection handler, reassigns error.response.data to parsed JSON; handle403 (SUBSCRIPTION_INACTIVE+message) & rejectWithApiError consume {message,code}; non-blob early-returns. Note: onReport catch still shows generic toast (422 xlsx-only msg not surfaced, but unreachable from real UI since FE always sends xlsx).
- Model map/permissions → aligned: 15 keys match FE \_model (Vehicle mapped but no button yet — superset OK); canonicalizeMappedModel case-insensitive; checkPermission now active (was commented) on ALL paths incl legacy.
