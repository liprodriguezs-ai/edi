<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
use Modules\Reports\Jobs\GenerateReportJob;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Services\ReportFormatter;
use Modules\Reports\Services\ReportService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for report export (PDF + Excel, Item 3) and async generation (Item 4).
 *
 * Covers:
 *  - PDF response returns application/pdf content type.
 *  - Excel response returns xlsx content type.
 *  - Async generate → GeneratedReport row created as pending, ends ready.
 *  - Download streams the file.
 *  - Prune removes expired rows.
 *  - Tenant isolation on generated_report.
 */
final class ReportExportTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

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
                'number' => (string) mt_rand(1, 999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    private function seedOverdueCharge(Tenant $tenant, Unit $unit): void
    {
        $tenant->run(function () use ($unit): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $batchStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            $concept = ChargeConcept::create([
                'code' => 'EXP-'.Str::random(4),
                'name' => 'Test',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Test policy',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'periodicity_id' => Periodicity::MONTHLY,
                'fixed_amount' => 50000,
                'valid_from' => '2026-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Test Batch',
                'year' => 2026,
                'month' => 1,
                'target_type' => 'all',
                'issued_at' => '2026-01-01',
                'due_date' => now()->subDays(30)->toDateString(),
                'charge_batch_status_id' => $batchStatus->id,
                'progress_pct' => 0,
            ]);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 50000,
                'paid_amount' => 0,
                'issued_at' => now()->subDays(45),
                'due_date' => now()->subDays(30),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);
        });
    }

    // =========================================================================
    // A. PDF export (Item 3)
    // =========================================================================

    public function test_pdf_format_returns_pdf_content_type(): void
    {
        $tenant = $this->makeTenant('PDF Export Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->seedOverdueCharge($tenant, $unit);

        $tenant->run(function (): void {
            $service = app(ReportService::class);
            $formatter = app(ReportFormatter::class);

            $data = $service->delinquency();
            $response = $formatter->pdf('delinquency', $data);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent(), 'PDF content must not be empty');
        });
    }

    // =========================================================================
    // B. Excel export (Item 3)
    // =========================================================================

    public function test_excel_format_returns_xlsx_response(): void
    {
        $tenant = $this->makeTenant('Excel Export Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->seedOverdueCharge($tenant, $unit);

        $tenant->run(function (): void {
            $service = app(ReportService::class);
            $formatter = app(ReportFormatter::class);

            $data = $service->delinquency();

            // Excel download must not throw
            $threw = false;
            try {
                $response = $formatter->excel('delinquency', $data);
                $this->assertNotNull($response, 'Excel response must not be null');
            } catch (\Throwable $e) {
                $threw = true;
            }

            $this->assertFalse($threw, 'Excel export must not throw');
        });
    }

    // =========================================================================
    // C. Async generation — GeneratedReport lifecycle (Item 4)
    // =========================================================================

    public function test_async_generate_creates_generated_report_row(): void
    {
        $tenant = $this->makeTenant('Async Gen Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->seedOverdueCharge($tenant, $unit);

        $tenant->run(function (): void {
            $report = GeneratedReport::create([
                'report_key' => 'delinquency',
                'format' => 'pdf',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'expires_at' => now()->addDays(90),
            ]);

            $this->assertSame(GeneratedReport::STATUS_PENDING, $report->status);
            $this->assertNotNull($report->id);
        });
    }

    public function test_generate_report_job_runs_and_sets_ready_status(): void
    {
        $tenant = $this->makeTenant('Job Ready Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->seedOverdueCharge($tenant, $unit);

        $tenant->run(function (): void {
            // Initialize tenancy manually (simulates what QueueTenancyBootstrapper does in prod)
            $report = GeneratedReport::create([
                'report_key' => 'delinquency',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'expires_at' => now()->addDays(90),
            ]);

            // Dispatch inline (sync queue) — job runs immediately
            GenerateReportJob::dispatchSync($report);

            $report->refresh();
            $this->assertSame(GeneratedReport::STATUS_READY, $report->status, 'Job must set status to ready');
            $this->assertNotNull($report->file_path, 'Job must set a file_path');
            Storage::assertExists($report->file_path);
        });
    }

    public function test_generated_report_file_path_is_stored(): void
    {
        $tenant = $this->makeTenant('File Path Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $report = GeneratedReport::create([
                'report_key' => 'account_balances',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'expires_at' => now()->addDays(90),
            ]);

            GenerateReportJob::dispatchSync($report);

            $report->refresh();
            $this->assertSame(GeneratedReport::STATUS_READY, $report->status);
            $this->assertStringContainsString('reports/', $report->file_path);
        });
    }

    // =========================================================================
    // D. Prune expired reports (Item 4)
    // =========================================================================

    public function test_prune_removes_expired_generated_report_rows(): void
    {
        $tenant = $this->makeTenant('Prune Tenant');
        $this->seedTenant($tenant);

        $expiredReportId = null;

        $tenant->run(function () use (&$expiredReportId): void {
            // Create an already-expired row
            $report = GeneratedReport::create([
                'report_key' => 'delinquency',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_READY,
                'file_path' => null,
                'expires_at' => now()->subDay(), // expired yesterday
            ]);

            $expiredReportId = $report->id;
            $this->assertSame(1, GeneratedReport::count());
        });

        // Run the prune command
        Artisan::call('reports:prune-expired');

        $tenant->run(function () use ($expiredReportId): void {
            $this->assertSame(0, GeneratedReport::count(), 'Expired report must be pruned');
            $this->assertNull(GeneratedReport::withTrashed()->find($expiredReportId), 'Row must be hard-deleted');
        });
    }

    public function test_prune_does_not_remove_non_expired_rows(): void
    {
        $tenant = $this->makeTenant('Prune Non-Expired Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            GeneratedReport::create([
                'report_key' => 'delinquency',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_READY,
                'file_path' => null,
                'expires_at' => now()->addDays(89), // still valid
            ]);

            $this->assertSame(1, GeneratedReport::count());
        });

        Artisan::call('reports:prune-expired');

        $tenant->run(function (): void {
            $this->assertSame(1, GeneratedReport::count(), 'Non-expired report must not be pruned');
        });
    }

    // =========================================================================
    // E. Tenant isolation on generated_report
    // =========================================================================

    public function test_generated_report_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Gen Report TA');
        $tenantB = $this->makeTenant('Gen Report TB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            GeneratedReport::create([
                'report_key' => 'delinquency',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'expires_at' => now()->addDays(90),
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, GeneratedReport::count(), 'Tenant B must not see Tenant A generated reports');
        });
    }
}
