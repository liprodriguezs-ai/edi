<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Access\Models\AccessEntryType;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\IOFactory;
use QCH\Core\Http\Controllers\ReportExport;
use QCH\Core\Models\Role;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the hardened ReportController::customReport() endpoint
 * (export-excel-lists spec: custom-report-endpoint).
 */
final class CustomReportTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 999999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    private function makeCharge(Tenant $tenant, Unit $unit, int $expectedAmount = 50000): Charge
    {
        $charge = null;
        $tenant->run(function () use ($unit, $expectedAmount, &$charge): void {
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $batchStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            $concept = ChargeConcept::create([
                'code' => 'EXP-'.Str::random(4),
                'name' => 'Mantenimiento',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política de prueba',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'periodicity_id' => Periodicity::MONTHLY,
                'fixed_amount' => $expectedAmount,
                'valid_from' => '2026-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Lote de prueba',
                'year' => 2026,
                'month' => 1,
                'target_type' => 'all',
                'issued_at' => '2026-01-01',
                'due_date' => now()->addDays(15)->toDateString(),
                'charge_batch_status_id' => $batchStatus->id,
                'progress_pct' => 0,
            ]);

            $charge = Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => $expectedAmount,
                'paid_amount' => 0,
                'issued_at' => now(),
                'due_date' => now()->addDays(15),
                'charge_status_id' => $pendingStatus->id,
                'is_cancelled' => false,
            ]);
        });

        return $charge;
    }

    /**
     * A central user with no tenant membership — used for the platform-role
     * (Super Admin/Lawyer) seam, which operates unscoped by design.
     */
    private function makeCentralUser(): User
    {
        return User::create([
            'name' => 'Central '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    // =========================================================================
    // 1. Sanctum Ability Authorization
    // =========================================================================

    public function test_export_is_forbidden_without_report_ability(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unit);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['read.charge'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos',
            ])
            ->assertForbidden();
    }

    public function test_export_succeeds_with_specific_report_ability(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unit);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos',
            ])
            ->assertOk();

        Excel::assertDownloaded('cargos.xlsx');
    }

    public function test_export_succeeds_with_wildcard_ability(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unit);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos',
            ])
            ->assertOk();

        Excel::assertDownloaded('cargos.xlsx');
    }

    // =========================================================================
    // 2. Audit Cross-Tenant Isolation Guard
    // =========================================================================

    public function test_audit_export_is_scoped_to_the_current_tenant(): void
    {
        Excel::fake();

        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        // Audit::save() defers the insert to DB::afterCommit() when inside a
        // transaction; under RefreshDatabase the wrapper transaction never
        // commits, so create()/saveQuietly() rows silently never persist.
        // Insert via the query builder instead (bypasses the model's save()).
        $auditIdA = (string) Str::uuid();
        $auditIdB = (string) Str::uuid();

        DB::table('audits')->insert([
            'id' => $auditIdA,
            'event' => 'created',
            'auditable_type' => 'Modules\\Collections\\Models\\Charge',
            'auditable_id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'old_values' => json_encode([]),
            'new_values' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audits')->insert([
            'id' => $auditIdB,
            'event' => 'created',
            'auditable_type' => 'Modules\\Collections\\Models\\Charge',
            'auditable_id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'old_values' => json_encode([]),
            'new_values' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/customReport', [
                '_model' => 'Audit',
                '_type' => 'xlsx',
                '_title' => 'auditoria',
            ])
            ->assertOk();

        Excel::assertDownloaded('auditoria.xlsx', function (ReportExport $export) use ($auditIdA, $auditIdB) {
            $ids = $export->collection()->pluck('id')->all();

            return in_array($auditIdA, $ids, true) && ! in_array($auditIdB, $ids, true);
        });
    }

    /**
     * Fail-closed branch of applyAuditTenantGuard(): tenancy NOT initialized +
     * a non-wildcard token must return ZERO rows, never the seeded ones.
     *
     * Reaching "tenancy not initialized" over real HTTP is NOT platform-role
     * exclusive. Two real seams:
     *   1. A platform-role user (Super Admin/Lawyer, per
     *      ActiveTenantResolver::resolveForPlatformRole) hitting the endpoint
     *      WITHOUT an X-Tenant-Id header — covered by this test.
     *   2. A REGULAR user with ZERO tenant memberships (e.g. de-provisioned from
     *      every cluster while their 60-min token is still valid):
     *      ActiveTenantResolver::resolveForRegularUser() returns null when
     *      `$user->tenants()->get()` is empty, so InitializeTenancyFromUser never
     *      calls tenancy()->initialize() — covered by
     *      test_non_audit_mapped_model_export_fails_closed_when_tenancy_is_not_initialized()
     *      below (a non-Audit mapped model, mappedModelReport()'s own fail-closed
     *      guard). A regular user with AT LEAST ONE membership always resolves to
     *      a tenant, so that sub-case only matters when membership is empty.
     * Either way, InitializeTenancyFromUser never aborts the request — it just
     * leaves tenancy uninitialized and calls next($request) — so the request
     * reaches the controller unscoped.
     */
    public function test_audit_export_fails_closed_when_tenancy_is_not_initialized(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        DB::table('audits')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'created',
            'auditable_type' => 'Modules\\Collections\\Models\\Charge',
            'auditable_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'old_values' => json_encode([]),
            'new_values' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // firstOrCreate() would silently drop guard_name/uuid (not mass-assignable
        // on Role — see $fillable); assign directly + save(), mirroring
        // SubscriptionEnforcementTest::test_super_admin_and_tenantless_users_are_never_blocked.
        $role = Role::firstOrNew(['uuid' => Role::SUPER_ADMIN]);
        $role->name = Role::SLUG_SUPER_ADMIN;
        $role->guard_name = 'api';
        $role->save();

        $superAdmin = $this->makeCentralUser();
        $superAdmin->assignRole(Role::SLUG_SUPER_ADMIN);

        // Ability only, no '*' — the ability check alone must not be enough to
        // bypass the tenant guard once inside applyAuditTenantGuard().
        $token = $superAdmin->createToken('sa', ['report.audit'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Deliberately NO X-Tenant-Id header: the platform-role branch of
        // ActiveTenantResolver then resolves no tenant, so InitializeTenancyFromUser
        // never calls tenancy()->initialize() and the request reaches the
        // controller with tenancy uninitialized.
        $this->withToken($token)
            ->postJson('/api/customReport', [
                '_model' => 'Audit',
                '_type' => 'xlsx',
                '_title' => 'auditoria',
            ])
            ->assertOk();

        Excel::assertDownloaded('auditoria.xlsx', function (ReportExport $export) {
            return $export->collection()->count() === 0;
        });
    }

    // =========================================================================
    // 3. Whitelisted Body-Filter Application
    // =========================================================================

    public function test_body_filters_narrow_the_export(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unitA);
        $this->makeCharge($tenant, $unitB);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos',
                'filters' => [
                    ['field' => 'unit_id', 'operator' => '=', 'value' => $unitA->id],
                ],
            ])
            ->assertOk();

        Excel::assertDownloaded('cargos.xlsx', function (ReportExport $export) use ($unitA) {
            $collection = $export->collection();

            return $collection->count() === 1 && $collection->first()->unit_id === $unitA->id;
        });
    }

    // =========================================================================
    // 4. Perf hardening — hybrid size strategy + xlsx-only mapped exports
    // =========================================================================

    /**
     * Above config('excel.exports.sync_row_threshold'), mappedModelReport()
     * streams via lazy(500) + generateExcelToFile() and returns a real file
     * download instead of routing through the Excel facade — Excel::fake()
     * does NOT intercept this path (generateExcelToFile builds a PhpSpreadsheet
     * Spreadsheet directly), so we assert on the HTTP response + the file
     * actually written to storage instead of Excel::assertDownloaded().
     */
    public function test_large_dataset_streams_to_a_file_download_instead_of_loading_into_memory(): void
    {
        config(['excel.exports.sync_row_threshold' => 2]);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        for ($i = 0; $i < 4; $i++) {
            $this->makeCharge($tenant, $unit);
        }

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $reportsDir = storage_path('app/temp/reports');
        $before = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos-grandes',
            ]);

        $response->assertOk();

        // Download name goes through Str::slug($title, '_') (same idiom as
        // generateReportAsync), which also normalizes existing hyphens to
        // underscores — "cargos-grandes" becomes "cargos_grandes.xlsx".
        $disposition = $response->headers->get('content-disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('cargos_grandes.xlsx', $disposition);

        $after = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];
        $newFiles = array_values(array_diff($after, $before));

        $this->assertNotEmpty($newFiles, 'Expected mappedModelReport() to write a streamed report file under temp/reports');
        $this->assertGreaterThan(0, filesize($newFiles[0]), 'Streamed report file must not be empty');

        // Test-side cleanup: the download response is never actually "sent" by
        // the HTTP test client (no send()/sendContent()), so deleteFileAfterSend
        // never fires here — remove the file we just asserted on.
        @unlink($newFiles[0]);
    }

    public function test_pdf_type_is_rejected_for_mapped_models(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unit);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'pdf',
                '_title' => 'cargos',
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Solo se soporta exportar a Excel (xlsx)']);
    }

    // =========================================================================
    // 5. Security-review hardening (export-excel-lists follow-up)
    // =========================================================================

    /**
     * C1 — a case-variant `_model` (e.g. lowercase "audit") must resolve through
     * the SAME guarded canonical path as the exact-case "Audit", never fall
     * through to the unguarded legacy resolver (PHP's class autoloading is
     * case-insensitive, so `Modules\audit\Models\audit` would otherwise happily
     * load `Modules\Audit\Models\Audit` and reach the UNGUARDED default branch).
     */
    public function test_case_variant_model_name_still_routes_through_the_guarded_audit_path(): void
    {
        Excel::fake();

        [$tenantA, , $tenantB] = $this->twoTenants();

        // A NON-wildcard token (report.audit only) tenant admin — the guard must
        // hold even without the wildcard-token shortcut.
        $adminA = $this->makeUser($tenantA);
        $tokenA = $adminA->createToken('t', ['report.audit'])->plainTextToken;

        $auditIdA = (string) Str::uuid();
        $auditIdB = (string) Str::uuid();

        DB::table('audits')->insert([
            'id' => $auditIdA,
            'event' => 'created',
            'auditable_type' => 'Modules\\Collections\\Models\\Charge',
            'auditable_id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'old_values' => json_encode([]),
            'new_values' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audits')->insert([
            'id' => $auditIdB,
            'event' => 'created',
            'auditable_type' => 'Modules\\Collections\\Models\\Charge',
            'auditable_id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'old_values' => json_encode([]),
            'new_values' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/customReport', [
                // Lowercase, deliberately NOT matching the REPORT_MODEL_MAP key
                // case ("Audit") exactly.
                '_model' => 'audit',
                '_type' => 'xlsx',
                '_title' => 'auditoria-lower',
            ])
            ->assertOk();

        Excel::assertDownloaded('auditoria-lower.xlsx', function (ReportExport $export) use ($auditIdA, $auditIdB) {
            $ids = $export->collection()->pluck('id')->all();

            return in_array($auditIdA, $ids, true) && ! in_array($auditIdB, $ids, true);
        });
    }

    /**
     * C2 — non-Audit mapped models must fail CLOSED when tenancy is not
     * initialized, exactly like Audit already does. TenantScope only narrows
     * WHEN tenancy is initialized; it silently no-ops otherwise. This is
     * reachable for a perfectly REGULAR (non-platform-role) user who has been
     * removed from every tenant while their 60-min token is still valid:
     * ActiveTenantResolver::resolveForRegularUser() returns null when the user
     * has zero tenant memberships, so InitializeTenancyFromUser never calls
     * tenancy()->initialize() — the request reaches the controller unscoped.
     */
    public function test_non_audit_mapped_model_export_fails_closed_when_tenancy_is_not_initialized(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeCharge($tenant, $unit);

        // A regular user, deliberately NEVER attached to any tenant (mirrors a
        // de-provisioned user) — NOT a super admin/lawyer.
        $deprovisionedUser = User::create([
            'name' => 'Deprovisioned '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        // Ability only, no '*' — the ability check alone must not be enough to
        // bypass the tenancy-initialized guard in mappedModelReport().
        $token = $deprovisionedUser->createToken('t', ['report.charge'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // No X-Tenant-Id header, and the user has no memberships at all, so
        // ActiveTenantResolver resolves null and tenancy is never initialized.
        $this->withToken($token)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos-fuga',
            ])
            ->assertForbidden();
    }

    /**
     * C3 — the streamed (>threshold) path's stored filename must be unique per
     * request. Before the fix, `slug_YmdHis.xlsx` had no tenant/random
     * component, so two exports in the same wall-clock second overwrote each
     * other on disk (the HTTP test client never actually triggers
     * deleteFileAfterSend, so a same-second collision leaves only ONE survivor
     * for TWO requests). Two back-to-back streamed exports must leave TWO
     * distinct files on disk, not one.
     */
    public function test_streamed_export_filenames_are_unique_across_back_to_back_requests(): void
    {
        config(['excel.exports.sync_row_threshold' => 2]);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        for ($i = 0; $i < 4; $i++) {
            $this->makeCharge($tenant, $unit);
        }

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $reportsDir = storage_path('app/temp/reports');
        $before = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];

        $payload = [
            '_model' => 'Charge',
            '_type' => 'xlsx',
            '_title' => 'cargos-concurrentes',
        ];

        $this->tenantRequest($token, $tenant)->postJson('/api/customReport', $payload)->assertOk();
        $this->tenantRequest($token, $tenant)->postJson('/api/customReport', $payload)->assertOk();

        $after = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];
        $newFiles = array_values(array_diff($after, $before));

        $this->assertCount(2, $newFiles, 'Two back-to-back streamed exports must produce TWO distinct stored files, not overwrite each other');
        $this->assertNotSame($newFiles[0], $newFiles[1]);
        foreach ($newFiles as $file) {
            $this->assertGreaterThan(0, filesize($file));
            @unlink($file);
        }
    }

    /**
     * L1 — lazy(500) with a non-unique `created_at` sort uses offset pagination;
     * rows sharing the exact same created_at (common for bulk-generated charges)
     * could duplicate/skip across chunk boundaries. Insert enough rows sharing
     * an IDENTICAL created_at to cross the 500-row lazy() chunk boundary, and
     * assert every single one survives the export exactly once.
     */
    public function test_streamed_export_includes_every_row_exactly_once_across_the_lazy_chunk_boundary(): void
    {
        config(['excel.exports.sync_row_threshold' => 100]);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $rowCount = 512;
        $sameCreatedAt = now();

        $tenant->run(function () use ($unit, $tenant, $rowCount, $sameCreatedAt): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $concept = ChargeConcept::create([
                'code' => 'EXP-'.Str::random(4),
                'name' => 'Mantenimiento masivo',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

            $rows = [];
            for ($i = 1; $i <= $rowCount; $i++) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'charge_batch_id' => null,
                    'charge_concept_id' => $concept->id,
                    'charge_policy_id' => null,
                    'unit_id' => $unit->id,
                    // Distinct, monotonically increasing amounts (in cents) —
                    // used afterwards as a unique fingerprint per row.
                    'expected_amount' => $i * 100,
                    'paid_amount' => 0,
                    'issued_at' => now()->toDateString(),
                    'due_date' => now()->addDays(15)->toDateString(),
                    'charge_status_id' => $pendingStatus->id,
                    'is_cancelled' => false,
                    // IDENTICAL created_at across every row — the exact
                    // condition that breaks offset-based lazy() pagination
                    // without a deterministic tie-break.
                    'created_at' => $sameCreatedAt,
                    'updated_at' => $sameCreatedAt,
                ];
            }

            // Bulk-insert bypassing Eloquent (fast, and lets us force an
            // identical created_at across all rows).
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('charge')->insert($chunk);
            }
        });

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $reportsDir = storage_path('app/temp/reports');
        $before = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos-boundary',
            ])
            ->assertOk();

        $after = is_dir($reportsDir) ? glob($reportsDir.'/*') : [];
        $newFiles = array_values(array_diff($after, $before));
        $this->assertNotEmpty($newFiles, 'Expected a streamed report file under temp/reports');

        $spreadsheet = IOFactory::load($newFiles[0]);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        // Row 1 = title, row 2 = headers, data starts at row 3.
        $dataRowCount = $highestRow - 2;

        // Column D = "Monto esperado" per Charge::reportColumns() column order
        // (A=Unidad, B=Concepto, C=Cobro, D=Monto esperado).
        $amounts = [];
        for ($row = 3; $row <= $highestRow; $row++) {
            $amounts[] = (float) $sheet->getCell('D'.$row)->getValue();
        }

        @unlink($newFiles[0]);

        $this->assertSame($rowCount, $dataRowCount, 'Every inserted charge must appear exactly once — no duplication, no skipping, across the lazy(500) chunk boundary');
        $this->assertCount($rowCount, array_unique($amounts), 'Duplicate rows would collapse the unique amount fingerprint count');
    }

    /**
     * Integration C1 (partial) — the export must apply the list's own `sort`
     * (Orion-style `[{field, direction}]`) instead of always forcing
     * created_at desc, as long as the field is in the model's column
     * whitelist.
     */
    public function test_export_applies_the_requested_sort_field(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);

        $chargeHigh = $this->makeCharge($tenant, $unitA, 900000);
        $chargeLow = $this->makeCharge($tenant, $unitB, 100000);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.charge'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'Charge',
                '_type' => 'xlsx',
                '_title' => 'cargos-orden',
                'sort' => [
                    ['field' => 'expected_amount', 'direction' => 'asc'],
                ],
            ])
            ->assertOk();

        Excel::assertDownloaded('cargos-orden.xlsx', function (ReportExport $export) use ($chargeLow, $chargeHigh) {
            $ids = $export->collection()->pluck('id')->values()->all();

            return $ids === [$chargeLow->id, $chargeHigh->id];
        });
    }

    /**
     * Integration C2 — operator normalization (`is_null`) + the Schema-based
     * column whitelist (replacing the old getFillable()-based one). AccessLog's
     * `exited_at`/`denied_at` are NOT in $fillable, so the "Dentro/Salieron/
     * Denegados" filter on `exited_at` was silently dropped by the OLD
     * whitelist even when the operator itself was supported — exporting the
     * FULL bitácora instead of the filtered subset.
     */
    public function test_access_log_is_null_filter_narrows_export_to_matching_rows(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $insideIds = [];
        $exitedIds = [];

        $tenant->run(function () use ($unit, &$insideIds, &$exitedIds): void {
            for ($i = 0; $i < 2; $i++) {
                $log = AccessLog::create([
                    'unit_id' => $unit->id,
                    'visitor_name' => 'Visitante '.Str::random(4),
                    'access_entry_type_id' => AccessEntryType::QR,
                    'access_verification_id' => AccessVerification::QR,
                    'entered_at' => now()->subHours(2),
                ]);
                $insideIds[] = $log->id;
            }

            for ($i = 0; $i < 3; $i++) {
                $log = AccessLog::create([
                    'unit_id' => $unit->id,
                    'visitor_name' => 'Visitante '.Str::random(4),
                    'access_entry_type_id' => AccessEntryType::QR,
                    'access_verification_id' => AccessVerification::QR,
                    'entered_at' => now()->subHours(5),
                ]);
                // exited_at is NOT fillable — set directly and save.
                $log->exited_at = now()->subHour();
                $log->save();
                $exitedIds[] = $log->id;
            }
        });

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.access_log'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                '_model' => 'AccessLog',
                '_type' => 'xlsx',
                '_title' => 'bitacora-dentro',
                'filters' => [
                    ['field' => 'exited_at', 'operator' => 'is_null', 'value' => null],
                ],
            ])
            ->assertOk();

        Excel::assertDownloaded('bitacora-dentro.xlsx', function (ReportExport $export) use ($insideIds, $exitedIds) {
            $ids = $export->collection()->pluck('id')->all();
            sort($insideIds);
            sort($ids);

            $onlyInsideRows = count($ids) === count($insideIds) && $ids === $insideIds;
            $noExitedRowsLeaked = empty(array_intersect($ids, $exitedIds));

            return $onlyInsideRows && $noExitedRowsLeaked;
        });
    }

    /**
     * C4 — checkPermission() canonicalizes `_model` BEFORE deriving the
     * required ability. A lowercase, no-underscore variant like "accesslog"
     * must resolve to the SAME ability as "AccessLog" (`report.access_log`),
     * not the wrong un-canonicalized snake_case of the raw input
     * (`report.accesslog`). A token holding ONLY the correct canonical
     * ability (no wildcard, no `report.accesslog`) must still be authorized.
     */
    public function test_lowercase_model_key_resolves_to_the_canonical_ability_and_authorizes(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            AccessLog::create([
                'unit_id' => $unit->id,
                'visitor_name' => 'Visitante '.Str::random(4),
                'access_entry_type_id' => AccessEntryType::QR,
                'access_verification_id' => AccessVerification::QR,
                'entered_at' => now()->subHour(),
            ]);
        });

        $user = $this->makeUser($tenant);
        // Only the correct canonical ability — NOT 'report.accesslog', NOT '*'.
        $token = $user->createToken('t', ['report.access_log'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/customReport', [
                // Lowercase, no-underscore variant — previously derived the
                // WRONG ability 'report.accesslog' instead of the canonical
                // 'report.access_log', incorrectly denying an authorized user.
                '_model' => 'accesslog',
                '_type' => 'xlsx',
                '_title' => 'bitacora-canon',
            ])
            ->assertOk();

        Excel::assertDownloaded('bitacora-canon.xlsx');
    }
}
