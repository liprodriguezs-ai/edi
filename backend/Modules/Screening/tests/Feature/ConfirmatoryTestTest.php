<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Models\ConfirmatoryTest;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-014 — confirmatory test capture: only for a completed, red
 * screening; at most one per screening.
 */
final class ConfirmatoryTestTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function completeScreening(string $token, $tenant, array $answers): string
    {
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        return $screeningId;
    }

    public function test_only_red_completed_screenings_admit_a_confirmatory_test(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $greenScreeningId = $this->completeScreening($token, $tenant, $this->g10GreenAnswers($version));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/confirmatory_test', ['screening_id' => $greenScreeningId, 'test_name' => 'Battelle'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONFIRMATORY_NOT_APPLICABLE');

        $this->inTenant($tenant, function () {
            $this->assertSame(0, ConfirmatoryTest::count());
        });
    }

    public function test_red_completed_screening_admits_exactly_one_confirmatory_test(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $redScreeningId = $this->completeScreening($token, $tenant, $this->g10RedAnswers($version));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/confirmatory_test', ['screening_id' => $redScreeningId, 'test_name' => 'Battelle'])
            ->assertCreated()
            ->assertJsonPath('data.test_name', 'Battelle');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/confirmatory_test', ['screening_id' => $redScreeningId, 'test_name' => 'Battelle'])
            ->assertStatus(422);

        $this->inTenant($tenant, function () {
            $this->assertSame(1, ConfirmatoryTest::count());
        });
    }
}
