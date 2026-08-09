<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for tenancy isolation (spec "Tenancy Isolation"):
 *
 *  - A complaint belonging to tenant A is not-found when requested by an
 *    authenticated manager of tenant B.
 */
final class ComplaintTenancyTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    /** @return array<string, string> */
    private function catalogIds(Tenant $tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids): void {
            $ids['category_id'] = ComplaintCategory::first()->id;
            $ids['priority_id'] = ComplaintPriority::first()->id;
        });

        return $ids;
    }

    // =========================================================================
    // Cross-tenant read blocked
    // =========================================================================

    public function test_cross_tenant_read_returns_not_found(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $presidentA = $this->makeUser($tenantA, 'president');
        $catalogA = $this->catalogIds($tenantA);

        $response = $this->tenantRequest($this->token($presidentA, ['create.complaint']), $tenantA)
            ->postJson('/api/complaint', [
                'title' => 'Fuga de agua en área común',
                'complaint_category_id' => $catalogA['category_id'],
                'complaint_priority_id' => $catalogA['priority_id'],
                'against_description' => 'Incidencia general del condominio A',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');

        $presidentB = $this->makeUser($tenantB, 'president');

        $this->tenantRequest($this->token($presidentB, ['read.complaint']), $tenantB)
            ->getJson("/api/complaint/{$complaintId}")
            ->assertNotFound();
    }
}
