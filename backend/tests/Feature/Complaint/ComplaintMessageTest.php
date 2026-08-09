<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the follow-up message thread (spec "Follow-up Message Thread"):
 *
 *  - A non-excluded manager can post and read the thread
 *  - A manager excluded per the unit-linkage rule is blocked from both
 *    reading and posting to the thread
 */
final class ComplaintMessageTest extends TestCase
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

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    private function linkToUnit(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });
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
    // Thread visible to an eligible manager
    // =========================================================================

    public function test_eligible_manager_can_post_and_read_the_thread(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $secretary = $this->makeUser($tenant, 'secretary');
        $catalog = $this->catalogIds($tenant);

        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Fuga de agua en área común',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_description' => 'Incidencia general del condominio',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');
        $secretaryToken = $this->token($secretary, ['read.complaint']);

        $this->tenantRequest($secretaryToken, $tenant)
            ->postJson("/api/complaint/{$complaintId}/message", ['body' => 'Ya se contactó a mantenimiento.'])
            ->assertCreated();

        $thread = $this->tenantRequest($secretaryToken, $tenant)
            ->getJson("/api/complaint/{$complaintId}/messages")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $thread);
        $this->assertSame('Ya se contactó a mantenimiento.', $thread[0]['body']);
    }

    // =========================================================================
    // Excluded manager blocked
    // =========================================================================

    public function test_excluded_manager_blocked_from_thread(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $treasurer);

        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra el tesorero',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_unit_id' => $unit->id,
                'against_description' => 'Uso indebido de fondos comunitarios',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');
        $treasurerToken = $this->token($treasurer, ['read.complaint']);

        $this->tenantRequest($treasurerToken, $tenant)
            ->getJson("/api/complaint/{$complaintId}/messages")
            ->assertStatus(403);

        $this->tenantRequest($treasurerToken, $tenant)
            ->postJson("/api/complaint/{$complaintId}/message", ['body' => 'Intento de acceso no autorizado.'])
            ->assertStatus(403);
    }
}
