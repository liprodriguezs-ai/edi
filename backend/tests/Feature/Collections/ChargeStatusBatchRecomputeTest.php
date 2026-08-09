<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * The batch unit-aggregate recompute must (a) produce the SAME worst-wins status
 * per unit as the per-unit path, and (b) issue a bounded number of queries that
 * does NOT grow with the number of units (the N+1 this replaces).
 */
final class ChargeStatusBatchRecomputeTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private ?string $conceptId = null;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_batch_recompute_produces_correct_worst_wins_per_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            // 3 units, each with a single charge in a distinct status.
            $overdueUnit = $this->makeUnitWithChargeStatus(ChargeStatus::OVERDUE);
            $partialUnit = $this->makeUnitWithChargeStatus(ChargeStatus::PARTIAL);
            $completedUnit = $this->makeUnitWithChargeStatus(ChargeStatus::COMPLETED);

            app(ChargeStatusService::class)->recomputeUnitAggregates([
                $overdueUnit->id, $partialUnit->id, $completedUnit->id,
            ]);

            $this->assertSame(ChargeStatus::OVERDUE, $overdueUnit->fresh()->charge_status_id);
            $this->assertSame(ChargeStatus::PARTIAL, $partialUnit->fresh()->charge_status_id);
            $this->assertSame(ChargeStatus::COMPLETED, $completedUnit->fresh()->charge_status_id);
        });
    }

    public function test_query_count_does_not_grow_with_unit_count(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $service = app(ChargeStatusService::class);

            $threeUnits = collect(range(1, 3))
                ->map(fn () => $this->makeUnitWithChargeStatus(ChargeStatus::PENDING)->id)
                ->all();
            $sixUnits = collect(range(1, 6))
                ->map(fn () => $this->makeUnitWithChargeStatus(ChargeStatus::PENDING)->id)
                ->all();

            $queriesFor3 = $this->countQueries(fn () => $service->recomputeUnitAggregates($threeUnits));
            $queriesFor6 = $this->countQueries(fn () => $service->recomputeUnitAggregates($sixUnits));

            // The whole point: query count is bounded and constant in unit count.
            $this->assertSame($queriesFor3, $queriesFor6, 'Query count must not grow with the number of units');
            $this->assertLessThanOrEqual(10, $queriesFor6, 'Recompute must use a small bounded number of queries');
        });
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a unit plus one non-cancelled charge fixed at the given status.
     * Must run inside $tenant->run().
     */
    private function makeUnitWithChargeStatus(string $statusId): Unit
    {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
        $unit = Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => (string) mt_rand(1, 999999),
            'unit_status_id' => $unitStatus->id,
            'ownership_share' => 1.0,
        ]);

        // Minimal charge row carrying the desired status. The aggregate only reads
        // charge_status_id of non-cancelled charges; concept is required by the FK.
        Charge::query()->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $unit->tenant_id,
            'unit_id' => $unit->id,
            'charge_batch_id' => null,
            'charge_concept_id' => $this->ensureConcept(),
            'expected_amount' => 10000,
            'paid_amount' => 0,
            'issued_at' => '2026-01-01',
            'due_date' => '2026-02-01',
            'grace_days' => 0,
            'charge_status_id' => $statusId,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unit;
    }

    /**
     * Create one ChargeConcept for the tenant and reuse its id for all test
     * charges (the FK requires a concept; its values are irrelevant to the
     * aggregate). Must run inside $tenant->run().
     */
    private function ensureConcept(): string
    {
        if ($this->conceptId !== null) {
            return $this->conceptId;
        }

        $concept = ChargeConcept::create([
            'code' => 'BATCH_'.Str::upper(Str::random(5)),
            'name' => 'Batch recompute concept',
            'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Recargo'),
            'is_active' => true,
        ]);

        return $this->conceptId = $concept->id;
    }
}
