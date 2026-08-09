<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Http\Controllers\ReportExport;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the server-side filters of GET /api/delinquency (and their
 * pass-through to GET /api/delinquency/report): combined unit/resident search,
 * charge-concept multiselect, minimum months overdue, and the min/max
 * outstanding range (integer MINOR UNITS). Both endpoints share
 * PaymentController::delinquencyRows + validateDelinquencyFilters, so the
 * export can never drift from the dashboard.
 */
final class DelinquencyFilterTest extends TestCase
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

    private function makeUnit(Tenant $tenant, ?string $number = null): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $number): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                // Distinctive default so substring search tests never collide.
                'number' => $number ?? 'U'.Str::random(8),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    private function makeConcept(Tenant $tenant, string $name): ChargeConcept
    {
        $concept = null;
        $tenant->run(function () use (&$concept, $name): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $concept = ChargeConcept::create([
                'code' => 'FLT-'.Str::random(6),
                'name' => $name,
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
        });

        return $concept;
    }

    private function makeOverdueCharge(
        Tenant $tenant,
        Unit $unit,
        ChargeConcept $concept,
        int $expectedAmount,
        string $dueDate,
    ): Charge {
        $charge = null;
        $tenant->run(function () use ($unit, $concept, $expectedAmount, $dueDate, &$charge): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $batchStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::ACTIVE);

            // ONE active policy per (concept, periodicity) — enforced by the
            // charge_policy_active_unique partial index, so reuse when present.
            $policy = ChargePolicy::where('charge_concept_id', $concept->id)->first()
                ?? ChargePolicy::create([
                    'charge_concept_id' => $concept->id,
                    'name' => 'Política '.Str::random(4),
                    'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                    'periodicity_id' => Periodicity::EVENTUAL,
                    'fixed_amount' => $expectedAmount,
                    'valid_from' => '2020-01-01',
                    'is_active' => true,
                    'grace_days' => 0,
                ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Lote de prueba',
                'year' => 2020,
                // Null month = eventual batch → exempt from charge_batch_period_unique,
                // so one policy can back several batches in these fixtures.
                'month' => null,
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

    /**
     * Attach a named resident to a unit via the unit_user pivot.
     * A non-null $endDate makes the link INACTIVE (former resident).
     */
    private function attachResident(Tenant $tenant, Unit $unit, string $name, ?string $endDate = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::random(12).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        $tenant->run(function () use ($unit, $user, $endDate): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'start_date' => '2020-01-01',
                'end_date' => $endDate,
            ]);
        });

        return $user;
    }

    private function readToken(Tenant $tenant): string
    {
        return $this->makeUser($tenant)->createToken('t', ['read.payment'])->plainTextToken;
    }

    // =========================================================================
    // search — combined unit / resident
    // =========================================================================

    public function test_search_matches_active_resident_name_but_not_former_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $concept = $this->makeConcept($tenant, 'Mantenimiento');

        $unitWithJuan = $this->makeUnit($tenant);
        $unitWithMaria = $this->makeUnit($tenant);
        $unitWithFormerJuan = $this->makeUnit($tenant);

        $this->attachResident($tenant, $unitWithJuan, 'Juan Pérez');
        $this->attachResident($tenant, $unitWithMaria, 'María López');
        // Former resident (end_date set) must NOT match the search.
        $this->attachResident($tenant, $unitWithFormerJuan, 'Juanito Gómez', '2024-01-01');

        $this->makeOverdueCharge($tenant, $unitWithJuan, $concept, 50000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $unitWithMaria, $concept, 30000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $unitWithFormerJuan, $concept, 20000, '2020-01-15');

        $response = $this->tenantRequest($this->readToken($tenant), $tenant)
            ->getJson('/api/delinquency?'.http_build_query(['search' => 'juan']))
            ->assertOk()
            ->json();

        $unitIds = array_column($response['data'], 'unit_id');
        $this->assertSame([$unitWithJuan->id], $unitIds);
        $this->assertSame(1, $response['units_with_debt']);
        $this->assertSame('50000', $response['total_outstanding']['amount'], 'KPI totals reflect the filtered set');
    }

    public function test_search_still_matches_the_unit_number(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $concept = $this->makeConcept($tenant, 'Mantenimiento');

        $targetUnit = $this->makeUnit($tenant, 'ZQX-77');
        $otherUnit = $this->makeUnit($tenant, 'ABC-11');

        $this->makeOverdueCharge($tenant, $targetUnit, $concept, 50000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $otherUnit, $concept, 30000, '2020-01-15');

        $response = $this->tenantRequest($this->readToken($tenant), $tenant)
            ->getJson('/api/delinquency?'.http_build_query(['search' => 'zqx']))
            ->assertOk()
            ->json();

        $this->assertSame([$targetUnit->id], array_column($response['data'], 'unit_id'));
    }

    // =========================================================================
    // charge_concept_ids
    // =========================================================================

    public function test_concept_filter_narrows_both_the_charges_and_the_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $maintenance = $this->makeConcept($tenant, 'Mantenimiento');
        $water = $this->makeConcept($tenant, 'Agua');

        $unitBoth = $this->makeUnit($tenant);
        $unitWaterOnly = $this->makeUnit($tenant);

        $this->makeOverdueCharge($tenant, $unitBoth, $maintenance, 50000, '2020-02-15');
        $this->makeOverdueCharge($tenant, $unitBoth, $water, 30000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $unitWaterOnly, $water, 20000, '2020-01-15');

        $token = $this->readToken($tenant);

        // Unfiltered baseline: both units, unitBoth aggregates both concepts.
        $baseline = $this->tenantRequest($token, $tenant)
            ->getJson('/api/delinquency')
            ->assertOk()
            ->json();
        $this->assertSame(2, $baseline['units_with_debt']);
        $this->assertSame('100000', $baseline['total_outstanding']['amount']);

        // Concept filter: the water-only unit disappears AND unitBoth's row now
        // reports ONLY the maintenance debt (outstanding + charge_count + oldest date).
        $filtered = $this->tenantRequest($token, $tenant)
            ->getJson('/api/delinquency?'.http_build_query(['charge_concept_ids' => [$maintenance->id]]))
            ->assertOk()
            ->json();

        $this->assertSame(1, $filtered['units_with_debt']);
        $row = $filtered['data'][0];
        $this->assertSame($unitBoth->id, $row['unit_id']);
        $this->assertSame('50000', $row['outstanding']['amount']);
        $this->assertSame(1, $row['charge_count']);
        $this->assertStringStartsWith('2020-02-15', $row['oldest_due_date']);
    }

    // =========================================================================
    // min_months_overdue
    // =========================================================================

    public function test_min_months_overdue_keeps_only_units_whose_oldest_debt_is_old_enough(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $concept = $this->makeConcept($tenant, 'Mantenimiento');

        $oldDebtUnit = $this->makeUnit($tenant);
        $recentDebtUnit = $this->makeUnit($tenant);

        $this->makeOverdueCharge(
            $tenant,
            $oldDebtUnit,
            $concept,
            50000,
            Carbon::today()->subMonthsNoOverflow(4)->toDateString(),
        );
        $this->makeOverdueCharge(
            $tenant,
            $recentDebtUnit,
            $concept,
            90000,
            Carbon::today()->subDays(10)->toDateString(),
        );

        $response = $this->tenantRequest($this->readToken($tenant), $tenant)
            ->getJson('/api/delinquency?'.http_build_query(['min_months_overdue' => 3]))
            ->assertOk()
            ->json();

        $this->assertSame([$oldDebtUnit->id], array_column($response['data'], 'unit_id'));
    }

    // =========================================================================
    // min_amount / max_amount
    // =========================================================================

    public function test_amount_range_bounds_the_aggregated_outstanding_per_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $concept = $this->makeConcept($tenant, 'Mantenimiento');

        $smallDebt = $this->makeUnit($tenant);
        $midDebt = $this->makeUnit($tenant);
        $bigDebt = $this->makeUnit($tenant);

        $this->makeOverdueCharge($tenant, $smallDebt, $concept, 30000, '2020-01-15');
        // Two charges aggregate to 50000 — the range applies to the SUM, not per charge.
        $this->makeOverdueCharge($tenant, $midDebt, $concept, 20000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $midDebt, $concept, 30000, '2020-02-15');
        $this->makeOverdueCharge($tenant, $bigDebt, $concept, 90000, '2020-01-15');

        $response = $this->tenantRequest($this->readToken($tenant), $tenant)
            ->getJson('/api/delinquency?'.http_build_query(['min_amount' => 40000, 'max_amount' => 60000]))
            ->assertOk()
            ->json();

        $this->assertSame([$midDebt->id], array_column($response['data'], 'unit_id'));
        $this->assertSame('50000', $response['data'][0]['outstanding']['amount']);
    }

    // =========================================================================
    // combinations, validation, export
    // =========================================================================

    public function test_concept_and_min_amount_filters_compose(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $maintenance = $this->makeConcept($tenant, 'Mantenimiento');
        $water = $this->makeConcept($tenant, 'Agua');

        $bigMaintenanceDebt = $this->makeUnit($tenant);
        $smallMaintenanceDebt = $this->makeUnit($tenant);
        $bigWaterDebt = $this->makeUnit($tenant);

        $this->makeOverdueCharge($tenant, $bigMaintenanceDebt, $maintenance, 50000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $smallMaintenanceDebt, $maintenance, 30000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $bigWaterDebt, $water, 90000, '2020-01-15');

        $response = $this->tenantRequest($this->readToken($tenant), $tenant)
            ->getJson('/api/delinquency?'.http_build_query([
                'charge_concept_ids' => [$maintenance->id],
                'min_amount' => 40000,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame([$bigMaintenanceDebt->id], array_column($response['data'], 'unit_id'));
    }

    public function test_invalid_filters_are_rejected_with_422(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $foreignConcept = $this->makeConcept($tenantB, 'Concepto ajeno');
        $token = $this->readToken($tenantA);

        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/delinquency?'.http_build_query(['min_amount' => -5]))
            ->assertStatus(422);

        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/delinquency?'.http_build_query(['min_months_overdue' => 0]))
            ->assertStatus(422);

        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/delinquency?'.http_build_query(['charge_concept_ids' => ['not-a-uuid']]))
            ->assertStatus(422);

        // A concept id belonging to ANOTHER tenant fails the tenant-scoped exists rule.
        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/delinquency?'.http_build_query(['charge_concept_ids' => [$foreignConcept->id]]))
            ->assertStatus(422);
    }

    public function test_export_respects_the_active_filters(): void
    {
        Excel::fake();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $maintenance = $this->makeConcept($tenant, 'Mantenimiento');
        $water = $this->makeConcept($tenant, 'Agua');

        $maintenanceUnit = $this->makeUnit($tenant);
        $waterUnit = $this->makeUnit($tenant);

        $this->makeOverdueCharge($tenant, $maintenanceUnit, $maintenance, 50000, '2020-01-15');
        $this->makeOverdueCharge($tenant, $waterUnit, $water, 30000, '2020-01-15');

        $token = $this->makeUser($tenant)->createToken('t', ['report.payment'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/delinquency/report?'.http_build_query(['charge_concept_ids' => [$maintenance->id]]))
            ->assertOk();

        Excel::assertDownloaded('morosidad.xlsx', function (ReportExport $export) use ($maintenanceUnit, $waterUnit): bool {
            $unitIds = $export->collection()->pluck('unit_id')->all();

            $this->assertContains($maintenanceUnit->id, $unitIds, 'The filtered concept unit is exported');
            $this->assertNotContains($waterUnit->id, $unitIds, 'Units outside the filter must NOT be exported');

            return true;
        });
    }
}
