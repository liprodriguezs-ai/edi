<?php

declare(strict_types=1);

namespace Modules\Stimulation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SG-001 coverage.
 */
final class StimulationGroupApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeHealthCenter(): HealthCenter
    {
        $municipality = HealthCenterFactory::defaultMunicipality();

        return HealthCenter::factory()->create(['municipality_id' => $municipality->id]);
    }

    private function adminToken(Tenant $tenant): string
    {
        $user = $this->makeUser($tenant);

        return $user->createToken('test', ['*'])->plainTextToken;
    }

    private function payload(Tenant $tenant, array $overrides = []): array
    {
        [$healthCenter, $groupType, $promoter] = $this->inTenant($tenant, function () use ($tenant) {
            $healthCenter = $this->makeHealthCenter();
            $groupType = StimulationGroupType::first() ?? StimulationGroupType::factory()->create();
            $promoter = $this->makeUser($tenant);

            return [$healthCenter, $groupType, $promoter];
        });

        return array_merge([
            'name' => 'Grupo de prueba',
            'stimulation_group_type_id' => $groupType->id,
            'health_center_id' => $healthCenter->id,
            'promoter_user_id' => $promoter->id,
            'start_date' => now()->toDateString(),
        ], $overrides);
    }

    public function test_name_uniqueness_is_tenant_and_soft_delete_aware(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $tokenB = $this->adminToken($tenantB);

        $payloadA = $this->payload($tenantA, ['name' => 'Estimulación matutina']);
        $this->tenantRequest($tokenA, $tenantA)->postJson('/api/stimulation_group', $payloadA)->assertCreated();

        // same tenant, same name -> 422
        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_group', $this->payload($tenantA, ['name' => 'Estimulación matutina']))
            ->assertStatus(422);

        // another tenant, same name -> success
        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/stimulation_group', $this->payload($tenantB, ['name' => 'Estimulación matutina']))
            ->assertCreated();

        // soft-delete then recreate in tenant A -> success
        $group = $this->inTenant($tenantA, fn () => StimulationGroup::where('name', 'Estimulación matutina')->firstOrFail());
        $this->inTenant($tenantA, fn () => $group->delete());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_group', $this->payload($tenantA, ['name' => 'Estimulación matutina']))
            ->assertCreated();
    }

    public function test_promoter_must_belong_to_the_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $outsider = $this->inTenant($tenantB, fn () => User::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_group', $this->payload($tenantA, ['promoter_user_id' => $outsider->id]))
            ->assertStatus(422);
    }

    public function test_incoherent_dates_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group', $this->payload($tenant, [
                'start_date' => now()->toDateString(),
                'end_date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422);
    }
}
