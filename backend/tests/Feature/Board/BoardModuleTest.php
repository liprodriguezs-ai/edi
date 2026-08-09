<?php

declare(strict_types=1);

namespace Tests\Feature\Board;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Board\Services\BoardService;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 3 — Board (Mesa Directiva).
 *
 * Covers:
 *  - Catalog seeding (board_role, board_period_status)
 *  - One-current-period invariant
 *  - Role grant on period start (critical: user HAS Spatie role)
 *  - Role revoke on period close (critical: user LOSES Spatie role)
 *  - Multiple vocales allowed
 *  - Name uniqueness per tenant
 *  - Tenant isolation
 */
final class BoardModuleTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Seed the global Spatie roles so assignRole/removeRole can find them
        $this->seedGlobalRoles();
    }

    /**
     * Seed the Spatie global roles needed for board role grant/revoke.
     * Uses App\Models\Role (the Spatie-compatible model with HasUuids + CentralConnection)
     * so that getKey() returns the `uuid` column value correctly.
     */
    private function seedGlobalRoles(): void
    {
        $boardSlugs = ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'];
        foreach ($boardSlugs as $slug) {
            Role::firstOrCreate(
                ['name' => $slug, 'guard_name' => 'api'],
                ['name' => $slug, 'guard_name' => 'api'],
            );
        }

        // Clear Spatie permission cache so the new roles are visible
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeCentralUser(string $name = 'Test User'): User
    {
        return User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * Build the three mandatory board members (President, Treasurer, Secretary) so a
     * startPeriod call satisfies the §7.3.2 minimum. Each gets a fresh central user.
     * Must be called inside the tenant context.
     *
     * @return array<int, array{user_id: string, board_role_id: string, unit_id: null}>
     */
    private function mandatoryMembers(): array
    {
        return [
            ['user_id' => $this->makeCentralUser('Presidente')->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
            ['user_id' => $this->makeCentralUser('Tesorero')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
            ['user_id' => $this->makeCentralUser('Secretario')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
        ];
    }

    // =========================================================================
    // CATALOG SEEDING
    // =========================================================================

    public function test_fresh_tenant_has_five_board_roles_after_seeding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(5, BoardRole::count());
            $ids = BoardRole::orderBy('order')->pluck('id')->all();
            $this->assertSame([
                BoardRole::PRESIDENT,
                BoardRole::TREASURER,
                BoardRole::SECRETARY,
                BoardRole::VOCAL,
                BoardRole::OVERSIGHT_COMMITTEE,
            ], $ids);
        });
    }

    public function test_fresh_tenant_has_two_board_period_statuses_after_seeding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(2, BoardPeriodStatus::count());
            $ids = BoardPeriodStatus::orderBy('order')->pluck('id')->all();
            $this->assertSame([BoardPeriodStatus::CURRENT, BoardPeriodStatus::CLOSED], $ids);
        });
    }

    public function test_board_roles_have_correct_spatie_slugs(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $slugMap = BoardRole::pluck('spatie_role_slug', 'id')->all();
            $this->assertSame('president', $slugMap[BoardRole::PRESIDENT]);
            $this->assertSame('treasurer', $slugMap[BoardRole::TREASURER]);
            $this->assertSame('secretary', $slugMap[BoardRole::SECRETARY]);
            $this->assertSame('vocal', $slugMap[BoardRole::VOCAL]);
            $this->assertSame('oversight_committee', $slugMap[BoardRole::OVERSIGHT_COMMITTEE]);
        });
    }

    // =========================================================================
    // ROLE GRANT / REVOKE — CRITICAL
    // =========================================================================

    public function test_starting_period_grants_spatie_role_to_president(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser('Presidente Test');

        $tenant->run(function () use ($user): void {
            $presidentRole = BoardRole::findOrFail(BoardRole::PRESIDENT);
            $service = app(BoardService::class);

            $service->startPeriod(
                periodData: [
                    'name' => '2025-2026',
                    'start_date' => '2025-06-01',
                    'end_date' => '2026-05-31',
                    'auto_close_current' => false,
                ],
                membersData: [
                    ['user_id' => $user->id, 'board_role_id' => $presidentRole->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );
        });

        // After period start, user must have global Spatie 'president' role
        $freshUser = User::withoutGlobalScopes()->find($user->id);
        $this->assertTrue(
            $freshUser->hasRole('president'),
            'User should have Spatie president role after being assigned as board president'
        );
    }

    public function test_closing_period_revokes_spatie_role_from_president(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser('Ex-Presidente Test');

        $period = null;

        $tenant->run(function () use ($user, &$period): void {
            $presidentRole = BoardRole::findOrFail(BoardRole::PRESIDENT);
            $service = app(BoardService::class);

            $period = $service->startPeriod(
                periodData: [
                    'name' => '2024-2025',
                    'start_date' => '2024-06-01',
                    'end_date' => '2025-05-31',
                    'auto_close_current' => false,
                ],
                membersData: [
                    ['user_id' => $user->id, 'board_role_id' => $presidentRole->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );
        });

        // Verify role was assigned
        $this->assertTrue(
            User::withoutGlobalScopes()->find($user->id)->hasRole('president'),
            'Role should be assigned before closing'
        );

        // Close the period
        $tenant->run(function () use ($period): void {
            $service = app(BoardService::class);
            $service->closePeriod($period);
        });

        // Role must be revoked
        $this->assertFalse(
            User::withoutGlobalScopes()->find($user->id)->hasRole('president'),
            'User should NOT have president role after period is closed'
        );
    }

    public function test_all_five_board_role_types_grant_correct_spatie_roles(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $users[] = $this->makeCentralUser("Member {$i}");
        }

        $period = null;

        $tenant->run(function () use ($users, &$period): void {
            $service = app(BoardService::class);

            $period = $service->startPeriod(
                periodData: [
                    'name' => '2025-2026',
                    'start_date' => '2025-06-01',
                    'end_date' => '2026-05-31',
                    'auto_close_current' => false,
                ],
                membersData: [
                    ['user_id' => $users[0]->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                    ['user_id' => $users[1]->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $users[2]->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                    ['user_id' => $users[3]->id, 'board_role_id' => BoardRole::VOCAL, 'unit_id' => null],
                    ['user_id' => $users[4]->id, 'board_role_id' => BoardRole::OVERSIGHT_COMMITTEE, 'unit_id' => null],
                ],
            );
        });

        $expectedSlugs = ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'];
        foreach ($users as $i => $user) {
            $freshUser = User::withoutGlobalScopes()->find($user->id);
            $this->assertTrue(
                $freshUser->hasRole($expectedSlugs[$i]),
                "User {$i} should have role {$expectedSlugs[$i]}"
            );
        }
    }

    public function test_closing_period_revokes_all_member_roles(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeCentralUser('Presidente');
        $treasurer = $this->makeCentralUser('Tesorero');
        $period = null;

        $tenant->run(function () use ($president, $treasurer, &$period): void {
            $service = app(BoardService::class);

            $period = $service->startPeriod(
                periodData: [
                    'name' => 'Periodo de cierre',
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31',
                    'auto_close_current' => false,
                ],
                membersData: [
                    ['user_id' => $president->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                    ['user_id' => $treasurer->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );
        });

        // Close period
        $tenant->run(function () use ($period): void {
            app(BoardService::class)->closePeriod($period);
        });

        $this->assertFalse(User::withoutGlobalScopes()->find($president->id)->hasRole('president'));
        $this->assertFalse(User::withoutGlobalScopes()->find($treasurer->id)->hasRole('treasurer'));
    }

    // =========================================================================
    // ONE-CURRENT-PERIOD INVARIANT
    // =========================================================================

    public function test_starting_second_current_period_without_auto_close_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $tenant->run(function (): void {
            $role = BoardRole::findOrFail(BoardRole::PRESIDENT);
            $service = app(BoardService::class);

            $service->startPeriod(
                periodData: ['name' => 'Primer Periodo', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'auto_close_current' => false],
                membersData: $this->mandatoryMembers(),
            );

            // Second period has non-overlapping dates + valid mandatory roles, so the
            // ONLY reason it must throw is the single-current-period invariant.
            $this->expectException(\InvalidArgumentException::class);

            $service->startPeriod(
                periodData: ['name' => 'Segundo Periodo', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'auto_close_current' => false],
                membersData: $this->mandatoryMembers(),
            );
        });
    }

    public function test_starting_second_period_with_auto_close_closes_previous(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user1 = $this->makeCentralUser('Old President');
        $user2 = $this->makeCentralUser('New President');

        $firstPeriod = null;

        $tenant->run(function () use ($user1, $user2, &$firstPeriod): void {
            $role = BoardRole::findOrFail(BoardRole::PRESIDENT);
            $service = app(BoardService::class);

            $firstPeriod = $service->startPeriod(
                periodData: ['name' => 'Primer Periodo', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'auto_close_current' => false],
                membersData: [
                    ['user_id' => $user1->id, 'board_role_id' => $role->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero 1')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario 1')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );

            $service->startPeriod(
                periodData: ['name' => 'Segundo Periodo', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'auto_close_current' => true],
                membersData: [
                    ['user_id' => $user2->id, 'board_role_id' => $role->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero 2')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario 2')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );

            // Only one current period must exist
            $currentCount = BoardPeriod::where('board_period_status_id', BoardPeriodStatus::CURRENT)->count();
            $this->assertSame(1, $currentCount);

            // First period must now be closed
            $firstPeriod->refresh();
            $this->assertSame(BoardPeriodStatus::CLOSED, $firstPeriod->board_period_status_id);
        });

        // Old president's role revoked; new president's role granted
        $this->assertFalse(User::withoutGlobalScopes()->find($user1->id)->hasRole('president'));
        $this->assertTrue(User::withoutGlobalScopes()->find($user2->id)->hasRole('president'));
    }

    // =========================================================================
    // MULTIPLE VOCALES
    // =========================================================================

    public function test_multiple_vocales_allowed_in_same_period(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $vocal1 = $this->makeCentralUser('Vocal 1');
        $vocal2 = $this->makeCentralUser('Vocal 2');
        $vocal3 = $this->makeCentralUser('Vocal 3');

        $tenant->run(function () use ($vocal1, $vocal2, $vocal3): void {
            $vocalRole = BoardRole::findOrFail(BoardRole::VOCAL);
            $service = app(BoardService::class);

            $period = $service->startPeriod(
                periodData: ['name' => 'Periodo Vocales', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'auto_close_current' => false],
                membersData: array_merge($this->mandatoryMembers(), [
                    ['user_id' => $vocal1->id, 'board_role_id' => $vocalRole->id, 'unit_id' => null],
                    ['user_id' => $vocal2->id, 'board_role_id' => $vocalRole->id, 'unit_id' => null],
                    ['user_id' => $vocal3->id, 'board_role_id' => $vocalRole->id, 'unit_id' => null],
                ]),
            );

            // 3 mandatory officers + 3 vocales.
            $this->assertSame(6, $period->members()->count());
            $this->assertSame(3, $period->members()->where('board_role_id', $vocalRole->id)->count());
        });

        // All three vocales get the spatie vocal role
        foreach ([$vocal1, $vocal2, $vocal3] as $user) {
            $this->assertTrue(User::withoutGlobalScopes()->find($user->id)->hasRole('vocal'));
        }
    }

    // =========================================================================
    // NAME UNIQUENESS
    // =========================================================================

    public function test_board_period_name_must_be_unique_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $this->expectException(UniqueConstraintViolationException::class);

        $tenant->run(function (): void {
            $role = BoardRole::findOrFail(BoardRole::PRESIDENT);
            $status = BoardPeriodStatus::findOrFail(BoardPeriodStatus::CLOSED);
            // Create two periods with the same name — DB unique should reject
            BoardPeriod::create(['name' => 'Mismo Nombre', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'board_period_status_id' => $status->id, 'notes' => null]);
            BoardPeriod::create(['name' => 'Mismo Nombre', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'board_period_status_id' => $status->id, 'notes' => null]);
        });
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_board_periods_are_isolated_between_tenants(): void
    {
        $tenant1 = $this->makeTenant('Fraccionamiento A');
        $tenant2 = $this->makeTenant('Fraccionamiento B');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $user1 = $this->makeCentralUser('User A');
        $user2 = $this->makeCentralUser('User B');

        $tenant1->run(function () use ($user1): void {
            $role = BoardRole::findOrFail(BoardRole::PRESIDENT);
            app(BoardService::class)->startPeriod(
                ['name' => 'Periodo A', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'auto_close_current' => false],
                [
                    ['user_id' => $user1->id, 'board_role_id' => $role->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero A')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario A')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );
        });

        $tenant2->run(function () use ($user2): void {
            $role = BoardRole::findOrFail(BoardRole::PRESIDENT);
            app(BoardService::class)->startPeriod(
                ['name' => 'Periodo B', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'auto_close_current' => false],
                [
                    ['user_id' => $user2->id, 'board_role_id' => $role->id, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Tesorero B')->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeCentralUser('Secretario B')->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            );
        });

        $tenant1->run(function (): void {
            $this->assertSame(1, BoardPeriod::count(), 'Tenant 1 should only see its own period');
        });

        $tenant2->run(function (): void {
            $this->assertSame(1, BoardPeriod::count(), 'Tenant 2 should only see its own period');
        });
    }
}
