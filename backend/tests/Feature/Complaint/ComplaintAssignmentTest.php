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
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for complaint assignment (spec "Assignment"):
 *
 *  - A manager assigns another manager-role user; the assignee is notified
 *  - An excluded (denounced-unit) user can never be assigned
 */
final class ComplaintAssignmentTest extends TestCase
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

    /** @return array<int, string> recipient user ids of the latest complaint_assigned notification */
    private function latestComplaintAssignedRecipientIds(Tenant $tenant): array
    {
        return $this->inTenant($tenant, function (): array {
            $notification = Notification::where('title', 'Se te asignó una incidencia')
                ->orderByDesc('created_at')
                ->first();

            if (! $notification) {
                return [];
            }

            return NotificationUser::where('notification_id', $notification->id)
                ->pluck('user_id')
                ->all();
        });
    }

    // =========================================================================
    // Manager assigns a complaint
    // =========================================================================

    public function test_manager_assigns_complaint_and_notifies_assignee(): void
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

        $this->tenantRequest($this->token($president, ['update.complaint']), $tenant)
            ->postJson("/api/complaint/{$complaintId}/assign", ['assigned_user_id' => $secretary->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', $secretary->id);

        $recipientIds = $this->latestComplaintAssignedRecipientIds($tenant);
        $this->assertContains($secretary->id, $recipientIds);
    }

    // =========================================================================
    // Excluded assignee rejected
    // =========================================================================

    public function test_excluded_user_cannot_be_assigned(): void
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

        $this->tenantRequest($this->token($president, ['update.complaint']), $tenant)
            ->postJson("/api/complaint/{$complaintId}/assign", ['assigned_user_id' => $treasurer->id])
            ->assertStatus(422);
    }

    // =========================================================================
    // Eligible assignees (review finding W6)
    // =========================================================================

    /**
     * GET /complaint/{id}/eligible-assignees feeds the frontend picker with
     * configured managers MINUS the unit-linkage exclusion set — the excluded
     * treasurer must never appear, even though they hold a manager role.
     */
    public function test_eligible_assignees_excludes_the_denounced_unit_manager(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $secretary = $this->makeUser($tenant, 'secretary');
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

        $eligibleIds = $this->tenantRequest($this->token($president, ['read.complaint']), $tenant)
            ->getJson("/api/complaint/{$complaintId}/eligible-assignees")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($president->id, $eligibleIds);
        $this->assertContains($secretary->id, $eligibleIds);
        $this->assertNotContains($treasurer->id, $eligibleIds);
    }
}
