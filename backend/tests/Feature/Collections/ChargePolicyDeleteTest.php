<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\ChargePolicyService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * DELETE /api/charge_policy/{id} — the "live billing" guard.
 *
 * Deletion is blocked ONLY by live billing (a charge not covered by a CANCELLED batch).
 * Counting batches instead blocked every periodic policy forever, because creating one always
 * leaves a template batch behind (is_initial = true, no charges). When deletion is allowed the
 * template and the cancelled batches are cascaded (soft-deleted) so they never survive as
 * orphans in the general charge-batch list.
 */
final class ChargePolicyDeleteTest extends TestCase
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

        Carbon::setTestNow();

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
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    /**
     * Create a MONTHLY policy through the service (template + first-period clone).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPolicy(Tenant $tenant, array $overrides = []): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $overrides): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'DELPOL_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Cuota de prueba',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = app(ChargePolicyService::class)->create(array_merge([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota de prueba',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'is_active' => true,
                'grace_days' => 0,
                'target_type' => 'all',
            ], $overrides));
        });

        return $policy;
    }

    /** @return array<int, ChargeBatch> */
    private function batchesOf(Tenant $tenant, ChargePolicy $policy, bool $withTrashed = false): array
    {
        $batches = [];
        $tenant->run(function () use (&$batches, $policy, $withTrashed): void {
            $batches = ChargeBatch::when($withTrashed, fn ($query) => $query->withTrashed())
                ->where('charge_policy_id', $policy->id)
                ->get()
                ->all();
        });

        return $batches;
    }

    private function deletePolicy(Tenant $tenant, ChargePolicy $policy): TestResponse
    {
        $admin = $this->makeUser($tenant);
        // Wildcard token: the test targets the destroy guard, not the permission layer.
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        return $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/charge_policy/{$policy->id}");
    }

    // =========================================================================
    // Template-only policy → deletable (the template alone is not billing)
    // =========================================================================

    public function test_periodic_policy_with_only_its_template_can_be_deleted(): void
    {
        $tenant = $this->makeTenant('PolicyDelete Template Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        // A future valid_from materializes ONLY the template — no period batch, no charges.
        $policy = $this->createPolicy($tenant, [
            'valid_from' => '2026-09-01',
            'valid_to' => null,
        ]);

        $batches = $this->batchesOf($tenant, $policy);
        $this->assertCount(1, $batches, 'Precondition: exactly one batch, the template');
        $this->assertTrue($batches[0]->is_initial);

        $this->deletePolicy($tenant, $policy)->assertStatus(200);

        $tenant->run(function () use ($policy, $batches): void {
            $this->assertNull(ChargePolicy::find($policy->id), 'The policy must be soft-deleted');
            $this->assertNull(ChargeBatch::find($batches[0]->id), 'The template must be cascaded');
            $this->assertNotNull(
                ChargeBatch::withTrashed()->find($batches[0]->id)->deleted_at,
                'The cascade must be a SOFT delete, not a hard one',
            );
        });
    }

    // =========================================================================
    // Only cancelled billing → deletable (the reported symptom)
    // =========================================================================

    public function test_policy_whose_only_charges_belong_to_a_cancelled_batch_can_be_deleted(): void
    {
        $tenant = $this->makeTenant('PolicyDelete Cancelled Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        $policy = $this->createPolicy($tenant, ['valid_from' => '2026-07-01']);

        $chargeIds = [];
        $tenant->run(function () use ($policy, &$chargeIds): void {
            $period = ChargeBatch::where('charge_policy_id', $policy->id)
                ->where('is_initial', false)
                ->firstOrFail();

            $chargeIds = Charge::where('charge_batch_id', $period->id)->pluck('id')->all();
            $this->assertNotEmpty($chargeIds, 'Precondition: the first period must have charges');

            app(ChargeGenerationService::class)->cancelBatch($period, 'Prueba');
        });

        $this->deletePolicy($tenant, $policy)->assertStatus(200);

        $tenant->run(function () use ($policy, $chargeIds): void {
            $this->assertNull(ChargePolicy::find($policy->id));
            $this->assertSame(
                0,
                ChargeBatch::where('charge_policy_id', $policy->id)->count(),
                'Template and cancelled batch must be cascaded — no orphans left behind',
            );
            $this->assertSame(
                0,
                Charge::whereIn('id', $chargeIds)->count(),
                'The charges of the cancelled batch must be cascaded too',
            );
            $this->assertCount(
                count($chargeIds),
                Charge::withTrashed()->whereIn('id', $chargeIds)->get(),
                'The cascade must be a SOFT delete, not a hard one',
            );
        });
    }

    // =========================================================================
    // Live billing → 422, nothing touched
    // =========================================================================

    public function test_policy_with_an_active_batch_and_charges_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant('PolicyDelete Live Tenant');
        $this->seedTenant($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 7, 24, 9, 0, 0));

        $policy = $this->createPolicy($tenant, ['valid_from' => '2026-07-01']);

        $tenant->run(function () use ($policy): void {
            $this->assertTrue(
                Charge::where('charge_policy_id', $policy->id)->exists(),
                'Precondition: the policy must have live charges',
            );
        });

        $this->deletePolicy($tenant, $policy)->assertStatus(422);

        $tenant->run(function () use ($policy): void {
            $this->assertNotNull(ChargePolicy::find($policy->id), 'A billing policy must survive the attempt');
            $this->assertSame(
                2,
                ChargeBatch::where('charge_policy_id', $policy->id)->count(),
                'Template and active period must both survive',
            );
            $this->assertTrue(Charge::where('charge_policy_id', $policy->id)->exists());
        });
    }
}
