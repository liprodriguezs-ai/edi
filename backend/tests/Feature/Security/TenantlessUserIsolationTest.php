<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Company\Models\Company;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * A logged-in user who belongs to NO organization must not see any organization.
 *
 * Stancl's TenantScope adds no condition while tenancy is uninitialized, which is
 * the right behaviour for console and seeders but fail-open for an authenticated
 * user: every tenant-scoped query ran unfiltered, exposing (and allowing writes to)
 * every organization's clinical data.
 *
 * Every user here carries a WILDCARD token on purpose, so a failure can only mean
 * the isolation gate is missing -- never a permission check standing in for it.
 */
final class TenantlessUserIsolationTest extends TestCase
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

    /**
     * Token for a user attached to no tenant at all.
     */
    private function tenantlessToken(): string
    {
        $orphan = User::create([
            'name' => 'Sin organización',
            'email' => 'orphan@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        return $orphan->createToken('orphan', ['*'])->plainTextToken;
    }

    public function test_tenantless_user_cannot_list_children_of_every_organization(): void
    {
        $this->twoTenants();

        $this->app['auth']->forgetGuards();

        $this->withToken($this->tenantlessToken())
            ->getJson('/api/child')
            ->assertForbidden();
    }

    public function test_tenantless_user_cannot_read_organizations(): void
    {
        $this->twoTenants();

        $this->app['auth']->forgetGuards();

        $this->withToken($this->tenantlessToken())
            ->getJson('/api/company')
            ->assertForbidden();
    }

    /**
     * Denial must not cost them the ability to end their own session; otherwise a
     * user in this state is trapped in a session they cannot even sign out of.
     */
    public function test_tenantless_user_can_still_reach_their_own_session(): void
    {
        $this->twoTenants();

        $this->app['auth']->forgetGuards();

        $this->withToken($this->tenantlessToken())
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    /**
     * The other half of the leak: WITH an organization active, the company table
     * was still unscoped, so one organization listed (and could overwrite) another's
     * legal identity.
     */
    public function test_organization_only_sees_its_own_company(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $this->inTenant($tenantB, function () use ($tenantB): void {
            Company::create([
                'name' => 'Organización B',
                'slug' => 'organizacion-b-leak',
                'domain' => '',
                'main' => true,
                'tenant_id' => $tenantB->getTenantKey(),
            ]);
        });

        $response = $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/company')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('Organización B', $names, 'Another organization leaked into the company list.');
    }
}
