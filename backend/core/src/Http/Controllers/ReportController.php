<?php

namespace QCH\Core\Http\Controllers;

use App;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Audit\Models\Audit;
use Modules\ChildRecord\Models\Child;
use Modules\Event\Entities\EventServicePerson;
use Modules\Person\Entities\Person;
use Modules\Person\Entities\PersonVisit;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use Modules\Screening\Models\Screening;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use QCH\Core\Http\Api\ApiHandler;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Class ReporteController.
 */
class ReportController extends BaseController
{
    use Helpers;

    /**
     * EDI export targets. Key = _model from the frontend; include key = relation
     * method name (verified per model). Models NOT listed fall through to the
     * legacy resolution below.
     */
    /**
     * Whitelist of exportable models for the generic report engine.
     *
     * Empty on purpose: the template mapped the condominium entities (charges,
     * payments, units, visitors...), all of which are gone. Register the EDI
     * entities here as their modules are built — the export machinery below
     * (filters, sorting, Excel/PDF, async generation) is domain-agnostic and
     * needs no change.
     *
     * @var array<string, array{class: class-string, with: string[]}>
     */
    private const REPORT_MODEL_MAP = [
        // edi-reports-and-indicators (REQ-RPT-007). Every `with` relation is
        // eager-loaded before reportColumns() runs — the N+1 firewall: each
        // model's reportColumns() closures MUST read only these relations.
        'Screening' => [
            'class' => Screening::class,
            'with' => [
                'child.sex', 'visit.health_center', 'visit.practitioner',
                'age_group', 'applied_age_group', 'result_color', 'situation', 'screening_status',
                'area_results.development_area', 'area_results.result_color',
            ],
        ],
        'Child' => [
            'class' => Child::class,
            'with' => ['sex', 'health_center'],
        ],
        'ScreeningIndicator' => [
            'class' => ScreeningIndicatorSnapshot::class,
            'with' => ['health_center'],
        ],
    ];

    protected function checkPermission($type)
    {
        $user = auth()->guard('sanctum')->user();
        $ability = 'report.'.Str::snake($type);

        if (! $user || (! $user->tokenCan($ability) && ! $user->tokenCan('*'))) {
            throw new AuthorizationException('No tiene permisos para ejecutar esta acción.');
        }
    }

    /**
     * Case-insensitive lookup of the canonical REPORT_MODEL_MAP key for a given
     * `_model` value. Returns null when no mapped model matches any casing.
     */
    protected function canonicalizeMappedModel(string $model): ?string
    {
        foreach (array_keys(self::REPORT_MODEL_MAP) as $mappedKey) {
            if (strcasecmp($mappedKey, $model) === 0) {
                return $mappedKey;
            }
        }

        return null;
    }

    public function customReport()
    {
        $request = request()->all();

        if (! isset($request['_model'])) {
            return response()->json(['message' => 'Ocurrió un error en la generación del reporte, intente más tarde'], 422);
        }

        // Canonicalize BEFORE the exact-match map check AND before the permission
        // check: `_model=audit` (or any other case variant of a guarded mapped
        // model) must resolve to the SAME guarded path — and the SAME ability key
        // (`report.access_log`, not `report.accesslog`) — as `_model=Audit`.
        // Without this, a case variant misses the map (isset() is case-sensitive),
        // falls into the legacy resolver below, and PHP's case-insensitive class
        // autoloading happily loads `Modules\Audit\Models\Audit` anyway — reaching
        // an UNGUARDED query for a model that DOES have a guarded mapped entry.
        $canonicalModel = $this->canonicalizeMappedModel($request['_model']);

        $this->checkPermission($canonicalModel ?? $request['_model']);

        if ($canonicalModel !== null) {
            $request['_model'] = $canonicalModel;

            return $this->mappedModelReport($request);
        }

        // $class = 'App\\Modules\\' . $request['_model'] . '\\Repositories\\' . $request['_model'] . 'Repository';
        $class = 'Modules\\'.$request['_model'].'\\Models\\'.$request['_model'];

        if ($request['_model'] == 'User') {
            $class = 'App\\Models\\User';
        }

        if (class_exists($class)) {
            $model = app($class);
        } else {
            if (! class_exists($class)) {
                $model = app('Modules\\Catalog\\Entities\\'.$request['_model']);
            }
        }

        // Defense in depth for the legacy resolution path: a handful of
        // explicitly-known legacy models (and catalog entities) are allowed to
        // run unguarded, exactly as before. Any OTHER legacy-resolved model must
        // be tenant-scoped (BelongsToTenant) — a model lacking it would otherwise
        // dump every tenant's rows via the unguarded default branch below.
        $legacyAllowList = ['user', 'person', 'event', 'eventserviceperson', 'notification', 'collaborator'];
        $isCatalogEntity = str_starts_with(get_class($model), 'Modules\\Catalog\\Entities\\');
        $isAllowedLegacyModel = in_array(strtolower((string) $request['_model']), $legacyAllowList, true);

        if (! $isAllowedLegacyModel && ! $isCatalogEntity) {
            $isTenantScoped = in_array(BelongsToTenant::class, class_uses_recursive($model), true);

            if (! $isTenantScoped) {
                throw new AuthorizationException('No tiene permisos para ejecutar esta acción.');
            }
        }

        $customQuery = false;
        if (($request['protocol_id'] ?? null) && $request['protocol_id'] == 'Not-Required') {
            $request = $this->request->except(['protocol_id']);
        }

        switch ($request['_model']) {
            case 'User':
                $customQuery = true;
                $data = ApiHandler::parseMultiple($model, [], request()->except(['profile_id']))
                    ->getBuilder();
                if ($request['profile_id'] ?? null) {
                    $data = $data->whereIn('id', function ($query) use ($request) {
                        $query->select('user_id')->from('role_user')->where('role_id', $request['profile_id']);
                    });
                }

                $data = $data->orderBy('created_at', 'DESC')->get();
                $columns = $model->reportColumns();
                break;
            case 'Notification':
                $model->orderBy('created_at', 'DESC');

                $columns = [
                    'Titulo' => function ($result) {
                        return $result->title;
                    },
                    'Descripcion' => function ($result) {
                        return $result->titulo;
                    },
                    'F. registro' => function ($result) {
                        return $result->created_at ? $result->created_at->format('d/m/Y') : '';
                    },
                ];
                break;
            case 'Event':
                $columns = $model->reportColumns();
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [
                    'name',
                    'description',
                    'location',
                ])
                    ->getBuilder();

                $columns = $model->reportColumns();
                $data = $query->get();
                break;
            case 'EventServicePerson':
                // Agrupar por persona y concatenar nombres de eventos
                $columns = [
                    'ID de beneficiario' => 'code',
                    'Beneficiario' => 'full_name',
                    'Eventos' => 'eventos',
                ];
                $customQuery = true;
                DB::statement('SET SESSION group_concat_max_len = 100000');
                DB::statement('SET SESSION sql_mode = ""');
                $query = ApiHandler::parseMultiple($model, [
                    'name',
                    'description',
                    'location',
                ])
                    ->getBuilder();
                $query = $query
                    ->join('event', 'event_service_person.event_id', '=', 'event.id')
                    ->join('service', 'event_service_person.service_id', '=', 'service.id')
                    ->join('person', 'event_service_person.person_id', '=', 'person.id')
                    ->whereNull('event_service_person.deleted_at')
                    ->select([
                        'event_service_person.*',
                        DB::raw('GROUP_CONCAT(service.name ORDER BY service.name SEPARATOR ", ") as services'),
                    ])
                    ->groupBy('person.id');

                $columns = $model->reportColumns();
                $data = $query->get();
                break;
            case 'Person':
                $columns = $model->reportColumns();
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [
                    'name',
                    'last_primary_name',
                    'last_second_name',
                    'section',
                    'phone',
                    'code',
                ], $this->request->except(['_birth_date_today']))
                    ->getBuilder();

                if (isset($request['_birth_date_today']) && $request['_birth_date_today'] == 'true') {
                    // detect if birthday is today
                    $query = $query->whereRaw('MONTH(birth_date) = MONTH(NOW()) AND DAY(birth_date) = DAY(NOW())');
                }

                if (isset($request['_filter_image']) && $request['_filter_image'] == 'true') {
                    // filter media with group person-image
                    $query = $query->whereHas('images', function ($query) {
                        $query->where('group', 'person-image');
                    });
                }

                $columns = $model->reportColumns();
                $data = $query->get();

                break;
            case 'PersonVisit':
                $columns = $model->reportColumns();
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [])
                    ->getBuilder();

                // Usar lazy() para procesar en chunks con eager loading
                $data = $query
                    ->select('person_visit.*')
                    ->join('person', 'person_visit.person_id', '=', 'person.id')
                    ->with(['images', 'person.zone'])
                    ->orderBy('person.zone_id', 'ASC')
                    ->orderBy('created_at', 'DESC')
                    ->lazy(500);
                break;

            case 'Collaborator':
                // Export especializado: dos hojas (Empresa + Colaboradores)
                $customQuery = true;
                $collaboratorData = ApiHandler::parseMultiple($model)
                    ->getBuilder()
                    ->with(['company', 'nationality', 'job_position', 'area', 'contract_type'])
                    ->orderBy('created_at', 'DESC')
                    ->get();

                return app(CollaboratorExportService::class)->generateExcel(
                    $request['_title'],
                    $collaboratorData,
                    $model->reportColumns(),
                    $model->companyReportColumns()
                );

            default:
                $model->orderBY('created_at', 'DESC');
                $columns = $model->reportColumns();
                break;
        }

        if ($request['_model'] === 'AccountPayable' || $request['_model'] === 'PatientExpenseReport') {
            $data = $model->fakeData();
        } else {
            if (! $customQuery) {
                $data = ApiHandler::parseMultiple($model)->getBuilder()->get();
            }
        }

        switch ($request['_type']) {
            case 'xlsx':
                return $this->generateExcel([
                    'title' => $request['_title'],
                    'data' => $data,
                    'columns' => $columns,
                    'model' => $request['_model'], // Pasar modelo para evitar inspeccionar datos
                ]);
                break;
            case 'pdf':
                return $this->generatePDF('report.templatePdf', [
                    'title' => $request['_title'],
                    'data' => $data,
                    'columns' => $columns,
                ]);
                break;
        }
    }

    protected function mappedModelReport(array $request)
    {
        // Mapped models only ever export to xlsx. PDF (dompdf) is the historic
        // crash vector for large datasets and was never wired for these models
        // beyond the legacy switch — reject it explicitly instead of silently
        // falling through.
        if (($request['_type'] ?? 'xlsx') !== 'xlsx') {
            return response()->json(['message' => 'Solo se soporta exportar a Excel (xlsx)'], 422);
        }

        $config = self::REPORT_MODEL_MAP[$request['_model']];
        $model = app($config['class']);

        // Fail-closed guard (non-Audit mapped models): TenantScope only narrows
        // the query WHEN tenancy is initialized — it silently no-ops otherwise
        // (e.g. a de-provisioned user whose still-valid 60-min token carries
        // report.* abilities, hitting the endpoint without an X-Tenant-Id/tenant
        // membership). Audit keeps its own dedicated guard below; every other
        // mapped model must either have an initialized tenant scope or a
        // wildcard ('*') token — never an unscoped full-table dump.
        if ($request['_model'] !== 'Audit') {
            $user = auth()->guard('sanctum')->user();
            if (! tenancy()->initialized && ! ($user && $user->tokenCan('*'))) {
                throw new AuthorizationException('No tiene permisos para ejecutar esta acción.');
            }
        }

        // Audit does not use SoftDeletes (unlike the other 14 mapped models, which
        // all extend BaseModel): the shared Parser (core/src/Http/Api/Parser.php)
        // unconditionally calls withTrashed() when building the query, which is
        // undefined on a model without that trait. Build its query directly.
        $builder = $request['_model'] === 'Audit'
            ? $model->newQuery()->with($config['with'])
            : ApiHandler::parseMultiple($model)->getBuilder()->with($config['with']);
        $this->applyRequestFilters($builder, $model);

        if ($request['_model'] === 'Audit') {
            $builder = $this->applyAuditTenantGuard($builder);
        }

        $columns = $model->reportColumns();
        $title = $request['_title'] ?? 'reporte';

        // Apply the list's own sort when its field is whitelisted; otherwise fall
        // back to created_at desc. Either way, append an `id` tie-break: bulk
        // inserts sharing the exact same created_at (or requested sort value)
        // would otherwise duplicate/skip rows across lazy(500)'s offset-based
        // pagination boundaries.
        $sortField = $this->resolveSortField($request, $model);
        if ($sortField !== null) {
            $builder->orderBy($sortField['field'], $sortField['direction']);
        } else {
            $builder->orderBy('created_at', 'desc');
        }
        $builder->orderBy('id', 'desc');

        // Hybrid size strategy: cheap COUNT (cloned BEFORE the orderBy tie-break
        // above — filters/audit guard already applied) decides between the small
        // in-memory styled path and the chunked streaming path used by the async
        // export.
        $count = (clone $builder)->reorder()->count();
        $threshold = config('excel.exports.sync_row_threshold', 5000);

        if ($count > $threshold) {
            $this->cleanOldReportFiles();

            $data = $builder->lazy(500);
            $safeTitle = Str::slug($title, '_');
            // Unique component (tenant id when initialized + a random token) so
            // two exports landing in the same second never collide on the same
            // stored filename — Xlsx::save() writes in place and
            // deleteFileAfterSend() deletes mid-flight, so a same-second
            // cross-tenant/cross-request race could overwrite or truncate
            // another download still being streamed. The user-facing download
            // name (second arg to response()->download()) stays the clean,
            // human-readable slug.
            $uniqueSuffix = (tenancy()->initialized ? Str::substr((string) tenant('id'), 0, 8).'_' : '').Str::random(8);
            $fileName = $safeTitle.'_'.now()->format('Ymd_His').'_'.$uniqueSuffix.'.xlsx';
            $filePath = 'temp/reports/'.$fileName;

            $this->generateExcelToFile($data, $columns, $title, $filePath);

            return response()->download(storage_path('app/'.$filePath), $safeTitle.'.xlsx')->deleteFileAfterSend(true);
        }

        $data = $builder->get();

        return $this->generateExcel([
            'title' => $title,
            'data' => $data,
            'columns' => $columns,
            'model' => $request['_model'],
        ]);
    }

    /**
     * ApiHandler::parseMultiple() reads ONLY the query string (ApiHandler.php:44-45);
     * useBaseList.onReport sends Orion-style filters in the JSON POST body. Translate
     * them here. Whitelist: the model's actual table columns (see filterableColumns()).
     * Dotted (relation) fields and unknown operators are skipped per-filter
     * (documented export gaps), never fatal.
     *
     * Every applied clause is AND-only (where/whereIn/whereNotIn/whereNull/
     * whereNotNull/whereBetween) so filters can only NARROW the result set —
     * this is what makes allowing a user-supplied `tenant_id` filter safe: it
     * AND-combines with TenantScope (14 models) or the Audit tenant guard, so a
     * mismatching value yields zero rows, never another tenant's data.
     */
    protected function applyRequestFilters($builder, $model): void
    {
        $filters = request()->input('filters', []);
        if (! is_array($filters)) {
            return;
        }

        $allowed = $this->filterableColumns($model);

        foreach ($filters as $filter) {
            $field = $filter['field'] ?? null;
            $operator = $this->normalizeFilterOperator((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;

            if (! is_string($field) || str_contains($field, '.') || ! in_array($field, $allowed, true)) {
                continue;
            }
            if ($operator === null) {
                continue;
            }

            match ($operator) {
                'in' => $builder->whereIn($field, (array) $value),
                'not in' => $builder->whereNotIn($field, (array) $value),
                'is_null' => $builder->whereNull($field),
                'is_not_null' => $builder->whereNotNull($field),
                'between' => (is_array($value) && count($value) === 2)
                    ? $builder->whereBetween($field, [$value[0], $value[1]])
                    : null,
                default => $builder->where($field, $operator, $value),
            };
        }
    }

    /**
     * Normalizes filter operators coming from the frontend. useBaseList/Orion
     * conventions emit underscore forms (`not_like`, `not_in`) and null-checks
     * (`is_null`, `is_not_null`) that the previous whitelist did not recognize —
     * it only accepted the space forms the frontend never actually sends, so
     * those filters were silently dropped (e.g. AccessLog's "Dentro/Salieron/
     * Denegados" filter on exited_at/denied_at exported the FULL bitácora
     * instead of the filtered subset). Returns null for anything unsupported.
     */
    protected function normalizeFilterOperator(string $operator): ?string
    {
        $operator = strtolower($operator);

        $aliases = [
            'not_like' => 'not like',
            'not_in' => 'not in',
        ];
        $operator = $aliases[$operator] ?? $operator;

        $allowedOperators = [
            '=', '!=', '>', '>=', '<', '<=', 'like', 'not like', 'in', 'not in',
            'is_null', 'is_not_null', 'between',
        ];

        return in_array($operator, $allowedOperators, true) ? $operator : null;
    }

    /**
     * Real table columns for $model, used as the export filter/sort whitelist
     * (replaces the old getFillable()-based whitelist, which excluded
     * legitimately-filterable non-fillable columns like AccessLog.exited_at/
     * denied_at). Cached per table for the life of the worker — this is schema
     * metadata shared by every tenant (single shared DB), not tenant data, so a
     * process-lifetime cache is safe and avoids a repeat DESCRIBE per export.
     */
    protected function filterableColumns($model): array
    {
        static $cache = [];
        $table = $model->getTable();

        return $cache[$table] ??= Schema::getColumnListing($table);
    }

    /**
     * Resolves the request body's `sort` (Orion-style `[{field, direction}]`)
     * against the same column whitelist as applyRequestFilters(). Only the
     * first entry is honored — mappedModelReport() always appends its own `id`
     * tie-break after whatever this returns, so multi-column sort collapses to
     * (requested field, id), which is deterministic and sufficient for exports.
     * Returns null when no valid sort was supplied, so the caller falls back
     * to its default (`created_at desc`).
     *
     * @return array{field: string, direction: string}|null
     */
    protected function resolveSortField(array $request, $model): ?array
    {
        $sort = $request['sort'] ?? null;
        if (! is_array($sort) || ! isset($sort[0]) || ! is_array($sort[0])) {
            return null;
        }

        $field = $sort[0]['field'] ?? null;
        if (! is_string($field) || str_contains($field, '.') || ! in_array($field, $this->filterableColumns($model), true)) {
            return null;
        }

        $direction = strtolower((string) ($sort[0]['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        return ['field' => $field, 'direction' => $direction];
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

    /**
     * Genera un archivo Excel (restaurado al original con optimizaciones de config)
     */
    public function generateExcel($info)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '4096M'); // 4GB para reportes con imágenes
        header('Expires: Thu, 01-Jan-70 00:00:01 GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        $reportExport = app(ReportExport::class);
        $reportExport->setTitle($info['title']);

        // Usar nombre del modelo para determinar el tipo (evita consumir el iterator)
        $modelName = $info['model'] ?? null;

        // --- SOLO PARA PERSONAS ---
        if ($modelName === 'Person') {
            // Convertir a Collection porque necesitamos iterar dos veces (datos + imágenes)
            $data = $info['data'] instanceof LazyCollection
                ? $info['data']->collect()
                : $info['data'];
            $columns = $info['columns'];
            $columnsKeys = array_keys($columns);
            $columnsValues = array_values($columns);

            $maxImages = 1;

            $newColumns = [];
            $newMap = [];
            foreach ($columnsKeys as $i => $col) {
                if ($col === 'Foto') {
                    $newColumns[] = $col;
                    $newMap[] = function ($person) {
                        return '';
                    };
                    for ($j = 1; $j <= $maxImages; $j++) {
                        $newColumns[] = 'INE ';
                        $newMap[] = function ($person) {
                            return '';
                        };
                    }
                } else {
                    $newColumns[] = $col;
                    $newMap[] = $columnsValues[$i];
                }
            }

            $reportExport->setHeadings($newColumns);
            $reportExport->setMap($newMap);
            $reportExport->setCollection($data);

            $rowIndex = 3;
            $fotoColIndex = array_search('Foto', $newColumns);
            $imageHeight = 50; // Reducido para menor consumo de memoria
            $storageBasePath = storage_path('app/public/');

            foreach ($data as $person) {
                if (isset($person->photo) && ! empty($person->photo->media)) {
                    $fotoCol = chr(66 + $fotoColIndex - 1);
                    $imagePath = $storageBasePath.$person->photo->media;
                    if (is_readable($imagePath)) {
                        $reportExport->addImage($imagePath, $fotoCol.$rowIndex, $imageHeight);
                    }
                }
                for ($i = 0; $i < $maxImages; $i++) {
                    if (isset($person->images[$i]) && ! empty($person->images[$i]->media)) {
                        $imgColIndex = $fotoColIndex + 1 + $i;
                        $imgCol = chr(66 + $imgColIndex - 1);
                        $imagePath = $storageBasePath.$person->images[$i]->media;
                        if (is_readable($imagePath)) {
                            $reportExport->addImage($imagePath, $imgCol.$rowIndex, $imageHeight);
                        }
                    }
                }
                $rowIndex++;

                // Limpiar memoria cada 50 registros (más agresivo)
                if ($rowIndex % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        }
        // --- SOLO PARA VISITAS DE PERSONAS ---
        elseif ($modelName === 'PersonVisit') {
            // Convertir a Collection porque necesitamos iterar dos veces (datos + imágenes)
            $data = $info['data'] instanceof LazyCollection
                ? $info['data']->collect()
                : $info['data'];
            $columns = $info['columns'];
            $columnsKeys = array_keys($columns);
            $columnsValues = array_values($columns);

            $maxImages = 1;

            $newColumns = [];
            $newMap = [];
            foreach ($columnsKeys as $i => $col) {
                if ($col === 'Imagen') {
                    $newColumns[] = $col;
                    $newMap[] = function ($personVisit) {
                        return '';
                    };
                    for ($j = 1; $j < $maxImages; $j++) {
                        $newColumns[] = 'Imagen '.($j + 1);
                        $newMap[] = function ($personVisit) {
                            return '';
                        };
                    }
                } else {
                    $newColumns[] = $col;
                    $newMap[] = $columnsValues[$i];
                }
            }

            $reportExport->setHeadings($newColumns);
            $reportExport->setMap($newMap);
            $reportExport->setCollection($data);

            $rowIndex = 3;
            $imagenColIndex = array_search('Imagen', $newColumns);
            $imageHeight = 50; // Reducido para menor consumo de memoria
            $storageBasePath = storage_path('app/public/');

            foreach ($data as $personVisit) {
                if (isset($personVisit->images) && $personVisit->images->isNotEmpty() && ! empty($personVisit->images->first()->media)) {
                    $imagenCol = chr(66 + $imagenColIndex - 1);
                    $imagePath = $storageBasePath.$personVisit->images->first()->media;
                    if (is_readable($imagePath)) {
                        $reportExport->addImage($imagePath, $imagenCol.$rowIndex, $imageHeight);
                    }
                }
                for ($i = 1; $i < $maxImages; $i++) {
                    if (isset($personVisit->images[$i]) && ! empty($personVisit->images[$i]->media)) {
                        $imgColIndex = $imagenColIndex + $i;
                        $imgCol = chr(66 + $imgColIndex - 1);
                        $imagePath = $storageBasePath.$personVisit->images[$i]->media;
                        if (is_readable($imagePath)) {
                            $reportExport->addImage($imagePath, $imgCol.$rowIndex, $imageHeight);
                        }
                    }
                }
                $rowIndex++;

                // Limpiar memoria cada 50 registros (más agresivo)
                if ($rowIndex % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        } else {
            // --- PARA OTROS MODELOS ---
            $reportExport->setHeadings(array_keys($info['columns']));
            $reportExport->setMap(array_values($info['columns']));
            $reportExport->setCollection($info['data']);
        }

        $fileName = $info['title'].'.xlsx';

        // Limpiar memoria antes de generar
        gc_collect_cycles();

        return Excel::download($reportExport, $fileName);
    }

    public function generatePDF($template, $info)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '2048M');
        try {
            $pdf = Pdf::loadView($template, ['info' => $info])->setPaper('a4', 'landscape');
            echo $pdf->stream();
        } catch (\Exception $e) {
            return $this->response->error($e->getMessage(), 500);
        }
    }

    /**
     * Genera un reporte básico
     *
     * @return BinaryFileResponse
     */
    public function generate(Request $request)
    {
        // Lógica para obtener la colección de datos
        $collection = collect([]); // Reemplazar con tu colección real

        $export = new ReportExport;
        $export->setCollection($collection);
        $export->setTitle('Título del Reporte');
        $export->setHeadings(['Columna 1', 'Columna 2', 'Columna 3']);
        $export->setMap([
            'columna1' => 'campo1',
            'columna2' => 'campo2',
            'columna3' => 'campo3',
        ]);

        return Excel::download($export, 'reporte.xlsx');
    }

    /**
     * Genera un reporte de personas con fotografías
     *
     * @return BinaryFileResponse
     */
    public function generatePersonReport(Request $request)
    {
        // Obtener colección de personas
        $persons = Person::query();

        // Aplicar filtros si es necesario
        if ($request->has('nombre')) {
            $persons->where('nombre', 'like', '%'.$request->input('nombre').'%');
        }

        // Cargar relaciones necesarias y obtener resultados
        $persons = $persons->with('photo')->get();

        // Configurar el reporte
        $export = new ReportExport;
        $export->setCollection($persons);
        $export->setTitle('Reporte de Personas');
        $export->setHeadings(['ID', 'Nombre', 'Apellido', 'Correo', 'Teléfono', 'Foto']);

        // Configurar el mapeo de datos
        $export->setMap([
            'id' => 'id',
            'nombre' => 'nombre',
            'apellido' => 'apellido',
            'correo' => 'email',
            'telefono' => 'telefono',
            'foto' => function ($person) {
                return ''; // Celda vacía, la foto se mostrará como imagen
            },
        ]);

        // Añadir imágenes a las celdas específicas
        // Considerando que los encabezados están en las filas 1 y 2, los datos comienzan en la fila 3
        $rowIndex = 3;
        foreach ($persons as $person) {
            if (isset($person->photo) && ! empty($person->photo->path)) {
                $imagePath = storage_path('app/public/'.$person->photo->path);

                if (file_exists($imagePath)) {
                    // Añadir imagen en la columna F (correspondiente a 'Foto')
                    $export->addImage(
                        $imagePath,      // Ruta de la imagen
                        'F'.$rowIndex, // Coordenada de la celda (columna F, fila actual)
                        50               // Altura
                    );
                }
            }
            $rowIndex++;
        }

        // Opcional: ocultar el logo si no es necesario
        // $export->hideLogo();

        // Generar y descargar el archivo Excel
        return Excel::download($export, 'reporte_personas.xlsx');
    }

    /**
     * Genera el reporte y devuelve URL firmada para descarga
     */
    public function generateReportAsync()
    {
        // Limpiar archivos viejos antes de generar uno nuevo
        $this->cleanOldReportFiles();

        $request = $this->request->all();

        if (! isset($request['_model'])) {
            return $this->response->error('Ocurrió un error en la generación del reporte', 500);
        }

        // Reusar lógica existente para obtener data y columns
        $result = $this->getReportData($request);
        if (isset($result['error'])) {
            return $this->response->error($result['error'], 500);
        }

        $data = $result['data'];
        $columns = $result['columns'];
        $type = $request['_type'] ?? 'xlsx';
        $title = $request['_title'] ?? 'reporte';

        // Sanitizar nombre
        $safeTitle = Str::slug($title, '_');
        $fileName = $safeTitle.'_'.now()->format('Ymd_His').'.'.$type;
        $filePath = 'temp/reports/'.$fileName;

        // Generar archivo
        if ($type === 'xlsx') {
            $this->generateExcelToFile($data, $columns, $title, $filePath);
        } else {
            return $this->response->error('Solo xlsx soportado para descarga async', 400);
        }

        // Generar URL firmada (expira en 30 minutos)
        $downloadUrl = URL::signedRoute('report.download', ['file' => $fileName], now()->addMinutes(30));

        return $this->response->success([
            'data' => [
                'download_url' => $downloadUrl,
                'file_name' => $fileName,
                'expires_in' => 30,
            ],
        ]);
    }

    /**
     * Descarga el archivo con URL firmada
     */
    public function downloadReport(Request $request, string $file)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'URL expirada o inválida');
        }

        $filePath = 'temp/reports/'.$file;

        if (! Storage::disk('local')->exists($filePath)) {
            abort(404, 'Archivo no encontrado');
        }

        $fullPath = storage_path('app/'.$filePath);

        return response()->download($fullPath, $file);
    }

    /**
     * Extrae la lógica de obtención de datos del reporte
     */
    protected function getReportData(array $request): array
    {
        $class = 'Modules\\'.$request['_model'].'\\Entities\\'.$request['_model'];

        if ($request['_model'] == 'User') {
            $class = 'App\\Models\\User';
        }
        if ($request['_model'] == 'EventServicePerson') {
            $class = EventServicePerson::class;
        }
        if ($request['_model'] == 'Audit') {
            $class = 'App\\Modules\\Audit\\Audit';
        }
        if ($request['_model'] == 'PersonVisit') {
            $class = PersonVisit::class;
        }

        if (class_exists($class)) {
            $model = app($class);
        } else {
            if (! class_exists($class)) {
                $model = app('Modules\\Catalog\\Entities\\'.$request['_model']);
            }
        }

        $customQuery = false;
        if (($request['protocol_id'] ?? null) && $request['protocol_id'] == 'Not-Required') {
            $request = collect($request)->except(['protocol_id'])->toArray();
        }

        switch ($request['_model']) {
            case 'User':
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [], request()->except(['profile_id']))
                    ->getBuilder();
                if ($request['profile_id'] ?? null) {
                    $query = $query->whereIn('id', function ($q) use ($request) {
                        $q->select('user_id')->from('role_user')->where('role_id', $request['profile_id']);
                    });
                }
                $data = $query->orderBy('created_at', 'DESC')->cursor();
                $columns = $model->reportColumns();
                break;
            case 'Event':
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, ['name', 'description', 'location'])->getBuilder();
                $columns = $model->reportColumns();
                $data = $query->cursor();
                break;
            case 'Person':
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [
                    'name', 'last_primary_name', 'last_second_name', 'section', 'phone', 'code',
                ], collect($request)->except(['_birth_date_today'])->toArray())->getBuilder();

                if (isset($request['_birth_date_today']) && $request['_birth_date_today'] == 'true') {
                    $query = $query->whereRaw('MONTH(birth_date) = MONTH(NOW()) AND DAY(birth_date) = DAY(NOW())');
                }
                if (isset($request['_filter_image']) && $request['_filter_image'] == 'true') {
                    $query = $query->whereHas('images', function ($q) {
                        $q->where('group', 'person-image');
                    });
                }
                $columns = $model->reportColumns();
                $data = $query->cursor();
                break;
            case 'PersonVisit':
                $customQuery = true;
                $query = ApiHandler::parseMultiple($model, [])->getBuilder();
                // Usar lazy() en lugar de cursor() para que funcione el eager loading
                $data = $query->select('person_visit.*')
                    ->join('person', 'person_visit.person_id', '=', 'person.id')
                    ->with(['images', 'person.zone'])
                    ->orderBy('person.zone_id', 'ASC')
                    ->orderBy('created_at', 'DESC')
                    ->lazy(500); // Procesa en chunks de 500
                $columns = $model->reportColumns();
                break;
            default:
                $model->orderBY('created_at', 'DESC');
                $columns = $model->reportColumns();
                break;
        }

        if (! $customQuery) {
            $data = ApiHandler::parseMultiple($model)->getBuilder()->cursor();
        }

        return ['data' => $data, 'columns' => $columns];
    }

    /**
     * Eleva el `memory_limit` al valor pedido SOLO si el vigente es menor.
     *
     * Un `ini_set('memory_limit', ...)` a secas es bidireccional: también REDUCE
     * el techo, descartando el headroom que el entorno haya concedido a
     * propósito (php-fpm, un worker de cola, o el propio `phpunit.xml`). Un
     * límite de `-1` (sin tope) se respeta y nunca se degrada a un valor finito.
     */
    protected function raiseMemoryLimitTo(string $target): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '-1' || $current === '') {
            return;
        }

        if ($this->memoryLimitToBytes($current) >= $this->memoryLimitToBytes($target)) {
            return;
        }

        ini_set('memory_limit', $target);
    }

    /**
     * Convierte la notación abreviada de PHP (`512M`, `2G`, `1024K`) a bytes.
     */
    protected function memoryLimitToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Genera Excel y lo guarda en storage (optimizado por chunks)
     */
    protected function generateExcelToFile($data, $columns, $title, $filePath): void
    {
        ini_set('max_execution_time', 0);
        // Esta es la ruta de streaming para los datasets MÁS grandes, y tenía el
        // techo MÁS BAJO de las tres rutas de reporte (generateExcel: 4096M,
        // generatePDF: 2048M). Peor aún, `ini_set` también BAJA el límite: pisaba
        // el headroom que el entorno hubiera concedido a propósito. Eso mataba al
        // suite de tests con "Allowed memory size of 536870912 bytes exhausted"
        // dentro de PhpSpreadsheet aunque phpunit.xml otorgara más memoria.
        $this->raiseMemoryLimitTo('2048M');

        $fullPath = storage_path('app/'.$filePath);

        // Asegurar que existe el directorio. NOTA (bug preexistente detectado):
        // Storage::disk('local') apunta a storage/app/private (default de
        // Laravel 11+), pero $fullPath usa storage_path('app/...') sin
        // "private" — Storage::disk('local')->makeDirectory() creaba el
        // directorio equivocado y dejaba el que realmente usa save() sin
        // crear. Crear el directorio real con la ruta que efectivamente se
        // escribe.
        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        // Crear spreadsheet directamente para mejor control de memoria
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Escribir título en fila 1
        $sheet->setCellValue('A1', '');
        $sheet->setCellValue('B1', $title);
        $sheet->getRowDimension(1)->setRowHeight(50);

        // Escribir headers en fila 2
        $colIndex = 1;
        foreach (array_keys($columns) as $header) {
            $sheet->setCellValueByColumnAndRow($colIndex, 2, $header);
            $colIndex++;
        }

        // Escribir datos por chunks
        $rowIndex = 3;
        $chunkSize = 500;
        $mapValues = array_values($columns);

        // Si es LazyCollection o cursor, iterar directamente
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($mapValues as $colData) {
                $value = is_callable($colData) ? $colData($row) : ($row->{$colData} ?? '');
                // reportColumns() closures are expected to return scalars, but
                // guard against a stray Money/value-object slipping through
                // (e.g. cknow/laravel-money's Money implements Stringable) —
                // PhpSpreadsheet's value binder rejects non-scalar cell values.
                if ($value instanceof \Stringable) {
                    $value = (string) $value;
                }
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                $colIndex++;
            }
            $rowIndex++;

            // Liberar memoria cada chunk
            if ($rowIndex % $chunkSize === 0) {
                gc_collect_cycles();
            }
        }

        // Aplicar estilos básicos
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $lastRow = $rowIndex - 1;

        // Estilo para headers
        $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true);

        // Ancho fijo para todas las columnas (más rápido que AutoSize)
        $defaultWidth = 18;
        for ($i = 1; $i <= count($columns); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setWidth($defaultWidth);
        }

        // Guardar archivo
        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        // Liberar memoria
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
    }

    /**
     * Limpia archivos temporales viejos (reportes y cache de Excel)
     */
    protected function cleanOldReportFiles(): void
    {
        $now = time();
        $maxAge = 30 * 60; // 30 minutos

        // Limpiar reportes temporales
        $reportsDir = storage_path('app/temp/reports');
        if (is_dir($reportsDir)) {
            foreach (glob($reportsDir.'/*') as $file) {
                if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                    @unlink($file);
                }
            }
        }

        // Limpiar cache de Excel (storage/framework/cache/data/)
        $cacheDir = storage_path('framework/cache/data');
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir.'/*') as $file) {
                if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                    @unlink($file);
                }
            }
        }

        // Limpiar temporales de laravel-excel
        $excelDir = storage_path('framework/laravel-excel');
        if (is_dir($excelDir)) {
            foreach (glob($excelDir.'/*') as $file) {
                if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                    @unlink($file);
                }
            }
        }
    }
}
