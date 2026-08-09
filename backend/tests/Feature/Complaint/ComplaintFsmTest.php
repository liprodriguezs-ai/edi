<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Complaint\Models\Complaint;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Complaint\Models\ComplaintStatus;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the complaint status FSM (spec "Status FSM"):
 *
 *  - A manager-role user moves open -> in_review
 *  - A manager may reopen a terminal (resolved/dismissed) complaint, which
 *    clears resolved_at (amended spec scenario)
 *  - A non-manager is denied any transition
 *  - An invalid direct swap (resolved -> dismissed) is rejected
 */
final class ComplaintFsmTest extends TestCase
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

    private function registerComplaint(Tenant $tenant, User $president, array $catalog): string
    {
        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Fuga de agua en área común',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_description' => 'Incidencia general del condominio',
            ])
            ->assertCreated();

        return $response->json('data.id');
    }

    private function fetchComplaint(Tenant $tenant, string $complaintId): Complaint
    {
        return $this->inTenant($tenant, fn () => Complaint::findOrFail($complaintId));
    }

    // =========================================================================
    // Valid transition
    // =========================================================================

    public function test_manager_can_transition_open_to_in_review(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);
        $complaintId = $this->registerComplaint($tenant, $president, $catalog);

        $this->tenantRequest($this->token($president, ['update.complaint']), $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", [
                'complaint_status_id' => ComplaintStatus::IN_REVIEW,
            ])
            ->assertOk()
            ->assertJsonPath('data.complaint_status_id', ComplaintStatus::IN_REVIEW);
    }

    // =========================================================================
    // Reopen clears resolved_at
    // =========================================================================

    public function test_manager_reopen_resolved_clears_resolved_at(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);
        $complaintId = $this->registerComplaint($tenant, $president, $catalog);
        $token = $this->token($president, ['update.complaint']);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::RESOLVED])
            ->assertOk();

        $resolved = $this->fetchComplaint($tenant, $complaintId);
        $this->assertNotNull($resolved->resolved_at);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::OPEN])
            ->assertOk()
            ->assertJsonPath('data.complaint_status_id', ComplaintStatus::OPEN);

        $reopened = $this->fetchComplaint($tenant, $complaintId);
        $this->assertNull($reopened->resolved_at);
    }

    public function test_manager_reopen_dismissed_clears_resolved_at(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);
        $complaintId = $this->registerComplaint($tenant, $president, $catalog);
        $token = $this->token($president, ['update.complaint']);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::DISMISSED])
            ->assertOk();

        $dismissed = $this->fetchComplaint($tenant, $complaintId);
        $this->assertNotNull($dismissed->resolved_at);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::IN_REVIEW])
            ->assertOk();

        $reopened = $this->fetchComplaint($tenant, $complaintId);
        $this->assertNull($reopened->resolved_at);
    }

    // =========================================================================
    // Non-manager blocked
    // =========================================================================

    public function test_non_manager_cannot_transition(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $resident = $this->makeUser($tenant, 'resident');
        $catalog = $this->catalogIds($tenant);
        $complaintId = $this->registerComplaint($tenant, $president, $catalog);

        // Resident's token lacks update.complaint entirely — denied at the
        // coarse gate before ComplaintService::changeStatus ever runs.
        $this->tenantRequest($this->token($resident, ['read.complaint']), $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::IN_REVIEW])
            ->assertStatus(403);
    }

    // =========================================================================
    // Invalid transition
    // =========================================================================

    public function test_invalid_direct_transition_between_terminal_states_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);
        $complaintId = $this->registerComplaint($tenant, $president, $catalog);
        $token = $this->token($president, ['update.complaint']);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::RESOLVED])
            ->assertOk();

        // resolved -> dismissed is not a permitted direct swap (must reopen first).
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::DISMISSED])
            ->assertStatus(422);
    }
}
