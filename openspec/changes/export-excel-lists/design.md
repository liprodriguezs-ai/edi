# Design: Export to Excel on Business List Pages (export-excel-lists)

> Builds on `proposal.md` and the user-approved plan `/Users/rodriguez/.claude/plans/hola-mira-necesito-intenrar-glowing-dawn.md` (canonical for per-entity Spanish column lists and the 15-page wiring table). This design fixes what the plan guessed, gives exact code shapes, and settles test mechanics. Every relation name below was **verified against the model source files**.

## Technical Approach

Harden `ReportController::customReport()` with a permission check + a map-driven early-return branch for the 15 Kivo7 models, leaving the legacy QCH switch (User/Notification/Event/Person/PersonVisit/EventServicePerson/Collaborator) byte-for-byte untouched. Each model gains `reportColumns()` (Collaborator pattern, `Collaborator.php:152`). Frontend reuses `useBaseList.onReport` + the `CollaboratorList.tsx:245-272` button block, with three designed deviations (CatalogCrudModule props, `exportXlsx` in useContributionsList, AccessLog CSV replacement).

## Architecture Decisions

### Decision: Mapped branch = guarded early return, not switch cases
**Choice**: After the `_model` guard + permission check, `if (isset(self::REPORT_MODEL_MAP[$request['_model']])) { return $this->mappedModelReport($request); }`. Legacy code below stays unmodified (no re-indentation).
**Alternatives rejected**: (a) 15 new `switch` cases — noisy, still flows through the broken shared `$customQuery`/output blocks; (b) wrapping the legacy body in `else` — re-indents ~170 lines, destroys diff reviewability.
**Rationale**: The legacy branches reference dead modules (`Modules\Person\Entities\Person`) and latent fatals; sharing nothing with them is the safety property. The Collaborator case already sets the early-return precedent (line 203).

### Decision: 403 via `AuthorizationException`, NOT the plan's `UnauthorizedHttpException`
**Choice**: `checkPermission()` throws `Illuminate\Auth\Access\AuthorizationException` (renders 403).
**Rationale**: Verified `TraitController::authorize()` (core, line 82-94) — the project-wide permission mechanism — throws `AuthorizationException`; every other permission failure in the API is a 403. The plan's snippet used `Symfony\...\UnauthorizedHttpException`, which renders **401** and would break the proposal's own success criterion ("403 without ability"). Remove the now-unused `UnauthorizedHttpException` import.

### Decision: Permission check applies to ALL `_model` values, including legacy
**Choice**: `checkPermission()` is the first statement after the `_model` guard, before any branch.
**Rationale**: `report.user`, `report.notification`, `report.collaborator` are seeded (permissions.csv lines 7/61/49); login tokens carry the user's permission names as Sanctum abilities (`AuthController::generateTokens($user, $this->getPermissions($user))`), so live consumers keep working. Dead legacy models (Person, Event…) now fail 403 instead of fataling on missing classes — strictly better.

### Decision: Fix the `_model`-missing guard while touching it
**Choice**: Replace `return $this->error(...)` with `return response()->json(['message' => 'Ocurrió un error en la generación del reporte, intente más tarde'], 422);`
**Rationale**: `trait Helpers {}` (core/src/Http/Controllers/Helpers.php:20) is **empty** — `$this->error()` is a latent fatal that only survives because the frontend always sends `_model`. Our permission check depends on this guard running first; leaving a fatal above our code is not "preserving legacy", it's preserving a landmine. One line, same behavior contract (error response).

### Decision: Nested eager loads for `unit` (corrects the plan)
**Choice**: Wherever a column renders `$r->unit?->full_identifier`, eager-load `unit.unitType` + `unit.tower` instead of flat `unit` (Charge, Payment, Vehicle, UnitUser, AccessLog, Visitor, ProjectContribution).
**Rationale**: `Unit::buildFullIdentifier()` reads `$this->unitType` and `$this->tower` (Unit.php:69-87). Flat `unit` triggers 2 lazy queries per row on export — thousands of rows on AccessLog. Model caching (`TenantCatalogModel`) is disabled by default, so it does not save us.

### Decision: Filter whitelist = `getFillable() + [id, created_at, updated_at]`; skip dotted fields and unknown operators per-filter
**Choice**: A skipped filter never aborts the export (per-filter continue). Documented residual gaps (out of scope per proposal): full-text `search.value`, Charge's controller-level `owner_ids`, dotted relation filters.
**Rationale**: All primary list filters (`unit_id`, `charge_status_id`, `entered_at` ranges, `declined`, `project_contribution_status_id`, …) are fillable columns, so real UI filters apply. Failing closed (rejecting the request) would break exports whenever a page adds a cosmetic relation filter.

### Decision: Audit guard mirrors `AuditController::buildFetchQuery` verbatim
**Choice**: `tenancy()->initialized` → `where(qualifyColumn('tenant_id'), tenant('id'))`; else wildcard token → unscoped; else `whereRaw('1 = 0')`.
**Rationale**: Verified at `Modules/Audit/app/Http/Controllers/AuditController.php:25-39`; same threat (central `audits` table, no `BelongsToTenant`; `tenant_id` stamped by `QCH\Core\Models\Audit::stampTenantId()`). Two code paths, one semantics — divergence here is a security bug.

### Decision: `reportColumns()` for Audit goes on `Modules\Audit\Models\Audit`
**Rationale**: That subclass has an empty body today and is what the map instantiates; core `QCH\Core\Models\Audit` stays untouched (it's the write path). `old_values`/`new_values` are `array` casts → serialize with `json_encode($v, JSON_UNESCAPED_UNICODE)`.

## Verified Model Map (corrections applied)

Include key = relation **method** name (project rule: no case conversion). Verified against all 15 model files. Plan's names were correct; the only corrections are the nested `unit.*` loads (marked •).

| `_model` | class (`Modules\…`) | `with` (verified) |
|---|---|---|
| Charge | `Collections\Models\Charge` | `unit.unitType`•, `unit.tower`•, `concept`, `batch`, `charge_status` |
| ChargeBatch | `Collections\Models\ChargeBatch` | `policy`, `status` |
| Payment | `Collections\Models\Payment` | `unit.unitType`•, `unit.tower`•, `payer_user`, `payment_method`, `bank_account`, `payment_status` |
| Expense | `Treasury\Models\Expense` | `category`, `provider`, `employee`, `payment_method`, `bank_account`, `status` |
| BankAccount | `Treasury\Models\BankAccount` | `type`, `purpose` |
| Unit | `Housing\Models\Unit` | `unitType`, `unitStatus`, `tower`, `section` |
| Vehicle | `Housing\Models\Vehicle` | `unit.unitType`•, `unit.tower`• |
| UnitUser | `Housing\Models\UnitUser` | `unit.unitType`•, `unit.tower`•, `user`, `unitRelationship` |
| AccessLog | `Access\Models\AccessLog` | `visitor`, `unit.unitType`•, `unit.tower`•, `access_entry_type`, `access_verification`, `visitor_motive`, `guard_user` |
| Visitor | `Access\Models\Visitor` | `unit.unitType`•, `unit.tower`•, `visitor_motive`, `qr_type`, `visitor_status` |
| BlacklistEntry | `Access\Models\BlacklistEntry` | `blacklist_type` |
| Provider | `Providers\Models\Provider` | `providerType`, `serviceType` |
| CommunityProject | `Projects\Models\CommunityProject` | `status`, `category`, `participationType`, `amount_mode`, `responsibleUser` |
| ProjectContribution | `Projects\Models\ProjectContribution` | `project`, `unit.unitType`•, `unit.tower`•, `contributionStatus` |
| Audit | `Audit\Models\Audit` | `user` |

**Money-cast verification** (all `TenantMoneyCast` → Money objects; `formatByDecimal()` is correct everywhere, never divide by 100): Charge `expected/paid/discount/surcharge/tax_amount`; Payment `total_amount`; Expense `amount`; BankAccount `initial_balance`, `current_balance`; Provider `estimated_amount` ✓ (plan asked to verify — confirmed cast); CommunityProject `fixed/min/ideal/max/goal_amount` ✓ (confirmed, all five); ProjectContribution `expected/contributed_amount`.

## Interfaces / Contracts

### 1. `ReportController` (backend/core/src/Http/Controllers/ReportController.php)

Legacy QCH core file — match its loose style (no strict types, `$request = request()->all()`), do NOT refactor. Surgical edits only:

```php
// NEW import (top): use Illuminate\Auth\Access\AuthorizationException;
// REMOVE import: Symfony\...\UnauthorizedHttpException (now unused)

/**
 * Kivo7 export targets. Key = _model from the frontend; include key = relation
 * method name (verified per model). Models NOT listed fall through to the
 * legacy resolution below.
 */
private const REPORT_MODEL_MAP = [
    'Charge' => ['class' => \Modules\Collections\Models\Charge::class, 'with' => ['unit.unitType', 'unit.tower', 'concept', 'batch', 'charge_status']],
    // ... 14 more rows exactly per the Verified Model Map table (inline FQCNs — do not add 15 imports)
];

protected function checkPermission($type)
{
    $user = auth()->guard('sanctum')->user();
    $ability = 'report.'.Str::snake($type);

    if (! $user || (! $user->tokenCan($ability) && ! $user->tokenCan('*'))) {
        throw new AuthorizationException('No tiene permisos para ejecutar esta acción.');
    }
}

public function customReport()
{
    $request = request()->all();

    if (! isset($request['_model'])) {
        return response()->json(['message' => 'Ocurrió un error en la generación del reporte, intente más tarde'], 422);
    }

    $this->checkPermission($request['_model']);                      // ← was commented out + inverted

    if (isset(self::REPORT_MODEL_MAP[$request['_model']])) {
        return $this->mappedModelReport($request);                    // ← new branch, early return
    }

    // ... legacy body UNCHANGED from here (class concat, User special case, switch, output),
    // EXCEPT: delete the dead Audit special case (old lines 60-62) — the map intercepts 'Audit'.
}

protected function mappedModelReport(array $request)
{
    $config = self::REPORT_MODEL_MAP[$request['_model']];
    $model = app($config['class']);

    $builder = ApiHandler::parseMultiple($model)->getBuilder()->with($config['with']);
    $this->applyRequestFilters($builder, $model);

    if ($request['_model'] === 'Audit') {
        $builder = $this->applyAuditTenantGuard($builder);
    }

    $data = $builder->orderBy('created_at', 'desc')->get();
    $columns = $model->reportColumns();

    switch ($request['_type'] ?? 'xlsx') {
        case 'pdf':
            return $this->generatePDF('report.templatePdf', [
                'title' => $request['_title'] ?? 'reporte',
                'data' => $data,
                'columns' => $columns,
            ]);
        default:
            return $this->generateExcel([
                'title' => $request['_title'] ?? 'reporte',
                'data' => $data,
                'columns' => $columns,
                'model' => $request['_model'],
            ]);
    }
}

/**
 * ApiHandler::parseMultiple() reads ONLY the query string (ApiHandler.php:44-45);
 * useBaseList.onReport sends Orion-style filters in the JSON POST body. Translate
 * them here. Whitelist: own fillable + id/timestamps. Dotted (relation) fields and
 * unknown operators are skipped per-filter (documented export gaps), never fatal.
 */
protected function applyRequestFilters($builder, $model): void
{
    $filters = request()->input('filters', []);
    if (! is_array($filters)) {
        return;
    }

    $allowed = array_merge($model->getFillable(), ['id', 'created_at', 'updated_at']);

    foreach ($filters as $filter) {
        $field = $filter['field'] ?? null;
        $operator = strtolower((string) ($filter['operator'] ?? '='));
        $value = $filter['value'] ?? null;

        if (! is_string($field) || str_contains($field, '.') || ! in_array($field, $allowed, true)) {
            continue;
        }
        if (! in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'like', 'not like', 'in', 'not in'], true)) {
            continue;
        }

        if ($operator === 'in') {
            $builder->whereIn($field, (array) $value);
        } elseif ($operator === 'not in') {
            $builder->whereNotIn($field, (array) $value);
        } else {
            $builder->where($field, $operator, $value);
        }
    }
}

/**
 * audits is a CENTRAL table (no BelongsToTenant; tenant_id stamped on write).
 * Mirror of AuditController::buildFetchQuery — keep both in sync.
 */
protected function applyAuditTenantGuard($builder)
{
    if (tenancy()->initialized) {
        return $builder->where($builder->qualifyColumn('tenant_id'), tenant('id'));
    }

    $user = auth()->guard('sanctum')->user();
    if ($user && $user->tokenCan('*')) {
        return $builder;
    }

    return $builder->whereRaw('1 = 0');
}
```

Notes: `/customReport` runs inside `['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']` (routes/api.php:149-154) → the other 14 models are tenant-isolated automatically by `BelongsToTenant`. POST body params never reach ApiHandler's pagination (`request()->query()` is empty on JSON POST), so `->get()` exports ALL filtered rows — intended.

### 2. `reportColumns()` — reference implementation (Charge)

Conventions (all 15 models): Spanish labels (user-facing header strings); money `(float) formatByDecimal()` with `0` fallback; dates `d/m/Y`, datetimes `d/m/Y H:i`, `''` fallback; booleans `'Sí'/'No'`; relations `?->name`; user names `trim(name.' '.last_name.' '.second_last_name)` (User fields verified).

```php
public function reportColumns(): array
{
    return [
        'Unidad' => function ($result) {
            return $result->unit?->full_identifier;
        },
        'Concepto' => function ($result) {
            return $result->concept?->name;
        },
        'Cobro' => function ($result) {
            return $result->batch?->title;
        },
        'Monto esperado' => function ($result) {
            return $result->expected_amount ? (float) $result->expected_amount->formatByDecimal() : 0;
        },
        'Monto pagado' => function ($result) {
            return $result->paid_amount ? (float) $result->paid_amount->formatByDecimal() : 0;
        },
        'Descuento' => function ($result) {
            return $result->discount_amount ? (float) $result->discount_amount->formatByDecimal() : 0;
        },
        'Recargo' => function ($result) {
            return $result->surcharge_amount ? (float) $result->surcharge_amount->formatByDecimal() : 0;
        },
        'Impuesto' => function ($result) {
            return $result->tax_amount ? (float) $result->tax_amount->formatByDecimal() : 0;
        },
        'F. emisión' => function ($result) {
            return $result->issued_at ? $result->issued_at->format('d/m/Y') : '';
        },
        'F. vencimiento' => function ($result) {
            return $result->due_date ? $result->due_date->format('d/m/Y') : '';
        },
        'Estado' => function ($result) {
            return $result->charge_status?->name;
        },
        'Cancelado' => function ($result) {
            return $result->is_cancelled ? 'Sí' : 'No';
        },
        'F. creación' => function ($result) {
            return $result->created_at ? $result->created_at->format('d/m/Y') : '';
        },
    ];
}
```

The other 14 follow the plan's column lists. Non-obvious source resolutions (verified):
- **Expense · Beneficiario**: `$r->employee ? trim($r->employee->name.' '.$r->employee->last_name) : ($r->beneficiary_text ?? '')` (employee = Collaborator with own `name`/`last_name` columns; Proveedor is its own column via `provider?->name`).
- **Expense · Estado**: relation is `status()` (→ ExpenseStatus). `beneficiaryType()` exists (camelCase) but is not needed by the columns.
- **AccessLog · Visitante**: `$r->visitor_name ?: $r->visitor?->name`; **Documento**: `trim(($r->id_document_type ?? '').' '.($r->id_document_number ?? ''))`; **Entrada**: `entered_at` datetime cast → `d/m/Y H:i`; `authorized_by` is a plain string column.
- **Audit** (on `Modules\Audit\Models\Audit`, empty subclass — does not extend BaseModel): Fecha `created_at?->format('d/m/Y H:i')`, Usuario via `user` relation full name, Evento `event`, Entidad `class_basename($r->auditable_type)`, ID de entidad `auditable_id`, Valores anteriores/nuevos `$r->old_values ? json_encode($r->old_values, JSON_UNESCAPED_UNICODE) : ''`, IP `ip_address`, URL `url`.
- **Unit**: `built_m2`/`land_m2`/`ownership_share` are decimal strings — cast `(float)` for numeric cells; Tipo/Estado via `unitType?->name` / `unitStatus?->name`.
- **Visitor · QR expira**: `qr_expires_at` datetime → `d/m/Y H:i`. Never export `qr_token` (hidden credential).

### 3. Frontend

**Standard button** (12 pages, exact `CollaboratorList.tsx:245-272` pattern — header flex row, NOT inside the table). Per-page `_model`/title/permission per the plan's wiring table:

```tsx
<PermissionGuard permission="report.charge">
  <Button variant="outline" onClick={() => onReport('Charge', 'xlsx', 'cargos')}
    disabled={isLoading} className="flex items-center gap-2">
    <Download className="h-4 w-4" />
    Descargar
  </Button>
</PermissionGuard>
```

`onReport` comes from each page's `useBaseList`-based hook (already returned by the hook, useBaseList.ts:267); it serializes the live `filters`/`search`/`sort` state into the POST body and handles blob download + empty toast. ChargeBatch and UnitUser pages wrap their lone `PageHeader` in `<div className="flex flex-wrap items-center justify-between gap-4">`.

**CatalogCrudModule (Vehicle)** — `frontend/src/shared/components/catalog/CatalogCrudModule.tsx`:
- Props (interface at line 73): add `reportModel?: string` and `reportTitle?: string` (JSDoc: "Optional export: renders a Descargar button gated by `report.${permissionEntity}`").
- Destructure `onReport` from the existing `useBaseList` call (line 341).
- Header (lines 400-409): wrap the create button in a `flex items-center gap-2` div and render the standard button before it when `reportModel` is set, with `permission={`report.${permissionEntity}`}` and `onReport(reportModel, 'xlsx', reportTitle ?? reportModel.toLowerCase())`. Import `Download` from lucide-react.
- `VehicleListPage.tsx` passes `reportModel="Vehicle" reportTitle="vehiculos"`. Zero impact on the other catalog pages (prop absent → no button).

**useContributionsList (`frontend/src/modules/communityProject/hooks/useContributionsList.ts`)** — react-query hook, no store. Name it `exportXlsx` (`onReport` is taken by "report a contribution payment" in `ContributionsTable.tsx`). It reuses the hook's existing `filters` memo (already includes `projectId` scoping + status + declined) and `search` state — export matches the visible list:

```ts
const [isExporting, setIsExporting] = useState(false)

const exportXlsx = useCallback(async () => {
  setIsExporting(true)
  try {
    const blob = await ProjectContributionResource.report({
      _model: 'ProjectContribution',
      _type: 'xlsx',
      _title: 'aportaciones',
      filters,
      ...(search ? { search: { value: search, case_sensitive: false } } : {}),
      sort: [{ field: 'created_at', direction: 'desc' }],
    })
    if (blob.size <= 0) {
      toast.info('No hay datos para exportar')
      return
    }
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'aportaciones.xlsx'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
    toast.success('Reporte descargado correctamente')
  } catch {
    toast.error('Error al descargar el reporte, inténtalo más tarde')
  } finally {
    setIsExporting(false)
  }
}, [filters, search])
```

Return `exportXlsx, isExporting`; button in `components/ContributionsList.tsx` toolbar, gated `report.project_contribution`, `disabled={isExporting}`. (`ProjectContributionResource` comes from `createCrudApi` → `.report()` exists, POST `/customReport`, blob, `timeout: 0`.)

**AccessLogListPage (`frontend/src/modules/access/AccessLogListPage.tsx`)**: delete the `downloadCsv()` helper (lines 172-200) and replace the header button (lines 322-326) with the standard PermissionGuard-ed button calling `onReport('AccessLog', 'xlsx', 'bitacora-accesos')` (destructure `onReport` from the page's existing `useBaseList` call; `disabled={isLoading}`). Net win: server-side full filtered export (tab date-range filters on `entered_at` are fillable → whitelist passes them) instead of client-side current-page-only CSV, now permission-gated.

## Data Flow

    List page (filters/search state)
        │ onReport('_model','xlsx',title)  — useBaseList serializes live params
        ▼
    POST /api/customReport  {_model,_type,_title, filters[], search, sort, page, limit}
        │ auth:sanctum → rejectRefreshToken → authActive → initializeTenancy
        ▼
    ReportController::customReport
        ├─ checkPermission: tokenCan('report.'.Str::snake(_model)) | '*'  → 403 AuthorizationException
        ├─ REPORT_MODEL_MAP hit → mappedModelReport
        │     ApiHandler::parseMultiple(model).getBuilder()          (TenantScope for 14 models)
        │     .with(map.with) → applyRequestFilters (body whitelist) → [Audit guard] → get()
        └─ miss → legacy switch (untouched)
        ▼
    generateExcel → ReportExport (headings = array_keys(reportColumns), map = closures) → xlsx blob
        ▼
    Browser blob download (useBaseList.onReport: empty→toast.info, else save + toast.success)

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/core/src/Http/Controllers/ReportController.php` | Modify | const map, checkPermission fix, mapped branch + 2 helpers, guard fix, remove dead Audit case |
| 15 models (paths per map table; all under `backend/Modules/*/app/Models/`) | Modify | add `reportColumns()` |
| `backend/tests/Feature/CustomReportTest.php` | Create | 5 cases below |
| 12 list pages under `frontend/src/modules/{charge,chargeBatch,payment,expense,bankAccount,unit,unitUser,access,provider,communityProject,audit}/` | Modify | standard button (plan wiring table) |
| `frontend/src/shared/components/catalog/CatalogCrudModule.tsx` + `vehicle/VehicleListPage.tsx` | Modify | optional `reportModel`/`reportTitle` props + pass-through |
| `frontend/src/modules/communityProject/hooks/useContributionsList.ts` + `components/ContributionsList.tsx` | Modify | `exportXlsx` + toolbar button |
| `frontend/src/modules/access/AccessLogListPage.tsx` | Modify | replace client CSV with server export |

## Testing Strategy

`backend/tests/Feature/CustomReportTest.php` — `use RefreshDatabase; use Tests\Support\WithTenantSetup;`. Scoped tokens: `$this->makeUser($tenant)->createToken('t', ['report.charge'])->plainTextToken`; requests via `$this->tenantRequest($token, $tenant)->postJson('/api/customReport', [...])`. Use `Excel::fake()` + `Excel::assertDownloaded('{title}.xlsx', closure)` — `ReportExport implements FromCollection`, so the closure inspects `$export->collection()` (no xlsx binary parsing).

| # | Case | Setup / Assert |
|---|------|----------------|
| 1 | 403 without ability | token `['read.charge']`; POST `{_model:'Charge',_type:'xlsx',_title:'cargos'}` → `assertForbidden()` |
| 2 | 200 xlsx with ability | token `['report.charge']`, 1 charge seeded in-tenant → `assertOk()` + `Excel::assertDownloaded('cargos.xlsx')` |
| 3 | Wildcard passes | token `['*']` → `assertOk()` |
| 4 | Audit tenant isolation | `twoTenants()`; insert one audit row per tenant **via `Audit::query()->insert([...])`** (see gotcha); request as tenant-A user → closure asserts collection contains A's row id and NOT B's |
| 5 | Body filters narrow | 2 charges on different units (in-tenant factories/creates); POST with `filters: [{field:'unit_id',operator:'=',value:$unitA->id}]` → closure asserts `collection()->count() === 1` and `first()->unit_id === $unitA->id` |

**Test gotcha (verified)**: `QCH\Core\Models\Audit::save()` defers inserts to `DB::afterCommit()` when inside a transaction — under `RefreshDatabase` the wrapper transaction never commits, so `create()`/`saveQuietly()` audit rows silently never persist. Tests MUST insert audit fixtures via the query builder (`insert()` skips model save): provide `id` (uuid), `event`, `auditable_type`, `auditable_id`, `tenant_id`, `created_at`/`updated_at`, `old_values`/`new_values` as JSON strings.

Run: host php, single process (shared `testing` DB): `php artisan test --compact --filter=CustomReport`. Then `vendor/bin/pint --dirty`; frontend `pnpm lint && pnpm build`; manual QA at `http://192.168.100.136:5173/` (Collaborator export regression included).

## Migration / Rollout

No migration required. Pure code; revert = buttons disappear + endpoint reverts. Forward-fix preferred for the authorization hardening (reverting re-opens the ungated endpoint).

## Open Questions

None blocking. Two accepted gaps to restate in the PR description: (1) full-text `search.value` and dotted-field filters are not applied to exports; (2) Charge's controller-level `owner_ids` scoping does not apply to exports (Charge export is admin/treasurer-facing via `report.charge`).

## Amendment (2026-07-06): local export busy state

User feedback during QA rejected the original loading UX (export flipped the list into skeleton state). `useBaseList.onReport` no longer touches the store's global `isLoading`; it uses a hook-local `isExporting` state returned to consumers. Every export button disables on `isExporting` and swaps the `Download` icon for `Loader2 animate-spin` (idiom from `ChangeIntervalModal.tsx`). Applied to all 14 new call sites, `CatalogCrudModule`, and the pre-existing `CollaboratorList` button (same defect). `ContributionsList` already had a correctly scoped `isExporting`; only the spinner idiom was aligned. Recorded as task 4.18.

## Amendment (2026-07-06): perf hardening — xlsx-only + hybrid streaming for mapped exports

`mappedModelReport()` was the last large-dataset crash vector: `->get()` loaded the entire result set into memory (behind a 4GB `memory_limit`), and `_type=pdf` still routed through `generatePDF()` (dompdf), the historic crash cause for large exports in the sibling morelos project. Fixed with two changes, scoped entirely to `mappedModelReport()` — the legacy switch, `getReportData()`, and the async endpoints (`generateReportAsync`/`downloadReport`) were left untouched:

1. **xlsx-only.** Any `_type` other than `xlsx` (i.e. `pdf`) now returns `422 {"message": "Solo se soporta exportar a Excel (xlsx)"}` before any query runs.
2. **Hybrid size strategy.** After filters/audit-guard are applied, a cheap `(clone $builder)->count()` (cloned before `orderBy`) decides the path against `config('excel.exports.sync_row_threshold', 5000)` (new key, `config/excel.php`):
   - `count <= threshold`: unchanged styled path (`->get()` + `generateExcel()`, `Excel::download()`).
   - `count > threshold`: `cleanOldReportFiles()`, then `->orderBy('created_at','desc')->lazy(500)` streamed through the already-existing `generateExcelToFile()` (512M ceiling, gc every 500 rows) — the same machinery the async path (`generateReportAsync`) uses — writing to `temp/reports/`, and returned via `response()->download(...)->deleteFileAfterSend(true)`. The download filename mirrors `generateReportAsync`'s idiom: `Str::slug($title, '_').'.xlsx'` (note: `Str::slug(..., '_')` also normalizes any existing hyphens in the title to underscores — e.g. `cargos-grandes` → `cargos_grandes.xlsx`).

**Verified, not changed:** `phpoffice/phpspreadsheet` is `1.30.5` — `Worksheet::setCellValueByColumnAndRow()` (used throughout `generateExcelToFile()`) still exists in 1.x (it's removed only in 2.x), so no cell-API migration was needed; the async path was not "secretly broken" by that API. Added a defensive `$value instanceof \Stringable` → `(string) $value` coercion in `generateExcelToFile()`'s row-writing loop as a guard against a stray value object (e.g. `cknow/laravel-money` `Money`) reaching PhpSpreadsheet's value binder; spot-checked `Charge::reportColumns()` and confirmed its money columns already coerce via `(float) ...->formatByDecimal() : 0`, so this is defense-in-depth, not a live bug.

**Bonus fix (found while wiring the streaming path):** `generateExcelToFile()` ensured its target directory via `Storage::disk('local')->makeDirectory('temp/reports')`, but the `local` disk's root is `storage_path('app/private')` (Laravel 11+ default) while `$fullPath` is built from the disk-agnostic `storage_path('app/'.$filePath)` — the two never pointed at the same directory, so the real target directory was never actually created. This silently affected the async path too (`generateReportAsync` → `generateExcelToFile`) whenever `storage/app/temp/reports` didn't already exist from a prior run. Replaced with a direct `is_dir(dirname($fullPath)) || mkdir(..., 0755, true)` against the literal path that `$writer->save()` uses.

Tests: `backend/tests/Feature/CustomReportTest.php` gained `test_large_dataset_streams_to_a_file_download_instead_of_loading_into_memory` (sets `sync_row_threshold=2`, seeds 4 Charges, asserts a real file download + non-empty file on disk — `Excel::fake()` does not intercept this path since it bypasses the Excel facade entirely) and `test_pdf_type_is_rejected_for_mapped_models` (asserts 422). All 8 `CustomReportTest` cases pass on host `php artisan test --compact --filter=CustomReport`; `vendor/bin/pint --dirty` passed clean.
