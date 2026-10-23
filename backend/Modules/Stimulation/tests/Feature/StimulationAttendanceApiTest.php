<?php

declare(strict_types=1);

namespace Modules\Stimulation\Tests\Feature;

use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\StimulationAttendance;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupMember;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use QCH\Core\Support\TenantTime;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SA-001 / REQ-SA-002 coverage.
 *
 * The bulk-upsert endpoint is gated by the `permission` middleware (real
 * Spatie role permissions via getPermissionsViaRoles()), NOT by Sanctum
 * token ability scope — a wildcard `['*']` token alone is NOT enough here
 * (unlike Orion resource routes). Every test needs the platform module/
 * permission/role registry seeded and a REAL administrator role assigned.
 */
final class StimulationAttendanceApiTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** Civil "today" of the test tenants (they keep the default zone), never the UTC day. */
    private function tenantToday(): string
    {
        return TenantTime::today(Tenant::DEFAULT_TIMEZONE);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function seedPlatformRegistry(): void
    {
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SystemTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolePermissionTableSeeder::class, '--force' => true]);
    }

    private function adminToken(Tenant $tenant): string
    {
        $this->seedPlatformRegistry();
        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);

        return $user->createToken('test', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;
    }

    /**
     * @return array{0: StimulationGroup, 1: array<int, StimulationGroupMember>}
     */
    private function makeGroupWithMembers(Tenant $tenant, int $count = 3): array
    {
        return $this->inTenant($tenant, function () use ($count) {
            $group = StimulationGroup::factory()->create(['start_date' => now()->subMonth()->toDateString()]);
            $members = [];
            for ($i = 0; $i < $count; $i++) {
                $members[] = StimulationGroupMember::factory()->create([
                    'stimulation_group_id' => $group->id,
                    'child_id' => Child::factory()->create()->id,
                    'default_attendance_payment_status_id' => AttendancePaymentStatus::SCHOLARSHIP,
                ]);
            }

            return [$group, $members];
        });
    }

    public function test_idempotent_replay(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 3);
        $date = $this->tenantToday();

        $entries = collect($members)->map(fn ($m) => [
            'child_id' => $m->child_id,
            'attended' => true,
            'homework_done' => false,
        ])->all();

        $payload = ['session_date' => $date, 'entries' => $entries];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', $payload)
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', $payload)
            ->assertOk();

        $tenant->run(function () use ($group, $date): void {
            $this->assertSame(3, StimulationAttendance::where('stimulation_group_id', $group->id)
                ->where('session_date', $date)->count());
        });
    }

    public function test_member_default_payment_status_applied(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 1);
        $member = $members[0];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => $this->tenantToday(),
                'entries' => [
                    ['child_id' => $member->child_id, 'attended' => true],
                ],
            ])
            ->assertOk();

        $tenant->run(function () use ($group, $member): void {
            $row = StimulationAttendance::where('stimulation_group_id', $group->id)
                ->where('child_id', $member->child_id)->firstOrFail();
            $this->assertSame(AttendancePaymentStatus::SCHOLARSHIP, $row->attendance_payment_status_id);
        });
    }

    public function test_non_member_entry_aborts_the_batch(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 2);
        $nonMemberChild = $this->inTenant($tenant, fn () => Child::factory()->create());

        $entries = [
            ['child_id' => $members[0]->child_id, 'attended' => true],
            ['child_id' => $members[1]->child_id, 'attended' => true],
            ['child_id' => $nonMemberChild->id, 'attended' => true],
        ];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => $this->tenantToday(),
                'entries' => $entries,
            ])
            ->assertStatus(422);

        $tenant->run(function () use ($group): void {
            $this->assertSame(0, StimulationAttendance::where('stimulation_group_id', $group->id)->count());
        });
    }

    public function test_date_bounds(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 1);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => now()->addDay()->toDateString(),
                'entries' => [['child_id' => $members[0]->child_id, 'attended' => true]],
            ])
            ->assertStatus(422);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => now()->subYears(2)->toDateString(),
                'entries' => [['child_id' => $members[0]->child_id, 'attended' => true]],
            ])
            ->assertStatus(422);
    }

    public function test_inactive_group_rejects_attendance(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 1);
        $this->inTenant($tenant, fn () => $group->update(['is_active' => false]));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => $this->tenantToday(),
                'entries' => [['child_id' => $members[0]->child_id, 'attended' => true]],
            ])
            ->assertStatus(422);
    }

    public function test_no_destroy_route_exposed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->adminToken($tenant);
        [$group, $members] = $this->makeGroupWithMembers($tenant, 1);
        $attendance = $this->inTenant($tenant, fn () => StimulationAttendance::factory()->create([
            'stimulation_group_id' => $group->id,
            'child_id' => $members[0]->child_id,
        ]));

        // The show route (GET) exists for this URI pattern, so an unsupported
        // DELETE resolves to 405 Method Not Allowed, not 404 — proof the
        // destroy action itself was never registered (Orion ->only([...])).
        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/stimulation_attendance/'.$attendance->id)
            ->assertStatus(405);
    }

    public function test_tenant_isolation_of_reads(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();
        [$groupA, $membersA] = $this->makeGroupWithMembers($tenantA, 1);
        $this->inTenant($tenantA, fn () => StimulationAttendance::factory()->create([
            'stimulation_group_id' => $groupA->id,
            'child_id' => $membersA[0]->child_id,
        ]));
        [$groupB, $membersB] = $this->makeGroupWithMembers($tenantB, 1);
        $this->inTenant($tenantB, fn () => StimulationAttendance::factory()->create([
            'stimulation_group_id' => $groupB->id,
            'child_id' => $membersB[0]->child_id,
        ]));

        $response = $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/stimulation_attendance')
            ->assertOk();

        $groupIds = collect($response->json('data'))->pluck('stimulation_group_id')->unique()->all();
        $this->assertSame([$groupA->id], $groupIds);
    }
}
