<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithChargeConfig;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * GET /charge-batch/{id}/summary — server-side KPI aggregation.
 *
 * Asserts the contract figures (raw minor units / counts) and the per-row outstanding
 * clamp that the previous client-side reduce could not guarantee once a batch exceeded
 * the 100-row Orion sample. Runs on SQLite (CASE WHEN, not GREATEST).
 */
final class ChargeBatchSummaryTest extends TestCase
{
    use RefreshDatabase;
    use WithChargeConfig;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Summary Tenant');
        $this->seedTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_aggregates_a_mixed_batch_and_clamps_overpaid_outstanding_to_zero(): void
    {
        $batchId = null;

        $this->tenant->run(function () use (&$batchId): void {
            $policy = $this->makeChargePolicyInTenant();
            $batch = $this->makeBatch($policy);
            $batchId = $batch->id;

            // Fully paid → outstanding 0, completed.
            $this->makeCharge($batch, $policy, 100000, 100000, 0, ChargeStatus::COMPLETED);
            // Partial → outstanding 60000, partial (paid > 0 and still owes).
            $this->makeCharge($batch, $policy, 100000, 40000, 0, ChargeStatus::PARTIAL);
            // Unpaid → outstanding 100000, unpaid, and flagged OVERDUE.
            $this->makeCharge($batch, $policy, 100000, 0, 0, ChargeStatus::OVERDUE);
            // OVERPAID (paid > expected) → per-row outstanding clamps to 0, counts as completed.
            $this->makeCharge($batch, $policy, 100000, 120000, 0, ChargeStatus::OVERPAID);
        });

        $token = $this->adminToken();

        $this->tenantRequest($token, $this->tenant)
            ->getJson('/api/charge-batch/'.$batchId.'/summary')
            ->assertOk()
            ->assertJsonPath('data.expected.amount', '400000')
            ->assertJsonPath('data.expected.currency', 'MXN')
            ->assertJsonPath('data.paid.amount', '260000')
            // 0 (full) + 60000 (partial) + 100000 (unpaid) + 0 (overpaid clamp) — NOT 140000.
            ->assertJsonPath('data.outstanding.amount', '160000')
            ->assertJsonPath('data.total', 4)
            // fully-paid + overpaid (overpaid is NOT negative-outstanding).
            ->assertJsonPath('data.completed', 2)
            ->assertJsonPath('data.partial', 1)
            ->assertJsonPath('data.unpaid', 1)
            ->assertJsonPath('data.overdue', 1)
            // sum(paid) over partial rows only.
            ->assertJsonPath('data.partial_paid.amount', '40000')
            // sum(outstanding) over unpaid (paid = 0) rows only.
            ->assertJsonPath('data.unpaid_outstanding.amount', '100000');
    }

    public function test_discount_reduces_outstanding(): void
    {
        $batchId = null;

        $this->tenant->run(function () use (&$batchId): void {
            $policy = $this->makeChargePolicyInTenant();
            $batch = $this->makeBatch($policy);
            $batchId = $batch->id;

            // expected 100000 − discount 30000 − paid 0 = 70000 outstanding.
            $this->makeCharge($batch, $policy, 100000, 0, 30000, ChargeStatus::PENDING);
        });

        $this->tenantRequest($this->adminToken(), $this->tenant)
            ->getJson('/api/charge-batch/'.$batchId.'/summary')
            ->assertOk()
            ->assertJsonPath('data.expected.amount', '100000')
            ->assertJsonPath('data.outstanding.amount', '70000')
            ->assertJsonPath('data.unpaid', 1)
            ->assertJsonPath('data.unpaid_outstanding.amount', '70000')
            ->assertJsonPath('data.completed', 0);
    }

    public function test_cancelled_charges_are_excluded_from_every_figure(): void
    {
        $batchId = null;

        $this->tenant->run(function () use (&$batchId): void {
            $policy = $this->makeChargePolicyInTenant();
            $batch = $this->makeBatch($policy);
            $batchId = $batch->id;

            // One live unpaid charge.
            $this->makeCharge($batch, $policy, 100000, 0, 0, ChargeStatus::PENDING);
            // A cancelled charge that must NOT contribute to any figure.
            $this->makeCharge($batch, $policy, 999999, 555555, 0, ChargeStatus::OVERDUE, true);
        });

        $this->tenantRequest($this->adminToken(), $this->tenant)
            ->getJson('/api/charge-batch/'.$batchId.'/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.expected.amount', '100000')
            ->assertJsonPath('data.paid.amount', '0')
            ->assertJsonPath('data.outstanding.amount', '100000')
            ->assertJsonPath('data.overdue', 0);
    }

    public function test_resident_only_sees_their_own_units_figures(): void
    {
        $batchId = null;
        $resident = $this->makeUser($this->tenant);

        $this->tenant->run(function () use (&$batchId, $resident): void {
            $policy = $this->makeChargePolicyInTenant();
            $batch = $this->makeBatch($policy);
            $batchId = $batch->id;

            $ownUnit = $this->makeUnit();
            $otherUnit = $this->makeUnit();

            UnitUser::create([
                'unit_id' => $ownUnit->id,
                'user_id' => $resident->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'is_legal_representative' => true,
            ]);

            // Resident's own unit: 1 charge owing 100000.
            $this->makeChargeForUnit($batch, $policy, $ownUnit, 100000, 0, 0, ChargeStatus::PENDING);
            // A neighbour's unit in the same batch must be invisible to the resident.
            $this->makeChargeForUnit($batch, $policy, $otherUnit, 500000, 0, 0, ChargeStatus::PENDING);
        });

        // Resident token: read.charge_batch (passes authorize) but NOT read.payment/'*'
        // → resident scoping kicks in.
        $token = $resident->createToken('resident', ['read.charge_batch'])->plainTextToken;

        $this->tenantRequest($token, $this->tenant)
            ->getJson('/api/charge-batch/'.$batchId.'/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.expected.amount', '100000')
            ->assertJsonPath('data.outstanding.amount', '100000');
    }

    private function adminToken(): string
    {
        // Wildcard isolates the test to scoping/aggregation, not permission wiring.
        return $this->makeUser($this->tenant)->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * DRAFT (not ACTIVE) on purpose: an ACTIVE batch makes UnitObserver::created
     * auto-generate a charge for every unit created afterwards (§6.5 r4), which would
     * collide with the manual charges this test inserts. The summary endpoint aggregates
     * purely by charge_batch_id, so batch status is irrelevant to what it returns.
     */
    private function makeBatch(ChargePolicy $policy): ChargeBatch
    {
        return ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => 'Summary batch',
            'year' => 2026,
            'month' => 6,
            'target_type' => 'all',
            'issued_at' => '2026-06-01',
            'due_date' => '2026-06-10',
            'charge_batch_status_id' => ChargeBatchStatus::DRAFT,
            'progress_pct' => 0,
        ]);
    }

    private function makeUnit(): Unit
    {
        return Unit::create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => (string) mt_rand(1, 999999),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'ownership_share' => 1.0,
        ]);
    }

    private function makeCharge(
        ChargeBatch $batch,
        ChargePolicy $policy,
        int $expected,
        int $paid,
        int $discount,
        string $statusId,
        bool $cancelled = false,
    ): Charge {
        return $this->makeChargeForUnit($batch, $policy, $this->makeUnit(), $expected, $paid, $discount, $statusId, $cancelled);
    }

    private function makeChargeForUnit(
        ChargeBatch $batch,
        ChargePolicy $policy,
        Unit $unit,
        int $expected,
        int $paid,
        int $discount,
        string $statusId,
        bool $cancelled = false,
    ): Charge {
        return Charge::create([
            'charge_batch_id' => $batch->id,
            'charge_concept_id' => $policy->charge_concept_id,
            'charge_policy_id' => $policy->id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'discount_amount' => $discount,
            'issued_at' => '2026-06-01',
            'due_date' => '2026-06-10',
            'grace_days' => 0,
            'charge_status_id' => $statusId,
            'is_cancelled' => $cancelled,
        ]);
    }
}
