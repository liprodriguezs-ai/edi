<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
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
use QCH\Core\Http\Controllers\ReportExport;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for GET /api/delinquency/report — the xlsx export of the morosidad
 * dashboard (PaymentController::delinquencyReport). The export reuses the SAME
 * aggregation as GET /api/delinquency (delinquencyRows), so these tests pin the
 * ability gate, the filename, and the aggregated per-unit row.
 */
final class DelinquencyReportExportTest extends TestCase
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

    private function makeOverdueCharge(Tenant $tenant, Unit $unit, int $expectedAmount, string $dueDate): Charge
    {
        $charge = null;
        $tenant->run(function () use ($unit, $expectedAmount, $dueDate, &$charge): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $batchStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::ACTIVE);

            $concept = ChargeConcept::create([
                'code' => 'DELQ-'.Str::random(4),
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
                'valid_from' => '2020-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Lote de prueba',
                'year' => 2020,
                'month' => 1,
                'target_type' => 'all',
                'issued_at' => '2020-01-01',
                'due_date' => $dueDate,
                'charge_batch_status_id' => $batchStatus->id,
                'progress_pct' => 0,
            ]);

            $charge = Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => $expectedAmount,
                'paid_amount' => 0,
                'issued_at' => '2020-01-01',
                'due_date' => $dueDate,
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);
        });

        return $charge;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_delinquency_export_is_forbidden_without_report_ability(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $this->makeOverdueCharge($tenant, $unit, 50000, '2020-01-15');

        // read.payment lets the caller SEE the dashboard, but export needs report.payment.
        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['read.payment'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/delinquency/report')
            ->assertForbidden();
    }

    public function test_delinquency_export_downloads_morosidad_xlsx_with_aggregated_unit_row(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        // Two overdue charges → ONE aggregated row: outstanding 80000 (=$800.00),
        // charge_count 2, oldest due date 15/01/2020.
        $this->makeOverdueCharge($tenant, $unit, 50000, '2020-02-15');
        $this->makeOverdueCharge($tenant, $unit, 30000, '2020-01-15');

        $user = $this->makeUser($tenant);
        $token = $user->createToken('t', ['report.payment'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/delinquency/report')
            ->assertOk();

        Excel::assertDownloaded('morosidad.xlsx', function (ReportExport $export) use ($unit): bool {
            $rows = $export->collection();
            $this->assertCount(1, $rows, 'Both overdue charges aggregate into a single unit row');

            [$unidad, $chargeCount, $oldestDue, $outstanding] = $export->map($rows->first());

            $this->assertSame('Casa '.$unit->number, $unidad);
            $this->assertSame(2, $chargeCount);
            $this->assertSame('15/01/2020', $oldestDue);
            // Money cell follows the project rule: (float) formatByDecimal(), never minor units.
            $this->assertSame(800.0, $outstanding);

            return true;
        });
    }

    public function test_delinquency_export_is_scoped_to_the_current_tenant(): void
    {
        Excel::fake();

        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $this->makeOverdueCharge($tenantA, $unitA, 50000, '2020-01-15');
        $this->makeOverdueCharge($tenantB, $unitB, 99900, '2020-01-15');

        $userA = $this->makeUser($tenantA);
        $tokenA = $userA->createToken('t', ['report.payment'])->plainTextToken;

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/delinquency/report')
            ->assertOk();

        Excel::assertDownloaded('morosidad.xlsx', function (ReportExport $export) use ($unitA, $unitB): bool {
            $unitIds = $export->collection()->pluck('unit_id')->all();

            $this->assertContains($unitA->id, $unitIds, "Tenant A's unit is exported");
            $this->assertNotContains($unitB->id, $unitIds, "Tenant B's unit must NOT leak into tenant A's export");

            return true;
        });
    }
}
