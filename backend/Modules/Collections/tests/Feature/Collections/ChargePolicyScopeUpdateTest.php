<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargePolicyService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * charge-policy-editable-until-billed — HTTP-level coverage of the scope/periodicity lock:
 *
 *  - `target_type`/`target_selector` (+ `issued_at`/`due_date` on direct/eventual) route to
 *    the batch that already stores the scope (template on periodic, single batch on direct)
 *    while `has_billed = false`.
 *  - Once `has_billed = true`, those fields (+ `periodicity_id`) reject with 422 while
 *    name/amounts/recargos/valid_from/valid_to stay editable.
 *  - `has_billed` is exposed correctly (withExists, no N+1) on index/show/search.
 *  - `resolveScopeBatch()`'s invariant: an eventual policy must never own more than one
 *    non-initial batch; if it does (data anomaly), the service aborts instead of guessing.
 */
final class ChargePolicyScopeUpdateTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function singleTenant(): array
    {
        $tenant = $this->makeTenant('Scope Update Tenant '.uniqid());
        $this->seedTenant($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function makeConcept(Tenant $tenant): ChargeConcept
    {
        return $this->inTenant($tenant, function (): ChargeConcept {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            return ChargeConcept::create([
                'code' => 'SCOPE_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Scope update concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
        });
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        return $this->inTenant($tenant, function (): Unit {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            return Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);
        });
    }

    // =========================================================================
    // Periodic, not billed (future valid_from): scope routes to the template.
    // =========================================================================

    public function test_periodic_policy_scope_update_persists_on_template_before_billing(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);

        // Anchor "today" BEFORE valid_from so only the template exists (no first-period
        // clone, no charges) — mirrors ChargePolicyInitialBatchTest's future-valid_from case.
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 7, 0, 0));

        $policyId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota futura',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-03-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'target_type' => 'all',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertFalse(
            $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId)->charges()->exists()),
            'A future valid_from policy must not have billed yet',
        );

        $newSelector = ['unit_ids' => ['11111111-1111-1111-1111-111111111111']];

        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'target_type' => 'manual',
                'target_selector' => $newSelector,
            ])
            ->assertStatus(200);

        $template = $this->inTenant(
            $tenant,
            fn () => ChargeBatch::where('charge_policy_id', $policyId)->where('is_initial', true)->firstOrFail(),
        );

        $this->assertSame('manual', $template->target_type);
        $this->assertSame($newSelector, $template->target_selector);
    }

    // =========================================================================
    // Direct/eventual, 0 units resolved: scope + dates route to the single batch.
    // =========================================================================

    public function test_eventual_policy_scope_update_persists_on_single_batch_with_zero_units(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);

        // No unit exists in this tenant → target_type 'all' resolves 0 units; the batch
        // still exists (0 charges), so has_billed stays false.
        $policyId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $concept->id,
                'name' => 'Multa sin unidades',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertFalse(
            $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId)->charges()->exists()),
            'A 0-unit eventual batch must not count as billed',
        );

        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => []],
                'issued_at' => '2026-04-01',
                'due_date' => '2026-04-10',
            ])
            ->assertStatus(200);

        $batch = $this->inTenant(
            $tenant,
            fn () => ChargeBatch::where('charge_policy_id', $policyId)->where('is_initial', false)->firstOrFail(),
        );

        $this->assertSame('manual', $batch->target_type);
        $this->assertSame(['unit_ids' => []], $batch->target_selector);
        $this->assertSame('2026-04-01', $batch->issued_at->toDateString());
        $this->assertSame('2026-04-10', $batch->due_date->toDateString());
    }

    // =========================================================================
    // Billed: scope/periodicity 422, everything else persists.
    // =========================================================================

    public function test_scope_and_periodicity_locked_after_billing_but_other_fields_persist(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);
        $this->makeUnit($tenant);

        $policyId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $concept->id,
                'name' => 'Multa única',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertTrue(
            $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId)->charges()->exists()),
            'The billed policy must own at least one charge',
        );

        // Attempting to change scope AND periodicity once billed → 422 on both, in the same
        // request that also carries name/amount changes (nothing must persist on rollback).
        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'name' => 'Multa actualizada',
                'fixed_amount' => 75_00,
                'target_type' => 'manual',
                'periodicity_id' => Periodicity::EVENTUAL,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_type', 'periodicity_id']);

        // Nothing persisted from the rejected request (transaction rolled back).
        $unchanged = $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId));
        $this->assertSame('Multa única', $unchanged->name);
        $batch = $this->inTenant($tenant, fn () => ChargeBatch::where('charge_policy_id', $policyId)->firstOrFail());
        $this->assertSame('all', $batch->target_type);

        // A follow-up update WITHOUT the locked fields still persists name/amount.
        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'name' => 'Multa actualizada',
                'fixed_amount' => 75_00,
            ])
            ->assertStatus(200);

        $policy = $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId));
        $this->assertSame('Multa actualizada', $policy->name);
        $this->assertSame(75_00, (int) $policy->getAttributes()['fixed_amount']);
    }

    // =========================================================================
    // valid_from/valid_to stay editable even when billed, without retro effect.
    // =========================================================================

    public function test_valid_to_editable_when_billed_without_retroactive_effect(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);
        $this->makeUnit($tenant);

        Carbon::setTestNow(Carbon::create(2026, 1, 10, 7, 0, 0));

        $policyId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 100_00,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'target_type' => 'all',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $charge = $this->inTenant($tenant, fn () => Charge::where('charge_policy_id', $policyId)->firstOrFail());
        $originalAmount = (int) $charge->getAttributes()['expected_amount'];
        $originalStatusId = $charge->charge_status_id;

        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'valid_to' => '2026-06-30',
            ])
            ->assertStatus(200);

        $policy = $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId));
        $this->assertSame('2026-06-30', $policy->valid_to->toDateString());

        $refreshedCharge = $this->inTenant($tenant, fn () => Charge::findOrFail($charge->id));
        $this->assertSame($originalAmount, (int) $refreshedCharge->getAttributes()['expected_amount']);
        $this->assertSame($originalStatusId, $refreshedCharge->charge_status_id);
    }

    // =========================================================================
    // periodicity_id unique-ignore: re-saving the SAME value must not 422.
    // =========================================================================

    public function test_periodicity_id_unique_ignores_self_when_unchanged(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);

        // Future valid_from: not billed yet, periodicity_id stays editable.
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 7, 0, 0));

        $policyId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota anual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 200_00,
                'periodicity_id' => Periodicity::YEARLY,
                'valid_from' => '2026-03-01',
                'valid_to' => '2026-12-31',
                'is_active' => true,
                'target_type' => 'all',
            ])
            ->assertStatus(201)
            ->json('data.id');

        // Sending the SAME periodicity_id back must not trip the active-policy unique
        // index against itself (Rule::unique(...)->ignore($policy->id)).
        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$policyId, [
                'periodicity_id' => Periodicity::YEARLY,
                'name' => 'Cuota anual (rev)',
            ])
            ->assertStatus(200);

        $policy = $this->inTenant($tenant, fn () => ChargePolicy::findOrFail($policyId));
        $this->assertSame(Periodicity::YEARLY, $policy->periodicity_id);
        $this->assertSame('Cuota anual (rev)', $policy->name);
    }

    // =========================================================================
    // has_billed correctness: withExists on index/show/search, no N+1.
    // =========================================================================

    public function test_has_billed_is_correct_on_index_show_and_search(): void
    {
        [$tenant, $token] = $this->singleTenant();
        $billedConcept = $this->makeConcept($tenant);
        $unbilledConcept = $this->makeConcept($tenant);
        $this->makeUnit($tenant);

        $billedId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $billedConcept->id,
                'name' => 'Política facturada',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ])
            ->assertStatus(201)
            ->json('data.id');

        // Explicitly targets 0 units (independent of the unit created above) → has_billed = false.
        $unbilledId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', [
                'charge_concept_id' => $unbilledConcept->id,
                'name' => 'Política sin facturar',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => []],
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ])
            ->assertStatus(201)
            ->json('data.id');

        // index
        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/charge_policy')
            ->assertStatus(200)
            ->json('data');
        $byId = collect($index)->keyBy('id');
        $this->assertTrue((bool) $byId[$billedId]['has_billed']);
        $this->assertFalse((bool) $byId[$unbilledId]['has_billed']);

        // show
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/charge_policy/'.$billedId)
            ->assertStatus(200)
            ->assertJsonPath('data.has_billed', true);
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/charge_policy/'.$unbilledId)
            ->assertStatus(200)
            ->assertJsonPath('data.has_billed', false);

        // search
        $search = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy/search', [])
            ->assertStatus(200)
            ->json('data');
        $bySearchId = collect($search)->keyBy('id');
        $this->assertTrue((bool) $bySearchId[$billedId]['has_billed']);
        $this->assertFalse((bool) $bySearchId[$unbilledId]['has_billed']);
    }

    // =========================================================================
    // Invariant: an eventual policy with >1 non-initial batch (data anomaly) must
    // abort a scope update instead of guessing which batch to touch.
    // =========================================================================

    public function test_eventual_policy_with_more_than_one_non_initial_batch_aborts_scope_update(): void
    {
        [$tenant] = $this->singleTenant();
        $concept = $this->makeConcept($tenant);

        $this->inTenant($tenant, function () use ($concept): void {
            $policy = app(ChargePolicyService::class)->create([
                'charge_concept_id' => $concept->id,
                'name' => 'Multa duplicada',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50_00,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'target_type' => 'all',
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
            ]);

            // Fabricate a SECOND non-initial batch for the same policy — an invariant
            // violation that should never happen via the normal flow (charge_batch_period_unique
            // only guards month IS NOT NULL rows, so two month=null batches don't collide at the
            // DB level), but the service must not silently guess which one to update.
            ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Lote duplicado',
                'year' => 2026,
                'month' => null,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
                'charge_batch_status_id' => ChargeBatchStatus::ACTIVE,
                'progress_pct' => 0,
                'is_initial' => false,
            ]);

            $this->expectException(HttpException::class);

            app(ChargePolicyService::class)->update($policy, [
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => []],
            ]);
        });
    }
}
