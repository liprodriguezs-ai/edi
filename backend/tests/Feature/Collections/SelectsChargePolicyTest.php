<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\Periodicity;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Regression test for POST /api/selects covering the `charge_policy` catalog.
 *
 * Why this exists: AngularController::selects() resolves each request key through
 * $mapperSelects and calls app($this->mapperSelects[$nameModel]). `ChargePolicy`
 * was missing from $mapperSelects, so the request fell into the
 * `app("App\\Modules\\ChargePolicy\\ChargePolicy")` branch — a class that does not
 * exist — producing an HTTP 500 and leaving the policy dropdown empty, which blocked
 * batch creation from the UI. There was NO test of /selects at all, so it slipped in.
 *
 * The frontend posts exactly:
 *   { "charge_policy": { "_sort": "name", "_with": "concept,periodicity" } }
 *
 * Contract verified here:
 *   - 200 (not 500) when requesting the charge_policy select.
 *   - The created policy appears under data.charge_policy.
 *   - The eager `_with=concept,periodicity` relations are loaded.
 *   - Tenant isolation: a policy from another tenant never leaks in.
 */
final class SelectsChargePolicyTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /**
     * Tenants run outside DB transactions in this suite because the per-tenant
     * catalog seeding relies on tenancy context switching; transactional rollback
     * would interfere. Matches the sibling Collections feature tests.
     *
     * @var array<string>
     */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * The exact body the Angular frontend posts for the policy dropdown.
     *
     * @return array<string, array<string, string>>
     */
    private function chargePolicySelectBody(): array
    {
        return [
            'charge_policy' => [
                '_sort' => 'name',
                '_with' => 'concept,periodicity',
            ],
        ];
    }

    /**
     * Create a charge_concept inside the tenant scope and return its id.
     */
    private function makeChargeConcept(Tenant $tenant, string $name): string
    {
        return (string) $tenant->run(static function () use ($name): string {
            $categoryId = DB::table('charge_concept_category')->value('id');

            $id = (string) Str::uuid();
            DB::table('charge_concept')->insert([
                'id' => $id,
                'code' => Str::upper(Str::random(6)),
                'name' => $name,
                'charge_concept_category_id' => $categoryId,
                'is_active' => true,
                'tenant_id' => tenancy()->tenant->id,
                'user_created_id' => null,
                'user_updated_id' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return $id;
        });
    }

    /**
     * Create a periodic charge_policy (with periodicity + concept) inside the
     * tenant scope and return its id. Periodicity/AmountMode are central catalogs
     * seeded by CatalogSeeder, so we reference their fixed UUID constants.
     */
    private function makeChargePolicy(Tenant $tenant, string $conceptId, string $name): string
    {
        return (string) $tenant->run(static function () use ($conceptId, $name): string {
            $id = (string) Str::uuid();
            DB::table('charge_policy')->insert([
                'id' => $id,
                'charge_concept_id' => $conceptId,
                'name' => $name,
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 150000,
                'grace_days' => 5,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => now()->toDateString(),
                'valid_to' => now()->addYear()->toDateString(),
                'is_active' => true,
                'tenant_id' => tenancy()->tenant->id,
                'user_created_id' => null,
                'user_updated_id' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return $id;
        });
    }

    public function test_selects_charge_policy_returns_200_with_the_created_policy(): void
    {
        $tenantA = $this->makeTenant('Cluster Selects Policy');
        $this->seedTenant($tenantA);

        $conceptId = $this->makeChargeConcept($tenantA, 'Mantenimiento');
        $policyId = $this->makeChargePolicy($tenantA, $conceptId, 'Mantenimiento mensual');

        // Any authenticated tenant user reaches the endpoint — /selects only carries
        // auth:sanctum + initializeTenancy, no `permission` middleware.
        $token = $this->makeUser($tenantA)->createToken('selects', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->postJson('/api/selects', $this->chargePolicySelectBody());

        // Regression guard: previously this returned 500 because ChargePolicy was
        // missing from $mapperSelects.
        $response->assertOk();

        $policies = $response->json('data.charge_policy');
        $this->assertIsArray($policies);
        $this->assertCount(1, $policies, 'exactly the one seeded policy must come back');

        $returnedIds = array_column($policies, 'id');
        $this->assertContains($policyId, $returnedIds, 'the created policy must be present');
    }

    public function test_selects_charge_policy_eager_loads_concept_and_periodicity(): void
    {
        $tenantA = $this->makeTenant('Cluster Selects With');
        $this->seedTenant($tenantA);

        $conceptId = $this->makeChargeConcept($tenantA, 'Agua');
        $this->makeChargePolicy($tenantA, $conceptId, 'Cuota de agua');

        $token = $this->makeUser($tenantA)->createToken('selects', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->postJson('/api/selects', $this->chargePolicySelectBody());

        $response->assertOk();

        $policy = $response->json('data.charge_policy.0');
        $this->assertNotNull($policy);

        // _with=concept,periodicity must hydrate both relations.
        $this->assertArrayHasKey('concept', $policy);
        $this->assertNotNull($policy['concept'], 'concept relation must be eager-loaded');
        $this->assertSame($conceptId, $policy['concept']['id']);
        $this->assertSame('Agua', $policy['concept']['name']);

        $this->assertArrayHasKey('periodicity', $policy);
        $this->assertNotNull($policy['periodicity'], 'periodicity relation must be eager-loaded');
        $this->assertSame(Periodicity::MONTHLY, $policy['periodicity']['id']);
    }

    public function test_selects_charge_policy_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant('Cluster Selects IsoA');
        $tenantB = $this->makeTenant('Cluster Selects IsoB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Tenant A gets one policy; tenant B gets a different one.
        $conceptA = $this->makeChargeConcept($tenantA, 'Mantenimiento A');
        $policyA = $this->makeChargePolicy($tenantA, $conceptA, 'Política A');

        $conceptB = $this->makeChargeConcept($tenantB, 'Mantenimiento B');
        $policyB = $this->makeChargePolicy($tenantB, $conceptB, 'Política B');

        $token = $this->makeUser($tenantA)->createToken('selects', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->postJson('/api/selects', $this->chargePolicySelectBody());

        $response->assertOk();

        $returnedIds = array_column($response->json('data.charge_policy'), 'id');

        $this->assertContains($policyA, $returnedIds, 'tenant A must see its own policy');
        $this->assertNotContains($policyB, $returnedIds, "tenant B's policy must NOT leak into tenant A");
        $this->assertCount(1, $returnedIds, 'only tenant A policies are visible');
    }

    /**
     * The body the frontend posts for the charge_concept dropdown (direct-charge picker
     * + charge-list concept filter).
     *
     * @return array<string, array<string, string>>
     */
    private function chargeConceptSelectBody(): array
    {
        return [
            'charge_concept' => [
                '_sort' => 'name',
            ],
        ];
    }

    /**
     * The technical fallback concept (code OTHER) is resolved server-side by the free-text
     * cobro-directo path; it must NEVER surface as a pickable option in the charge_concept
     * /selects catalog, or the direct-charge sheet would show a redundant "Otro" next to its
     * synthetic free-text option. AngularController::selects() excludes it via $filterEspecial
     * (code is unique + NOT NULL, so only that one system row is hidden). Legitimate admin
     * concepts (any other code) still come back.
     */
    public function test_selects_charge_concept_excludes_the_technical_other_concept(): void
    {
        $tenantA = $this->makeTenant('Cluster Selects Concepts');
        $this->seedTenant($tenantA);

        // A legitimate admin concept that MUST appear, plus the seeded OTHER that must NOT.
        $realId = $this->makeChargeConcept($tenantA, 'Mantenimiento');
        $otherId = (string) $tenantA->run(
            static fn (): string => (string) ChargeConcept::where('code', 'OTHER')->value('id')
        );

        $token = $this->makeUser($tenantA)->createToken('selects', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenantA)
            ->postJson('/api/selects', $this->chargeConceptSelectBody());

        $response->assertOk();

        $concepts = $response->json('data.charge_concept');
        $this->assertIsArray($concepts);

        $returnedIds = array_column($concepts, 'id');
        $returnedCodes = array_column($concepts, 'code');

        $this->assertContains($realId, $returnedIds, 'a legitimate admin concept must still be selectable');
        $this->assertNotContains($otherId, $returnedIds, 'the technical OTHER concept must be excluded');
        $this->assertNotContains('OTHER', $returnedCodes, 'no OTHER-coded row may reach the dropdown');
    }

    public function test_selects_requires_authentication(): void
    {
        $this->postJson('/api/selects', $this->chargePolicySelectBody())
            ->assertStatus(401);
    }
}
