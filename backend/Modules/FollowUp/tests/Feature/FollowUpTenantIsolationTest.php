<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/**
 * REQ-FUP-010.
 */
final class FollowUpTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithFollowUpFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * administratorToken (not twoTenants()'s wildcard '*' token): the
     * screening creation route is gated by the `permission:create.screening`
     * ROUTE MIDDLEWARE, which checks the user's real Spatie role
     * permissions -- a bare token ability of '*' does not satisfy it.
     *
     * @return array{0: Tenant, 1: string, 2: Tenant, 3: string}
     */
    private function twoAdministeredTenants(): array
    {
        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        return [$tenantA, $this->administratorToken($tenantA), $tenantB, $this->administratorToken($tenantB)];
    }

    public function test_cross_tenant_follow_up_is_invisible(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenA, $tenantB, $tokenB] = $this->twoAdministeredTenants();
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($tokenA, $tenantA, $fx['visit'], $this->g10RedAnswers($version));

        $followUpId = $this->inTenant($tenantA, fn () => FollowUp::where('screening_id', $screeningId)->firstOrFail()->id);

        $this->tenantRequest($tokenB, $tenantB)
            ->getJson("/api/follow_up/{$followUpId}")
            ->assertNotFound();
    }

    public function test_a_foreign_screening_id_is_rejected(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenA, $tenantB, $tokenB] = $this->twoAdministeredTenants();
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($tokenA, $tenantA, $fx['visit'], $this->g10RedAnswers($version));

        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/follow_up', [
                'screening_id' => $screeningId,
                'follow_up_type_id' => FollowUpType::CONFIRMATORY_TEST,
                'due_date' => now()->addDays(30)->toDateString(),
            ])
            ->assertStatus(422);
    }
}
