<?php

declare(strict_types=1);

namespace Modules\Stimulation\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupMember;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SG-002 coverage.
 */
final class StimulationGroupMemberApiTest extends TestCase
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

    private function adminToken(Tenant $tenant): string
    {
        $user = $this->makeUser($tenant);

        return $user->createToken('test', ['*'])->plainTextToken;
    }

    public function test_duplicate_enrollment_rejected_reenrollment_after_unenroll_allowed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);

        [$group, $child] = $this->inTenant($tenant, fn () => [
            StimulationGroup::factory()->create(),
            Child::factory()->create(),
        ]);

        $payload = [
            'stimulation_group_id' => $group->id,
            'child_id' => $child->id,
            'default_attendance_payment_status_id' => AttendancePaymentStatus::UNPAID,
        ];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group_member', $payload)
            ->assertCreated();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group_member', $payload)
            ->assertStatus(422);

        $member = $this->inTenant($tenant, fn () => StimulationGroupMember::where('child_id', $child->id)->firstOrFail());
        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/stimulation_group_member/'.$member->id)
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group_member', $payload)
            ->assertCreated();
    }

    public function test_cross_tenant_child_rejected(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        $group = $this->inTenant($tenantA, fn () => StimulationGroup::factory()->create());
        $otherChild = $this->inTenant($tenantB, fn () => Child::factory()->create());

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/stimulation_group_member', [
                'stimulation_group_id' => $group->id,
                'child_id' => $otherChild->id,
                'default_attendance_payment_status_id' => AttendancePaymentStatus::UNPAID,
            ])
            ->assertStatus(422);
    }

    public function test_inactive_group_rejects_enrollment(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);

        [$group, $child] = $this->inTenant($tenant, fn () => [
            StimulationGroup::factory()->create(['is_active' => false]),
            Child::factory()->create(),
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group_member', [
                'stimulation_group_id' => $group->id,
                'child_id' => $child->id,
                'default_attendance_payment_status_id' => AttendancePaymentStatus::UNPAID,
            ])
            ->assertStatus(422);
    }
}
