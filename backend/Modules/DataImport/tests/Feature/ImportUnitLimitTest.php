<?php

declare(strict_types=1);

namespace Modules\DataImport\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\DataImport\Models\ImportRun;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the importer's unit-limit preview (REQ-05, REQ-10).
 *
 * Uses the dev-fixture numbers from the spec: tenant on Básico (suggested_unit_max
 * = 25) with 6 active units — a 30-new-unit file blocks, a 15-new-unit file passes.
 */
class ImportUnitLimitTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected string $token;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        $this->seedTenant($this->tenant);
        tenancy()->initialize($this->tenant);

        $user = $this->makeUser($this->tenant);
        $this->token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tempDir = sys_get_temp_dir().'/import_unit_limit_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*.xlsx') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function grantBand(int $limit): void
    {
        $module = BillableModule::create([
            'key' => 'base_'.Str::random(6),
            'name' => 'Plan Base',
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => true,
        ]);

        $plan = BillingPackage::create([
            'key' => 'basico_'.Str::random(6),
            'name' => 'Básico',
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => 1,
            'suggested_unit_max' => $limit,
        ]);

        TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'expires_at' => null,
        ]);
    }

    private function seedActiveUnits(int $count): void
    {
        $unitTypeId = UnitType::firstOrCreate(
            ['tenant_id' => tenant('id'), 'name' => 'Casa'],
            ['order' => 1, 'is_active' => true, 'requires_floor' => false]
        )->id;
        $unitStatusId = UnitStatus::firstOrCreate(
            ['tenant_id' => tenant('id'), 'name' => 'Activa'],
            ['order' => 1, 'is_active' => true, 'is_billable' => true]
        )->id;

        $tenantId = tenant('id');

        for ($i = 0; $i < $count; $i++) {
            // withoutEvents() mutes BelongsToTenant's own `creating` listener too
            // (it is a normal event listener, not a query scope) — forceFill()
            // bypasses the $fillable guard so tenant_id still gets set directly.
            Unit::withoutEvents(function () use ($tenantId, $i, $unitTypeId, $unitStatusId): void {
                $unit = new Unit;
                $unit->forceFill([
                    'tenant_id' => $tenantId,
                    'unit_type_id' => $unitTypeId,
                    'unit_status_id' => $unitStatusId,
                    'number' => 'existing-'.$i,
                ]);
                $unit->save();
            });
        }
    }

    /**
     * @return array<int, string>
     */
    private function headerRow(): array
    {
        return [
            'tipo_unidad', 'numero', 'torre', 'piso', 'seccion', 'estado_unidad',
            'indiviso', 'm2_construido', 'm2_terreno',
            'nombre', 'apellido_paterno', 'apellido_materno',
            'email', 'telefono', 'relacion', 'titular_de_pago',
            'rfc', 'curp', 'fecha_nacimiento',
            'fecha_pago', 'monto', 'metodo_pago', 'cuenta_destino',
            'referencia', 'folio', 'notas',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function validDataRow(string $numero): array
    {
        return [
            'Casa', $numero, null, null, null, null,
            null, null, null,
            'Nuevo', 'Residente', null,
            null, null, null, null,
            null, null, null,
            '2024-03-15', '1500.00', null, null,
            null, null, null,
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function distinctNewUnitRows(int $count, string $prefix): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->validDataRow($prefix.'-'.$i);
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildXlsx(array $rows, string $filename): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }

        $path = $this->tempDir.'/'.$filename;
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // =========================================================================
    // REQ-05 — dev fixture scenarios
    // =========================================================================

    public function test_file_exceeding_remaining_room_blocks_with_row_zero_unidades_error(): void
    {
        $this->grantBand(25);
        $this->seedActiveUnits(6);

        $rows = array_merge([$this->headerRow()], $this->distinctNewUnitRows(30, 'over'));
        $file = $this->buildXlsx($rows, 'over.xlsx');

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(422);

        $response->assertJsonPath('blocked', fn ($v) => $v > 0)
            ->assertJsonPath('units_to_create', 30)
            ->assertJsonPath('residents_to_create', 30)
            ->assertJsonPath('payments_to_register', 30);

        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('rows.0.unidades', $errors);

        $message = $errors['rows.0.unidades'][0] ?? '';
        $this->assertStringContainsString('Básico', $message);
        $this->assertStringContainsString('25', $message);
        $this->assertStringContainsString('6', $message);
        $this->assertStringContainsString('30', $message);
    }

    public function test_file_within_remaining_room_passes_without_a_unit_limit_error(): void
    {
        $this->grantBand(25);
        $this->seedActiveUnits(6);

        $rows = array_merge([$this->headerRow()], $this->distinctNewUnitRows(15, 'fits'));
        $file = $this->buildXlsx($rows, 'fits.xlsx');

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('units_to_create', 15);

        $rowsPayload = $response->json('rows');
        $unitLimitIssues = array_filter($rowsPayload, fn ($issue) => ($issue['column'] ?? null) === 'unidades');

        $this->assertEmpty($unitLimitIssues, 'No unit-limit issue must be present when the file fits within the remaining room');
    }

    public function test_no_active_entitlement_fails_open_and_never_blocks_on_unit_limit(): void
    {
        // No grantBand() call — the tenant has no active entitlement.
        $rows = array_merge([$this->headerRow()], $this->distinctNewUnitRows(50, 'noplan'));
        $file = $this->buildXlsx($rows, 'noplan.xlsx');

        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $file])
            ->assertStatus(200);

        $rowsPayload = $response->json('rows');
        $unitLimitIssues = array_filter($rowsPayload, fn ($issue) => ($issue['column'] ?? null) === 'unidades');

        $this->assertEmpty($unitLimitIssues);
    }

    // =========================================================================
    // REQ-10 — no per-row queries regression guard
    // =========================================================================

    public function test_validate_query_count_does_not_scale_with_row_count(): void
    {
        // Band far above either fixture's size, so validate() never blocks and
        // both requests exercise the exact same code path.
        $this->grantBand(10000);

        $smallFile = $this->buildXlsx(
            array_merge([$this->headerRow()], $this->distinctNewUnitRows(5, 'small')),
            'small.xlsx'
        );

        DB::connection()->enableQueryLog();

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $smallFile])
            ->assertStatus(200);

        $smallQueryCount = count(DB::connection()->getQueryLog());
        DB::connection()->flushQueryLog();

        $largeFile = $this->buildXlsx(
            array_merge([$this->headerRow()], $this->distinctNewUnitRows(500, 'large')),
            'large.xlsx'
        );

        $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/validate', ['file' => $largeFile])
            ->assertStatus(200);

        $largeQueryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // 100x more rows must NOT translate into anywhere near 100x more queries.
        // The unit-limit check (band resolution + active count) runs exactly ONCE
        // per validate() call, never per row — same contract as
        // calculateDistinctCounters()'s existing constant-query guarantee.
        $this->assertLessThan(
            $smallQueryCount * 10,
            $largeQueryCount,
            'Query count must stay roughly constant across row counts — the unit-limit check must never scale per row'
        );
    }

    // =========================================================================
    // REQ-11 — execute/chunk-job path untouched (smoke check)
    // =========================================================================

    public function test_execute_endpoint_is_unaffected_by_the_unit_limit_preview_check(): void
    {
        $this->grantBand(25);
        $this->seedActiveUnits(6);

        $rows = array_merge([$this->headerRow()], $this->distinctNewUnitRows(3, 'exec'));
        $file = $this->buildXlsx($rows, 'exec.xlsx');

        Queue::fake([GenerateReceiptPdfJob::class]);

        // execute() is unbounded by the preview's unit-limit check — this is a
        // smoke check only, per the design (REQ-11): no new assertions on
        // execute's internal chunking/FIFO behavior are introduced here.
        $response = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file]);

        $this->assertContains($response->status(), [200, 201, 202]);
    }

    // =========================================================================
    // F-29 — the row guard is a BUSINESS block, not an unexpected error
    // =========================================================================

    public function test_row_blocked_by_the_unit_limit_is_reported_without_the_unexpected_error_prefix(): void
    {
        $this->grantBand(25);
        $this->seedActiveUnits(24);

        // Skipping the preview lands the guard on the chunk job's per-row catch.
        $rows = array_merge([$this->headerRow()], $this->distinctNewUnitRows(5, 'blocked'));
        $file = $this->buildXlsx($rows, 'blocked.xlsx');

        config(['queue.default' => 'sync']);
        Queue::fake([GenerateReceiptPdfJob::class]);

        $runId = $this->tenantRequest($this->token, $this->tenant)
            ->postJson('/api/import/execute', ['file' => $file])
            ->json('import_run_id');

        tenancy()->initialize($this->tenant);

        $issues = collect(ImportRun::find($runId)->report['rows'] ?? [])
            ->filter(fn (array $issue): bool => str_contains((string) ($issue['message'] ?? ''), 'unidades'));

        $this->assertNotEmpty($issues, 'the unit-limit block must be reported as a row issue');

        foreach ($issues as $issue) {
            $this->assertStringNotContainsString(
                'Error inesperado al procesar fila',
                $issue['message'],
                'an expected business block must not be dressed up as an unexpected error'
            );
        }
    }
}
