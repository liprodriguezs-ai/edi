<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\Periodicity;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for ChargePolicy create/update validation hardening (R9), which now
 * owns the charge BEHAVIOR (amount mode/amounts, periodicity, validity window):
 *
 *  - BUG-R9-1: amount_mode_id == RANGE must satisfy min ≤ ideal ≤ max → 422
 *    (server-side defense; the API can't persist an incoherent range).
 *  - BUG-R9-2: valid_from > valid_to returns a Spanish message for
 *    valid_to.after_or_equal (not the raw "validation.after_or_equal" key).
 *
 * The validation moved from ChargeConcept to ChargePolicy when the concept was
 * slimmed to identity-only.
 */
final class ChargeConceptRangeAndDateValidationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function singleTenant(): array
    {
        $tenant = $this->makeTenant('Cluster '.uniqid());
        $this->seedTenant($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function makeConceptId(Tenant $tenant): string
    {
        return $this->inTenant($tenant, function (): string {
            $categoryId = Catalog::id(ChargeConceptCategory::class, 'Ordinaria');

            return ChargeConcept::create([
                'code' => 'IDENT_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'Concepto identidad',
                'charge_concept_category_id' => $categoryId,
                'is_active' => true,
            ])->id;
        });
    }

    /**
     * Base payload for a valid RANGE policy; overrides let each test break one rule.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rangePayload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'charge_concept_id' => $this->makeConceptId($tenant),
            'name' => 'Política rango',
            'amount_mode_id' => AmountMode::RANGE,
            'periodicity_id' => Periodicity::MONTHLY,
            'min_amount' => 10000,
            'ideal_amount' => 15000,
            'max_amount' => 20000,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'is_active' => true,
            // A rule is configured 1-to-1 with its initial batch → target_type is required.
            'target_type' => 'all',
        ], $overrides);
    }

    // =========================================================================
    // BUG-R9-1 — range coherence (min ≤ ideal ≤ max)
    // =========================================================================

    public function test_range_concept_with_coherent_amounts_is_created(): void
    {
        [$tenant, $token] = $this->singleTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant))
            ->assertStatus(201);
    }

    public function test_range_min_greater_than_ideal_returns_422(): void
    {
        [$tenant, $token] = $this->singleTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant, [
                'min_amount' => 18000,
                'ideal_amount' => 15000, // ideal < min
                'max_amount' => 20000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_amount');
    }

    public function test_range_ideal_greater_than_max_returns_422(): void
    {
        [$tenant, $token] = $this->singleTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant, [
                'min_amount' => 10000,
                'ideal_amount' => 25000, // ideal > max
                'max_amount' => 20000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ideal_amount');
    }

    public function test_range_min_greater_than_max_returns_422(): void
    {
        [$tenant, $token] = $this->singleTenant();

        // ideal equals min so the explicit min ≤ max guard is exercised.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant, [
                'min_amount' => 30000,
                'ideal_amount' => 30000,
                'max_amount' => 20000, // max < min
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_amount');
    }

    public function test_range_update_with_incoherent_amounts_returns_422(): void
    {
        [$tenant, $token] = $this->singleTenant();

        // Create a coherent policy first.
        $id = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant))
            ->assertStatus(201)
            ->json('data.id');

        // Update it to an incoherent range → 422.
        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/charge_policy/'.$id, [
                'amount_mode_id' => AmountMode::RANGE,
                'min_amount' => 50000,
                'ideal_amount' => 10000,
                'max_amount' => 20000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_amount');
    }

    // =========================================================================
    // BUG-R9-2 — Spanish message for valid_to before valid_from
    // =========================================================================

    public function test_valid_to_before_valid_from_returns_spanish_message(): void
    {
        [$tenant, $token] = $this->singleTenant();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/charge_policy', $this->rangePayload($tenant, [
                'valid_from' => '2026-06-01',
                'valid_to' => '2026-01-01', // before valid_from
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_to');

        $message = $response->json('errors.valid_to.0');
        $this->assertSame("La fecha 'Hasta' debe ser posterior o igual a 'Vigente desde'.", $message);
        $this->assertStringNotContainsString('validation.', (string) $message);
    }
}
